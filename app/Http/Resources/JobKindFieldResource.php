<?php

namespace App\Http\Resources;

use App\Models\JobKindAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question a kind asks, as the master's own editor reads it.
 *
 * Not the same shape as `attributeSchema()`: that is what a *form* needs — the
 * label, the suffix, the fixed values — and this is what an editor needs, which
 * includes the key, whether the field is switched on, and the bounds as stored
 * rather than as rendered.
 *
 * @mixin JobKindAttribute
 */
class JobKindFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'job_kind_id' => (int) $this->job_kind_id,
            'key' => $this->key,
            'label' => $this->label,
            'data_type' => $this->data_type->value,
            'unit_code' => $this->unit_code,
            'suffix' => $this->suffix(),
            'is_required' => (bool) $this->is_required,
            'default_value' => $this->default_value,
            'options' => $this->optionList(),
            'min_value' => $this->min_value === null ? null : (string) $this->min_value,
            'max_value' => $this->max_value === null ? null : (string) $this->max_value,
            'help_text' => $this->help_text,
            'display_order' => (int) $this->display_order,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
