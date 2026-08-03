<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'gstin' => [
                'sometimes',
                'nullable',
                'string',
                'size:15',
                'regex:'.Tenant::GSTIN_PATTERN,
                Rule::unique('tenants', 'gstin')->ignore($this->route('tenant')),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'state_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[0-9]{2}$/'],
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('gstin')) {
            $this->merge(['gstin' => strtoupper(trim((string) $this->input('gstin')))]);
        }
    }

    /**
     * Only the keys actually present, so a PATCH cannot blank a field the
     * caller never mentioned.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->only(
            array_filter(
                ['name', 'gstin', 'address', 'state_code', 'status'],
                fn (string $field) => $this->has($field),
            )
        );
    }
}
