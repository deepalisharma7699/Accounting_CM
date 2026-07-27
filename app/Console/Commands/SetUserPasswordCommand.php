<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Set a user's password and/or display name from the CLI.
 *
 * AdminUserSeeder deliberately never overwrites an existing user's password or
 * name, so changing ADMIN_PASSWORD / ADMIN_NAME in .env and re-seeding does
 * nothing. This is the supported way to change them afterwards.
 */
class SetUserPasswordCommand extends Command
{
    protected $signature = 'user:password
                            {email : The email address of the account}
                            {--password= : The new password (prompted for when neither this nor --name is given)}
                            {--name= : Also update the display name shown in the sidebar and greeting}
                            {--unlock : Also clear the failed-attempt counter and any lockout}
                            {--force : Accept a password that does not meet the application policy}
                            {--keep-sessions : Do not revoke the user\'s existing refresh tokens}';

    protected $description = "Set a user's password and/or display name, optionally clearing an account lockout";

    public function handle(TokenService $tokens): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::withTrashed()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No account found for [{$email}].");

            return self::FAILURE;
        }

        $name = $this->option('name');
        $password = $this->option('password');

        // Only fall back to prompting when the caller gave nothing to change.
        // Otherwise `--name` on its own would demand a pointless password reset.
        if ($password === null && $name === null) {
            $password = $this->secret('New password');
        }

        if ($name !== null && ! $this->applyName($user, $name)) {
            return self::FAILURE;
        }

        if ($password !== null && ! $this->applyPassword($user, (string) $password)) {
            return self::FAILURE;
        }

        if ($this->option('unlock')) {
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
        }

        $user->save();

        // A password change should end existing sessions: whoever knew the old
        // password must not keep a live refresh token. Renaming is harmless, so
        // it does not sign anyone out.
        $revoked = 0;

        if ($password !== null && ! $this->option('keep-sessions')) {
            $revoked = $tokens->revokeAllForUser($user, 'password_changed');
        }

        $this->components->info("Updated {$user->email}.");

        $this->components->twoColumnDetail('Name', $user->name);
        $this->components->twoColumnDetail('Password', $password !== null ? 'changed' : 'unchanged');
        $this->components->twoColumnDetail('Status', $user->status->value);
        $this->components->twoColumnDetail('Role', $user->customRole?->name ?? 'none');
        $this->components->twoColumnDetail('Lockout', $user->isLocked() ? 'still locked' : 'clear');
        $this->components->twoColumnDetail('Sessions revoked', (string) $revoked);

        return self::SUCCESS;
    }

    private function applyName(User $user, string $name): bool
    {
        $name = trim($name);

        // Mirrors the rules used by the user-management endpoints so the CLI
        // cannot create a record the API would reject.
        $validator = Validator::make(
            ['name' => $name],
            ['name' => ['required', 'string', 'min:2', 'max:120']]
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('name'));

            return false;
        }

        $user->name = $name;

        return true;
    }

    private function applyPassword(User $user, string $password): bool
    {
        if ($password === '') {
            $this->components->error('The password may not be empty.');

            return false;
        }

        if (! $this->passwordMeetsPolicy($password)) {
            // The API enforces Password::defaults() on every password change, so
            // a weaker one set here can log in but can never be changed through
            // the API afterwards. Worth being explicit about.
            $this->components->warn('This password does not meet the application policy (12+ chars, mixed case, number, symbol).');
            $this->components->warn('It will work for signing in, but PATCH /api/v1/users/{id} will reject it.');

            if (! $this->option('force')) {
                $this->components->error('Refusing to set it. Re-run with --force if that is what you want.');

                return false;
            }
        }

        // The `hashed` cast applies bcrypt at the configured cost.
        $user->password = $password;

        return true;
    }

    private function passwordMeetsPolicy(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]]
        )->passes();
    }
}
