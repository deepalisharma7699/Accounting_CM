<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_system_role
 */
#[Fillable(['name', 'slug', 'description', 'is_system_role'])]
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

    public function isSystemRole(): bool
    {
        return (bool) $this->is_system_role;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
