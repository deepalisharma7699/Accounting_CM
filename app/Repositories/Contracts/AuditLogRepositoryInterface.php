<?php

namespace App\Repositories\Contracts;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reading the trail.
 *
 * Note what is absent, exactly as it is on {@see JournalEntryRepositoryInterface}
 * and {@see StockMovementRepositoryInterface}: there is no create, no update and
 * no delete. Rows arrive through {@see \App\Services\Audit\AuditRecorder}, driven
 * by model events, and never through a repository — a write method here would be
 * a way to put a claim on the trail without anything having happened.
 */
interface AuditLogRepositoryInterface
{
    /**
     * @param  array{
     *     search?: string|null,
     *     resource?: string|null,
     *     resource_id?: int|null,
     *     action?: string|null,
     *     actor_id?: int|null,
     *     from?: string|null,
     *     to?: string|null
     * }  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator;

    /**
     * Everyone who appears on this workshop's trail, for the actor filter.
     *
     * Read from `audit_logs` rather than from `users`, and the difference
     * matters: somebody who has left still appears in the history, and a filter
     * built from the current user list could not select them. The name comes
     * from the copy on the row for the same reason.
     *
     * @return Collection<int, array{id: int|null, name: string, entries: int}>
     */
    public function actors(): Collection;

    /**
     * How many entries this workshop has, for the empty-state distinction
     * between "nothing has happened" and "nothing matches your filters".
     */
    public function count(): int;
}
