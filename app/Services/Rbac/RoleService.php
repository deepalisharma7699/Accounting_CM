<?php

namespace App\Services\Rbac;

use App\Exceptions\ConflictException;
use App\Exceptions\Rbac\PlatformRoleImmutableException;
use App\Exceptions\Rbac\RoleGrantNotAllowedException;
use App\Exceptions\Rbac\RoleInUseException;
use App\Exceptions\Rbac\SystemRoleImmutableException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Custom role management. System roles (ADMIN) are read-only here by design:
 * if the seeded superuser role could be edited through the API, a single
 * compromised admin session could silently lock everyone else out.
 *
 * Roles are tenant-based, and the two scopes are two separate lists. A workshop
 * creates and edits roles of its own and sees nothing else — not another
 * workshop's, and not the platform's either. The platform's own panel sees the
 * platform's roles, and reaches a workshop's through `/tenants/{tenant}/roles`,
 * where the context is re-pointed and every rule below applies unchanged. What a
 * workshop role may *contain* is bounded — see
 * {@see PermissionService::grantableFor()}.
 */
class RoleService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionService $permissions,
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $context,
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

        // A role made inside a workshop is that workshop's; one made with no
        // workshop in context is the platform's.
        $permissionIds = $this->assertGrantable(
            $this->assertPermissionsExist($data['permission_ids'] ?? []),
            workshopRole: $this->context->hasTenant(),
        );

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
                    $this->assertGrantable(
                        $this->assertPermissionsExist($data['permission_ids']),
                        workshopRole: ! $role->isPlatformRole(),
                    )
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

        $role = $this->roles->syncPermissions($role, $this->assertGrantable(
            $this->assertPermissionsExist($permissionIds),
            workshopRole: ! $role->isPlatformRole(),
        ));

        $this->authorization->flushRoleCache($role);

        return $role;
    }

    private function assertMutable(Role $role, string $operation): void
    {
        if ($role->isSystemRole()) {
            throw new SystemRoleImmutableException($role->name, $operation);
        }

        // Belt and braces. A workshop cannot even *see* a platform role any
        // more — the repository's scope answers 404 before this is reached — so
        // this is the service layer refusing on its own terms rather than
        // trusting the layer below to have filtered. A platform role is shared,
        // so a change would land on every workshop at once.
        if ($role->isPlatformRole() && $this->context->hasTenant()) {
            throw new PlatformRoleImmutableException($role->name, $operation);
        }
    }

    /**
     * Refuse a grant the role may not carry.
     *
     * Decided by the role being written, not by who is writing it: the platform
     * administrator editing a workshop's role is bound by the same rules as the
     * workshop's owner, because the role is the workshop's either way.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function assertGrantable(array $ids, bool $workshopRole): array
    {
        if ($ids === [] || ! $workshopRole) {
            return $ids;
        }

        $allowed = $this->permissions->grantableFor(auth()->user(), workshopRole: true)->modelKeys();
        $refused = array_diff($ids, $allowed);

        if ($refused === []) {
            return $ids;
        }

        $labels = $this->permissions->resolve(array_values($refused))['found']
            ->map(fn ($permission) => $permission->action.':'.$permission->resource)
            ->all();

        throw new RoleGrantNotAllowedException($labels);
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
