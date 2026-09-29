<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_system_role
 */
#[Fillable(['tenant_id', 'name', 'slug', 'description', 'is_system_role'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_system_role' => 'boolean',
        ];
    }

    /**
     * The workshop this role belongs to, or null for a platform role.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withTimestamps();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'custom_role_id');
    }

    /**
     * Canonical slug for a role name: "Branch Accountant" -> "BRANCH_ACCOUNTANT".
     */
    public static function slugFor(string $name): string
    {
        return str($name)->slug('_')->upper()->value();
    }

    /**
     * A platform role belongs to the platform's own panel; anything else is one
     * workshop's own. The two scopes are separate lists — neither sees the
     * other — so this is "whose list is it on", not "is it shared".
     */
    public function isPlatformRole(): bool
    {
        return $this->tenant_id === null;
    }

    public function isSystemRole(): bool
    {
        return (bool) $this->is_system_role;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
