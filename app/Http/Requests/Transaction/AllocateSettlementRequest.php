<?php

namespace App\Http\Requests\Transaction;

use App\Services\Accounting\SettlementService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-pointing a posted receipt at the bills it settles — M16.
 *
 * Safe in a way that re-posting a transaction never is, and that is why it has a
 * route of its own: an allocation writes no journal entry, moves no balance and
 * changes no total. The money arrived when the receipt was posted and stays
 * exactly where it landed; all this decides is which invoice the workshop
 * considers it to have discharged. Getting that wrong is a clerical matter, so
 * correcting it is one too — no reversal, no counter-entry.
 *
 * Shape only. That each bill exists, belongs to this party, is posted, and has
 * that much left owing are all {@see SettlementService}'s business.
 */
class AllocateSettlementRequest extends FormRequest
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
            // Optional, and the omission means "oldest first" — see
            // SettlementService::planOldestFirst(). An empty array means the
            // same thing rather than "allocate nothing", because there is no
            // useful reading of a request to do nothing at all.
            'allocations' => ['nullable', 'array', 'max:100'],
            'allocations.*.bill_transaction_id' => ['required', 'integer', 'min:1'],
            // Left off means "whatever is still owing on this one" — what
            // ticking an invoice off a list actually means.
            'allocations.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allocations.*.bill_transaction_id.required' => 'Say which bill each amount is for.',
            'allocations.*.amount.gt' => 'An allocation needs an amount greater than zero.',
            'allocations.*.amount.decimal' => 'Amounts are in rupees and paise — at most two decimal places.',
        ];
    }

    /**
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
}
