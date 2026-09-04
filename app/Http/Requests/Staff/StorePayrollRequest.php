<?php

namespace App\Http\Requests\Staff;

use App\Enums\PaymentMode;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use App\Services\Staff\PayrollService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying a month — M22.
 *
 * ## What the request does *not* carry
 *
 * The sheet. No gross, no per-employee figure, no total. Every one of those is
 * recomputed from live attendance at the moment of posting — see
 * {@see PayrollService::post()} — and accepting them here would let a client
 * post a month at figures that no longer follow from the register, with the
 * ledger and the payslips agreeing with each other and with nothing else.
 *
 * What it carries is the three things a machine cannot re-derive: which month,
 * how the money moved, and how much of each person's advance the workshop
 * decided to take back.
 *
 * ## `post` is absent too
 *
 * Every other store request in this application asks whether to post or park a
 * draft. There is no draft payroll — see `payroll_runs` — so there is no
 * question to ask, and offering one would imply a state that does not exist.
 */
class StorePayrollRequest extends FormRequest
{
    use CarriesClientRef;

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
            | The day the money moved, which is not the month it pays for.
            |
            | Optional and defaulted to today: a workshop paying August wages on
            | 7 September is posting a September cash movement, and dating it 31
            | August would put the payment in a month that may already be closed.
            */
            'date' => ['nullable', 'date_format:Y-m-d'],

            /*
            | How the net was handed over.
            |
            | Optional, unlike every other settlement in the application, and the
            | exception is real: a month where every rupee earned had already been
            | advanced is a complete payroll that moves no cash at all. What is
            | enforced is that recovery plus split equals gross, which the
            | template checks with a message that says by how much.
            */
            'payments' => ['nullable', 'array', 'max:20'],
            'payments.*.mode' => ['required_with:payments', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            // Keyed by employee id. Absent means "as much as this month covers".
            'recoveries' => ['nullable', 'array', 'max:500'],
            'recoveries.*' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            'notes' => ['nullable', 'string', 'max:500'],

            // A retry after a timeout must not pay everybody twice — M17. This
            // is the request in the whole application where that matters most.
            ...$this->clientRefRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.required' => 'Say which month to pay.',
            'period.date_format' => 'Give the month as YYYY-MM.',
            'date.date_format' => 'Give the payment date as YYYY-MM-DD.',
            'payments.*.mode.required_with' => 'Say how the money was paid — cash, bank, UPI or cheque.',
            'recoveries.*.min' => 'An advance recovery cannot be negative.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $recoveries = [];

        foreach ((array) $this->input('recoveries', []) as $employeeId => $amount) {
            $recoveries[(int) $employeeId] = $amount;
        }

        return [
            'period' => (string) $this->string('period'),
            'date' => $this->filled('date') ? (string) $this->string('date') : null,
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
            'recoveries' => $recoveries,
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'client_ref' => $this->clientRef(),
        ];
    }
}
