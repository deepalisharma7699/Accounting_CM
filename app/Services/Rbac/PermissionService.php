<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Models\User;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PermissionService
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The catalogue narrowed to what `$actor` may put into a role.
     *
     * A role that belongs to a workshop is held to two rules, and this is the one
     * place both are decided (§4.4) — the matrix a person is offered and the
     * check that refuses a hand-crafted request are the same list, so they cannot
     * drift:
     *
     *   1. no platform-level grant and no wildcard — authority across workshops
     *      is not something a workshop's role can carry;
     *   2. nothing the actor does not hold themselves — you cannot delegate
     *      authority you never had.
     *
     * A platform role is not narrowed: it is the platform administrator's to
     * write, and they hold the wildcard.
     *
     * @return Collection<int, Permission>
     */
    public function grantableFor(?User $actor, bool $workshopRole): Collection
    {
        $all = $this->permissions->all();

        if (! $workshopRole) {
            return $all;
        }

        $wildcard = (string) config('rbac.wildcard', '*');
        $platformOnly = array_map('strtoupper', (array) config('rbac.platform_only_resources', []));

        return $all->filter(function (Permission $permission) use ($actor, $wildcard, $platformOnly) {
            if ($permission->action === $wildcard || $permission->resource === $wildcard) {
                return false;
            }

            if (in_array(strtoupper($permission->resource), $platformOnly, true)) {
                return false;
            }

            return $actor !== null
                && $this->authorization->userHasPermission($actor, $permission->action, $permission->resource);
        })->values();
    }

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
     * @param  Collection<int, Permission>|null  $permissions  defaults to the whole catalogue
     * @return array<string, array<int, array{id: int, action: string, description: string|null}>>
     */
    public function groupedByResource(?Collection $permissions = null): array
    {
        return ($permissions ?? $this->permissions->all())
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
