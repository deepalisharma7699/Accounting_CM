<?php

namespace App\Http\Requests\Staff;

use App\Enums\SalaryBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEmployeeRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'designation_id' => ['nullable', 'integer', 'min:1'],
            'salary_basis' => ['nullable', Rule::enum(SalaryBasis::class)],
            'is_active' => ['nullable', 'boolean'],

            /*
            | The two attached figures, both opt-in.
            |
            | Each costs one extra query for the whole page rather than one per
            | row — but one extra query is still one a picker has no use for, and
            | the staff list is not the only thing that reads this endpoint. The
            | caller says which it wants rather than the server guessing.
            */
            'with_advances' => ['nullable', 'boolean'],
            'with_attendance' => ['nullable', 'boolean'],

            // Which month the attendance summary covers. Defaults to the current
            // one, which is what the list column means by "this month".
            'period' => ['nullable', 'date_format:Y-m'],

            'sort' => ['nullable', Rule::in(['name', 'joined_on', 'pay_rate', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array{search: string|null, designation_id: int|null, salary_basis: string|null, is_active: bool|null, sort: string|null, direction: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'designation_id' => $this->filled('designation_id') ? (int) $this->input('designation_id') : null,
            'salary_basis' => $this->input('salary_basis'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function wantsAdvances(): bool
    {
        return $this->boolean('with_advances');
    }

    public function wantsAttendance(): bool
    {
        return $this->boolean('with_attendance');
    }

    public function period(): string
    {
        return $this->filled('period') ? (string) $this->string('period') : now()->format('Y-m');
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 50);
    }
}
