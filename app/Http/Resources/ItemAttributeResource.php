<?php

namespace App\Http\Resources;

use App\Models\ItemAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One field a category asks for.
 *
 * Two shapes of the same thing are sent, and the duplication is deliberate:
 *
 *   * the **flat columns** — `key`, `label`, `data_type`, `options` — are what
 *     the Category Master's own edit form round-trips;
 *   * `schema` is the same field in the shape the *universal create form* reads,
 *     which is the shape `ItemType::attributeSchema()` used to return.
 *
 * One client edits fields and the other draws them, and making either derive the
 * other's shape would put the translation in JavaScript, where it would drift.
 *
 * @mixin ItemAttribute
 */
class ItemAttributeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => (int) $this->category_id,

            // Write-once: it is the JSON key the values are stored under, and
            // renaming it would orphan every one of them. Sent so the form can
            // show it, never accepted back.
            'key' => $this->key,

            'label' => $this->label,

            'data_type' => $this->data_type->value,
            'data_type_label' => $this->data_type->label(),

            'unit_code' => $this->unit_code,
            'unit_symbol' => $this->suffix(),

            'is_required' => (bool) $this->is_required,
            'default_value' => $this->default_value,

            // Always an array for a dropdown and always null otherwise, so a
            // client never has to decide which of the two "no options" means.
            'options' => $this->data_type->hasOptions() ? $this->optionList() : null,

            // Decimal strings, like every number here: a JSON number comes back
            // as a float in every client that receives it.
            'min_value' => $this->min_value === null ? null : (string) $this->min_value,
            'max_value' => $this->max_value === null ? null : (string) $this->max_value,

            'help_text' => $this->help_text,
            'display_order' => (int) $this->display_order,
            'is_active' => (bool) $this->is_active,

            // The same field as the universal form sees it.
            'schema' => $this->toSchemaField(),

            // How many products have answered this, where the caller asked. It
            // is what turns "Delete" into "Switch off" in the UI before the
            // server has to refuse anything.
            'usage' => $this->whenNotNull($this->usage ?? null),
        ];
    }
}
