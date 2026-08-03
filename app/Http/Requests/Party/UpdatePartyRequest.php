<?php

namespace App\Http\Requests\Party;

use App\Enums\PartyRole;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A partial edit. Every field is optional, and a field that is absent is left
 * alone — a PATCH must never blank something it did not mention.
 *
 * `state_code` is not accepted here any more than it is on create: it is
 * derived from the GSTIN, and one supplied by hand that disagreed with it would
 * compute the wrong GST on every bill in M9 without ever looking wrong.
 */
class UpdatePartyRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],

            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [Rule::enum(PartyRole::class)],

            'gstin' => ['sometimes', 'nullable', 'string', 'size:15', 'regex:'.Tenant::GSTIN_PATTERN],

            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Archive / restore. There is no DELETE for a party who has been
            // traded with, so this is the control that actually gets used.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('gstin')) {
            $this->merge(['gstin' => strtoupper(trim((string) $this->input('gstin')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.min' => 'A party must be a customer, a vendor, or both.',
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
            'gstin.regex' => 'That does not look like a GSTIN — the format is 2 digits, then a PAN, then 3 characters.',
        ];
    }

    /**
     * Only the keys the client actually sent, so the service can tell "set this
     * to null" apart from "do not touch this".
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'roles', 'gstin', 'phone', 'email', 'address', 'notes', 'is_active'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        return $payload;
    }
}
