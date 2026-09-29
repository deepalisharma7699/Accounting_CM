<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Rbac\AuthorizationService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The platform's own roles — and there is exactly one.
 *
 * A role belongs to a scope. ADMIN is the platform's: `tenant_id` NULL, the
 * wildcard grant, the account that provisions and suspends workshops. It is
 * what the platform administrator's own Users and Roles cards run on, and no
 * workshop ever sees it.
 *
 * Everything a *workshop* is given — OWNER, MANAGER, ACCOUNTANT, DATA_ENTRY —
 * is **not** seeded here, because it is not the platform's. Those are stamped
 * out per workshop when it is provisioned, from the blueprints in
 * {@see \App\Services\Rbac\RoleDefaults}, so each workshop owns its own copy and
 * can retune it without the change landing on anybody else. Seeding them here
 * as shared platform rows is precisely the bug this arrangement replaced: the
 * platform's role list *was* every workshop's role list, so a role deleted from
 * one disappeared from all of them.
 */
class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedAdminRole();
    }

    /**
     * ADMIN — the seeded system role. It holds the single `*` / `*` grant, so
     * it satisfies every permission check without enumerating them, and it is
     * flagged is_system_role so the API refuses to edit or delete it.
     */
    private function seedAdminRole(): void
    {
        $name = (string) config('rbac.system_roles.admin', 'ADMIN');
        $wildcard = (string) config('rbac.wildcard', '*');

        $role = Role::updateOrCreate(
            ['tenant_id' => null, 'slug' => Role::slugFor($name)],
            [
                'name' => $name,
                'description' => 'Full platform access. Cannot be modified or deleted.',
                'is_system_role' => true,
            ]
        );

        $fullAccess = Permission::firstOrCreate(
            ['action' => $wildcard, 'resource' => $wildcard],
            ['description' => 'Full access to every action on every resource.']
        );

        // syncWithoutDetaching, not sync: never strip grants an operator may
        // have deliberately attached to the admin role.
        $role->permissions()->syncWithoutDetaching([$fullAccess->id]);

        // Effective permissions are cached per role for an hour. Seeding writes
        // to the pivot directly rather than through RoleService, so without this
        // an operator re-seeding to pick up a new module's grants would find
        // them inert until the cache expired — which presents as a baffling
        // "the permission is in the database but I still get a 403".
        app(AuthorizationService::class)->flushRoleCache($role);
    }
}
