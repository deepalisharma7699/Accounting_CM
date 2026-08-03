<?php

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            // The workshop being signed up. Registration provisions a tenant
            // and its owner together; there is no user without a workshop.
            'workshop_name' => ['required', 'string', 'min:2', 'max:160'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.Tenant::GSTIN_PATTERN, Rule::unique('tenants', 'gstin')],
            'state_code' => ['nullable', 'string', 'size:2', 'regex:/^[0-9]{2}$/'],

            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                // Soft-deleted users keep their row, and the unique index
                // covers them, so the rule must not exclude trashed records.
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists.',
            'gstin.unique' => 'A workspace with this GSTIN already exists.',
            'gstin.regex' => 'That does not look like a valid GSTIN.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        if ($this->filled('gstin')) {
            $this->merge(['gstin' => strtoupper(trim((string) $this->input('gstin')))]);
        }
    }

    /**
     * @return array{workshop_name: string, gstin: string|null, state_code: string|null, name: string, email: string, password: string}
     */
    public function payload(): array
    {
        return [
            'workshop_name' => trim((string) $this->string('workshop_name')),
            'gstin' => $this->filled('gstin') ? (string) $this->string('gstin') : null,
            'state_code' => $this->filled('state_code') ? (string) $this->string('state_code') : null,
            'name' => trim((string) $this->string('name')),
            'email' => (string) $this->string('email'),
            'password' => (string) $this->string('password'),
        ];
    }
}
