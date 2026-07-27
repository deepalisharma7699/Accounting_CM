<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucwords(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Role::slugFor($name),
            'description' => fake()->sentence(),
            'is_system_role' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => ['is_system_role' => true]);
    }

    /**
     * Attach grants after creation, e.g. ->withPermissions([['READ', 'USERS']]).
     *
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    public function withPermissions(array $pairs): static
    {
        return $this->afterCreating(function (Role $role) use ($pairs) {
            $ids = [];

            foreach ($pairs as [$action, $resource]) {
                $ids[] = Permission::firstOrCreate(
                    ['action' => $action, 'resource' => $resource],
                    ['description' => "{$action} on {$resource}."]
                )->id;
            }

            $role->permissions()->sync($ids);
        });
    }
}
