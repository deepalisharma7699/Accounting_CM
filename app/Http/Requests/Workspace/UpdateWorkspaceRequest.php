<?php

namespace App\Http\Requests\Workspace;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An owner editing their own workshop.
 *
 * Three fields are deliberately absent:
 *
 *   status   — suspension is a platform decision; a workshop must not be able
 *              to un-suspend itself.
 *   slug     — it may already be in URLs, logs and support tickets.
 *   currency — display formatting only, and the tax engine is India-specific,
 *              so anything but INR would format correctly and compute wrongly.
 *
 * TenantService::updateOwnWorkspace() strips all three again, so a future
 * caller that bypasses this request cannot slip them through either.
 */
class UpdateWorkspaceRequest extends FormRequest
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
                // Ignores this workshop's own row, so re-submitting an
                // unchanged GSTIN is not a duplicate.
                Rule::unique('tenants', 'gstin')->ignore($this->user()?->tenant_id),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'state_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[0-9]{2}$/'],

            'financial_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
            // Validated against the tz database, so an invalid identifier
            // cannot reach date formatting and blow up at report time.
            'timezone' => ['sometimes', 'string', 'timezone'],
            'books_start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:2000-01-01'],
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
            'timezone.timezone' => 'That is not a recognised timezone.',
            'financial_year_start_month.between' => 'The financial year must start in a month between 1 and 12.',
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
        $fields = [
            'name', 'gstin', 'address', 'state_code',
            'financial_year_start_month', 'timezone', 'books_start_date',
        ];

        $payload = [];

        foreach ($fields as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        if (array_key_exists('financial_year_start_month', $payload)) {
            $payload['financial_year_start_month'] = (int) $payload['financial_year_start_month'];
        }

        return $payload;
    }
}
