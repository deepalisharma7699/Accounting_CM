<?php

namespace App\Http\Requests\JobKind;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a kind, or switching it off.
 *
 * `sometimes` throughout, so a request that carries only `is_active` archives
 * without restating the name — which is what the list's own switch sends.
 */
class UpdateJobKindRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only what was actually sent — an absent key means "leave it", which is
     * not the same as an empty one.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'description', 'display_order', 'is_active'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        return $payload;
    }
}
