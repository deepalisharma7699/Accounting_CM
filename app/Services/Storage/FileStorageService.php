<?php

namespace App\Services\Storage;

use App\Enums\AttachmentKind;
use App\Exceptions\Storage\FileRejectedException;
use App\Exceptions\Storage\StorageUnavailableException;
use App\Models\Attachment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Everything that touches object storage — M14.
 *
 * The one place in the application that knows a disk exists. Nothing else builds
 * a key, chooses a filename, or decides whether a file may be accepted; a second
 * caller assembling its own path is how a workshop's photograph ends up under
 * another workshop's prefix.
 *
 * ## The three rules
 *
 *   1. **The media type is sniffed, never believed.** `Content-Type` on a
 *      multipart part is whatever the client chose to write there. The type is
 *      taken from the bytes, and the stored extension is derived from *that* —
 *      so a file called `invoice.jpg.php` is stored as `.jpg` or refused, and is
 *      never written under the name it arrived with.
 *
 *   2. **The key carries the tenant.** `tenants/{id}/{kind}/{year}/{month}/{ulid}.{ext}`.
 *      Every read is already filtered by the tenant scope, but storage is the
 *      one place a bug would not be caught by it: an object key is a string, and
 *      a string assembled wrongly reaches whatever it names. With the tenant in
 *      the prefix, a bucket policy can enforce the boundary independently of
 *      this code, a mis-scoped read is visible in a listing, and a workshop's
 *      files can be exported or destroyed by prefix when they leave.
 *
 *   3. **Nothing is trusted until it has been read back.** A write to object
 *      storage can return cleanly and leave nothing readable — a wrong bucket, a
 *      policy that covers writes and not reads, a region that has not caught up.
 *      So {@see verify()} fetches the object and checks its length and its
 *      digest, and only that promotes a row to `ready`.
 */
