<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMode;
use App\Services\Accounting\PostingEngine;
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

            // Explicit, and never defaulted to true — the same rule as every
            // other transaction.
            'post' => ['required', 'boolean'],

            // Required. An invoice made out to nobody cannot be collected, and a
            // purchase owed to nobody sits in a control account no statement can
            // account for. Whether they hold the right role is the engine's check.
            'party_id' => ['required', 'integer', 'min:1'],

            // The cap is generous but finite. Fifty lines is a long bill; an
            // unbounded array is an unbounded number of ledger lines and stock
            // movements in one transaction.
            'items' => ['required', 'array', 'min:1', 'max:50'],
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
            'items.*.discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.memo' => ['nullable', 'string', 'max:255'],

            // Optional, unlike a settlement's. A sale on thirty-day terms carries
            // none; a counter sale carries the whole invoice. What it may not do
            // is exceed the document — the engine refuses that.
            'payments' => ['nullable', 'array', 'max:20'],
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
            'party_id.required' => 'Say who this bill is with.',
            'items.required' => 'A bill needs at least one line saying what was supplied.',
            'items.min' => 'A bill needs at least one line saying what was supplied.',
            'items.*.quantity.gt' => 'Each line needs a quantity greater than zero.',
            'items.*.quantity.decimal' => 'Quantities go to three decimal places at most.',
            'items.*.unit_price.decimal' => 'Prices are in rupees and paise — at most two decimal places.',
            'date.date_format' => 'Give the bill date as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array{date: string, notes: string|null, post: bool, party_id: int, items: array<int, array<string, mixed>>, payments: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'date' => (string) $this->string('date'),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'post' => $this->boolean('post'),
            'party_id' => (int) $this->input('party_id'),
            'items' => array_map(fn (array $line) => [
                'item_id' => ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null),
                'variant_id' => ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null),
                'quantity' => $line['quantity'] ?? 0,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount' => ($line['discount'] ?? null) === '' ? null : ($line['discount'] ?? null),
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('items', []))),
            'payments' => array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', []))),
        ];
    }
}
