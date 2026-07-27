<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
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
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password Bcrypt/Argon2 hash — never the plain value.
 * @property UserStatus $status
 * @property int|null $custom_role_id
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property Role|null $customRole
 */
#[Fillable(['name', 'email', 'password', 'status', 'custom_role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

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
     * Convenience wrapper so callers can ask the user directly. The actual
     * resolution (wildcards, caching) lives in AuthorizationService.
     */
    public function hasPermissionTo(string $action, string $resource): bool
    {
        return app(AuthorizationService::class)
            ->userHasPermission($this, $action, $resource);
    }
}
