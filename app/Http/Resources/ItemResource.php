<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item family, with its variants when they have been loaded.
 *
 * Note what is **not** here: no quantity, no cost, no stock value. Those are M8's
 * answer, derived from `stock_movement`, and putting a placeholder for them on this
 * payload now would invite a client to render a zero as though it meant "none in
 * stock" rather than "nobody has asked".
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            // Which word the tax code goes by for this item. The same column, but
            // a form must not label a service's code "HSN".
            'hsn_sac' => $this->hsn_sac,
            'tax_code_label' => $this->taxCodeLabel(),

            // A decimal string, like every other number here: a JSON number is
            // parsed back into a float by every client that receives it, and this
            // one gets multiplied by an amount to compute tax.
            'gst_rate' => (string) $this->gst_rate,

            'base_uom' => $this->base_uom->value,
            'base_uom_label' => $this->base_uom->label(),
            'base_uom_symbol' => $this->base_uom->symbol(),

            // Two flags, and the difference matters. `is_stock` is what the
            // workshop chose; `tracks_stock` is that choice AND the type being
            // capable of it, which is what M8 actually acts on.
            'is_stock' => $this->is_stock,
            'tracks_stock' => $this->tracksStock(),
            'can_hold_stock' => $this->type->canHoldStock(),

            'is_draft' => $this->is_draft,
            'is_active' => $this->is_active,
            'description' => $this->description,

            'variants' => ItemVariantResource::collection(
                $this->whenLoaded('variants')
            ),

            // Always present where the repository counted them, because the list
            // shows "4 variants" and loading every variant of every item to learn
            // that is the classic listing-page mistake. Null rather than zero when
            // nobody counted — an honest payload says "not fetched".
            'variant_count' => $this->whenNotNull(
                $this->relationLoaded('variants')
                    ? $this->variants->count()
                    : ($this->variants_count === null ? null : (int) $this->variants_count)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
