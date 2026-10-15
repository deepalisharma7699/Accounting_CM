<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rewriting a draft — a journal's lines, a settlement's split, or a bill: its
 * items, its terms and who did the work.
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
            'items' => ['sometimes', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:items.*.discount',
            ],
            'items.*.price_includes_tax' => ['nullable', 'boolean'],
            'items.*.memo' => ['nullable', 'string', 'max:255'],

            /*
            | The rest of a bill's vocabulary, with the rules StoreBillRequest
            | gives it — a draft being continued is the same document the create
            | form wrote, and an edit must be able to say everything the first
            | save could. Each is `sometimes` and an explicit null clears it,
            | because the service merges this over the stored request: a key the
            | edit left out would otherwise carry yesterday's discount into today's
            | bill. Sent to anything that is not a sale or a purchase, the service
            | refuses them rather than storing figures no template will read.
            */
            'vendor_invoice_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'combine_labour' => ['sometimes', 'boolean'],
            'bill_discount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'bill_discount_percent' => [
                'sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:bill_discount',
            ],
            'settle_total' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            // The vendor's printed total — a purchase's only. See StoreBillRequest.
            'vendor_total' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],

            // Who did the work — a sale's only, and refused on anything else by
            // WorkAttributionService. The null employee is load-bearing for the
            // reason StoreBillRequest gives: an emptied box has to remove a row.
            'staff' => ['sometimes', 'array', 'max:10'],
            'staff.*.designation_id' => ['required', 'integer', 'min:1'],
            'staff.*.employee_id' => ['nullable', 'integer', 'min:1'],

            // No `min:1`. A bill on credit has no split at all, and editing one
            // has to be able to say so; a settlement, which is meaningless
            // without one, is refused by its own template — the layer that knows
            // the type.
            'payments' => ['sometimes', 'array', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The wording StoreBillRequest uses, so a draft refused on edit reads the
     * same as the one refused when it was first written.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.min' => 'A bill needs at least one line saying what was supplied.',
            'items.*.quantity.gt' => 'Each line needs a quantity greater than zero.',
            'items.*.quantity.decimal' => 'Quantities go to three decimal places at most.',
            'items.*.unit_price.decimal' => 'Prices are in rupees and paise — at most two decimal places.',
            'items.*.discount_percent.prohibits' => 'Give a line discount in rupees or as a percentage, not both.',
            'items.*.discount_percent.max' => 'A line discount cannot be more than 100%.',
            'bill_discount_percent.prohibits' => 'Give the bill discount in rupees or as a percentage, not both.',
            'bill_discount_percent.max' => 'A discount cannot be more than 100% of the bill.',
            'date.date_format' => 'Give the bill date as YYYY-MM-DD.',
            'vendor_invoice_no.max' => 'The vendor invoice number can be at most 50 characters.',
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
            // The same mapping StoreBillRequest makes, key for key — a line that
            // lost its percentage or its inclusive-of-tax flag on the way through
            // an edit would post at a different total from the one it was saved at.
            $payload['items'] = array_map(fn (array $line) => [
                'item_id' => ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null),
                'variant_id' => ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null),
                'quantity' => $line['quantity'] ?? 0,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount' => ($line['discount'] ?? null) === '' ? null : ($line['discount'] ?? null),
                'discount_percent' => ($line['discount_percent'] ?? null) === ''
                    ? null
                    : ($line['discount_percent'] ?? null),
                'price_includes_tax' => array_key_exists('price_includes_tax', $line)
                    && $line['price_includes_tax'] !== null && $line['price_includes_tax'] !== ''
                    ? filter_var($line['price_includes_tax'], FILTER_VALIDATE_BOOLEAN)
                    : null,
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('items', [])));
        }

        if ($this->has('combine_labour')) {
            $payload['combine_labour'] = $this->boolean('combine_labour');
        }

        if ($this->has('vendor_invoice_no')) {
            $payload['vendor_invoice_no'] = $this->filled('vendor_invoice_no')
                ? trim((string) $this->string('vendor_invoice_no'))
                : null;
        }

        /*
        | The bill discount is one fact stated in either of two units, so it is
        | replaced as a pair. Merged key by key over the stored request, a draft
        | saved at ₹100 off and edited to 10% would carry both — and which of
        | them won would be a question nobody had answered.
        */
        if ($this->has('bill_discount') || $this->has('bill_discount_percent')) {
            $payload['bill_discount'] = $this->filled('bill_discount') ? $this->input('bill_discount') : null;
            $payload['bill_discount_percent'] = $this->filled('bill_discount_percent')
                ? $this->input('bill_discount_percent')
                : null;
        }

        if ($this->has('settle_total')) {
            $payload['settle_total'] = $this->filled('settle_total') ? (string) $this->input('settle_total') : null;
        }

        if ($this->has('vendor_total')) {
            $payload['vendor_total'] = $this->filled('vendor_total') ? (string) $this->input('vendor_total') : null;
        }

        if ($this->has('staff')) {
            $payload['staff'] = array_map(fn (array $pair) => [
                'designation_id' => $pair['designation_id'] ?? null,
                'employee_id' => ($pair['employee_id'] ?? null) === '' ? null : ($pair['employee_id'] ?? null),
            ], array_values((array) $this->input('staff', [])));
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
