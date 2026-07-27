<?php

namespace App\Repositories\Contracts;

use App\Models\RefreshToken;

interface RefreshTokenRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): RefreshToken;

    public function findByJti(string $jti): ?RefreshToken;

    public function findByTokenHash(string $tokenHash): ?RefreshToken;

    public function revoke(RefreshToken $token, string $reason, ?string $replacedByJti = null): RefreshToken;

    /**
     * Revoke every still-valid token in a login family (reuse detection).
     */
    public function revokeFamily(string $familyId, string $reason): int;

    /**
     * Revoke every still-valid token for a user ("log out everywhere").
     */
    public function revokeAllForUser(int $userId, string $reason): int;

    /**
     * Housekeeping: drop rows that can no longer be presented.
     */
    public function purgeExpired(): int;
}
