<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reading a workshop's trail — M13.
 *
 * Read-only, and structurally so: the write side is {@see AuditRecorder}, driven
 * by model events, and there is no method here that could put a row on the trail.
 * The same shape as {@see \App\Services\Reporting\ReportService}, for a related
 * reason — both answer questions about records that something else owns.
 */
class AuditService
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $entries,
    ) {}

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
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->entries->paginate($filters, $perPage);
    }

    /**
     * What a client may filter by, published rather than hard-coded on the
     * screen — the same arrangement `GET /reports/meta` uses, and for the same
     * reason: a copy in the client is right until somebody adds a resource.
     *
     * @return array{
     *     resources: array<int, array{value: string, label: string, route: string|null}>,
     *     actions: array<int, array{value: string, label: string}>,
     *     actors: Collection<int, array{id: int|null, name: string, entries: int}>,
     *     total: int
     * }
     */
    public function meta(): array
    {
        return [
            'resources' => array_map(
                fn (AuditResource $resource) => [
                    'value' => $resource->value,
                    'label' => $resource->label(),
                    'route' => $resource->route(),
                ],
                AuditResource::cases(),
            ),
            'actions' => AuditAction::catalogue(),
            'actors' => $this->entries->actors(),
            // So the screen can tell "this workshop has no history yet" from
            // "nothing matches what you asked for". The two look identical in an
            // empty table and mean completely different things.
            'total' => $this->entries->count(),
        ];
    }
}
