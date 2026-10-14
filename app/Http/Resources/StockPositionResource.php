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
 *
 * `is_below_minimum` is separate for the same kind of reason and a different
 * one. `reorder_level` is when to order and `min_stock` is when to stop what you
 * are doing and go and get some, so a row carries **both levels and both
 * verdicts** rather than one status the client has to unpick. Neither is
 * recomputed client-side: the levels are on the variant and the arithmetic that
 * compares them is {@see StockPosition}'s, once.
 *
 * `has_trading_history` is whether the variant has moved through anything but
 * an opening declaration — the server's own answer to "may opening stock still
 * be declared here", so the Stock card's template and the Items drawer offer
 * exactly what the import would accept. Null where the caller did not ask.
 */
class StockPositionResource extends JsonResource
{
    public function __construct(
        private readonly ItemVariant $variant,
        private readonly StockPosition $position,
        private readonly ?bool $hasTradingHistory = null,
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
                'price_includes_tax' => (bool) $item->price_includes_tax,
            ],

            // Decimal strings, like every other number leaving this API: a JSON
            // number is parsed back into a float by every client that receives
            // it, and these get multiplied by costs.
            'quantity' => $this->position->quantity->amount(),
            'value' => $this->position->value->amount(),
            'average_cost' => $this->position->averageCost()->amount(),

            'reorder_level' => $this->position->reorderLevel?->amount(),
            'min_stock' => $this->position->minStock?->amount(),
            'sell_price' => $this->variant->sell_price === null ? null : (string) $this->variant->sell_price,
            // What the workshop expects to pay — the starting figure the
            // opening-stock template writes into its unit cost column.
            'purchase_price' => $this->variant->purchase_price === null ? null : (string) $this->variant->purchase_price,
            'has_trading_history' => $this->hasTradingHistory,

            'has_stock' => $this->position->hasStock(),
            'is_low' => $this->position->isLow(),
            'is_below_minimum' => $this->position->isBelowMinimum(),
            'is_negative' => $this->position->isNegative(),
        ];
    }
}
