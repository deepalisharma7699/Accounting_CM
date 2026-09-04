<?php

namespace App\Http\Requests\Staff;

use App\Enums\SalaryBasis;
use App\Services\Staff\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding somebody to the staff list — M22.
 *
 * Shape only. Whether the name is already taken, whether the designation belongs
 * to this workshop, and what the flags mean together are
 * {@see EmployeeService}'s business — the same rules have to hold for an
 * importer, which does not come through a form request.
 */
class StoreEmployeeRequest extends FormRequest
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
            /*
            | A name that survives leaving the browser.
            |
            | The same rule a party name takes, and it earns it here for a
            | sharper reason: this name is copied onto every payslip the person
            | is ever issued, and a payslip is a document somebody is handed.
            | Markup in it is not an injection — the UI escapes correctly — it is
            | a name that reads as `<b>Ravi</b>` on every sheet the workshop ever
            | prints, in a CSV that a spreadsheet is entitled to treat
            | differently. Control characters are worse: invisible in the box
            | that accepted them, and corrupting the line they land on.
            */
            'name' => ['required', 'string', 'min:2', 'max:150', 'not_regex:/[\x00-\x1F\x7F<>]/u'],

            // Optional, and checked for ownership rather than existence in the
            // service — an id from another workshop resolves to null there
            // rather than reaching the column.
            'designation_id' => ['nullable', 'integer', 'min:1'],

            'salary_basis' => ['required', Rule::enum(SalaryBasis::class)],

            /*
            | Zero is allowed and means it: somebody on the books whose pay has
            | not been agreed yet, or an owner who takes drawings rather than a
            | salary. They appear on the attendance sheet and compute to nothing
            | on payroll, which is the correct treatment of both. Refusing zero
            | would push a workshop into typing 1.
            */
            'pay_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_RATE],

            // Optional, and defaulted to today: adding the fitter who started
            // this morning should not require a date, and the field is there for
            // the one who started last year.
            'joined_on' => ['nullable', 'date_format:Y-m-d'],

            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Every run of whitespace folded to one ordinary space before the rules
        // see the name: a pasted name is commonly untidy rather than hostile,
        // and refusing it for a line break nobody can see would be a refusal
        // nobody could act on.
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
            'name.required' => 'Give this person a name.',
            'name.not_regex' => 'A name cannot contain < or > or hidden control characters.',
            'salary_basis.required' => 'Say whether they are on a monthly salary or a daily wage.',
            'pay_rate.required' => 'Give the rate — a monthly salary, or the rate per day.',
            'pay_rate.min' => 'A pay rate cannot be negative.',
            'joined_on.date_format' => 'Give the joining date as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'designation_id' => $this->filled('designation_id') ? (int) $this->input('designation_id') : null,
            'salary_basis' => (string) $this->string('salary_basis'),
            'pay_rate' => $this->input('pay_rate'),
            'joined_on' => $this->filled('joined_on') ? (string) $this->string('joined_on') : null,
            'phone' => $this->filled('phone') ? trim((string) $this->string('phone')) : null,
            'email' => $this->filled('email') ? trim((string) $this->string('email')) : null,
            'address' => $this->filled('address') ? trim((string) $this->string('address')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
        ];
    }
}
