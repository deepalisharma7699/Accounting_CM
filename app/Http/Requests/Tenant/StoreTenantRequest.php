<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Platform super-admin provisioning a workshop, optionally with its owner in
 * the same call (sales-led onboarding).
 */
class StoreTenantRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.Tenant::GSTIN_PATTERN, Rule::unique('tenants', 'gstin')],
            'address' => ['nullable', 'string', 'max:500'],
            'state_code' => ['nullable', 'string', 'size:2', 'regex:/^[0-9]{2}$/'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],

            // Owner block: all-or-nothing. A half-supplied owner is a typo,
            // not an intention, so each field is required with the others.
            'owner' => ['nullable', 'array'],
            'owner.name' => ['required_with:owner', 'string', 'min:2', 'max:120'],
            'owner.email' => ['required_with:owner', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'owner.password' => ['required_with:owner', 'string', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.unique' => 'A workspace with this GSTIN already exists.',
            'gstin.regex' => 'That does not look like a valid GSTIN.',
            'owner.email.unique' => 'An account with this email address already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('gstin')) {
            $this->merge(['gstin' => strtoupper(trim((string) $this->input('gstin')))]);
        }

        if ($this->filled('owner.email')) {
            $this->merge([
                'owner' => [
                    ...(array) $this->input('owner'),
                    'email' => strtolower(trim((string) $this->input('owner.email'))),
                ],
            ]);
        }
    }

    /**
     * @return array{name: string, gstin: string|null, address: string|null, state_code: string|null, status: string|null}
     */
    public function tenantPayload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'gstin' => $this->filled('gstin') ? (string) $this->string('gstin') : null,
            'address' => $this->filled('address') ? trim((string) $this->string('address')) : null,
            'state_code' => $this->filled('state_code') ? (string) $this->string('state_code') : null,
            'status' => $this->filled('status') ? (string) $this->string('status') : null,
        ];
    }

    /**
     * @return array{name: string, email: string, password: string}|null
     */
    public function ownerPayload(): ?array
    {
        if (! $this->filled('owner')) {
            return null;
        }

        return [
            'name' => trim((string) $this->input('owner.name')),
            'email' => (string) $this->input('owner.email'),
            'password' => (string) $this->input('owner.password'),
        ];
    }
}
