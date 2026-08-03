<?php

namespace App\Repositories\Contracts;

use App\Models\JobRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Background work, as the application sees it.
 *
 * Unlike the ledger repositories, this one has writes and a delete — and both
 * are legitimate here for the same reason the table is mutable at all: a run row
 * describes a process, not a fact about the books. It moves while the process
 * moves, and once the process is long finished and long forgotten it is removed,
 * because the alternative is a table that grows for ever recording that eleven
 * thousand uploads worked.
 */
interface JobRunRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): JobRun;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(JobRun $run, array $attributes): JobRun;

    /**
     * The public handle. Null rather than an exception, so a caller polling a
     * run that has been pruned gets an answer rather than a stack trace.
     */
    public function findByUuid(string $uuid): ?JobRun;

    /**
     * @param  array{type?: string|null, status?: string|null, unsettled?: bool|null}  $filters
     * @return LengthAwarePaginator<int, JobRun>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * How many runs are still queued or running — the badge, and what a client
     * polls to know whether to keep polling.
     */
    public function unsettledCount(): int;

    /**
     * Remove settled runs finished before a cut-off.
     *
     * Two cut-offs rather than one, because the two outcomes are worth keeping
     * for very different lengths of time — see `config/attachments.php`.
     *
     * Deliberately *not* tenant-scoped: pruning is an operator's act over the
     * whole installation, run from the scheduler where there is no tenant at
     * all. It is the only method here that says so.
     */
    public function pruneSettled(string $succeededBefore, string $failedBefore): int;
}
