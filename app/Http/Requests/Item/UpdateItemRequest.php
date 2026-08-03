<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing an item family, and the archive control.
 *
 * `type` and `base_uom` are absent, deliberately and permanently. Reclassifying an
 * item would silently reinterpret every quantity recorded against it, and changing
 * "each" to "kilogram" would turn 40 pieces into 40 kilograms in every report ever
 * run — the same reasoning that keeps an account's type off its edit form. If the
 * type was wrong, the item was the wrong item: archive it and add the right one.
 *
 * Every rule is `sometimes`, so a PATCH that only clears the draft flag leaves
 * everything it did not mention alone.
 */
class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hsn_sac' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4,8}$/'],
            'gst_rate' => ['sometimes', 'numeric', 'decimal:0,2', 'between:0,100'],
            'is_stock' => ['sometimes', 'boolean'],
            // Clearing this is how the review queue is worked through.
            'is_draft' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            // The archive control. There is no DELETE for an item anything points
            // at — its bill lines would lose the name that explains them.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hsn_sac.regex' => 'An HSN or SAC code is 4 to 8 digits.',
            'gst_rate.between' => 'A GST rate is a percentage between 0 and 100 — 18, not 0.18.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * Only the keys the caller actually sent, so absent means unchanged and an
     * explicit null clears.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'code', 'hsn_sac', 'description'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->filled($field) ? trim((string) $this->input($field)) : null;
            }
        }

        if ($this->has('gst_rate')) {
            $payload['gst_rate'] = $this->input('gst_rate');
        }

        foreach (['is_stock', 'is_draft', 'is_active'] as $flag) {
            if ($this->has($flag)) {
                $payload[$flag] = $this->boolean($flag);
            }
        }

        return $payload;
    }
}
