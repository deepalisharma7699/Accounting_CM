<?php

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Models\JobRun;
use App\Repositories\Contracts\JobRunRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Throwable;

/**
 * The lifecycle of one piece of background work — M14.
 *
 * Everything that writes to `job_runs` is here, and the sequence is fixed:
 * {@see open()} at dispatch, {@see markRunning()} when a worker picks it up,
 * {@see report()} as often as the job likes, then exactly one of
 * {@see markSucceeded()} or {@see markFailed()}. {@see \App\Jobs\TrackedJob}
 * drives all of it, so an individual job never has to remember the order.
 */
class JobRunService
{
    public function __construct(
        private readonly JobRunRepositoryInterface $runs,
    ) {}

    /* ---------------------------------------------------------------------
     | Lifecycle
     |-------------------------------------------------------------------- */

    /**
     * Record that work has been queued.
     *
     * Called at *dispatch*, from the request that asked for it, so the caller
     * can be handed a handle immediately. That is what "nothing blocks" means
     * in practice: the response carries a uuid to poll rather than the outcome
     * of work that has not happened.
     *
     * @param  array<string, mixed>  $payload
     */
    public function open(string $type, int $tenantId, ?int $actorId, array $payload = []): JobRun
    {
        return $this->runs->create([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'status' => JobStatus::Queued,
            'progress' => 0,
            // The ids the job was handed, never the data itself. Enough to
            // understand the row six weeks later without making this table a
            // second copy of the work.
            'payload' => $payload === [] ? null : $payload,
            'created_by' => $actorId,
        ]);
    }

    /**
     * A worker has picked it up.
     *
     * Resets `error` as well as setting the status, because this is also the
     * retry path: an attempt that failed and is being tried again must not leave
     * the previous attempt's message sitting on a row that now says "running".
     */
    public function markRunning(JobRun $run): JobRun
    {
        return $this->runs->update($run, [
            'status' => JobStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
        ]);
    }

    /**
     * How far along, and optionally what it is doing.
     *
     * `total` is remembered once given, so a job that counts its work up front
     * can then report only the running count. A job that cannot count leaves
     * both null and sends a message instead — "reading the invoice" is a better
     * thing to show somebody than a bar frozen at zero.
     */
    public function report(JobRun $run, ?int $processed = null, ?int $total = null, ?string $message = null): JobRun
    {
        $total ??= $run->total;

        $attributes = array_filter([
            'processed' => $processed,
            'total' => $total,
            'message' => $message,
        ], fn ($value) => $value !== null);

        if ($processed !== null && $total !== null && $total > 0) {
            // Capped below 100 while the job is still running: a bar that reads
            // 100% next to a spinner is the single most common way a progress
            // display loses somebody's trust. Completion is `status`, and only
            // markSucceeded() writes it.
            $attributes['progress'] = min(99, (int) floor($processed / $total * 100));
        }

        return $attributes === [] ? $run : $this->runs->update($run, $attributes);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function markSucceeded(JobRun $run, ?array $result = null): JobRun
    {
        return $this->runs->update($run, [
            'status' => JobStatus::Succeeded,
            'progress' => 100,
            'finished_at' => now(),
            'result' => $result,
            'error' => null,
        ]);
    }

    /**
     * Every attempt is used up.
     *
     * The message is the exception's, and it is shown to an owner — so anything
     * a workshop is meant to act on has to be phrased for them where it is
     * thrown. The class name is kept beside it for whoever reads the logs; the
     * stack trace is not, because that is `failed_jobs`' job and this column is
     * read on a screen.
     */
    public function markFailed(JobRun $run, Throwable|string $reason): JobRun
    {
        return $this->runs->update($run, [
            'status' => JobStatus::Failed,
            'finished_at' => now(),
            'error' => [
                'message' => $reason instanceof Throwable ? $reason->getMessage() : $reason,
                'exception' => $reason instanceof Throwable ? $reason::class : null,
                'at' => now()->toIso8601String(),
            ],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    public function find(string $uuid): JobRun
    {
        return $this->runs->findByUuid($uuid)
            ?? throw new ResourceNotFoundException('Job', $uuid);
    }

    /**
     * The run row a job is tracking, or null if it has gone.
     *
     * Separate from {@see find()} because the worker's case is different from a
     * client's: a missing row there means the transaction that dispatched the
     * job rolled back, and the job is a ghost of work that was never committed.
     * See {@see \App\Jobs\TrackedJob}.
     */
    public function findOrNull(string $uuid): ?JobRun
    {
        return $this->runs->findByUuid($uuid);
    }

    /**
     * @param  array{type?: string|null, status?: string|null, unsettled?: bool|null}  $filters
     * @return LengthAwarePaginator<int, JobRun>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->runs->paginate($filters, $perPage);
    }

    public function unsettledCount(): int
    {
        return $this->runs->unsettledCount();
    }

    /* ---------------------------------------------------------------------
     | Housekeeping
     |-------------------------------------------------------------------- */

    /**
     * Drop settled runs past their retention. See `jobs:prune`.
     */
    public function prune(): int
    {
        $retention = (array) config('attachments.retention', []);

        return $this->runs->pruneSettled(
            now()->subDays((int) ($retention['succeeded_days'] ?? 7))->toDateTimeString(),
            now()->subDays((int) ($retention['failed_days'] ?? 90))->toDateTimeString(),
        );
    }
}
