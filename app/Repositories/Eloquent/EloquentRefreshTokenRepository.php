<?php

namespace App\Repositories\Eloquent;

use App\Models\RefreshToken;
use App\Repositories\Contracts\RefreshTokenRepositoryInterface;

class EloquentRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function create(array $attributes): RefreshToken
    {
        return RefreshToken::create($attributes);
    }

    public function findByJti(string $jti): ?RefreshToken
    {
        return RefreshToken::where('jti', $jti)->first();
    }

    public function findByTokenHash(string $tokenHash): ?RefreshToken
    {
        return RefreshToken::where('token_hash', $tokenHash)->first();
    }

    public function revoke(RefreshToken $token, string $reason, ?string $replacedByJti = null): RefreshToken
    {
        // Idempotent: re-revoking keeps the original reason/timestamp so the
        // audit trail still shows why the token first died.
        if (! $token->isRevoked()) {
            $token->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'replaced_by_jti' => $replacedByJti,
            ])->save();
        }

        return $token;
    }

    public function revokeFamily(string $familyId, string $reason): int
    {
        return RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function revokeAllForUser(int $userId, string $reason): int
    {
        return RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function purgeExpired(): int
    {
        return RefreshToken::where('expires_at', '<', now()->subDay())->delete();
    }
}
