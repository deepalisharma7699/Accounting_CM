<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rewriting a draft — a journal's lines, or a settlement's split.
 *
 * There is no equivalent for a posted transaction and there never will be: the
 * service refuses anything that has reached the ledger, and so does the model.
 * Corrections are reversing entries.
 *
 * One class for both shapes, where creating them takes two endpoints. The
 * asymmetry is deliberate: on a POST every field is required and the two payloads
 * have nothing in common, so a single endpoint would have to validate each half
 * conditionally and would end up validating neither. On a PATCH every field is
 * optional *by nature* — "leave what I did not mention alone" — so each shape is
 * still fully validated whenever it is present, and the service takes whichever
 * one the transaction's type actually uses. Sending `lines` for a payment draft is
 * refused by the service, which is the only place that knows the type.
 *
 * `post` is absent here — authorising a draft is its own action, `POST
 * /transactions/{id}/post`, so that saving an edit can never commit it by
 * accident.
 */
class UpdateTransactionRequest extends FormRequest
{
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
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],

            // `sometimes, nullable`: absent leaves the counterparty alone, an
            // explicit null detaches it. A settlement cannot in fact be detached
            // from its party — the engine refuses that — which is the right place
            // for the rule, since the type is what makes it true.
            'party_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // `sometimes`, so a PATCH that only moves the date keeps the lines
            // it did not mention.
            'lines' => ['sometimes', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.credit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],

            // A bill's own lines — M9. `sometimes` for the same reason as
            // `lines`: a PATCH that only moves the date keeps what it did not
            // mention. Sending these to a type that has no items is refused by
            // the service rather than ignored, because a caller told their edit
            // saved while nothing changed is worse off than one told it was not.
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.memo' => ['nullable', 'string', 'max:255'],

            'payments' => ['sometimes', 'array', 'min:1', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        if ($this->has('date')) {
            $payload['date'] = (string) $this->string('date');
        }

        if ($this->has('notes')) {
            $payload['notes'] = $this->filled('notes') ? trim((string) $this->string('notes')) : null;
        }

        if ($this->has('party_id')) {
            $payload['party_id'] = $this->filled('party_id') ? (int) $this->input('party_id') : null;
        }

        if ($this->has('lines')) {
            $payload['lines'] = array_map(fn (array $line) => [
                'account_id' => (int) ($line['account_id'] ?? 0),
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('lines', [])));
        }

        if ($this->has('items')) {
            $payload['items'] = array_map(fn (array $line) => [
                'item_id' => ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null),
                'variant_id' => ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null),
                'quantity' => $line['quantity'] ?? 0,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount' => ($line['discount'] ?? null) === '' ? null : ($line['discount'] ?? null),
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('items', [])));
        }

        if ($this->has('payments')) {
            $payload['payments'] = array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', [])));
        }

        return $payload;
    }
}
