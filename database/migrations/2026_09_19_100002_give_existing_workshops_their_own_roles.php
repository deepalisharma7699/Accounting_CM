<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Services\Rbac\RoleDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Every workshop that already existed gets its own set of default roles.
|
| Roles used to be platform-wide: one OWNER row, one DATA_ENTRY row, shared by
| every workshop and listed on the platform administrator's own Roles card. They
| are per workshop now — see RoleDefaults and RoleProvisioner, which a newly
| provisioned workshop runs through — so the workshops provisioned before this
| change have to be given theirs.
|
| Purely additive. It creates roles and nothing else: no user is re-pointed, no
| platform role is deleted, no tenant is touched. Moving the existing users onto
| their workshop's new roles, and retiring the platform OWNER/DATA_ENTRY rows
| they currently hold, is a one-time data operation and lives in
| `database/manual/` (CLAUDE.md §4.5), because a migration that rewrote a live
| user's authority would do it again on any restore-then-migrate.
|
| Idempotent: a workshop that already has a role under one of these slugs —
| including one it has since deleted — is left alone, which is also what stops a
| duplicate-key error against the per-scope unique indexes.
|
| On a fresh database there are no tenants yet and this does nothing at all,
| which is the right answer: the permission catalogue is seeded after migrate,
| so roles created here would have come out with no grants on them.
*/
return new class extends Migration
{
    public function up(): void
    {
        $tenantIds = Tenant::withTrashed()->pluck('id');

        if ($tenantIds->isEmpty()) {
            return;
        }

        $permissions = DB::table('permissions')
            ->get(['id', 'action', 'resource'])
            ->mapWithKeys(fn ($row) => ["{$row->action}:{$row->resource}" => $row->id]);

        foreach ($tenantIds as $tenantId) {
            $existing = Role::withTrashed()->where('tenant_id', $tenantId)->pluck('slug')->all();

            foreach (RoleDefaults::all() as $slug => $blueprint) {
                if (in_array($slug, $existing, true)) {
                    continue;
                }

                $role = Role::create([
                    'tenant_id' => $tenantId,
                    'name' => $blueprint['name'],
                    'slug' => $slug,
                    'description' => $blueprint['description'],
                    'is_system_role' => false,
                ]);

                $ids = collect($blueprint['grants'])
                    ->map(fn (array $pair) => $permissions->get($pair[0]->value.':'.$pair[1]->value))
                    ->filter()
                    ->values()
                    ->all();

                $role->permissions()->sync($ids);
            }
        }
    }

    public function down(): void
    {
        // Only the rows this migration could have created, and only while
        // nobody holds them: a role somebody was moved onto is somebody's
        // authority, and dropping it on a rollback would sign them out of their
        // own workshop.
        Role::whereNotNull('tenant_id')
            ->whereIn('slug', RoleDefaults::slugs())
            ->whereDoesntHave('users')
            ->get()
            ->each(function (Role $role) {
                $role->permissions()->detach();
                $role->forceDelete();
            });
    }
};
