<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
        $userId = (int) $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['sometimes', 'required', 'string', Password::defaults()],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
            'custom_role_id' => ['sometimes', 'nullable', 'integer', 'exists:roles,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = [];

        foreach (['name', 'email', 'password', 'status'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->input($field);
            }
        }

        if ($this->has('custom_role_id')) {
            $data['custom_role_id'] = $this->filled('custom_role_id')
                ? (int) $this->input('custom_role_id')
                : null;
        }

        return $data;
    }
}
