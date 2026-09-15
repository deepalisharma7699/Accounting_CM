<?php

namespace App\Http\Requests\Item;

use App\Services\Inventory\ItemService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new product, submitted from the one universal create form.
 *
 * ## Why the dynamic fields are not validated here
 *
 * `attributes` is a flat map whose allowed keys, types, options and bounds are
 * defined by rows in `item_attributes` — configured by the admin, different for
 * every category, and changing while the application runs. A form request cannot
 * see any of that, and a copy of the rules that lived here would be a second
 * source of truth that drifted the first time somebody added a field.
 *
 * So this checks *shape* — the payload is well-formed, the numbers are decimal,
 * nothing is a float on its way to a column — and
 * {@see \App\Services\Inventory\ItemVariantService::normaliseAttributes()} checks
 * *meaning*, against the category. That is also the only arrangement where the
 * importer and the capture agent are held to the same rules, since neither of
 * them comes through a form request.
 *
 * Whether the name is taken, whether the category may hold stock, and what the
 * unit defaults to are {@see ItemService}'s business, for the same reason.
 */
class StoreItemRequest extends FormRequest
{
    /** Matches DECIMAL(15, 2). */
    private const MAX_AMOUNT = '9999999999999.99';

    /**
     * How many variants one submission may declare.
     *
     * A generous ceiling rather than a considered one: a size run of fasteners is
     * legitimately thirty rows, and what this is really guarding is a client
     * looping. Each entry costs a row and, where it carries a quantity, a line on
     * one stock adjustment.
     */
    private const MAX_VARIANTS = 50;

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
            /* ---- The product ---- */

            'name' => ['required', 'string', 'min:2', 'max:180'],

            // The category decides what this product is asked to record, whether
            // it is counted in stock, and how it is taxed. That it exists, is
            // active and belongs to this workshop is checked in the service.
            'category_id' => ['required', 'integer', 'min:1'],

            'code' => ['nullable', 'string', 'max:40'],

            // A row of the Brand Master rather than a typed name — a typed name
            // is a master list nobody maintains, and "Crompton" spelled three
            // ways is three brands to a column and one to the shop. Null is a
            // legitimate answer: an unbranded bush is a real thing. That the id
            // resolves is the service's business.
            'brand_id' => ['nullable', 'integer', 'min:1'],

