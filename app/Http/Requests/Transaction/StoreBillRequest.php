<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use App\Services\Accounting\PostingEngine;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A sale or a purchase: who, when, what was supplied, and what was settled now.
 *
 * One request class for both, because the payload is genuinely identical — the
 * direction is stated by the route, not by a field. The same choice
 * {@see StoreSettlementRequest} makes, and the opposite of the one that gives
 * journals and settlements separate endpoints: there the payloads have nothing in
 * common, so one endpoint would validate neither.
 *
 * Shape only. Everything that makes it a *bill* — that the item exists in this
 * workshop, that a stocked item names a variant, that half a bearing is not a
 * quantity, that the tax follows the item's rate and the two state codes, that
 * the split does not exceed the invoice — belongs to the bill template and
 * {@see PostingEngine}, which M11's importer and M15's capture agent also pass
 * through.
 */
class StoreBillRequest extends FormRequest
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

            // The number on the vendor's own bill, where they gave one. A
            // purchase's only; the engine drops it from anything else.
            'vendor_invoice_no' => ['nullable', 'string', 'max:50'],

            // Show the labour as one row on the customer's copy. A sale's only;
            // the engine ignores it on a purchase, and leaves it off if unsent.
            'combine_labour' => ['sometimes', 'boolean'],

            // Explicit, and never defaulted to true — the same rule as every
            // other transaction.
            'post' => ['required', 'boolean'],

            // A retry after a timeout must not become a second document — M17.
            ...$this->clientRefRules(),

            // Optional here, and decided by the engine. A purchase owed to nobody
            // sits in a control account no statement can account for, so it is
            // refused there; a sale may name nobody when the customer would not
            // give a name, provided it is paid in full at the counter. Whether a
            // named party holds the right role is the engine's check too.
            'party_id' => ['nullable', 'integer', 'min:1'],

            // The cap is generous but finite, and the same as the preview's: a
            // supplier's monthly bill can run past fifty lines, while an
            // unbounded array is an unbounded number of ledger lines and stock
            // movements in one transaction.
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],

            // Three decimals, because copper is weighed to the gram. Whether a
            // fraction is meaningful at all is the unit's business, and refusing
            // half a bearing happens where the unit is known.
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],

            // Zero is allowed: a part fitted free under warranty is a real line,
            // and leaving it off the bill would take the stock without recording
            // why.
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            /*
            | A discount, stated as rupees off or as a percentage of the line.
            |
            | One or the other, never both — "₹100 or 10%?" is a question the
            | payload has failed to answer, and picking one silently is how a
            | bill comes to carry a discount nobody chose. `prohibits` refuses
            | the pair outright, per line.
            |
            | The percentage is resolved on the server, against the line's own
            | gross — see BillDiscount. A form that worked it out itself would be
            | applying JavaScript's float arithmetic to a customer's invoice.
            */
            'items.*.discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:items.*.discount',
            ],


            /*
            | Whether the rate above already has the GST in it.
            |
            | Absent means "ask the item", which is how a caller that predates the
            | toggle still gets the treatment the workshop set on the product.
            | Sent explicitly, it wins — the form prefills it from the item and
            | the operator may flip a line, because a shop selling parts at their
            | printed price still quotes the odd job before tax. See BillLine.
            */
            'items.*.price_includes_tax' => ['nullable', 'boolean'],

            'items.*.memo' => ['nullable', 'string', 'max:255'],

            /*
            | Money off the whole bill, on top of anything taken off a line.
            |
            | Apportioned across the lines *before* tax, so each line's GST falls
            | with it — see BillDiscount, which explains why a discount cannot
            | simply sit on the footer of a document whose lines are taxed at
            | different rates.
            |
            | Rupees or a percentage, and refused as a pair for the same reason a
            | line's is.
            */
            'bill_discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'bill_discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:bill_discount',
            ],

            /*
            | What the party actually paid, where it was less than the bill came
            | to and the counter called it square — "₹1,250, they paid ₹1,200,
            | mark it paid in full".
            |
            | The difference is taken off as a bill discount *before* tax, so the
            | GST falls with it: the discount was agreed at the moment of sale and
            | is on the invoice, which is exactly when the tax follows it. How
            | large a discount that takes is solved on the server, against the
            | lines it is about to tax — see BillTemplate::withBillDiscount(). The
            | payments must come to this figure; the template refuses otherwise.
            */
            'settle_total' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],

            /*
            | The grand total printed on the vendor's bill — a purchase's only.
            |
            | The vendor rounded it, up or down, and what is owed is what they
            | printed; the difference from the lines is booked to Round Off. The
            | template refuses a figure more than a rupee from the lines, because
            | that is a mismatched line rather than a rounding. See BillTemplate::charge().
            */
            'vendor_total' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],

            // Optional, unlike a settlement's. A sale on thirty-day terms carries
            // none; a counter sale carries the whole invoice. What it may not do
            // is exceed the document — the engine refuses that.
            'payments' => ['nullable', 'array', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            /*
            | Who did the work — M22, and a sale only.
            |
            | Shape only, like everything else here. Which trades this workshop
            | asks about, whether the person is on its staff list, and the refusal
            | of the whole idea on a purchase all belong to
            | {@see \App\Services\Staff\WorkAttributionService} — the same
            | division this class already draws for items and tax.
            |
            | The cap is the number of designations a workshop could plausibly
            | tick, not a business rule. Two is the ordinary case.
            */
            'staff' => ['nullable', 'array', 'max:10'],
            'staff.*.designation_id' => ['required', 'integer', 'min:1'],

            /*
            | Nullable, and the null is load-bearing.
            |
            | `{designation_id: 4, employee_id: null}` means "the Winder box is
            | empty" — which on a correction has to be able to *remove* a row.
            | Omitting the entry entirely would be indistinguishable from a client
            | that does not know about winders, and the two must not be treated
            | the same way. See the service's sync().
            */
            'staff.*.employee_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'A bill needs at least one line saying what was supplied.',
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
     * @return array{date: string, notes: string|null, vendor_invoice_no: string|null, combine_labour: bool, post: bool, party_id: int|null, bill_discount: mixed, bill_discount_percent: mixed, settle_total: string|null, vendor_total: string|null, items: array<int, array<string, mixed>>, staff: array<int, array<string, mixed>>, payments: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'date' => (string) $this->string('date'),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'vendor_invoice_no' => $this->filled('vendor_invoice_no')
                ? trim((string) $this->string('vendor_invoice_no'))
                : null,
            'combine_labour' => $this->boolean('combine_labour'),
            'post' => $this->boolean('post'),
            'client_ref' => $this->clientRef(),
            'party_id' => $this->filled('party_id') ? (int) $this->input('party_id') : null,
            'bill_discount' => $this->filled('bill_discount') ? $this->input('bill_discount') : null,
            'bill_discount_percent' => $this->filled('bill_discount_percent')
                ? $this->input('bill_discount_percent')
                : null,
            'settle_total' => $this->filled('settle_total') ? (string) $this->input('settle_total') : null,
            'vendor_total' => $this->filled('vendor_total') ? (string) $this->input('vendor_total') : null,
            'items' => array_map(fn (array $line) => [
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
            ], array_values((array) $this->input('items', []))),
            'staff' => array_map(fn (array $pair) => [
                'designation_id' => $pair['designation_id'] ?? null,
                'employee_id' => ($pair['employee_id'] ?? null) === '' ? null : ($pair['employee_id'] ?? null),
            ], array_values((array) $this->input('staff', []))),
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
        ];
    }
}
