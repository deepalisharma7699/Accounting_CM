<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An (action, resource) pair, e.g. READ / USERS.
 *
 * @property int $id
 * @property string $action
 * @property string $resource
 * @property string|null $description
 */
#[Fillable(['action', 'resource', 'description'])]
class Permission extends Model
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')->withTimestamps();
    }

    /**
     * Canonical "ACTION:RESOURCE" form, used in token claims and log lines.
     */
    public function key(): string
    {
        return $this->action.':'.$this->resource;
    }

    /**
     * Does this grant satisfy the requested (action, resource) pair?
     * Either side may be the wildcard "*".
     */
    public function grants(string $action, string $resource): bool
    {
        $wildcard = (string) config('rbac.wildcard', '*');

        $actionMatches = $this->action === $wildcard
            || strcasecmp($this->action, $action) === 0;

        $resourceMatches = $this->resource === $wildcard
            || strcasecmp($this->resource, $resource) === 0;

        return $actionMatches && $resourceMatches;
    }
}
