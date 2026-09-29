<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Gives a workshop its opening set of roles.
 *
 * The counterpart of {@see \App\Services\Accounting\ChartOfAccountProvisioner}
 * and {@see \App\Services\Inventory\CatalogueProvisioner}, and it exists for the
 * same reason they do: a workshop with no roles cannot be given a second user,
 * because there is nothing to give them. Called once when a tenant is
 * provisioned, and available afterwards as a backfill for workshops that predate
 * a newly added default.
 *
 * The list itself lives in {@see RoleDefaults}, shared with the migration that
 * backfilled the workshops that already existed.
 *
 * ## Idempotent, and create-only
 *
 * A role the workshop already has — under that slug, including one it has since
 * deleted — is left completely alone. A workshop that retuned its ACCOUNTANT, or
 * removed the MANAGER it never uses, must not have that undone by a later
 * backfill. Both columns carrying the slug are uniquely indexed per scope, so
 * the trashed check is also what stops a duplicate-key error on insert.
 *
 * ## Not system roles
 *
 * A system role is refused edit and delete by the API, and these are meant to be
 * retuned: the whole point of a role belonging to one workshop is that the
 * workshop — or the platform administrator acting inside it — can change it
 * without the change landing on everybody. The OWNER role is protected in
 * practice anyway, by the rule that a role somebody holds cannot be deleted.
 *
 * Deliberately writes through the models rather than through {@see RoleService}:
 * provisioning runs above the permission checks, exactly as the seeders do, and
 * there is no acting user whose grants a workshop's opening roles should be
 * measured against.
 */
class RoleProvisioner
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * Create any default role this workshop does not already have.
     *
     * @return array<int, Role> the roles created by this call, keyed by slug
     */
    public function seedFor(Tenant|int $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        $existing = Role::withTrashed()->where('tenant_id', $tenantId)->pluck('slug')->all();

        $created = [];

        DB::transaction(function () use ($tenantId, $existing, &$created) {
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

                $role->permissions()->sync($this->permissionIds($blueprint['grants']));

                $created[$slug] = $role;
            }
        });

        // Effective permissions are cached per role. These are new rows, so
        // nothing stale can exist yet — but a backfill re-creating a role that
        // reused an id would find the previous occupant's grants still cached,
        // and the flush costs one key.
        foreach ($created as $role) {
            $this->authorization->flushRoleCache($role);
        }

        return $created;
    }

    /**
     * The workshop's own role under a given slug, or null.
     *
     * Used at provisioning to find the OWNER this workshop was just given —
     * never the platform's, which is the bug this whole change exists to fix.
     */
    public function findFor(Tenant|int $tenant, string $slug): ?Role
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        return Role::where('tenant_id', $tenantId)->where('slug', $slug)->first();
    }

    /**
     * @param  array<int, array{0: \App\Enums\PermissionAction, 1: \App\Enums\PermissionResource}>  $grants
     * @return array<int, int>
     */
    private function permissionIds(array $grants): array
    {
        return collect($grants)
            ->map(fn (array $pair) => Permission::where('action', $pair[0]->value)
                ->where('resource', $pair[1]->value)
                ->value('id'))
            ->filter()
            ->values()
            ->all();
    }
}
