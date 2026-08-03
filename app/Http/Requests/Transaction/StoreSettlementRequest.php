<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use App\Services\Accounting\PostingEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment or a receipt: who, when, and how the money moved.
 *
 * One request class for both, because the payload is genuinely identical — the
 * direction is stated by the route, not by a field. That is the opposite of the
 * reason journals and settlements have separate endpoints: there the payloads
 * differ completely, so one endpoint accepting both would validate neither.
 *
 * Shape only. That the party exists, is not archived, holds the role this
 * transaction claims of them, and that the split adds up to the entries, are all
 * {@see PostingEngine}'s business — every future entry point passes through it and
 * a rule enforced in one controller says nothing about the other five.
 */
class StoreSettlementRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Explicit, and never defaulted to true — same rule as a journal.
            // Committing to the ledger must not be something that happened
            // because a field was left out.
            'post' => ['required', 'boolean'],

            // Required here where a journal's is optional: money moved to or from
            // somebody, and a settlement attributed to nobody sits in a control
            // account no statement can account for. Whether they hold the right
            // role is the engine's check.
            'party_id' => ['required', 'integer', 'min:1'],

            // At least one way the money moved. The cap is generous but finite:
            // nobody settles one invoice across fifty tenders, and an unbounded
            // array is an unbounded number of ledger lines.
            'payments' => ['required', 'array', 'min:1', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],

            // `decimal:0,2` rejects a third decimal place rather than rounding it
            // away — a client sending 100.005 has a bug, and quietly posting
            // 100.01 hides it.
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
            'party_id.required' => 'Say who the money moved to or from.',
            'payments.required' => 'Say how the money moved — cash, bank, UPI or cheque.',
            'payments.min' => 'Say how the money moved — cash, bank, UPI or cheque.',
            'payments.*.amount.gt' => 'Each payment line needs an amount greater than zero.',
            'payments.*.amount.decimal' => 'Amounts are in rupees and paise — at most two decimal places.',
            'date.date_format' => 'Give the transaction date as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array{date: string, notes: string|null, post: bool, party_id: int, payments: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'date' => (string) $this->string('date'),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'post' => $this->boolean('post'),
            'party_id' => (int) $this->input('party_id'),
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
        ];
    }
}
