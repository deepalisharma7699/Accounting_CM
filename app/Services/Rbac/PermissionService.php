<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PermissionService
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
    ) {}

    /**
     * @return Collection<int, Permission>
     */
    public function list(): Collection
    {
        return $this->permissions->all();
    }

    /**
     * The catalogue grouped by resource — the shape a permissions matrix UI
     * actually wants to render.
     *
     * @return array<string, array<int, array{id: int, action: string, description: string|null}>>
     */
    public function groupedByResource(): array
    {
        return $this->permissions->all()
            ->groupBy('resource')
            ->map(fn (Collection $group) => $group->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'action' => $permission->action,
                'description' => $permission->description,
            ])->values()->all())
            ->all();
    }

    /**
     * Validate that every supplied id exists, returning the models.
     *
     * @param  array<int, int>  $ids
     * @return array{found: Collection<int, Permission>, missing: array<int, int>}
     */
    public function resolve(array $ids): array
    {
        $found = $this->permissions->findByIds($ids);

        return [
            'found' => $found,
            'missing' => array_values(array_diff($ids, $found->modelKeys())),
        ];
    }
}
