<?php

namespace App\Services\Auth;

use App\DataTransferObjects\TokenPair;
use App\Enums\TokenType;
use App\Exceptions\Auth\InvalidTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use App\Repositories\Contracts\RefreshTokenRepositoryInterface;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Cookie;
use UnexpectedValueException;

/**
 * Everything token-shaped lives here: minting, verifying, rotating and
 * revoking. No other class touches the JWT library or the refresh token
 * table directly.
 */
class TokenService
{
    public function __construct(
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
        private readonly CookieJar $cookies,
    ) {}

    /* ---------------------------------------------------------------------
     | Issuing
     |-------------------------------------------------------------------- */

    /**
     * Mint a fresh access + refresh pair for a brand new session (login).
     */
    public function issueTokenPair(User $user, ?Request $request = null, ?string $familyId = null): TokenPair
    {
        $accessTtl = $this->ttl('access');
        $refreshTtl = $this->ttl('refresh');

        return new TokenPair(
            accessToken: $this->issueAccessToken($user),
            accessTokenExpiresIn: $accessTtl,
            refreshToken: $this->issueRefreshToken($user, $request, $familyId),
            refreshTokenExpiresIn: $refreshTtl,
        );
    }

    public function issueAccessToken(User $user): string
    {
        $now = time();

        return $this->encode([
            'iss' => $this->config('issuer'),
            'aud' => $this->config('audience'),
            'sub' => (string) $user->getKey(),
            'jti' => (string) Str::uuid(),
            'typ' => TokenType::Access->value,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl('access'),
            // Non-authoritative context for logging/UI. Authorization always
            // re-reads the user + role from the database, so a stale role
            // claim can never widen access.
            'email' => $user->email,
            'role' => $user->customRole?->slug,
        ]);
    }

    /**
     * Mint a refresh token and persist its hash so it can be revoked.
     *
     * @param  string|null  $familyId  Continues an existing login family when rotating.
     */
    public function issueRefreshToken(User $user, ?Request $request = null, ?string $familyId = null): string
    {
        return $this->mintRefreshToken($user, $request, $familyId)['token'];
    }

    /**
     * @return array{token: string, jti: string, family_id: string}
     */
    private function mintRefreshToken(User $user, ?Request $request, ?string $familyId): array
    {
        $now = time();
        $ttl = $this->ttl('refresh');
        $jti = (string) Str::uuid();
        $family = $familyId ?? (string) Str::uuid();

        $token = $this->encode([
            'iss' => $this->config('issuer'),
            'aud' => $this->config('audience'),
            'sub' => (string) $user->getKey(),
            'jti' => $jti,
            'fam' => $family,
            'typ' => TokenType::Refresh->value,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ]);

        $this->refreshTokens->create([
            'user_id' => $user->getKey(),
            'jti' => $jti,
            'token_hash' => $this->hash($token),
            'family_id' => $family,
            'expires_at' => now()->addSeconds($ttl),
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 500, ''),
        ]);

