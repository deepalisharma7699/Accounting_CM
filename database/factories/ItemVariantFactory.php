<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemVariant>
 */
class ItemVariantFactory extends Factory
{
    protected $model = ItemVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            'item_id' => Item::factory(),
            'sku' => null,
            'label' => null,
            'attributes' => null,
            'sell_price' => null,
            'markup_percent' => null,
            'reorder_level' => null,
            'is_draft' => false,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     */
    public function withAttributes(array $attributes): static
    {
        return $this->state(fn () => ['attributes' => $attributes]);
    }

    /**
     * The specification a customer actually asks for.
     */
    public function motor(string $hp = '5', string $phase = '3', string $rpm = '1440'): static
    {
        return $this->withAttributes(['hp' => $hp, 'phase' => $phase, 'rpm' => $rpm]);
    }

    public function pricedAt(string $sellPrice): static
    {
        return $this->state(fn () => ['sell_price' => $sellPrice]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['is_draft' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
