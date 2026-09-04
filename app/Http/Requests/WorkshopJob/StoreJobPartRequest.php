<?php

namespace App\Http\Requests\WorkshopJob;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing a part onto the job — §17.
 *
 * The same shape a bill line takes, deliberately: an item or a variant, a
 * quantity, a price and a discount. That is what makes generating the invoice a
 * copy rather than a translation, and it means a fitter and a counter clerk are
 * filling in the same form twice rather than two different ones.
 *
 * Whether the item exists, whether a stocked family needs a specification and
 * whether half a bearing is a quantity all belong to
 * {@see \App\Services\Workshop\JobService} — the same division every store
 * request here makes.
 */
class StoreJobPartRequest extends FormRequest
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
            'item_id' => ['nullable', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],

            // Three decimals, because copper is weighed to the gram. Whether a
            // fraction is meaningful at all is the unit's business, and refusing
            // half a bearing happens where the unit is known.
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],

            // Zero is allowed: a part fitted free under warranty is a real line,
            // and leaving it off the job would take the stock without recording
            // why. Absent falls back to the variant's list price.
            'unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.gt' => 'A part needs a quantity greater than zero.',
            'quantity.decimal' => 'Quantities go to three decimal places at most.',
            'unit_price.decimal' => 'Prices are in rupees and paise — at most two decimal places.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'item_id' => $this->filled('item_id') ? (int) $this->input('item_id') : null,
            'variant_id' => $this->filled('variant_id') ? (int) $this->input('variant_id') : null,
            'quantity' => $this->input('quantity'),
            // Null rather than zero where it was left off, so the service can
            // tell "charge nothing for this" from "use the list price".
            'unit_price' => $this->filled('unit_price') ? $this->input('unit_price') : null,
            'discount' => $this->input('discount', 0),
            'memo' => $this->input('memo'),
        ];
    }
}
