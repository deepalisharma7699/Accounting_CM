<?php

namespace Database\Seeders;

use App\Enums\PermissionAction;
use App\Enums\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedAdminRole();
        $this->seedExampleCustomRole();
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
            ['slug' => Role::slugFor($name)],
            [
                'name' => $name,
                'description' => 'Full system access. Cannot be modified or deleted.',
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
    }

    /**
     * A worked example of a custom (non-system) role: it can see and create
     * users and read the role catalogue, but cannot delete anything.
     */
    private function seedExampleCustomRole(): void
    {
        $role = Role::updateOrCreate(
            ['slug' => Role::slugFor('User Manager')],
            [
                'name' => 'User Manager',
                'description' => 'Can view, create and update users; read-only on roles.',
                'is_system_role' => false,
            ]
        );

        $grants = [
            [PermissionAction::Read, PermissionResource::Users],
            [PermissionAction::Write, PermissionResource::Users],
            [PermissionAction::Update, PermissionResource::Users],
            [PermissionAction::Read, PermissionResource::Roles],
        ];

        $ids = collect($grants)
            ->map(fn (array $pair) => Permission::where('action', $pair[0]->value)
                ->where('resource', $pair[1]->value)
                ->value('id'))
            ->filter()
            ->all();

        $role->permissions()->sync($ids);
    }
}
