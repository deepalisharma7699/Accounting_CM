<?php

namespace App\Http\Requests\Staff;

use App\Enums\PaymentMode;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use App\Services\Staff\AdvanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying an advance to an employee — M22.
 *
 * ## There is no amount field
 *
 * The amount **is** the payment split, exactly as it is for a vendor payment: an
 * advance is money leaving the till, and the ways it left add up to what left. A
 * separate amount would be a second figure that could disagree with the first,
 * and the disagreement would be a debit in Staff Advance that no cash box could
 * account for.
 *
 * ## There is no `post`
 *
 * An advance is cash in somebody's hand at the moment they ask for it. A draft
 * advance would be a promise sitting in a queue while the money is already gone,
 * so {@see AdvanceService::pay()} forces the posting and this offers no choice
 * about it.
 */
class StoreAdvanceRequest extends FormRequest
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
            // Required, and the whole point: an advance attributed to nobody is
            // a debit in Staff Advance that no payroll could ever recover.
            'employee_id' => ['required', 'integer', 'min:1'],

            'date' => ['nullable', 'date_format:Y-m-d'],

            'payments' => ['required', 'array', 'min:1', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            'notes' => ['nullable', 'string', 'max:400'],

            // A retry after a timeout must not hand over the money twice — M17.
            ...$this->clientRefRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Say who the advance is for.',
            'payments.required' => 'Say how much was paid, and how — cash, bank, UPI or cheque.',
            'payments.min' => 'Say how much was paid, and how — cash, bank, UPI or cheque.',
            'date.date_format' => 'Give the date as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'employee_id' => (int) $this->input('employee_id'),
            'date' => $this->filled('date') ? (string) $this->string('date') : null,
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'client_ref' => $this->clientRef(),
        ];
    }
}
