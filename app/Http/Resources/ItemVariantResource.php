<?php

namespace App\Http\Resources;

use App\Models\ItemVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One variant — the specific thing that is bought and sold.
 *
 * `label` and `display_label` are both sent, and the distinction is the point: the
 * first is what the workshop typed, which may be nothing, and the second is what to
 * *show*, derived from the attributes when nobody named it. An edit form has to
 * round-trip the first without overwriting it with the second.
 *
 * No quantity and no cost, for the same reason as {@see ItemResource}: those are
 * M8's, and a zero here would read as "none in stock" when it means "nobody asked".
 *
 * @mixin ItemVariant
 */
class ItemVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,

            'sku' => $this->sku,
            // What the workshop typed — null when they typed nothing.
            'label' => $this->label,
            // What to show. Never null.
            'display_label' => $this->displayLabel(),

            // Reached by name rather than as $this->attributes, which is
            // Eloquent's own column bag — see ItemVariant::attributeBag().
            'attributes' => $this->attributeBag(),

            // Decimal strings, and null where genuinely unpriced: a motor rewind
            // is quoted per job, and a zero would say "free".
            'barcode' => $this->barcode,

            'sell_price' => $this->sell_price === null ? null : (string) $this->sell_price,
            'markup_percent' => $this->markup_percent === null ? null : (string) $this->markup_percent,
            'reorder_level' => $this->reorder_level === null ? null : (string) $this->reorder_level,
            // The hard floor, distinct from the reorder level above it: one is
            // "order more when it reaches this" and the other is "never let it
            // fall below this".
            'min_stock' => $this->min_stock === null ? null : (string) $this->min_stock,

            'is_draft' => $this->is_draft,
            'is_active' => $this->is_active,

            // The family, when it was loaded. A variant means almost nothing
            // without its type — the attribute schema, the unit and the tax code
            // all live there.
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'name' => $this->item->name,
                'category_id' => $this->item->category_id === null ? null : (int) $this->item->category_id,
                'category_label' => $this->item->categoryLabel(),
                'brand_id' => $this->item->brand_id === null ? null : (int) $this->item->brand_id,
                'brand' => $this->item->brandLabel(),
                'base_uom_symbol' => $this->item->base_uom?->symbol(),
                'gst_rate' => (string) $this->item->gst_rate,
                'tracks_stock' => $this->item->tracksStock(),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
