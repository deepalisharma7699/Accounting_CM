<?php

namespace App\Http\Resources;

use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One category — the template that says what to record about a kind of thing.
 *
 * `attributes` is this category's **own** fields, which is what the Category
 * Master edits. `schema` is the **resolved** question set, inherited fields
 * folded in, which is what the universal create form draws. A subcategory's two
 * lists differ, and conflating them would either hide a parent's fields from the
 * form or offer them for editing in the wrong place.
 *
 * @mixin ItemCategory
 */
class ItemCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id === null ? null : (int) $this->parent_id,
            'parent_name' => $this->whenLoaded('parent', fn () => $this->parent?->name),

            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,

            // Capability, not choice. `items.is_stock` is the shop's choice
            // within it — a part bought to order and never inventoried is a real
            // arrangement, and labour is not.
            'holds_stock' => (bool) $this->holds_stock,
            'uses_sac_code' => (bool) $this->uses_sac_code,
            'tax_code_label' => $this->taxCodeLabel(),

            // Copied onto a new product, never referenced by an existing one —
            // correcting a default next March must not restate what every
            // product already charges.
            'default_unit_code' => $this->default_unit_code,
            'default_hsn_sac' => $this->default_hsn_sac,
            'default_gst_rate' => $this->default_gst_rate === null ? null : (string) $this->default_gst_rate,

            // One of the four the system was set up with. Renameable and
            // archivable, never deletable.
            'is_system' => (bool) $this->is_system,
            'is_active' => (bool) $this->is_active,
            'display_order' => (int) $this->display_order,

            // This category's own fields — what the master screen edits.
            'attributes' => ItemAttributeResource::collection($this->whenLoaded('fields')),

            // The resolved question set, inherited fields included — what the
            // universal create form draws. Keyed by field name.
            // Cast, so a category with no fields serialises as {} rather than
            // []. Both are falsy in JavaScript and only one of them is an
            // object, and a client indexing into the array form gets undefined
            // rather than an empty result.
            'schema' => (object) $this->attributeSchema(),

            'children' => ItemCategoryResource::collection($this->whenLoaded('children')),

            // Present where the repository counted them: the master shows "12
            // products" against each row, and loading every product to learn
            // that is the classic listing-page mistake.
            'item_count' => $this->whenNotNull(
                $this->items_count === null ? null : (int) $this->items_count
            ),
            'child_count' => $this->whenNotNull(
                $this->children_count === null ? null : (int) $this->children_count
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
