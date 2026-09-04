<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTransactionRequest extends FormRequest
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
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            /*
            | Several types at once, because a screen's idea of "sales" is wider
            | than one enum case: collecting money from a customer belongs beside
            | the invoice it settles, not on a separate page. Validated
            | case-by-case rather than left open — an unknown type silently
            | dropped would show a shorter list to somebody who believes they
            | are looking at a complete one.
            */
            'types' => ['nullable', 'array', 'max:'.count(TransactionType::cases())],
            'types.*' => [Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'source' => ['nullable', Rule::enum(TransactionSource::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Everything that touched one account — the drill-down from a
            // ledger line back to the events behind it.
            'account_id' => ['nullable', 'integer', 'min:1'],
            // Everything one counterparty was involved in — the drill-down
            // behind a party statement.
            'party_id' => ['nullable', 'integer', 'min:1'],
            /*
            | Where a bill stands against what has been paid on it — M16.
            | Derived on the fly from the document's total, its own counter
            | payments and the receipts allocated to it, so this filter is
            | computed in SQL rather than read from a column: filtering the
            | current page in PHP would produce a page count that disagreed with
            | the rows on it.
            */
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            // "Show me only what is still owed" — the toggle above a bills list,
            // and a shorthand for every payment status except paid.
            'outstanding' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['date', 'total', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'type' => $this->input('type'),
            // Both are honoured together and narrow each other, so
            // `?type=sale&types[]=receipt` returns nothing rather than
            // quietly ignoring one of the two.
            'types' => $this->filled('types') ? array_values((array) $this->input('types')) : null,
            'status' => $this->input('status'),
            'source' => $this->input('source'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            'account_id' => $this->filled('account_id') ? (int) $this->input('account_id') : null,
            'party_id' => $this->filled('party_id') ? (int) $this->input('party_id') : null,
            'payment_status' => $this->input('payment_status'),
            // Absent rather than false when the toggle is off, so it narrows
            // nothing — `outstanding=0` is "I did not ask", not "show me the
            // settled ones", which is what `payment_status=paid` is for.
            'outstanding' => $this->boolean('outstanding') ?: null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
