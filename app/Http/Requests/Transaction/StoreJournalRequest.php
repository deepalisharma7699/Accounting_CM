<?php

namespace App\Http\Requests\Transaction;

use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual journal entry: the date, some lines, and whether to commit it.
 *
 * Shape only. Everything that makes it a *journal* rather than a list of
 * numbers — two lines at least, one side each, debits equal to credits, real
 * and unarchived accounts, a date the books are open on — is checked by the
 * posting engine, in one place, for every route that ever posts anything.
 */
class StoreJournalRequest extends FormRequest
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

            // Explicit, and never defaulted to true: committing to the ledger
            // is the consequential act here, and it must not be something that
            // happened because a field was left out.
            'post' => ['required', 'boolean'],

            // A retry after a timeout must not become a second document — M17.
            ...$this->clientRefRules(),

            // Optional, and legitimately so: a depreciation entry and a
            // correcting journal have no counterparty. That the party exists,
            // is not archived and belongs to this workshop is checked by the
            // posting engine, which every future entry point also passes
            // through — see PostingEngine::assertPartyUsable().
            'party_id' => ['nullable', 'integer', 'min:1'],

            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],

            // `decimal:0,2` rejects a third decimal place outright rather than
            // rounding it away — a client sending 100.005 has a bug, and
            // silently posting 100.01 hides it.
            'lines.*.debit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.credit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'A journal entry needs at least two lines — one account to debit and one to credit.',
            'lines.required' => 'A journal entry needs at least two lines — one account to debit and one to credit.',
            'date.date_format' => 'Give the transaction date as YYYY-MM-DD.',
            'lines.*.debit.decimal' => 'Amounts are in rupees and paise — at most two decimal places.',
            'lines.*.credit.decimal' => 'Amounts are in rupees and paise — at most two decimal places.',
        ];
    }

    /**
     * @return array{date: string, notes: string|null, post: bool, party_id: int|null, lines: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'date' => (string) $this->string('date'),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'post' => $this->boolean('post'),
            'client_ref' => $this->clientRef(),
            'party_id' => $this->filled('party_id') ? (int) $this->input('party_id') : null,
            'lines' => array_map(fn (array $line) => [
                'account_id' => (int) ($line['account_id'] ?? 0),
                // Absent means zero, which is how a line declares which side it
                // is on: a credit line simply has no debit.
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('lines', []))),
        ];
    }
}
