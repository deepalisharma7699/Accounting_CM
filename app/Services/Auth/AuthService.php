<?php

namespace App\Services\Auth;

use App\DataTransferObjects\TokenPair;
use App\Exceptions\ApiException;
use App\Exceptions\Auth\AccountInactiveException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Tenancy\TenantInactiveException;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\RefreshTokenRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Tenancy\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Use cases for the authentication lifecycle. Controllers do no work beyond
 * validating input and shaping the response; everything below is transport
 * agnostic and independently testable.
 */
class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
        private readonly TokenService $tokens,
        private readonly LoginThrottleService $throttle,
        private readonly TenantService $tenants,
    ) {}

    /**
     * Sign up a new workshop and its owner, then sign them straight in.
     *
     * Registration is tenant provisioning: there is no such thing as a user
     * without a workshop in this product, so the two are created together or
     * not at all. Joining an existing workshop is an invitation, issued by its
     * owner through the user-management API — never self-serve, or anyone
     * could type their way into someone else's books.
     *
     * @param  array{workshop_name: string, name: string, email: string, password: string, gstin?: string|null, state_code?: string|null}  $data
     * @return array{user: User, tenant: Tenant, tokens: TokenPair}
     */
    public function register(array $data, ?Request $request = null): array
    {
        if (! (bool) config('tenancy.allow_public_signup', true)) {
            throw new ApiException(
                message: 'Self-serve sign-up is disabled. Contact sales to have a workspace created.',
                status: 403,
                errorCode: 'SIGNUP_DISABLED',
            );
        }

        ['tenant' => $tenant, 'owner' => $user] = $this->tenants->provisionWithOwner(
            [
                'name' => $data['workshop_name'],
                'gstin' => $data['gstin'] ?? null,
                'state_code' => $data['state_code'] ?? null,
            ],
            [
                'name' => $data['name'],
                'email' => $data['email'],
                // The `hashed` cast applies bcrypt with the configured cost
                // (BCRYPT_ROUNDS=12); the plain value never reaches the DB.
                'password' => $data['password'],
            ],
        );

        Log::info('auth.registered', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'ip' => $request?->ip(),
        ]);

        return [
            'user' => $user,
            'tenant' => $tenant,
            'tokens' => $this->tokens->issueTokenPair($user, $request),
        ];
    }

    /**
     * Verify credentials and start a session.
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, tokens: TokenPair}
     *
     * @throws InvalidCredentialsException|AccountInactiveException
     */
    public function login(array $credentials, ?Request $request = null): array
    {
        $user = $this->users->findByEmail(strtolower(trim($credentials['email'])));

        if ($user === null) {
            // Hash anyway so a missing account and a wrong password take the
            // same amount of time — otherwise login leaks which emails exist.
            Hash::check($credentials['password'], $this->dummyHash());

            throw new InvalidCredentialsException;
        }

        // Locked accounts are rejected before the password is checked, so a
        // lock cannot be probed for password correctness.
        $this->throttle->assertNotLocked($user);

        if (! Hash::check($credentials['password'], $user->password)) {
            $remaining = $this->throttle->recordFailure($user);

            Log::warning('auth.login_failed', [
                'user_id' => $user->id,
                'ip' => $request?->ip(),
                'remaining_attempts' => $remaining,
            ]);

            // Re-assert so the response is "locked" rather than "bad
            // credentials" on the attempt that trips the lock.
            $this->throttle->assertNotLocked($user->refresh());

            throw new InvalidCredentialsException($remaining);
        }

        if (! $user->isActive()) {
            throw new AccountInactiveException($user->status);
        }

        // Checked after the password so a suspended workshop cannot be used to
        // probe whether a given email exists.
        if ($user->tenant !== null && ! $user->tenant->isActive()) {
            throw new TenantInactiveException($user->tenant->status);
        }

        // Rehash transparently if the configured cost has since increased.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
        }

        $this->throttle->clear($user);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request?->ip(),
        ])->save();

        Log::info('auth.login', ['user_id' => $user->id, 'ip' => $request?->ip()]);

        return [
            'user' => $user->load('customRole'),
            'tokens' => $this->tokens->issueTokenPair($user, $request),
        ];
    }

    /**
     * Exchange a refresh token for a new access token (and a rotated refresh
     * token). The old refresh token is revoked in the same transaction.
     *
     * @return array{user: User, tokens: TokenPair}
     *
     * @throws InvalidTokenException|AccountInactiveException
     */
    public function refresh(?string $refreshToken, ?Request $request = null): array
    {
        if ($refreshToken === null || $refreshToken === '') {
            throw InvalidTokenException::missing();
        }

        $record = $this->tokens->verifyRefreshToken($refreshToken);

        // Cross-tenant: a refresh happens before any tenant is established.
        $user = $this->users->findAuthenticatable($record->user_id);

        if ($user === null) {
            $this->refreshTokens->revokeFamily($record->family_id, 'user_missing');

            throw InvalidTokenException::revoked();
        }

        // Status is re-checked on every refresh, so suspending an account
        // kills its sessions within one access-token lifetime at most.
        if (! $user->isActive()) {
            $this->refreshTokens->revokeAllForUser((int) $user->getKey(), 'account_inactive');

            throw new AccountInactiveException($user->status);
        }

        // Same for the workshop: suspending a tenant must not leave its users
        // able to mint fresh access tokens for the next seven days.
        if ($user->tenant !== null && ! $user->tenant->isActive()) {
            $this->refreshTokens->revokeAllForUser((int) $user->getKey(), 'tenant_inactive');

            throw new TenantInactiveException($user->tenant->status);
        }

        $tokens = DB::transaction(fn () => $this->tokens->rotate($record, $user, $request));

        return ['user' => $user, 'tokens' => $tokens];
    }

    /**
     * Revoke the presented refresh token. Never throws: a logout with a
     * missing or already-dead token is still a successful logout.
     */
    public function logout(?string $refreshToken): void
    {
        if ($refreshToken === null || $refreshToken === '') {
            return;
        }

        try {
            $record = $this->tokens->verifyRefreshToken($refreshToken);
            $this->tokens->revokeRefreshToken($record, 'logout');

            Log::info('auth.logout', ['user_id' => $record->user_id]);
        } catch (InvalidTokenException) {
            // Already invalid — the client is in the state it asked for.
        }
    }

    /**
     * Revoke every session for the user ("sign out of all devices").
     */
    public function logoutEverywhere(User $user): int
    {
        $count = $this->tokens->revokeAllForUser($user, 'logout_all');

        Log::info('auth.logout_all', ['user_id' => $user->id, 'sessions' => $count]);

        return $count;
    }

    /**
     * A constant, valid hash used purely to equalise timing.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('password-that-is-never-valid');
    }
}
