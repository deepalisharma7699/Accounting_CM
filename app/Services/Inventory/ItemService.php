<?php

namespace App\Services\Inventory;

use App\Enums\TransactionType;
use App\Exceptions\Accounting\ItemInUseException;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\User;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Services\Accounting\TransactionService;
use App\Support\Units\UnitRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maintaining the catalogue: what the shop deals in.
 *
 * Nothing here posts anything and nothing here holds a quantity or a cost. Stock
 * on hand and weighted average cost are sums over `stock_movements`; this class is
 * concerned with the record — its name, its category, its tax identity, and
 * whether it may be removed.
 *
 * Two rules are enforced here rather than in a form request, because the importer
 * and the capture agent create items without passing through one:
 *
 *   * **a product of a category that holds no stock can never be stocked.** An
 *     hour is produced at the moment it is sold, and an opening balance of forty
 *     hours would be inventing an asset;
 *   * **the unit and the category are fixed once set.** Changing either would
 *     silently reinterpret every quantity and every bill line already recorded.
 *
 * ## The one-step create
 *
 * {@see createWithVariant()} is the universal form's endpoint: one submission
 * produces the product *and* the first thing on the shelf, because "add a
 * Crompton 5 HP motor" is one act and making somebody do it in two screens is how
 * a catalogue ends up full of families with no variants under them. The two-step
 * path is still there and still used — adding a second size to a shirt that
 * already exists must not mean re-typing its tax details.
 */
