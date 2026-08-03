<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use App\Models\Concerns\Auditable;
use App\Services\Rbac\AuthorizationService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A user belongs to exactly one tenant, or to none at all — `tenant_id` is
 * NULL for platform super-admins, who manage the tenants themselves and own
 * no accounting data.
 *
 * Unlike every other tenant-aware table, User does *not* use the
 * BelongsToTenant trait. Authentication has to find a user before it knows
 * which tenant they belong to, so a global scope here would either have to be
 * bypassed on every auth call or would deadlock the login path. Users are
 * scoped explicitly in EloquentUserRepository instead, and TenantIsolationTest
 * covers that scoping directly.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password Bcrypt/Argon2 hash — never the plain value.
 * @property UserStatus $status
 * @property int|null $custom_role_id
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property Role|null $customRole
 * @property Tenant|null $tenant
 */
#[Fillable(['tenant_id', 'name', 'email', 'password', 'status', 'custom_role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, SoftDeletes;

    /**
     * Four fields, and the omissions are the interesting part.
     *
     * `password` is not here, and cannot be added by accident: the trail is
     * built from this list rather than from `$fillable` or from whatever
     * happened to be dirty, precisely so that a hash never reaches a table an
     * owner is allowed to read. The lockout counters and `remember_token` are
     * absent for the same reason, plus a second one — they move on their own,
     * without anybody deciding anything, and a log of things nobody did is how
     * a log stops being read.
     *
     * `custom_role_id` is here because it is the one that matters. Changing
     * somebody's role changes what they can reach, and the change leaves no
     * other mark anywhere in the system.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['name', 'email', 'status', 'custom_role_id'];
    }

    /**
     * The email as well as the name, because two people called Ramesh is
     * ordinary and the trail has to say which one.
     */
    public function auditLabel(): string
    {
        return trim($this->name.' · '.$this->email, ' ·');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'custom_role_id');
    }

    /**
     * @return HasMany<RefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * A platform super-admin: belongs to no tenant, manages the tenants.
     */
    public function isPlatformUser(): bool
    {
        return $this->tenant_id === null;
    }

    public function belongsToTenant(Tenant|int|null $tenant): bool
    {
        if ($this->tenant_id === null || $tenant === null) {
            return false;
        }

        return $this->tenant_id === ($tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant);
    }

    /**
     * Convenience wrapper so callers can ask the user directly. The actual
     * resolution (wildcards, caching) lives in AuthorizationService.
     */
    public function hasPermissionTo(string $action, string $resource): bool
    {
        return app(AuthorizationService::class)
            ->userHasPermission($this, $action, $resource);
    }
}
