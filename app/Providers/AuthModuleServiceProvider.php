<?php

namespace App\Providers;

use App\Repositories\Contracts\PermissionRepositoryInterface;
use App\Repositories\Contracts\RefreshTokenRepositoryInterface;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\EloquentPermissionRepository;
use App\Repositories\Eloquent\EloquentRefreshTokenRepository;
use App\Repositories\Eloquent\EloquentRoleRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Wires the Authentication & User Management module: repository bindings,
 * the password policy, and the rate limiters the API routes reference.
 */
class AuthModuleServiceProvider extends ServiceProvider
{
    /**
     * Contract => implementation. Swapping the persistence layer (a read
     * replica, a cached decorator, an in-memory fake in tests) is a one-line
     * change here and nothing above the repository layer notices.
     *
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        UserRepositoryInterface::class => EloquentUserRepository::class,
        RoleRepositoryInterface::class => EloquentRoleRepository::class,
        PermissionRepositoryInterface::class => EloquentPermissionRepository::class,
        RefreshTokenRepositoryInterface::class => EloquentRefreshTokenRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    public function boot(): void
    {
        $this->definePasswordPolicy();
        $this->defineRateLimiters();
    }

    /**
     * One password policy, applied everywhere `Password::defaults()` is used.
     */
    private function definePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)
                ->max(255)
                ->mixedCase()
                ->numbers()
                ->symbols();

            // uncompromised() calls the Have I Been Pwned range API. Enabled
            // in production only so tests and local work stay offline.
            return config('app.env') === 'production'
                ? $rule->uncompromised()
                : $rule;
        });
    }

    /**
     * Rate limiters referenced by `throttle:*` in routes/api.php.
     *
     * These sit in front of the per-account lockout in LoginThrottleService:
     * this layer stops the flood, that layer protects the individual account.
     */
    private function defineRateLimiters(): void
    {
        // Config is read inside the closure, not captured at boot, so the
        // limits stay overridable at runtime (and in tests).
        RateLimiter::for('auth-login', function (Request $request) {
            $login = (array) config('rbac.login.rate_limit');

            return [
                // Per credential-pair: stops password spraying one account.
                Limit::perMinutes(
                    (int) ($login['decay_minutes'] ?? 1),
                    (int) ($login['attempts'] ?? 10)
                )->by(sha1(strtolower((string) $request->input('email')).'|'.$request->ip())),

                // Per IP: stops one host spraying many accounts.
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));

        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? (string) $request->ip()));
    }
}
