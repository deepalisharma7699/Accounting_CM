<?php

namespace App\Http\Resources;

use App\Models\ItemVariant;
use App\Services\Inventory\StockPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the stock screen: a variant, and what is on the shelf.
 *
 * The three numbers are quantity, value and average cost — and the third is
 * *derived from* the first two rather than sent alongside them as an
 * independent figure, which is why it is computed in {@see StockPosition} and
 * not here. Two places rounding the same division is two answers.
 *
 * `is_negative` is reported separately from `is_low` rather than folded into it.
 * Low stock is a purchasing decision; negative stock is a data problem — a sale
 * recorded before its purchase — and a screen that showed them the same way
 * would train people to ignore the second.
 */
class StockPositionResource extends JsonResource
{
    public function __construct(
        private readonly ItemVariant $variant,
        private readonly StockPosition $position,
    ) {
        parent::__construct($variant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->variant->item;

        return [
            'variant_id' => $this->variant->id,
            'item_id' => $this->variant->item_id,

            'sku' => $this->variant->sku,
            'display_label' => $this->variant->displayLabel(),
            'is_active' => $this->variant->is_active,
            'is_draft' => $this->variant->is_draft,

            'item' => $item === null ? null : [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'category_id' => $item->category_id === null ? null : (int) $item->category_id,
                'category_label' => $item->categoryLabel(),
                'base_uom' => $item->base_uom->value,
                'base_uom_symbol' => $item->base_uom->symbol(),
                'is_fractional' => $item->base_uom->isFractional(),
                'gst_rate' => (string) $item->gst_rate,
            ],

            // Decimal strings, like every other number leaving this API: a JSON
            // number is parsed back into a float by every client that receives
            // it, and these get multiplied by costs.
            'quantity' => $this->position->quantity->amount(),
            'value' => $this->position->value->amount(),
            'average_cost' => $this->position->averageCost()->amount(),

            'reorder_level' => $this->position->reorderLevel?->amount(),
            'sell_price' => $this->variant->sell_price === null ? null : (string) $this->variant->sell_price,

            'has_stock' => $this->position->hasStock(),
            'is_low' => $this->position->isLow(),
            'is_negative' => $this->position->isNegative(),
        ];
    }
}
