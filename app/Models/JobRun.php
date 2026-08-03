<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of background work, from dispatch to outcome — M14.
 *
 * The row a progress bar polls. Created when a job is *dispatched*, not when a
 * worker picks it up, so there is never a window in which a user has been told
 * their upload is being processed and the application cannot say anything about
 * it. It outlives the job in both directions: it exists before the worker sees
 * the job and after the job has gone.
 *
 * Mutable, unlike almost everything else in this schema, and it has to be —
 * progress is the one figure here that is a fact about a process rather than a
 * sum over rows. See the migration on why that is not a stored aggregate in the
 * sense this codebase forbids.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $uuid
 * @property string $type
 * @property JobStatus $status
 * @property int $progress
 * @property int|null $processed
 * @property int|null $total
 * @property string|null $message
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 */
#[Fillable([
    'tenant_id', 'uuid', 'type', 'status', 'progress', 'processed', 'total',
    'message', 'payload', 'result', 'error', 'started_at', 'finished_at', 'created_by',
])]
class JobRun extends Model
{
    use BelongsToTenant;

    /**
     * Deliberately no HasFactory. A run row that no job produced would be a
     * claim that work happened, which is the same objection {@see JournalEntry}
     * and {@see AuditLog} raise — tests here queue a real job and let it run.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'progress' => 'integer',
            'processed' => 'integer',
            'total' => 'integer',
            'payload' => 'array',
            'result' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Addressed by uuid, never by id — see the migration on why the public
     * handle is not the primary key.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    public function hasFailed(): bool
    {
        return $this->status === JobStatus::Failed;
    }

    /**
     * How long it has been going, or how long it took. Seconds.
     *
     * Shown beside the progress bar, and that pairing is deliberate: progress is
     * a number a worker last wrote, so a worker killed mid-run leaves it frozen
     * at 47% for ever. The elapsed time is the thing that tells a reader the
     * difference between "working" and "stuck", and it is computed rather than
     * stored, so it cannot freeze.
     */
    public function elapsedSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), absolute: true);
    }

    /**
     * What went wrong, in one line, for somebody who is not a developer.
     *
     * The stack trace is `failed_jobs`' business. This column is read by an
     * owner looking at their own uploads, and an exception class name with a
     * file path in it tells them nothing they can act on.
     */
    public function errorMessage(): ?string
    {
        return $this->error['message'] ?? null;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, JobStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof JobStatus ? $status->value : $status);
    }

    /**
     * Still going: queued or running. The query behind a "something is
     * happening" badge, and the one a polling client makes most often.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value]);
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
