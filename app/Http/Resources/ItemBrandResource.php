<?php

namespace App\Http\Resources;

use App\Models\ItemBrand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Brand Master.
 *
 * `item_count` is present where the repository counted them: the master shows
 * "12 products" against each row, and it is what decides whether the delete
 * control is offered at all — a brand products carry is archived, never deleted.
 * Null rather than zero when nobody counted, so an honest payload says "not
 * fetched" instead of "none".
 *
 * @mixin ItemBrand
 */
class ItemBrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // What the create form's dropdown offers, and what a bill prints.
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,

            'is_active' => (bool) $this->is_active,
            'display_order' => (int) $this->display_order,

            'item_count' => $this->whenNotNull(
                $this->items_count === null ? null : (int) $this->items_count
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
