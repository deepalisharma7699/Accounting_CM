<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
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

            // Explicit, and never defaulted to true — same rule as a journal.
            // Committing to the ledger must not be something that happened
            // because a field was left out.
            'post' => ['required', 'boolean'],

            // A retry after a timeout must not become a second document — M17.
            ...$this->clientRefRules(),

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

            /*
            | Which bills this money settles — M16. Optional, and the omission
            | means something: leave it out and the money is applied to the
            | party's open bills oldest first, which is what an accounts
            | department does when nobody says otherwise.
            |
            | Send it when the customer *did* say — "this cheque is for invoice
            | 1012" — and the split is taken as given. An amount left off a line
            | means "whatever is still owing on this one", so ticking three
            | invoices off a list needs no figures typed at all.
            |
            | That the bill exists, is this party's, is posted, and has that much
            | left owing are all SettlementService's business, for the same reason
            | the payment split's arithmetic is the engine's: every entry point
            | passes through it, and a rule enforced in one controller says
            | nothing about the others.
            */
            'allocations' => ['nullable', 'array', 'max:100'],
            'allocations.*.bill_transaction_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
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
     * The bills this settlement is pointed at.
     *
     * Kept out of {@see payload()} deliberately: an allocation is not part of the
     * transaction at all — it writes no journal entry and changes no balance —
     * and putting it in the compose payload would park it on a draft's stored
     * request, where it would look like something the posting engine ought to
     * obey. It is applied after the money is in the books, by
     * {@see \App\Services\Accounting\SettlementService}.
     *
     * @return array<int, array{bill_transaction_id: int, amount: string|null}>
     */
    public function allocations(): array
    {
        return array_map(fn (array $line) => [
            'bill_transaction_id' => (int) ($line['bill_transaction_id'] ?? 0),
            'amount' => isset($line['amount']) && $line['amount'] !== ''
                ? (string) $line['amount']
                : null,
        ], array_values((array) $this->input('allocations', [])));
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
            'client_ref' => $this->clientRef(),
            'party_id' => (int) $this->input('party_id'),
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
        ];
    }
}
