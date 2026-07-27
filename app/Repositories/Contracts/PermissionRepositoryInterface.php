<?php

namespace App\Repositories\Contracts;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

interface PermissionRepositoryInterface
{
    /**
     * @return Collection<int, Permission>
     */
    public function all(): Collection;

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Permission>
     */
    public function findByIds(array $ids): Collection;

    public function findByActionAndResource(string $action, string $resource): ?Permission;

    /**
     * Flat "ACTION:RESOURCE" grants held by a role.
     *
     * @return array<int, string>
     */
    public function grantsForRole(int $roleId): array;
}
