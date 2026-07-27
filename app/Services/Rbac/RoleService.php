<?php

namespace App\Services\Rbac;

use App\Exceptions\ConflictException;
use App\Exceptions\Rbac\RoleInUseException;
use App\Exceptions\Rbac\SystemRoleImmutableException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Custom role management. System roles (ADMIN) are read-only here by design:
 * if the seeded superuser role could be edited through the API, a single
 * compromised admin session could silently lock everyone else out.
 */
class RoleService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionService $permissions,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @param  array{search?: string|null, system?: bool|null}  $filters
     * @return LengthAwarePaginator<int, Role>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->roles->paginate($filters, $perPage);
    }

    public function find(int $id): Role
    {
        return $this->roles->findById($id) ?? throw new ResourceNotFoundException('Role', $id);
    }

    /**
     * @param  array{name: string, description?: string|null, permission_ids?: array<int, int>}  $data
     */
    public function create(array $data): Role
    {
        $name = trim($data['name']);

        if ($this->roles->nameExists($name)) {
            throw new ConflictException(
                "A role named [{$name}] already exists.",
                'RBAC_ROLE_EXISTS',
                ['field' => 'name'],
            );
        }

        $permissionIds = $this->assertPermissionsExist($data['permission_ids'] ?? []);

        $role = DB::transaction(function () use ($name, $data, $permissionIds) {
            $role = $this->roles->create([
                'name' => $name,
                'slug' => Role::slugFor($name),
                'description' => $data['description'] ?? null,
                // Roles created through the API are never system roles.
                'is_system_role' => false,
            ]);

            return $this->roles->syncPermissions($role, $permissionIds);
        });

        Log::info('rbac.role_created', ['role_id' => $role->id, 'permissions' => count($permissionIds)]);

        return $role;
    }

    /**
     * @param  array{name?: string, description?: string|null, permission_ids?: array<int, int>}  $data
     */
    public function update(int $id, array $data): Role
    {
        $role = $this->find($id);

        $this->assertMutable($role, 'updated');

        $attributes = [];

        if (array_key_exists('name', $data) && trim($data['name']) !== $role->name) {
            $name = trim($data['name']);

            if ($this->roles->nameExists($name, $role->id)) {
                throw new ConflictException(
                    "A role named [{$name}] already exists.",
                    'RBAC_ROLE_EXISTS',
                    ['field' => 'name'],
                );
            }

            $attributes['name'] = $name;
            $attributes['slug'] = Role::slugFor($name);
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $data['description'];
        }

        $role = DB::transaction(function () use ($role, $attributes, $data) {
            if ($attributes !== []) {
                $role = $this->roles->update($role, $attributes);
            }

            if (array_key_exists('permission_ids', $data)) {
                $role = $this->roles->syncPermissions(
                    $role,
                    $this->assertPermissionsExist($data['permission_ids'])
                );
            }

            return $role;
        });

        // Permissions are cached per role; a stale entry would keep a revoked
        // grant alive, so the flush is not optional.
        $this->authorization->flushRoleCache($role);

        Log::info('rbac.role_updated', ['role_id' => $role->id]);

        return $role;
    }

    public function delete(int $id): void
    {
        $role = $this->find($id);

        $this->assertMutable($role, 'deleted');

        $assigned = $this->roles->countUsers($role);

        if ($assigned > 0) {
            // Refusing here is deliberate: silently nulling the role would
            // strip permissions from live users without anyone noticing.
            throw new RoleInUseException($role->name, $assigned);
        }

        DB::transaction(function () use ($role) {
            $this->roles->syncPermissions($role, []);
            $this->roles->delete($role);
        });

        $this->authorization->flushRoleCache($role);

        Log::info('rbac.role_deleted', ['role_id' => $role->id]);
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(int $id, array $permissionIds): Role
    {
        $role = $this->find($id);

        $this->assertMutable($role, 'modified');

        $role = $this->roles->syncPermissions($role, $this->assertPermissionsExist($permissionIds));

        $this->authorization->flushRoleCache($role);

        return $role;
    }

    private function assertMutable(Role $role, string $operation): void
    {
        if ($role->isSystemRole()) {
            throw new SystemRoleImmutableException($role->name, $operation);
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function assertPermissionsExist(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $resolved = $this->permissions->resolve($ids);

        if ($resolved['missing'] !== []) {
            throw new ResourceNotFoundException(
                'Permission',
                implode(', ', $resolved['missing'])
            );
        }

        return $ids;
    }
}
