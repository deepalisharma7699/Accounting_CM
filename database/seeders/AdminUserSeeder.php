<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds the initial administrator.
 *
 * Credentials come from the environment. Outside local/testing a password
 * must be supplied explicitly — seeding a known default into a production
 * database is how installations get owned.
 */
class AdminUserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = strtolower((string) env('ADMIN_EMAIL', 'admin@example.com'));
        $adminRole = Role::where('slug', Role::slugFor((string) config('rbac.system_roles.admin', 'ADMIN')))->first();

        if ($adminRole === null) {
            throw new RuntimeException('The ADMIN role is missing. Run RoleSeeder first.');
        }

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing !== null) {
            // Re-running the seeder must not reset a live admin's password.
            $existing->forceFill([
                'custom_role_id' => $adminRole->id,
                'status' => UserStatus::Active,
                'deleted_at' => null,
            ])->save();

            $this->command?->info("Admin user [{$email}] already exists — role and status re-asserted.");

            return;
        }

        $password = (string) env('ADMIN_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'ADMIN_PASSWORD must be set when seeding the administrator in production.'
                );
            }

            $password = Str::password(16);
            $generated = true;
        }

        User::create([
            'name' => (string) env('ADMIN_NAME', 'System Administrator'),
            'email' => $email,
            'password' => $password, // hashed by the model's `hashed` cast
            'status' => UserStatus::Active,
            'custom_role_id' => $adminRole->id,
        ])->forceFill(['email_verified_at' => now()])->save();

        $this->command?->info("Admin user created: {$email}");

        if ($generated) {
            $this->command?->warn("Generated admin password: {$password}");
            $this->command?->warn('Store it now — it is not recoverable, and change it after first sign-in.');
        }
    }
}
