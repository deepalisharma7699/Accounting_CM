<?php

namespace App\Services\Storage;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Jobs\ProcessAttachment;
use App\Models\Attachment;
use App\Models\JobRun;
use App\Models\User;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use App\Services\Jobs\JobRunService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Taking a file in, and giving it back — M14.
 *
 * ## What "nothing blocks on upload" actually means here
 *
 * It does not mean the bytes travel later. They cannot: PHP has the temporary
 * file only for the length of the request, and deferring the move would mean
 * storing it somewhere first, which is the same work in a worse place. What the
 * request does is the irreducible minimum — check the file, move it to object
 * storage, write one row — and then it *stops*. It does not read the object back
 * to confirm it landed, it does not open the image, and when M15 arrives it will
 * not call a model. All of that is queued, and the response carries a job handle
 * instead of an outcome.
 *
 * The distinction is the one that matters for the screen: an upload returns in
 * the time it takes to move a file, and the part that could take ten seconds or
 * fail is watched rather than waited on.
 */
class AttachmentService
{
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly FileStorageService $storage,
        private readonly JobRunService $runs,
    ) {}

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Store a file and queue its verification.
     *
     * @return array{attachment: Attachment, job: JobRun|null, duplicates: Collection<int, Attachment>}
     */
    public function upload(UploadedFile $file, AttachmentKind $kind, ?User $actor = null): array
    {
        // Refuses and writes before anything touches the database, so a rejected
        // file never leaves a row behind.
        $stored = $this->storage->store($file, $kind);

        try {
            $attachment = DB::transaction(fn () => $this->attachments->create([
                'kind' => $kind,
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'original_name' => $stored['original_name'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'checksum' => $stored['checksum'],
                // Not `ready`. The bytes are written; whether they can be read
                // back is a different claim, and it is the queued job's to make.
                'status' => AttachmentStatus::Pending,
                'uploaded_by' => $actor?->id,
            ]));
        } catch (Throwable $exception) {
            // The object is already in the bucket and the row that was to
            // describe it does not exist. Left alone that is storage nothing can
            // attribute and nothing will ever reclaim.
            $this->storage->discard($stored['path']);

            throw $exception;
        }

        return [
            'attachment' => $attachment,
            // Dispatched *after* the transaction has committed, not inside it.
            // The database queue driver would otherwise let a worker pick the
            // job up before the attachment row was visible, and the job would
            // fail looking for a row that was about to exist.
            'job' => $this->queueVerification($attachment),
            // Said, never acted on. Photographing one invoice twice is
            // reasonable; quietly handing back the first row would create a file
            // that two things point at and either may delete. The same treatment
            // a shared GSTIN gets in M5 and a duplicate specification in M7.
            'duplicates' => $this->attachments->duplicatesOf($stored['checksum'], $attachment->id),
        ];
    }

    /**
     * Remove a file and the row that described it.
     *
     * Row first, object second, and the order is deliberate: the row is the only
     * thing anybody can see, and an object left behind after a failure is wasted
     * storage an operator can sweep. The other order risks a row pointing at
     * bytes that are gone, which is a file the workshop believes they still have.
     *
     * The deletion is on the audit trail — the one thing worth recording about a
     * stored file, since unlike an archived party it leaves nothing behind.
     */
    public function delete(int $id): Attachment
    {
        $attachment = $this->find($id);

        DB::transaction(fn () => $this->attachments->delete($attachment));

        $this->storage->delete($attachment);

        return $attachment;
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    public function find(int $id): Attachment
    {
        return $this->attachments->findById($id)
            ?? throw new ResourceNotFoundException('Attachment', $id);
    }

    /**
     * @param  array{kind?: string|null, status?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Attachment>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->attachments->paginate($filters, $perPage);
    }

    /**
     * A short-lived URL straight to object storage, or null where the disk
     * cannot make one. The caller always has the download route to fall back on.
     */
    public function temporaryUrl(Attachment $attachment): ?string
    {
        return $this->storage->temporaryUrl($attachment);
    }

    public function stream(Attachment $attachment): mixed
    {
        return $this->storage->stream($attachment);
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * Queue the read-back, and point the row at the run so one poll of one URL
     * answers "did my upload store".
     *
     * A failure to *queue* is not allowed to fail the upload. The bytes are
     * safely stored and the row exists, which is the part that cannot be redone;
     * an unverified attachment is a lesser problem than a 500 in front of
     * somebody holding a phone, and it is visible — the row sits at `pending`
     * with no run behind it, which is exactly what it is.
     */
    private function queueVerification(Attachment $attachment): ?JobRun
    {
        try {
            $job = new ProcessAttachment($attachment->id);

            dispatch($job);

            $run = $this->runs->findOrNull($job->runUuid);

            if ($run !== null) {
                $this->attachments->attachJobRun($attachment, $run->id);
            }

            return $run;
        } catch (Throwable) {
            return null;
        }
    }
}
