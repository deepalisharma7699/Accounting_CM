<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'custom_role_id' => ['nullable', 'integer', 'exists:roles,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array{name: string, email: string, password: string, status: string, custom_role_id: int|null}
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'email' => (string) $this->string('email'),
            'password' => (string) $this->string('password'),
            'status' => (string) $this->input('status', UserStatus::Active->value),
            'custom_role_id' => $this->filled('custom_role_id') ? (int) $this->input('custom_role_id') : null,
        ];
    }
}
