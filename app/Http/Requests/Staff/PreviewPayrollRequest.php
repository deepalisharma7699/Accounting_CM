<?php

namespace App\Http\Requests\Staff;

use App\Services\Staff\PayrollService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Computing a month's payroll without writing anything — M22.
 *
 * A GET-shaped question sent as a POST, because the recovery overrides are a
 * map that does not belong in a query string. Nothing is written and nothing is
 * reserved; the sheet can be re-run all day, and it is recomputed again at post.
 * See {@see PayrollService}.
 */
class PreviewPayrollRequest extends FormRequest
{
    /** Matches DECIMAL(15, 2). */
    private const MAX_AMOUNT = '9999999999999.99';

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
            'period' => ['required', 'date_format:Y-m'],

            /*
            | How much of each person's advance this month takes back, keyed by
            | employee id.
            |
            | Absent means "as much as this month covers", which is the default
            | the sheet suggests. A figure typed over it is capped at what is
            | outstanding and at what was earned — the service does both, because
            | the same caps have to hold when the run is posted and the payload
            | for that arrives separately.
            */
            'recoveries' => ['nullable', 'array', 'max:500'],
            'recoveries.*' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.required' => 'Say which month to run.',
            'period.date_format' => 'Give the month as YYYY-MM.',
            'recoveries.*.min' => 'An advance recovery cannot be negative.',
        ];
    }

    public function period(): string
    {
        return (string) $this->string('period');
    }

    /**
     * @return array<int, mixed>
     */
    public function recoveries(): array
    {
        $recoveries = [];

        foreach ((array) $this->input('recoveries', []) as $employeeId => $amount) {
            $recoveries[(int) $employeeId] = $amount;
        }

        return $recoveries;
    }
}