class ItemService
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
        private readonly StockLedgerService $stock,
        private readonly TransactionLineRepositoryInterface $documentLines,
        private readonly ItemCategoryService $categories,
        private readonly ItemBrandService $brands,
        private readonly ItemVariantService $variants,
        private readonly TransactionService $transactions,
        private readonly UnitRegistry $units,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        // "Motors" on the filter means motors and the four kinds of motor under
        // it. A filter that matched only the exact category would hide every
        // product the moment somebody organised their catalogue properly.
        if (filled($filters['category_id'] ?? null)) {
            $filters['category_ids'] = $this->categories->descendantIds((int) $filters['category_id']);
        }

        return $this->items->paginate($filters, $perPage);
    }

    /**
     * @return Collection<int, Item>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return $this->items->all($activeOnly);
    }

    public function find(int $id): Item
    {
        return $this->items->findById($id)
            ?? throw new ResourceNotFoundException('Item', $id);
    }

    public function findWithVariants(int $id): Item
    {
        return $this->items->findWithVariants($id)
            ?? throw new ResourceNotFoundException('Item', $id);
    }

    public function draftCount(): int
    {
        return $this->items->draftCount();
    }

    /* ---------------------------------------------------------------------
     | The universal create
     |-------------------------------------------------------------------- */

    /**
     * One form, one submission: the product, its first variant, and — where the
     * shop already has some on the shelf — the opening stock.
     *
     * All three inside one database transaction, because the halves are not
     * independently useful. A product with no variant cannot be sold, priced or
     * counted; a variant whose opening stock failed to post would show zero on
     * the shelf while the shop has five, which is a figure somebody would act on.
     *
     * ## Why opening stock posts a stock adjustment
     *
     * Because that is what it *is*: the shop asserting what is physically there,
     * which is the authority the books answer to. It goes through
     * {@see TransactionService} exactly as the stock screen's own adjustment
     * does — same posting engine, same valuation, same ledger entry — so there is
     * no second way for stock to come into existence. Reimplementing it here
     * would be the one thing this codebase refuses everywhere else.
     *
     * The opening stock is skipped rather than refused when the caller cannot
     * post transactions. Cataloguing is an ITEMS grant and posting is a
     * TRANSACTIONS one; a clerk who may add a bearing but not write to the ledger
     * should still be able to add the bearing, and be told plainly that the
     * quantity was not recorded.
     *
     * @param  array<string, mixed>  $data
     * @return array{item: Item, variant: ItemVariant|null, opening_posted: bool, opening_skipped_reason: string|null}
     */
    public function createWithVariant(array $data, ?User $actor = null, bool $mayPostStock = true): array
    {
        $category = $this->resolveCategory($data['category_id'] ?? null);

        $openingQuantity = $this->trimmed($data['opening_stock'] ?? null);
        $wantsOpening = $openingQuantity !== null && (float) $openingQuantity > 0;

        $result = DB::transaction(function () use ($data, $category, $actor, $wantsOpening, $openingQuantity, $mayPostStock) {
            $item = $this->create($data);

            /*
            | Whether this submission is creating a *thing on the shelf* as well
            | as a product, and why it is asked rather than assumed.
            |
            | The universal form always is: it collects the specification, the
            | SKU and the price on the same screen, and says so with
            | `with_variant`. An API client adding a family it will hang four
            | ratings off later is not — and forcing a variant on it would mean
            | inventing one with no specification, which for a category that
            | demands HP and phase is a record that cannot be saved and, for one
            | that does not, a blank row nobody asked for.
            |
            | So: a variant is created when the caller supplied something for one,
            | or when it said outright that it meant to.
            */
            $variant = $this->hasVariantData($data) || ($data['with_variant'] ?? false)
                ? $this->variants->create($item, $this->variantPayload($data))
                : null;

            if (! $wantsOpening || $variant === null) {
                return ['item' => $item, 'variant' => $variant, 'opening_posted' => false, 'opening_skipped_reason' => null];
            }

            if (! $item->tracksStock()) {
                return [
                    'item' => $item,
                    'variant' => $variant,
                    'opening_posted' => false,
                    'opening_skipped_reason' => 'This category does not hold stock, so an opening quantity would be an asset that does not exist.',
                ];
            }

            if (! $mayPostStock) {
                return [
                    'item' => $item,
                    'variant' => $variant,
                    'opening_posted' => false,
                    'opening_skipped_reason' => 'The product was saved, but recording opening stock needs permission to write transactions. Ask somebody who has it to record the quantity.',
                ];
            }

            $this->postOpeningStock($variant, $openingQuantity, $data, $actor);

            return ['item' => $item, 'variant' => $variant, 'opening_posted' => true, 'opening_skipped_reason' => null];
        });

        Log::info('items.created_with_variant', [
            'item_id' => $result['item']->id,
            'variant_id' => $result['variant']?->id,
            'opening_posted' => $result['opening_posted'],
        ]);

        return $result;
    }

    /**
     * Record what is already on the shelf, through the engine everything else
     * uses.
     *
     * A stock adjustment rather than an `opening` transaction, and the difference
     * is not cosmetic: an opening balance is the go-live declaration, posted
     * against Opening Balance Equity, and routing a product added in November
     * through it would restate what the shop was worth in April. An adjustment is
     * the shop saying what is on the shelf *today*, which is exactly the claim
     * being made.
     *
     * @param  array<string, mixed>  $data
     */
    private function postOpeningStock(ItemVariant $variant, string $quantity, array $data, ?User $actor): void
    {
        $this->transactions->create(TransactionType::StockAdjustment, [
            'date' => $data['opening_date'] ?? now()->toDateString(),
            'notes' => sprintf('Opening stock for %s', $variant->displayLabel()),
            'post' => true,
            'adjustments' => [[
                'variant_id' => (int) $variant->id,
                'quantity' => $quantity,
                // What the shop says the stock cost. Falls back to the purchase
                // price on the form, because that is the number they just typed
                // and the honest answer to "what is this worth". Never zero by
                // default: stock valued at nothing reports a 100% margin on the
                // first sale.
                'unit_cost' => $this->trimmed($data['opening_cost'] ?? null)
                    ?? $this->trimmed($data['purchase_price'] ?? null),
                'memo' => 'Opening stock recorded when the product was created',
            ]],
        ], $actor);
    }

    /**
     * Whether the submission carried anything variant-shaped at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasVariantData(array $data): bool
    {
        foreach (['sku', 'barcode', 'variant_label', 'sell_price', 'attributes'] as $key) {
            $value = $data[$key] ?? null;

            if (is_array($value) ? $value !== [] : $this->trimmed($value) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variant half of a universal-form submission.
     *
     * The form is one flat set of fields, so the split happens here rather than
     * being something the client has to know about. `variant_label` is named
     * apart from the product's own name for the same reason: on one form they are
     * two different boxes and "label" alone would be ambiguous.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function variantPayload(array $data): array
    {
        return [
            'sku' => $data['sku'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'label' => $data['variant_label'] ?? null,
            'attributes' => $data['attributes'] ?? [],
            'sell_price' => $data['sell_price'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
            'reorder_level' => $data['reorder_level'] ?? null,
            'min_stock' => $data['min_stock'] ?? null,
            'is_draft' => $data['is_draft'] ?? null,
        ];
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Item
    {
        $category = $this->resolveCategory($data['category_id'] ?? null);
        $name = trim((string) $data['name']);
        $code = $this->normaliseCode($data['code'] ?? null);

        $this->assertNameAvailable($name);

        if ($code !== null) {
            $this->assertCodeAvailable($code);
        }

        // Refused rather than dropped when it does not resolve: silently saving a
        // product with no brand would leave somebody looking at a form that said
        // "Crompton" and a record that says nothing.
        $brand = $this->brands->resolve($data['brand_id'] ?? null);

        $item = $this->items->create([
            'name' => $name,
            'code' => $code,
            'category_id' => $category->id,
            'brand_id' => $brand?->id,
            // Defaulted from the category, which is where the shop said what it
            // usually charges on this kind of thing.
            'hsn_sac' => $this->normaliseTaxCode($data['hsn_sac'] ?? null) ?? $category->default_hsn_sac,
            'gst_rate' => $this->normaliseRate(
                $data['gst_rate'] ?? $category->default_gst_rate
            ),
            'base_uom' => $this->resolveUom($category, $data['base_uom'] ?? null),
            'is_stock' => $this->resolveStockFlag($category, $data['is_stock'] ?? null),
            'is_draft' => (bool) ($data['is_draft'] ?? false),
            'description' => $this->trimmed($data['description'] ?? null),
            'image_path' => $this->trimmed($data['image_path'] ?? null),
            'is_active' => true,
        ]);

        // So tracksStock() and the resource can reach them without a second query.
        $item->setRelation('category', $category);
        $item->setRelation('brand', $brand);

        Log::info('items.created', [
            'item_id' => $item->id,
            'category_id' => $category->id,
            'is_draft' => $item->is_draft,
        ]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): Item
    {
        $item = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name !== $item->name) {
                $this->assertNameAvailable($name, $item->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('code', $data)) {
            $code = $this->normaliseCode($data['code']);

            if ($code !== $item->code) {
                if ($code !== null) {
                    $this->assertCodeAvailable($code, $item->id);
                }

                $attributes['code'] = $code;
            }
        }

        foreach (['description', 'image_path'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->trimmed($data[$field]);
            }
        }

        /*
        | The brand, unlike the category, *is* editable.
        |
        | Nothing downstream is keyed by it: a specification is keyed by the
        | category's fields and a quantity is keyed by the unit, but a brand is
        | only a name, and correcting a bearing filed under the wrong make is an
        | ordinary correction rather than a reinterpretation. Clearing it is a
        | real edit too — an unbranded bush is a real thing — so null is honoured
        | rather than treated as "not mentioned".
        */
        if (array_key_exists('brand_id', $data)) {
            $attributes['brand_id'] = $this->brands->resolve($data['brand_id'])?->id;
        }

        if (array_key_exists('hsn_sac', $data)) {
            $attributes['hsn_sac'] = $this->normaliseTaxCode($data['hsn_sac']);
        }

        if (array_key_exists('gst_rate', $data)) {
            $attributes['gst_rate'] = $this->normaliseRate($data['gst_rate']);
        }

        if (array_key_exists('is_stock', $data)) {
            $category = $item->category ?? $this->resolveCategory($item->category_id);
            $attributes['is_stock'] = $this->resolveStockFlag($category, $data['is_stock']);
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_draft', $data)) {
            $attributes['is_draft'] = (bool) $data['is_draft'];
        }

        /*
        | `category_id` and `base_uom` are absent by design.
        |
        | Reclassifying a product would reinterpret its whole specification — the
        | bag is keyed by a schema the old category defined, and the new one has
        | different keys — and changing "each" to "kilogram" would turn 40 pieces
        | into 40 kilograms in every report ever run. If the category was wrong,
        | the product was the wrong product: archive it and add the right one.
        */

        if ($attributes === []) {
            return $item;
        }

        $item = $this->items->update($item, $attributes);

        Log::info('items.updated', ['item_id' => $item->id, 'fields' => array_keys($attributes)]);

        return $item;
    }

    /**
     * Remove an item nothing points at — a typo, a duplicate caught early.
     *
     * Stock movements are checked before variants, because they are the harsher
     * refusal and the more useful message: a product somebody has bought and sold
     * is not a typo, and telling them "it has 4 variants" would send them off to
     * delete those first and hit the same wall four times.
     */
    public function delete(int $id): void
    {
        $item = $this->find($id);

        $movements = $this->stock->movementCountForItem($item->id);

        if ($movements > 0) {
            throw ItemInUseException::hasStockHistory($item->id, $item->name, $movements);
        }

        $billed = $this->documentLines->countForItem($item->id);

        if ($billed > 0) {
            throw ItemInUseException::hasBillLines($item->id, $item->name, $billed);
        }

        $variants = $this->items->variantCount($item->id);

        if ($variants > 0) {
            throw ItemInUseException::hasVariants($item->id, $item->name, $variants);
        }

        $this->items->delete($item);

        Log::info('items.deleted', ['item_id' => $item->id]);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * The category, insisting it exists and is usable.
     *
     * An archived category is refused for new products and left alone for
     * existing ones — which is the whole meaning of archiving: stop offering it,
     * keep explaining what is already filed under it.
     */
    private function resolveCategory(mixed $categoryId): ItemCategory
    {
        if ($categoryId === null || $categoryId === '') {
            throw new ConflictException(
                'Choose a category. It decides what this product is asked to record, '.
                'whether it is counted in stock, and how it is taxed.',
                'ITEM_CATEGORY_REQUIRED',
                ['field' => 'category_id'],
            );
        }

        $category = $this->categories->find((int) $categoryId);

        if (! $category->is_active) {
            throw new ConflictException(
                sprintf(
                    '"%s" has been archived, so nothing new can be filed under it. '.
                    'Reactivate it under Categories, or pick another.',
                    $category->name,
                ),
                'ITEM_CATEGORY_ARCHIVED',
                ['field' => 'category_id'],
            );
        }

        return $category;
    }

    /**
     * A category that holds no stock can never have a stocked product; anything
     * else may, and the shop chooses.
     *
     * The asymmetry is the point. `holds_stock` is *capability* and
     * `items.is_stock` is the shop's choice within it — a part bought to order
     * and never inventoried is a real arrangement, so the flag is honoured there.
     * An hour of labour is not, so the flag is overruled.
     */
    private function resolveStockFlag(ItemCategory $category, mixed $requested): bool
    {
        if (! $category->holds_stock) {
            return false;
        }

        return $requested === null ? true : (bool) $requested;
    }

    /**
     * The unit: what was asked for, else the category's default, else pieces.
     *
     * An unrecognised code falls back rather than throwing. The form only ever
     * offers real units, so this is reached by an importer with a typo — and
     * refusing the whole product over the unit would lose the rest of a row
     * somebody typed correctly.
     */
    private function resolveUom(ItemCategory $category, ?string $requested): string
    {
        $requested = $this->trimmed($requested);

        if ($requested !== null && $this->units->has($requested)) {
            return $requested;
        }

        $default = $category->default_unit_code;

        if ($default !== null && $this->units->has($default)) {
            return $default;
        }

        return 'piece';
    }

    private function normaliseCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }

    private function normaliseTaxCode(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }

    /**
     * The GST rate as a two-decimal string.
     *
     * A string, not a float, for the reason every number in this product is: the
     * rate is multiplied by an amount to compute tax, and that is the one place a
     * rounding error becomes a figure on a government return.
     */
    private function normaliseRate(string|float|int|null $rate): string
    {
        if ($rate === null || $rate === '') {
            return '0.00';
        }

        return number_format((float) $rate, 2, '.', '');
    }

    private function trimmed(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if (! $this->items->nameExists($name, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "An item named \"{$name}\" already exists. Two records with one name split a single stock balance ".
            'in two, so add the variant to the existing item instead.',
            'ITEM_NAME_TAKEN',
            ['field' => 'name'],
        );
    }

    private function assertCodeAvailable(string $code, ?int $exceptId = null): void
    {
        if (! $this->items->codeExists($code, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "An item coded {$code} already exists. A code that identifies two things is worse than no code.",
            'ITEM_CODE_TAKEN',
            ['field' => 'code'],
        );
    }
}
