<?php

namespace App\Http\Requests\Staff;

use App\Enums\SalaryBasis;
use App\Services\Staff\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing somebody on the staff list — M22.
 *
 * A PATCH, so every field is `sometimes`: absent means unchanged. The one field
 * where that distinction carries weight is `left_on`, where **absent and null
 * are different things** — absent leaves the record alone, and an explicit null
 * un-archives somebody who has come back. See {@see payload()}, which is built
 * by key rather than with a `??` chain for exactly that reason.
 *
 * Shape only; the rules that matter are {@see EmployeeService}'s.
 */
class UpdateEmployeeRequest extends FormRequest
{
    /** Matches DECIMAL(15, 2). */
    private const MAX_RATE = '9999999999999.99';

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
            'name' => ['sometimes', 'string', 'min:2', 'max:150', 'not_regex:/[\x00-\x1F\x7F<>]/u'],

            'designation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            /*
            | The basis can be changed, and it is worth saying what that does and
            | does not touch: it changes what future months compute to, and it
            | changes nothing about a month already posted, because every payslip
            | snapshots the basis and the rate it used. Moving a helper from a
            | daily wage to a monthly salary mid-year is a real thing a workshop
            | does, and it is on the audit trail.
            */
            'salary_basis' => ['sometimes', Rule::enum(SalaryBasis::class)],
            'pay_rate' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_RATE],

            'joined_on' => ['sometimes', 'date_format:Y-m-d'],

            // The leaving date, and the archive control with it. Null means
            // "they are back" — see the class note.
            'left_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],

            // Suspending somebody without a leaving date is a real case, so this
            // stays available on its own and wins over the pair above.
            'is_active' => ['sometimes', 'boolean'],

            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge([
                'name' => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('name'))),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.not_regex' => 'A name cannot contain < or > or hidden control characters.',
            'pay_rate.min' => 'A pay rate cannot be negative.',
            'left_on.date_format' => 'Give the leaving date as YYYY-MM-DD.',
        ];
    }

    /**
     * Only the keys that were actually sent.
     *
     * Built by `has()` rather than by reading every field with a fallback,
     * because the service distinguishes "absent" from "explicitly null" and a
     * `??` chain would collapse the two — which would silently clear a phone
     * number every time somebody edited a pay rate.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim((string) $this->string('name'));
        }

        if ($this->has('designation_id')) {
            $payload['designation_id'] = $this->filled('designation_id')
                ? (int) $this->input('designation_id')
                : null;
        }

        if ($this->has('salary_basis')) {
            $payload['salary_basis'] = (string) $this->string('salary_basis');
        }

        if ($this->has('pay_rate')) {
            $payload['pay_rate'] = $this->input('pay_rate');
        }

        if ($this->has('joined_on')) {
            $payload['joined_on'] = (string) $this->string('joined_on');
        }

        if ($this->has('left_on')) {
            $payload['left_on'] = $this->filled('left_on') ? (string) $this->string('left_on') : null;
        }

        if ($this->has('is_active')) {
            $payload['is_active'] = $this->boolean('is_active');
        }

        foreach (['phone', 'email', 'address', 'notes'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->filled($field) ? trim((string) $this->string($field)) : null;
            }
        }

        return $payload;
    }
}
