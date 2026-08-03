<?php

namespace App\Http\Requests\Item;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Services\Inventory\ItemService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new item family.
 *
 * Shape only. Whether the name is taken, whether a service may hold stock, and
 * what the unit defaults to are all {@see ItemService}'s business — the same rules
 * have to hold for M11's importer and M15's capture agent, neither of which comes
 * through a form request.
 */
class StoreItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'type' => ['required', Rule::enum(ItemType::class)],

            'code' => ['nullable', 'string', 'max:40'],

            // HSN for goods, SAC for services — the same field in the same
            // position on a GST invoice. Digits only, and 4 to 8 of them: the
            // shape every published code takes.
            'hsn_sac' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],

            // A percentage, not a fraction: 18, not 0.18. Capped at 100 because a
            // rate above that is a typo rather than a tax.
            'gst_rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],

            // Optional: it defaults from the type, so the ordinary case needs no
            // decision. A motor is counted in pieces and copper is weighed.
            'base_uom' => ['nullable', Rule::enum(UnitOfMeasure::class)],

            // Accepted but clamped by the service — a service item can never hold
            // stock, whatever is sent.
            'is_stock' => ['nullable', 'boolean'],

            // Set by an importer or the capture agent, not usually by a person.
            'is_draft' => ['nullable', 'boolean'],

            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Say what kind of thing this is — a motor, a part, a bulk material or a service.',
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
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'type' => (string) $this->string('type'),
            'code' => $this->filled('code') ? (string) $this->string('code') : null,
            'hsn_sac' => $this->filled('hsn_sac') ? trim((string) $this->string('hsn_sac')) : null,
            'gst_rate' => $this->filled('gst_rate') ? $this->input('gst_rate') : null,
            'base_uom' => $this->filled('base_uom') ? (string) $this->string('base_uom') : null,
            'is_stock' => $this->has('is_stock') ? $this->boolean('is_stock') : null,
            'is_draft' => $this->boolean('is_draft'),
            'description' => $this->filled('description') ? trim((string) $this->string('description')) : null,
        ];
    }
}
