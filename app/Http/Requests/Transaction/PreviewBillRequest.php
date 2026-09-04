<?php

namespace App\Http\Requests\Transaction;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "What will this bill come to?" — M17, and the brief's §12.
 *
 * The same payload the sale and purchase routes take, minus everything that only
 * matters to a posting: no `post` flag, no `client_ref`, no payment split.
 * Deliberately, and for the reason the opening-balance module gives `preview` a
 * verb of its own — a request that writes nothing should not carry the fields
 * that would make it write something, so there is no arrangement in which a
 * missing boolean turns a question into a commitment.
 *
 * `type` is a field here where it is the URL everywhere else, because a preview
 * is one endpoint answering about several document types and there is nothing
 * to authorise differently between them.
 */
class PreviewBillRequest extends FormRequest
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
            // Only the types that *are* documents. Previewing a journal would be
            // previewing the lines the caller just typed.
            'type' => ['required', Rule::in([TransactionType::Sale->value, TransactionType::Purchase->value])],

            'date' => ['nullable', 'date_format:Y-m-d'],

            // Optional, unlike on a posting. The counter screen prices the
            // basket before it knows who is buying, and refusing to answer until
            // a customer is chosen would make the running total appear late —
            // which is the moment it is least useful.
            //
            // It does change the answer where it is given: the party's state code
            // decides CGST+SGST against IGST.
            'party_id' => ['nullable', 'integer', 'min:1'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount' => ['nullable', 'numeric', 'gte:0', 'max:'.self::MAX_AMOUNT],

            // Rupees or a percentage, never both — and resolved on the server
            // either way, which is the point of previewing at all. See
            // BillDiscount, and StoreBillRequest, which refuses the same pair.
            'items.*.discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:items.*.discount',
            ],

            'items.*.memo' => ['nullable', 'string', 'max:255'],

            // The discount on the whole bill, apportioned across the lines
            // before tax. Previewed through the same BillTemplate the posting
            // uses, so the figure quoted is the figure charged.
            'bill_discount' => ['nullable', 'numeric', 'gte:0', 'max:'.self::MAX_AMOUNT],
            'bill_discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:bill_discount',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item to see what the bill comes to.',
            'items.*.quantity.gt' => 'Each line needs a quantity greater than zero.',
            'items.*.discount_percent.prohibits' => 'Give a line discount in rupees or as a percentage, not both.',
            'bill_discount_percent.prohibits' => 'Give the bill discount in rupees or as a percentage, not both.',
        ];
    }

    public function type(): TransactionType
    {
        return TransactionType::from((string) $this->string('type'));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            // Today where none was given. The date only reaches the tax rate and
            // the place of supply, neither of which is dated in Phase 1 — but a
            // template that reads it should never be handed a null.
            'date' => $this->filled('date') ? (string) $this->string('date') : now()->toDateString(),
            'party_id' => $this->filled('party_id') ? (int) $this->input('party_id') : null,
            'bill_discount' => $this->filled('bill_discount') ? $this->input('bill_discount') : null,
            'bill_discount_percent' => $this->filled('bill_discount_percent')
                ? $this->input('bill_discount_percent')
                : null,
            'items' => array_map(fn (array $line) => [
                'item_id' => ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null),
                'variant_id' => ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null),
                'quantity' => $line['quantity'] ?? 0,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount' => ($line['discount'] ?? null) === '' ? null : ($line['discount'] ?? null),
                'discount_percent' => ($line['discount_percent'] ?? null) === ''
                    ? null
                    : ($line['discount_percent'] ?? null),
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('items', []))),
        ];
    }
}
