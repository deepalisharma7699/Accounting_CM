<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\AccountLockedException;
use App\Models\User;

/**
 * Per-account lockout.
 *
 * This is the second of two throttling layers. The first is the `throttle`
 * middleware, which is keyed on IP + email and stops a single client hammering
 * the endpoint. This one is keyed on the account itself, so a distributed
 * attack spread across many IPs still trips the lock.
 */
class LoginThrottleService
{
    /**
     * @throws AccountLockedException
     */
    public function assertNotLocked(User $user): void
    {
        if (! $user->isLocked()) {
            return;
        }

        throw new AccountLockedException(
            max(1, (int) ceil(now()->diffInSeconds($user->locked_until, absolute: false)))
        );
    }

    /**
     * Record a failed attempt and lock the account once the limit is reached.
     *
     * @return int Attempts remaining before the account locks.
     */
    public function recordFailure(User $user): int
    {
        $maxAttempts = $this->maxAttempts();

        // A lock that has already expired starts the counter from scratch.
        $attempts = $this->currentAttempts($user) + 1;

        $attributes = ['failed_login_attempts' => $attempts];

        if ($attempts >= $maxAttempts) {
            $attributes['locked_until'] = now()->addMinutes($this->lockMinutes());
        }

        $user->forceFill($attributes)->save();

        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Clear the counters after a successful sign-in.
     */
    public function clear(User $user): void
    {
        if ($user->failed_login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('rbac.login.max_attempts', 5));
    }

    private function lockMinutes(): int
    {
        return max(1, (int) config('rbac.login.lock_minutes', 15));
    }

    private function currentAttempts(User $user): int
    {
        // Counters left over from an expired lock are stale — reset them so a
        // user who waited out a lock gets a full allowance again.
        if ($user->locked_until !== null && $user->locked_until->isPast()) {
            return 0;
        }

        return (int) $user->failed_login_attempts;
    }
}
