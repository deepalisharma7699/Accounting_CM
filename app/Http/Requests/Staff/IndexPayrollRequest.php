<?php

namespace App\Http\Requests\Staff;

use App\Enums\PayrollRunStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPayrollRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(PayrollRunStatus::class)],
            // Months, not days: a payroll run is dated by the month it pays for.
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * @return array{status: string|null, from: string|null, to: string|null}
     */
    public function filters(): array
    {
        return [
            'status' => $this->input('status'),
            // Widened to whole months at the boundary, because `period_month`
            // holds the first of the month and a `Y-m` compared against it as a
            // string would silently exclude the month the caller asked for.
            'from' => $this->filled('from') ? $this->string('from').'-01' : null,
            'to' => $this->filled('to') ? $this->string('to').'-01' : null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 24);
    }
}
