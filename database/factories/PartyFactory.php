<?php

namespace Database\Factories;

use App\Enums\PartyRole;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            'name' => fake()->unique()->company(),
            'roles' => [PartyRole::Customer->value],
            'gstin' => null,
            'state_code' => null,
            'phone' => fake()->numerify('9#########'),
            'email' => null,
            'address' => fake()->address(),
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function customer(): static
    {
        return $this->withRoles(PartyRole::Customer);
    }

    public function vendor(): static
    {
        return $this->withRoles(PartyRole::Vendor);
    }

    /**
     * The case worth having a name for: one counterparty on both sides of the
     * business, whose ledger nets rather than splitting in two.
     */
    public function both(): static
    {
        return $this->withRoles(PartyRole::Customer, PartyRole::Vendor);
    }

    public function withRoles(PartyRole ...$roles): static
    {
        return $this->state(fn (array $attributes) => [
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
        ]);
    }

    public function withGstin(string $gstin): static
    {
        return $this->state(fn (array $attributes) => [
            'gstin' => strtoupper($gstin),
            'state_code' => substr($gstin, 0, 2),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
