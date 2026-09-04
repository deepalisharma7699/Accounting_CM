<?php

namespace App\Http\Requests\Catalogue;

use App\Enums\AttributeType;
use App\Services\Inventory\ItemAttributeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A field on a category, new or edited.
 *
 * `data_type` is validated against an enum here, unlike almost everything else in
 * this module — and the asymmetry is the point. Categories and units became
 * tables because they are the *business's* vocabulary; the data types are the
 * *system's* capability, the set of inputs the form knows how to draw and the
 * validator knows how to apply. A row saying `colour_picker` would be a promise
 * the application could not keep.
 *
 * `key` is accepted on create and ignored on update: it is the JSON key the
 * values are stored under, so renaming it would orphan every one of them. See
 * {@see ItemAttributeService}.
 */
class StoreAttributeRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => [$required, 'string', 'min:1', 'max:80'],

            // Optional: the service derives it from the label when nobody gives
            // one, which is what most admins want — they type "Flow Rate" and
            // get `flow_rate` without being asked about JSON keys.
            'key' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z][A-Za-z0-9_ -]*$/'],

            'data_type' => [$required, Rule::enum(AttributeType::class)],

            // A unit code. Dropped by the service where the type cannot carry
            // one — "kg" printed after a yes/no is a form somebody has to stop
            // and puzzle over.
            'unit_code' => ['nullable', 'string', 'max:20'],

            'is_required' => ['nullable', 'boolean'],
            'default_value' => ['nullable', 'string', 'max:120'],

            // The fixed set, for a dropdown. Order is preserved by the service:
            // it is what the select renders, and alphabetising would bury the
            // common choice in the middle.
            'options' => ['nullable', 'array', 'max:200'],
            'options.*' => ['nullable', 'string', 'max:120'],

            'min_value' => ['nullable', 'numeric', 'decimal:0,3'],
            'max_value' => ['nullable', 'numeric', 'decimal:0,3'],

            'help_text' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Give the field a label — it is what the create form prints beside the box.',
            'data_type.required' => 'Say what kind of value this field holds.',
            'key.regex' => 'A field key starts with a letter and contains only letters, numbers, spaces, hyphens and underscores.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $keys = [
            'label', 'data_type', 'unit_code', 'is_required', 'default_value',
            'options', 'min_value', 'max_value', 'help_text', 'display_order', 'is_active',
        ];

        // Only on create. The service ignores it on update anyway; leaving it out
        // here means a client that round-trips the whole record does not have to
        // remember to strip it.
        if ($this->isMethod('POST')) {
            $keys[] = 'key';
        }

        $payload = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $payload[$key] = match ($key) {
                'is_required', 'is_active' => $this->boolean($key),
                'display_order' => $this->input($key) === null ? null : (int) $this->input($key),
                'options' => (array) $this->input($key, []),
                default => $this->input($key),
            };
        }

        return $payload;
    }
}
