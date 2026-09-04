<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use App\Services\Accounting\PostingEngine;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A running cost: what it was for, how much, and how it was paid.
 *
 * Shape only. That the account exists, is active and is actually an *expense*
 * account, and that the split adds up to what the receipt says, are
 * {@see PostingEngine}'s and the template's business — every future entry point
 * passes through them.
 */
class StoreExpenseRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
            'post' => ['required', 'boolean'],

            // A retry after a timeout must not become a second document — M17.
            ...$this->clientRefRules(),

            // Optional, and defaulted to Misc Expense. "We spent money and I do
            // not want to categorise it right now" is a real state, and refusing
            // the entry over it would push people into not recording the spend.
            'account_id' => ['nullable', 'integer', 'min:1'],

            // Optional, unlike a settlement's. A tea bill has no party anybody
            // will ever record; a monthly landlord does.
            'party_id' => ['nullable', 'integer', 'min:1'],

            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],

            // The amount printed on the receipt, not a rate — see
            // ExpenseTemplate. Absent means none is claimable, which is the
            // ordinary case for most of what a workshop spends.
            'gst_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            'payments' => ['required', 'array', 'min:1', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'An expense needs an amount greater than zero.',
            'payments.required' => 'Say how it was paid — cash, bank, UPI or cheque.',
            'payments.min' => 'Say how it was paid — cash, bank, UPI or cheque.',
            'date.date_format' => 'Give the date as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'date' => (string) $this->string('date'),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'post' => $this->boolean('post'),
            'client_ref' => $this->clientRef(),
            'account_id' => $this->filled('account_id') ? (int) $this->input('account_id') : null,
            'party_id' => $this->filled('party_id') ? (int) $this->input('party_id') : null,
            'amount' => $this->input('amount'),
            'gst_amount' => $this->filled('gst_amount') ? $this->input('gst_amount') : null,
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
        ];
    }
}
