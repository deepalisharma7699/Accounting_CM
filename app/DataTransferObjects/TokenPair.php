<?php

namespace App\DataTransferObjects;

/**
 * The result of a successful authentication.
 *
 * Only the access token is ever serialised into the response body — the
 * refresh token leaves the application exclusively as an HTTP-only cookie.
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public int $accessTokenExpiresIn,
        public string $refreshToken,
        public int $refreshTokenExpiresIn,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenExpiresIn,
        ];
    }
}
