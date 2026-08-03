<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            'name' => fake()->unique()->words(2, true).' '.fake()->randomNumber(3),
            'code' => null,
            'type' => ItemType::Part,
            'hsn_sac' => '8483',
            'gst_rate' => '18.00',
            'base_uom' => UnitOfMeasure::Piece,
            'is_stock' => true,
            'is_draft' => false,
            'description' => null,
            'is_active' => true,
        ];
    }

    /**
     * A type, with the unit and stock capability that come with it — so a factory
     * cannot produce the one combination the service refuses, a service item that
     * holds stock.
     */
    public function ofType(ItemType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'base_uom' => $type->defaultUom(),
            'is_stock' => $type->canHoldStock(),
            'hsn_sac' => $type->usesSacCode() ? '998719' : ($attributes['hsn_sac'] ?? '8483'),
        ]);
    }

    public function motor(): static
    {
        return $this->ofType(ItemType::Motor)->state(fn () => [
            'name' => 'Induction Motor '.fake()->unique()->randomNumber(4),
            'hsn_sac' => '8501',
        ]);
    }

    public function part(): static
    {
        return $this->ofType(ItemType::Part);
    }

    public function bulkMaterial(): static
    {
        return $this->ofType(ItemType::BulkMaterial)->state(fn () => [
            'name' => 'Copper Winding Wire '.fake()->unique()->randomNumber(4),
            'hsn_sac' => '7408',
        ]);
    }

    public function service(): static
    {
        return $this->ofType(ItemType::Service)->state(fn () => [
            'name' => 'Rewinding Labour '.fake()->unique()->randomNumber(4),
        ]);
    }

    /**
     * Auto-created by an importer or the capture agent and not yet reviewed.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['is_draft' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
