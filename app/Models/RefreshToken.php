<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Server-side record of an issued refresh token. The token string itself is
 * never persisted — only its SHA-256 — so this table doubles as the
 * revocation list (a token with `revoked_at` set is blacklisted).
 *
 * @property int $id
 * @property int $user_id
 * @property string $jti
 * @property string $token_hash
 * @property string $family_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property string|null $replaced_by_jti
 */
#[Fillable([
    'user_id',
    'jti',
    'token_hash',
    'family_id',
    'expires_at',
    'revoked_at',
    'revoked_reason',
    'replaced_by_jti',
    'ip_address',
    'user_agent',
])]
class RefreshToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * @param  Builder<RefreshToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