class FileStorageService
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Check an upload and put it in object storage.
     *
     * Returns the facts about what was written — the caller makes the row. The
     * split matters: this method has no opinion about `attachments`, so the
     * verification job and any future re-upload path use the same code without
     * inheriting a table.
     *
     * @return array{
     *     disk: string,
     *     path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     checksum: string,
     *     original_name: string
     * }
     */
    public function store(UploadedFile $file, AttachmentKind $kind): array
    {
        if (! $file->isValid()) {
            throw FileRejectedException::unreadable();
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw FileRejectedException::empty();
        }

        if ($size > $kind->maxBytes()) {
            throw FileRejectedException::tooLarge($kind, $size);
        }

        // getMimeType() reads the bytes; getClientMimeType() reads the header the
        // client sent. Only the first is evidence.
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        if (! $kind->accepts($mimeType)) {
            throw FileRejectedException::unsupportedType($kind, $mimeType);
        }

        // Hashed before the move, from the file PHP received — so the digest is
        // of what arrived, and comparing it against the object later proves the
        // round trip rather than proving the disk agrees with itself.
        $checksum = hash_file('sha256', $file->getRealPath());

        $path = $this->keyFor($kind, $kind->extensionFor($mimeType));

        try {
            $this->disk()->putFileAs(dirname($path), $file, basename($path));
        } catch (Throwable $exception) {
            throw StorageUnavailableException::writing($exception);
        }

        return [
            'disk' => $this->diskName(),
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'checksum' => $checksum,
            // Kept for display and for the download filename, and used for
            // nothing else. It is user-supplied text and never touches a path.
            'original_name' => $this->safeName($file->getClientOriginalName()),
        ];
    }

    /**
     * Read the object back and check it is the file that was sent.
     *
     * The whole reason {@see \App\Enums\AttachmentStatus} has three cases. A
     * length that differs means a truncated write; a digest that differs means
     * different bytes; a missing object means the write went somewhere else
     * entirely. All three are silent at upload time and all three are fatal
     * later, when somebody needs the invoice.
     *
     * @return array{ok: bool, reason: string|null, meta: array<string, mixed>}
     */
    public function verify(Attachment $attachment): array
    {
        $disk = $this->diskFor($attachment);

        try {
            if (! $disk->exists($attachment->path)) {
                return $this->outcome(false, 'The file is not in the store. The upload did not complete.');
            }

            $size = (int) $disk->size($attachment->path);

            if ($size !== $attachment->size_bytes) {
                return $this->outcome(false, sprintf(
                    'The stored file is %d bytes but %d were uploaded, so it arrived incomplete.',
                    $size,
                    $attachment->size_bytes,
                ));
            }

            $checksum = $this->digestOf($disk, $attachment->path);
        } catch (Throwable $exception) {
            // Thrown rather than returned: an unreachable store is a retryable
            // outage, and the job's retries are the right response. A file that
            // is genuinely wrong returns false above and is not retried, because
            // trying again would produce the same wrong file.
            throw StorageUnavailableException::reading($exception);
        }

        if (! hash_equals($attachment->checksum, $checksum)) {
            return $this->outcome(false, 'The stored file does not match what was uploaded.');
        }

        return $this->outcome(true, null, ['verified_bytes' => $size]);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * A short-lived URL straight to object storage, where the driver can make
     * one.
     *
     * Null on a local disk, which has no such thing — the caller falls back to
     * the download route, which streams through the application. Both are
     * private; the signed URL simply keeps a workshop's photographs off the
     * application server's bandwidth in production.
     */
    public function temporaryUrl(Attachment $attachment): ?string
    {
        $disk = $this->diskFor($attachment);

        if (! method_exists($disk, 'temporaryUrl')) {
            return null;
        }

        try {
            return $disk->temporaryUrl(
                $attachment->path,
                now()->addMinutes((int) config('attachments.url_ttl_minutes', 10)),
            );
        } catch (Throwable) {
            // A driver that advertises the method and cannot honour it — the
            // local driver without `serve`, most often. The download route is
            // always there, so this is a fallback and not a failure.
            return null;
        }
    }

    /**
     * Stream the object through the application, under its original name.
     *
     * `attachment`, never `inline`: the browser is told to save the file rather
     * than to render it. An HTML or SVG file that a workshop had uploaded and
     * that the browser rendered in this application's origin would be somebody
     * else's script running with this application's cookies — and although
     * neither type is on any kind's allow-list today, a `Content-Disposition`
     * that depends on an allow-list staying correct is a guard that will be
     * wrong once.
     */
    public function stream(Attachment $attachment): StreamedResponse
    {
        $disk = $this->diskFor($attachment);

        try {
            return $disk->download($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            throw StorageUnavailableException::reading($exception);
        }
    }

    /* ---------------------------------------------------------------------
     | Removing
     |-------------------------------------------------------------------- */

    /**
     * Delete the object.
     *
     * Never fails the caller. By the time this runs the row is going or gone,
     * and an object left behind is wasted storage an operator can sweep; a
     * refusal here would leave a row nobody can remove pointing at a file
     * nobody can reach. The two failures are not symmetrical, so they are not
     * treated symmetrically.
     */
    public function delete(Attachment $attachment): bool
    {
        try {
            return $this->diskFor($attachment)->delete($attachment->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Remove an object written moments ago, when the row that was to describe it
     * could not be created. Without this, a failed insert leaves bytes in the
     * bucket that nothing in the database knows about — storage that can never
     * be attributed and never be reclaimed.
     */
    public function discard(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable) {
            // Best effort by definition: this is already the failure path.
        }
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * `tenants/{id}/{kind}/{year}/{month}/{ulid}.{ext}`
     *
     * The ULID is what makes the key unguessable and collision-free, and it
     * sorts by time so a bucket listing reads in upload order. The year and
     * month keep any one prefix small enough to list, which matters on the day
     * somebody has to go through a workshop's files by hand.
     */
    private function keyFor(AttachmentKind $kind, string $extension): string
    {
        return sprintf(
            'tenants/%d/%s/%s/%s.%s',
            $this->tenancy->requireTenant('storing a file'),
            $kind->value,
            now()->format('Y/m'),
            (string) Str::ulid(),
            $extension,
        );
    }

    /**
     * The display name, stripped of anything that could be read as a path.
     *
     * It is never used to build a key — that is the ULID's job — so this is
     * defence for everywhere *else* the name travels: a download header, a log
     * line, a screen.
     */
    private function safeName(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '', basename($name));
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $name === '' ? 'upload' : mb_substr($name, 0, 255);
    }

    /**
     * Hashed a chunk at a time. `get()` would pull an 8 MB photograph into
     * memory for the sake of a digest, and a worker doing that for several
     * uploads at once is a worker that gets killed.
     */
    private function digestOf(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if ($stream === null || $stream === false) {
            throw StorageUnavailableException::reading();
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{ok: bool, reason: string|null, meta: array<string, mixed>}
     */
    private function outcome(bool $ok, ?string $reason, array $meta = []): array
    {
        return ['ok' => $ok, 'reason' => $reason, 'meta' => $meta];
    }

    private function diskName(): string
    {
        return (string) config('attachments.disk', 'documents');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * The disk the row was written to, which is not necessarily today's default.
     * An operator moving from local storage to S3 must not find that yesterday's
     * uploads have become unreadable because a config key changed underneath
     * them — which is why `attachments.disk` is a column and not just a setting.
     */
    private function diskFor(Attachment $attachment): Filesystem
    {
        return Storage::disk($attachment->disk);
    }
}