        return ['token' => $token, 'jti' => $jti, 'family_id' => $family];
    }

    /* ---------------------------------------------------------------------
     | Verifying
     |-------------------------------------------------------------------- */

    /**
     * Verify an access token and return its claims.
     *
     * @throws InvalidTokenException
     */
    public function verifyAccessToken(string $token): stdClass
    {
        return $this->decode($token, TokenType::Access);
    }

    /**
     * Verify a refresh token against both its signature and the server-side
     * revocation list, returning the persisted record.
     *
     * @throws InvalidTokenException
     */
    public function verifyRefreshToken(string $token): RefreshToken
    {
        $claims = $this->decode($token, TokenType::Refresh);

        $record = $this->refreshTokens->findByJti((string) ($claims->jti ?? ''));

        if ($record === null) {
            // Signature was valid but we have no record: the token was purged
            // or belongs to another deployment. Treat as invalid, not expired.
            throw InvalidTokenException::revoked();
        }

        if (! hash_equals($record->token_hash, $this->hash($token))) {
            throw new InvalidTokenException;
        }

        if ($record->isRevoked()) {
            // A token rotated moments ago is almost certainly a client whose
            // refresh response never arrived (tab closed mid-flight, dropped
            // connection), not an attacker replaying a stolen cookie. Forgive
            // it briefly rather than destroying a live session over a transient
            // network fault. See config('jwt.rotation.grace_seconds').
            if ($this->withinRotationGrace($record)) {
                return $record;
            }

            // Otherwise: replaying a spent token means the cookie leaked. Kill
            // every descendant of that login so the attacker's copy dies too.
            if ((bool) config('jwt.rotation.revoke_family_on_reuse', true)) {
                $this->refreshTokens->revokeFamily($record->family_id, 'reuse_detected');
            }

            throw InvalidTokenException::reused();
        }

        if ($record->isExpired()) {
            throw InvalidTokenException::expired();
        }

        return $record;
    }

    /* ---------------------------------------------------------------------
     | Rotating / revoking
     |-------------------------------------------------------------------- */

    /**
     * Exchange a valid refresh token for a new pair, revoking the old one.
     */
    public function rotate(RefreshToken $current, User $user, ?Request $request = null): TokenPair
    {
        $rotationEnabled = (bool) config('jwt.rotation.enabled', true);

        if (! $rotationEnabled) {
            return new TokenPair(
                accessToken: $this->issueAccessToken($user),
                accessTokenExpiresIn: $this->ttl('access'),
                refreshToken: '',
                refreshTokenExpiresIn: 0,
            );
        }

        $minted = $this->mintRefreshToken($user, $request, $current->family_id);

        $this->refreshTokens->revoke($current, 'rotated', $minted['jti']);

        return new TokenPair(
            accessToken: $this->issueAccessToken($user),
            accessTokenExpiresIn: $this->ttl('access'),
            refreshToken: $minted['token'],
            refreshTokenExpiresIn: $this->ttl('refresh'),
        );
    }

    public function revokeRefreshToken(RefreshToken $token, string $reason = 'logout'): void
    {
        $this->refreshTokens->revoke($token, $reason);
    }

    public function revokeAllForUser(User $user, string $reason = 'logout_all'): int
    {
        return $this->refreshTokens->revokeAllForUser((int) $user->getKey(), $reason);
    }

    /* ---------------------------------------------------------------------
     | Transport
     |-------------------------------------------------------------------- */

    /**
     * Read the bearer token from the Authorization header.
     */
    public function accessTokenFromRequest(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function refreshTokenFromRequest(Request $request): ?string
    {
        $cookieName = (string) config('jwt.cookie.name');

        $token = $request->cookie($cookieName);

        // Native mobile clients cannot hold cookies; allow an explicit body
        // field as a documented fallback. Browsers must keep using the cookie.
        return is_string($token) && $token !== ''
            ? $token
            : ($request->input('refresh_token') ?: null);
    }

    /**
     * HTTP-only, SameSite, Secure cookie carrying the refresh token.
     */
    public function refreshCookie(string $token, int $ttlSeconds): Cookie
    {
        return $this->cookies->make(
            name: (string) config('jwt.cookie.name'),
            value: $token,
            minutes: (int) ceil($ttlSeconds / 60),
            path: (string) config('jwt.cookie.path'),
            domain: config('jwt.cookie.domain'),
            secure: (bool) config('jwt.cookie.secure'),
            httpOnly: (bool) config('jwt.cookie.http_only', true),
            raw: false,
            sameSite: (string) config('jwt.cookie.same_site'),
        );
    }

    /**
     * Expired twin of {@see refreshCookie()}; attributes must match for the
     * browser to actually drop the cookie.
     */
    public function forgetRefreshCookie(): Cookie
    {
        return $this->cookies->make(
            name: (string) config('jwt.cookie.name'),
            value: '',
            minutes: -2628000,
            path: (string) config('jwt.cookie.path'),
            domain: config('jwt.cookie.domain'),
            secure: (bool) config('jwt.cookie.secure'),
            httpOnly: (bool) config('jwt.cookie.http_only', true),
            raw: false,
            sameSite: (string) config('jwt.cookie.same_site'),
        );
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $claims
     */
    private function encode(array $claims): string
    {
        return JWT::encode($claims, $this->secret(), $this->algo());
    }

    /**
     * @throws InvalidTokenException
     */
    private function decode(string $token, TokenType $expectedType): stdClass
    {
        JWT::$leeway = (int) config('jwt.leeway', 0);

        try {
            // The Key object pins the algorithm, so a token whose header says
            // "none" (or any other alg) is rejected outright.
            $claims = JWT::decode($token, new Key($this->secret(), $this->algo()));
        } catch (ExpiredException $e) {
            throw InvalidTokenException::expired();
        } catch (SignatureInvalidException|BeforeValidException|UnexpectedValueException|\DomainException $e) {
            throw new InvalidTokenException(previous: $e);
        }

        if (($claims->typ ?? null) !== $expectedType->value) {
            throw InvalidTokenException::wrongType();
        }

        if (($claims->iss ?? null) !== $this->config('issuer')
            || ($claims->aud ?? null) !== $this->config('audience')) {
            throw new InvalidTokenException;
        }

        if (! isset($claims->sub, $claims->jti)) {
            throw new InvalidTokenException;
        }

        return $claims;
    }

    /**
     * Was this token rotated within the grace window?
     *
     * Deliberately narrow: only the "rotated" reason qualifies, so a token
     * killed by logout, a password change, a status change or an earlier reuse
     * is never forgiven.
     */
    private function withinRotationGrace(RefreshToken $record): bool
    {
        $grace = (int) config('jwt.rotation.grace_seconds', 0);

        if ($grace <= 0 || $record->revoked_reason !== 'rotated' || $record->revoked_at === null) {
            return false;
        }

        return $record->revoked_at->gt(now()->subSeconds($grace));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');

        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured. Set JWT_SECRET in your environment.');
        }

        // APP_KEY arrives base64 encoded; decode so the raw 32 bytes are used.
        if (str_starts_with($secret, 'base64:')) {
            $secret = (string) base64_decode(substr($secret, 7), true);
        }

        return $secret;
    }

    private function algo(): string
    {
        return (string) config('jwt.algo', 'HS256');
    }

    private function ttl(string $type): int
    {
        return (int) config("jwt.ttl.{$type}");
    }

    private function config(string $key): string
    {
        return (string) config("jwt.{$key}");
    }
}
