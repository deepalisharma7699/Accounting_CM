<?php

namespace App\Http\Requests\Transaction;

use App\Services\Accounting\PostingEngine;
use App\Services\Inventory\StockLedgerService;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A stock-take correction: what the count actually found.
 *
 * Shape only. That the variant exists in this workshop, that its item is
 * inventoried at all, that a fractional quantity is legal for its unit, and that
 * the resulting Inventory line agrees with the movements to the paise are all
 * {@see StockLedgerService} and {@see PostingEngine}'s business — M11's importer
 * and M15's capture agent adjust stock without passing through a controller, and
 * a rule enforced here would say nothing about either of them.
 *
 * Note that `quantity` is **signed**, and that the sign is the whole point: a
 * count that found two fewer bearings and one more motor is one document with
 * two lines pointing opposite ways.
 */
class StoreStockAdjustmentRequest extends FormRequest
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

            // Explicit, and never defaulted to true — the same rule as every
            // other transaction. Committing to the ledger must not be something
            // that happened because a field was left out.
            'post' => ['required', 'boolean'],

            // A retry after a timeout must not become a second document — M17.
            ...$this->clientRefRules(),

            'adjustments' => ['required', 'array', 'min:1', 'max:200'],
            'adjustments.*.variant_id' => ['required', 'integer', 'min:1'],

            // Signed and non-zero. `decimal:0,3` rejects a fourth decimal place
            // rather than rounding it away; whether even three are meaningful is
            // the *unit's* business, and refusing half a bearing happens in the
            // service where the unit is known.
            'adjustments.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'not_in:0'],

            // Only read for stock that was *found*. A shortage is written off at
            // what the books were carrying it at, which is not the counter's
            // number to choose.
            'adjustments.*.unit_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            'adjustments.*.memo' => ['nullable', 'string', 'max:180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'adjustments.required' => 'Say what the count found — at least one variant and the difference.',
            'adjustments.min' => 'Say what the count found — at least one variant and the difference.',
            'adjustments.*.quantity.not_in' => 'A difference of zero is not a correction. Leave the line out.',
            'adjustments.*.quantity.decimal' => 'Quantities go to three decimal places at most.',
            'date.date_format' => 'Give the transaction date as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array{date: string, notes: string|null, post: bool, adjustments: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'date' => (string) $this->string('date'),
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
            'post' => $this->boolean('post'),
            'client_ref' => $this->clientRef(),
            'adjustments' => array_map(fn (array $row) => [
                'variant_id' => (int) ($row['variant_id'] ?? 0),
                'quantity' => $row['quantity'] ?? 0,
                'unit_cost' => ($row['unit_cost'] ?? null) === '' ? null : ($row['unit_cost'] ?? null),
                'memo' => $row['memo'] ?? null,
            ], array_values((array) $this->input('adjustments', []))),
        ];
    }
}
