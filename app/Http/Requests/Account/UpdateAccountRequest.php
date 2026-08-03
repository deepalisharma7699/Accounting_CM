<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `type` is deliberately absent: reclassifying an account would silently move
 * every journal entry ever posted against it onto a different financial
 * statement. An account of the wrong type is archived and replaced, not edited.
 */
class UpdateAccountRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'digits:4'],
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            // false archives the account: it stops appearing in pickers but
            // keeps its history. Accounts are never hard-deleted.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only the keys actually present, so a PATCH cannot blank a field the
     * caller never mentioned.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['code', 'name', 'description'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        if ($this->has('is_active')) {
            $payload['is_active'] = $this->boolean('is_active');
        }

        return $payload;
    }
}