            // HSN for goods, SAC for services — the same field in the same
            // position on a GST invoice. Digits only, and 4 to 8 of them: the
            // shape every published code takes.
            'hsn_sac' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],

            // A percentage, not a fraction: 18, not 0.18. Capped at 100 because a
            // rate above that is a typo rather than a tax.
            'gst_rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],


            /*
            | Whether the price on the shelf is quoted with the GST already in it.
            |
            | A default for the bill form to prefill and nothing more — the flag
            | that decides a document's arithmetic is the one on its line. A shop
            | that prices parts at the figure printed on the box sets this once
            | and stops thinking about it; one that quotes before tax leaves it
            | alone, which is what every item has always meant.
            */
            'price_includes_tax' => ['nullable', 'boolean'],

            // A unit *code* rather than a fixed set: the Unit Master is a table
            // the admin edits, so the allowed values are not knowable here. The
            // service resolves it and falls back to the category's default.
            'base_uom' => ['nullable', 'string', 'max:20'],

            // Accepted but clamped by the service — a product of a category that
            // holds no stock can never be stocked, whatever is sent.
            'is_stock' => ['nullable', 'boolean'],

            // Set by an importer or the capture agent, not usually by a person.
            'is_draft' => ['nullable', 'boolean'],

            'description' => ['nullable', 'string', 'max:500'],
            'image_path' => ['nullable', 'string', 'max:255'],

            /* ---- The first variant, on the same form ---- */

            // Whether the caller means to create the first thing on the shelf
            // as well as the product. The universal form always does; an API
            // client adding a family to hang ratings off later does not, and
            // must not have a blank variant invented for it.
            'with_variant' => ['nullable', 'boolean'],

            'sku' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'variant_label' => ['nullable', 'string', 'max:180'],

            // Shape only — a flat map of short strings. Which keys are allowed
            // and which are required is the category's business; see the note
            // above. `max:120` is paired with StoreVariantRequest, which edits
            // what this creates — see the note there on why they must agree.
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:120'],

            // `decimal:0,2` rejects a third decimal place rather than rounding it
            // away — a client sending 100.005 has a bug, and quietly storing
            // 100.01 hides it.
            'sell_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            // Recorded for reference and for suggesting a price. **Never the
            // costing basis** — cost is the weighted average of what was actually
            // paid, derived from the stock movements, and a typed number that
            // fed into it would put a figure in the books that nobody paid.
            'purchase_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            'markup_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'reorder_level' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],

            /* ---- Opening stock ---- */

            // What is already on the shelf. Posted as a stock adjustment through
            // the ordinary engine — see ItemService::createWithVariants() — so
            // there is no second way for stock to come into existence. These are
            // the shorthand's; a repeater sends one pair per entry below.
            'opening_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'opening_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],

            // The day the shelf was counted, and it stays at the top level
            // whatever shape the variants arrive in: one submission posts one
            // stock adjustment, and a document has one date.
            'opening_date' => ['nullable', 'date_format:Y-m-d'],

            /* ---- Several variants, on the same form ---- */

            /*
            | The longhand. Every key inside an entry is the flat key above,
            | deliberately — `variant_label` included, ungainly as it reads here.
            | The two shapes are one shape: the flat keys are the one-variant
            | shorthand, and a divergence in a single key name is the kind of
            | thing that is discovered by an importer saving a hundred rows with
            | no label on any of them.
            |
            | Present *and* non-empty wins over the flat keys; see
            | ItemService::variantSpecs().
            */
            'variants' => ['nullable', 'array', 'max:'.self::MAX_VARIANTS],
            'variants.*' => ['array'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.variant_label' => ['nullable', 'string', 'max:180'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.attributes.*' => ['nullable', 'string', 'max:120'],
            'variants.*.sell_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'variants.*.markup_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'variants.*.reorder_level' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'variants.*.min_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'variants.*.opening_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'variants.*.opening_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'Choose a category. It decides what this product records, whether it is counted in stock, and how it is taxed.',
            'hsn_sac.regex' => 'An HSN or SAC code is 4 to 8 digits.',
            'gst_rate.between' => 'A GST rate is a percentage between 0 and 100 — 18, not 0.18.',
            'variants.max' => 'That is more variants than one product is added with. Save these, then add the rest from the product itself.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * The product's own fields.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'category_id' => $this->integer('category_id'),
            'code' => $this->filled('code') ? (string) $this->string('code') : null,
            'brand_id' => $this->filled('brand_id') ? $this->integer('brand_id') : null,
            'hsn_sac' => $this->filled('hsn_sac') ? trim((string) $this->string('hsn_sac')) : null,
            'gst_rate' => $this->filled('gst_rate') ? $this->input('gst_rate') : null,
            'price_includes_tax' => $this->boolean('price_includes_tax'),
            'base_uom' => $this->filled('base_uom') ? (string) $this->string('base_uom') : null,
            'is_stock' => $this->has('is_stock') ? $this->boolean('is_stock') : null,
            'is_draft' => $this->boolean('is_draft'),
            'description' => $this->filled('description') ? trim((string) $this->string('description')) : null,
            'image_path' => $this->filled('image_path') ? trim((string) $this->string('image_path')) : null,
        ];
    }

    /**
     * Everything the one-step create needs: the product, its first variant and
     * the opening stock, in one flat map.
     *
     * The service does the splitting rather than the client, so a form stays a
     * form and does not have to know that the catalogue has two levels in it.
     *
     * @return array<string, mixed>
     */
    public function universalPayload(): array
    {
        $payload = array_merge($this->payload(), [
            'with_variant' => $this->boolean('with_variant'),
            'sku' => $this->filled('sku') ? (string) $this->string('sku') : null,
            'barcode' => $this->filled('barcode') ? (string) $this->string('barcode') : null,
            'variant_label' => $this->filled('variant_label') ? trim((string) $this->string('variant_label')) : null,
            'attributes' => $this->input('attributes', []),
            'sell_price' => $this->filled('sell_price') ? $this->input('sell_price') : null,
            'purchase_price' => $this->filled('purchase_price') ? $this->input('purchase_price') : null,
            'markup_percent' => $this->filled('markup_percent') ? $this->input('markup_percent') : null,
            'reorder_level' => $this->filled('reorder_level') ? $this->input('reorder_level') : null,
            'min_stock' => $this->filled('min_stock') ? $this->input('min_stock') : null,
            'opening_stock' => $this->filled('opening_stock') ? $this->input('opening_stock') : null,
            'opening_cost' => $this->filled('opening_cost') ? $this->input('opening_cost') : null,
            'opening_date' => $this->filled('opening_date') ? (string) $this->string('opening_date') : null,
        ]);

        // Carried only when the caller sent it, because its *absence* is what
        // tells the service to read the flat keys as the one-variant shorthand.
        if ($this->has('variants')) {
            $payload['variants'] = $this->variantSpecs();
        }

        return $payload;
    }

    /**
     * Each declared variant, in the shape the flat keys make.
     *
     * Reduced to the keys the catalogue knows rather than passed through, so what
     * reaches the service is the same map whichever shape it arrived in — and so
     * an entry carrying `is_active` or `item_id` cannot smuggle either past a
     * layer that reads the array wholesale.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantSpecs(): array
    {
        $given = array_filter((array) $this->input('variants', []), 'is_array');

        return array_map(static function (array $variant): array {
            $value = static function (string $key) use ($variant): ?string {
                $given = $variant[$key] ?? null;

                return $given === null || $given === '' ? null : trim((string) $given);
            };

            return [
                'sku' => $value('sku'),
                'barcode' => $value('barcode'),
                'variant_label' => $value('variant_label'),
                'attributes' => is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [],
                'sell_price' => $value('sell_price'),
                'purchase_price' => $value('purchase_price'),
                'markup_percent' => $value('markup_percent'),
                'reorder_level' => $value('reorder_level'),
                'min_stock' => $value('min_stock'),
                'opening_stock' => $value('opening_stock'),
                'opening_cost' => $value('opening_cost'),
            ];
        }, array_values($given));
    }
}
