<?php

namespace App\Http\Requests\Item;

use App\Services\Inventory\ItemVariantService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new variant of an item, or an edit to one.
 *
 * One class for both, because a variant has no required field of its own at the
 * HTTP layer: which attributes are demanded depends entirely on the *item's* type,
 * and this request cannot see it. That check lives in
 * {@see ItemVariantService::normaliseAttributes()}, where M11's importer and M15's
 * capture agent also pass through it.
 *
 * What is checked here is shape: attributes are a flat map of short strings, prices
 * are decimal and non-negative, and nothing is a float on its way to a column.
 *
 * **Every column a variant has is named here**, and that is the rule rather than
 * an inventory. `barcode`, `min_stock` and `purchase_price` were absent for as
 * long as the columns existed: the table held them, the model filled them, the
 * service read and wrote them, and this class dropped them on the way in — so
 * they could be set by the universal create form and by nothing afterwards. A
 * field a request forgets is not a validation gap, it is a field the product
 * does not have.
 */
class StoreVariantRequest extends FormRequest
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
            'sku' => ['nullable', 'string', 'max:60'],

            // Scanned at the counter, so it has to resolve to exactly one thing.
            // Unique when present, which the service enforces — the same rule as
            // a SKU, for the reason a barcode identifying two products sells the
            // wrong one.
            'barcode' => ['nullable', 'string', 'max:64'],

            'label' => ['nullable', 'string', 'max:180'],

            // A flat map — "5", not ["5"] and not {"value": "5"}. Which keys are
            // allowed and which are required is the item type's business.
            //
            // `max:120` matches StoreItemRequest, and the pairing is load-bearing
            // rather than tidy: the universal create writes the first variant and
            // this class edits it afterwards. At 60 here, a frame size typed on
            // the create form came back 422 the first time anybody corrected the
            // price beside it — a refusal on a field they had not touched, about a
            // value this application had itself stored.
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:120'],

            // `decimal:0,2` rejects a third decimal place rather than rounding it
            // away — a client sending 100.005 has a bug, and quietly storing
            // 100.01 hides it.
            'sell_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            // What the workshop expects to pay. A reference figure beside the one
            // it charges, and **never a cost**: what stock was really bought at is
            // the weighted average of the movements, and nothing reads this into a
            // valuation or prefills a purchase line from it. See the column's
            // migration for why both of those would be wrong.
            'purchase_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            // A percentage over cost, used to suggest a price once M8 knows the
            // average. Not capped at 100: a 300% markup on a low-value part is
            // ordinary in the trade.
            'markup_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],

            // A quantity, so three decimals — copper is reordered at 12.5 kg.
            // Rounded to the unit's own scale by the service, which is where a
            // fractional bearing is caught.
            'reorder_level' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],

            // The hard floor, distinct from the reorder level above it: one is
            // "order more when it reaches this" and the other is "never let it
            // fall below this". A shop orders at 20 and panics at 5.
            'min_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],

            'is_draft' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attributes.array' => 'Attributes are a set of named values, like {"hp": "5", "rpm": "1440"}.',
            'sell_price.decimal' => 'Prices are in rupees and paise — at most two decimal places.',
            'purchase_price.decimal' => 'Prices are in rupees and paise — at most two decimal places.',
        ];
    }

    /**
     * The SKU is upper-cased and the barcode deliberately is not.
     *
     * A SKU is something a person types, so folding its case is what makes
     * "bl-6205" and "BL-6205" the same code. A barcode is something a scanner
     * emits, and folding it would make the stored value stop matching the label
     * it was read from. {@see ItemVariantService::normaliseBarcode()} trims it
     * and leaves it otherwise alone, which is the whole of the rule.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('sku')) {
            $this->merge(['sku' => strtoupper(trim((string) $this->input('sku')))]);
        }
    }

    /**
     * Only the keys the caller sent, so a PATCH that changes a price alone leaves
     * the attributes exactly as they were.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['sku', 'barcode', 'label'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->filled($field) ? trim((string) $this->input($field)) : null;
            }
        }

        if ($this->has('attributes')) {
            $payload['attributes'] = (array) $this->input('attributes', []);
        }

        foreach (['sell_price', 'purchase_price', 'markup_percent', 'reorder_level', 'min_stock'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->filled($field) ? $this->input($field) : null;
            }
        }

        foreach (['is_draft', 'is_active'] as $flag) {
            if ($this->has($flag)) {
                $payload[$flag] = $this->boolean($flag);
            }
        }

        return $payload;
    }
}
