<?php

namespace App\Repositories\Eloquent;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentPermissionRepository implements PermissionRepositoryInterface
{
    public function all(): Collection
    {
        return Permission::orderBy('resource')->orderBy('action')->get();
    }

    public function findByIds(array $ids): Collection
    {
        return Permission::whereIn('id', $ids)->get();
    }

    public function findByActionAndResource(string $action, string $resource): ?Permission
    {
        return Permission::where('action', $action)->where('resource', $resource)->first();
    }

    public function grantsForRole(int $roleId): array
    {
        // Kept as a join rather than a relation load: this runs on every
        // guarded request (behind the RBAC cache) and only needs two columns.
        return DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $roleId)
            ->orderBy('permissions.resource')
            ->orderBy('permissions.action')
            ->get(['permissions.action', 'permissions.resource'])
            ->map(fn ($row) => $row->action.':'.$row->resource)
            ->all();
    }
}
