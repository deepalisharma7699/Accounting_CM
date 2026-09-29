<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\AuthorizationService;
use App\Services\Rbac\RoleDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| Finishes the move to per-workshop roles.
|
| The automatic migration (2026_09_19_100002) gave every existing workshop its
| own OWNER, MANAGER, ACCOUNTANT and DATA_ENTRY. It deliberately stopped there.
| This one does the other half:
|
|   1. Moves each workshop user off the shared platform role they still hold and
|      onto their own workshop's role of the same slug.
|   2. Retires the platform OWNER and DATA_ENTRY rows once nobody holds them.
|
| ADMIN is never touched, and neither is any platform user: the platform
| administrator's account is what this whole arrangement exists to keep separate.
|
| ## Why it is not in the migration path
|
| It rewrites a live user's authority. CLAUDE.md §4.5 keeps that kind of one-time
| data operation out of `database/migrations` for the case nobody plans for —
| restore a dump taken before it ran, then migrate, and it runs again. §10.3 is
| the second reason and the sharper one: no migration may change the role of the
| two local development accounts, and `owner@choudharymotors.test` is exactly the
| record this moves.
|
| ## Running it
|
| Take a dump first. There is no `down()`: the platform rows are soft-deleted
| rather than dropped, but which user held which role beforehand is not recorded
| anywhere this could read back.
|
|   ALLOW_ROLE_REPOINT=yes php artisan migrate --path=database/manual
|
| The variable is read from the process environment rather than through `env()`,
| so a cached config cannot make a deliberate run silently refuse.
|
| It is safe to run twice: a user already on a workshop role is not a candidate,
| and a platform role already retired is not found.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (strtolower((string) getenv('ALLOW_ROLE_REPOINT')) !== 'yes') {
            // Refuses *before* writing anything, rather than by unwinding a
            // transaction afterwards.
            throw new RuntimeException(
                'Refusing to re-point user roles. Re-run with ALLOW_ROLE_REPOINT=yes once a database dump has been taken.'
            );
        }

        $adminSlug = Role::slugFor((string) config('rbac.system_roles.admin', 'ADMIN'));

        // The shared rows a workshop user may still be sitting on: platform,
        // not the administrator, and a slug a workshop now has of its own.
        $platformRoles = Role::withTrashed()
            ->whereNull('tenant_id')
            ->where('slug', '!=', $adminSlug)
            ->whereIn('slug', RoleDefaults::slugs())
            ->get()
            ->keyBy('id');

        if ($platformRoles->isEmpty()) {
            return;
        }

        $moved = 0;
        $stranded = [];

        DB::transaction(function () use ($platformRoles, &$moved, &$stranded) {
            $users = User::withTrashed()
                ->whereNotNull('tenant_id')
                ->whereIn('custom_role_id', $platformRoles->keys())
                ->get();

            foreach ($users as $user) {
                $slug = $platformRoles[$user->custom_role_id]->slug;

                $replacement = Role::where('tenant_id', $user->tenant_id)
                    ->where('slug', $slug)
                    ->first();

                if ($replacement === null) {
                    // Left exactly as it is. A user whose workshop has no role
                    // of that slug — because the workshop deleted it — keeps
                    // working on the platform row rather than being silently
                    // stripped of every grant they hold.
                    $stranded[] = "user {$user->id} (workshop {$user->tenant_id}, {$slug})";

                    continue;
                }

                $user->forceFill(['custom_role_id' => $replacement->getKey()])->save();
                $moved++;
            }
        });

        // Only the rows nobody is left on. A platform role still held by
        // somebody is a grant somebody is using.
        $retired = 0;

        foreach ($platformRoles as $role) {
            if ($role->trashed() || User::withTrashed()->where('custom_role_id', $role->getKey())->exists()) {
                continue;
            }

            $role->delete();
            app(AuthorizationService::class)->flushRoleCache($role);
            $retired++;
        }

        // Logged rather than printed: a migration has no console of its own,
        // and what this did to whose authority is worth having in the trail
        // long after the terminal it ran in has gone.
        Log::info('rbac.roles_repointed', [
            'users_moved' => $moved,
            'platform_roles_retired' => $retired,
            // Left exactly as they were, and named so somebody can look.
            'left_alone' => $stranded,
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This migration cannot be reversed: which user held which role beforehand is not recorded. Restore the dump taken before it ran.'
        );
    }
};
