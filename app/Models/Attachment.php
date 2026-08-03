<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stored file: a photographed invoice, a recorded instruction, a PDF — M14.
 *
 * The row is a pointer, never the bytes. What it records is everything needed to
 * find the object again, to prove it is the object that was sent, and to say who
 * sent it.
 *
 * Audited, unlike the rest of M14, and the exception is worth stating. Master
 * data is audited because it changes silently; a file's bytes never change at
 * all, so there is nothing to record on that side. What is worth recording is
 * its *removal*: an attachment is evidence, and unlike an archived party it
 * leaves nothing behind when it goes. Deleting the photograph of an invoice is
 * exactly the act an audit trail exists for.
 *
 * @property int $id
 * @property int $tenant_id
 * @property AttachmentKind $kind
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property AttachmentStatus $status
 * @property int|null $job_run_id
 * @property array<string, mixed>|null $meta
 * @property int|null $uploaded_by
 * @property Carbon $created_at
 */
#[Fillable([
    'tenant_id', 'kind', 'disk', 'path', 'original_name', 'mime_type',
    'size_bytes', 'checksum', 'status', 'job_run_id', 'meta', 'uploaded_by',
])]
class Attachment extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * Deliberately no HasFactory. A row here without an object behind it is a
     * pointer to nothing, and a test written against one would be asserting that
     * the application handles a state it cannot produce. Tests upload a real
     * file to a faked disk, which is what the application does.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'status' => AttachmentStatus::class,
            'size_bytes' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * Not one of these can change, and that is the point.
     *
     * A file's bytes never change — the whole shape of this module, and the
     * reason there is no UPDATE grant for attachments anywhere in the system —
     * so this list can never produce an *edit* entry. It exists solely for the
     * deletion snapshot, which is the only thing worth recording about a stored
     * file: somebody removed this evidence, and it was a 2.1 MB photograph
     * called `bharat-motors-14-mar.jpg`.
     *
     * `status` is deliberately absent although it does move, from `pending` to
     * `ready`. Nobody decides it — a queued job writes it — and a trail of
     * things nobody did is how a trail stops being read. It is the same
     * exclusion the lockout counters get on {@see User}.
     *
     * `path`, `disk` and `checksum` are absent for a different reason: an object
     * key is a thing you fetch with, and the trail has no business handing one
     * out to every reader of the history screen.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['original_name', 'kind', 'mime_type', 'size_bytes'];
    }

    public function auditLabel(): string
    {
        return $this->original_name;
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The verification run. Null once it has been pruned — the attachment
     * outlives it, which is why the foreign key is nullOnDelete.
     *
     * @return BelongsTo<JobRun, $this>
     */
    public function jobRun(): BelongsTo
    {
        return $this->belongsTo(JobRun::class, 'job_run_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * Whether this file has been read back and found to be the bytes that were
     * sent. Nothing should be *used* before this is true — M15 reads it before
     * handing anything to a model.
     */
    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * A size a person can read. Kept on the model rather than in a resource
     * because the console command and the screens both want it.
     */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0).' '.$units[$unit];
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, AttachmentKind|string $kind): Builder
    {
        return $query->where('kind', $kind instanceof AttachmentKind ? $kind->value : $kind);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, AttachmentStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof AttachmentStatus ? $status->value : $status);
    }

    /**
     * Other rows holding the same bytes — the duplicate notice.
     *
     * Reported and never acted on: uploading one invoice twice is reasonable,
     * and quietly handing back the first row would create a file that two things
     * point at and that either of them may delete. The same treatment a shared
     * GSTIN gets in M5.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, string $checksum, ?int $exceptId = null): Builder
    {
        return $query->where('checksum', $checksum)
            ->when($exceptId !== null, fn ($inner) => $inner->where('id', '!=', $exceptId));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
