<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => UserStatus::Active,
            'custom_role_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withStatus(UserStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function inactive(): static
    {
        return $this->withStatus(UserStatus::Inactive);
    }

    public function suspended(): static
    {
        return $this->withStatus(UserStatus::Suspended);
    }

    /**
     * Attach an existing role, or create one on the fly.
     */
    public function withRole(Role|int|null $role): static
    {
        return $this->state(fn (array $attributes) => [
            'custom_role_id' => $role instanceof Role ? $role->getKey() : $role,
        ]);
    }

    /**
     * Lock the account out as if it had failed too many sign-in attempts.
     */
    public function locked(int $minutes = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'failed_login_attempts' => (int) config('rbac.login.max_attempts', 5),
            'locked_until' => now()->addMinutes($minutes),
        ]);
    }
}
