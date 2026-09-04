<?php

namespace App\Services\Inventory;

use App\Enums\AttributeType;
use App\Exceptions\Accounting\InvalidItemAttributesException;
use App\Exceptions\Accounting\ItemInUseException;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The specific things the workshop buys and sells, and the rules about how they
 * are described.
 *
 * ## Why attribute validation lives here
 *
 * The category's question set says what a motor or a length of copper wire is
 * described by, and this class is where that is applied. Not in a form request,
 * because M11's importer and M15's capture agent both create variants without
 * passing through one — and a motor whose HP was never captured is not
 * identifiable by anybody afterwards, which is a permanent problem rather than a
 * validation message somebody missed.
 *
 * Required attributes are demanded; optional ones are accepted and never forced.
 * Workshops differ in how much they record, and refusing a bearing because nobody
 * typed its material would push people into not recording the bearing.
 *
 * ## No price is a stored margin
 *
 * `sell_price` is what is charged and `markup_percent` is what was intended.
 * Neither is derived from the other, and **neither is a margin**: the cost comes
 * from M8's weighted average at the moment of sale, so a margin computed here
 * would be stale the next time stock arrived. M9 computes it per line.
 */
class ItemVariantService
{
    public function __construct(
        private readonly ItemVariantRepositoryInterface $variants,
        private readonly StockLedgerService $stock,
        private readonly TransactionLineRepositoryInterface $documentLines,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @return Collection<int, ItemVariant>
     */
    public function forItem(Item $item, bool $activeOnly = false): Collection
    {
        return $this->variants->forItem($item, $activeOnly);
    }

    public function find(int $id): ItemVariant
    {
        return $this->variants->findWithItem($id)
            ?? throw new ResourceNotFoundException('Item variant', $id);
    }

    /**
     * A variant, insisting that it belongs to the item the caller named.
     *
     * The nesting in the URL has to mean something. Without this,
     * `PATCH /items/7/variants/12` would happily edit variant 12 of item 3 —
     * inside the right workshop, so the tenant scope would not catch it, and the
     * caller would be told the edit applied to the item they were looking at. The
     * answer is a 404 rather than a 403: from the caller's point of view there is
     * no variant 12 of item 7.
     */
    public function findForItem(Item $item, int $id): ItemVariant
    {
        $variant = $this->find($id);

        if ($variant->item_id !== $item->id) {
            throw new ResourceNotFoundException('Item variant', $id);
        }

        return $variant;
    }

    public function draftCount(): int
    {
        return $this->variants->draftCount();
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{attributes?: array<string, mixed>|null, sku?: string|null, label?: string|null, sell_price?: string|float|int|null, markup_percent?: string|float|int|null, reorder_level?: string|float|int|null, is_draft?: bool|null}  $data
     */
    public function create(Item $item, array $data): ItemVariant
    {
        $attributes = $this->normaliseAttributes($this->categoryOf($item), $data['attributes'] ?? []);
        $sku = $this->normaliseSku($data['sku'] ?? null);
        $barcode = $this->normaliseBarcode($data['barcode'] ?? null);

        if ($sku !== null) {
            $this->assertSkuAvailable($sku);
        }

        if ($barcode !== null) {
            $this->assertBarcodeAvailable($barcode);
        }

        $variant = $this->variants->create([
            'item_id' => $item->id,
            'sku' => $sku,
            'barcode' => $barcode,
            'label' => $this->trimmed($data['label'] ?? null),
            'attributes' => $attributes === [] ? null : $attributes,
            'sell_price' => $this->normaliseAmount($data['sell_price'] ?? null),
            'markup_percent' => $this->normaliseRate($data['markup_percent'] ?? null),
            'reorder_level' => $this->normaliseQuantity($item, $data['reorder_level'] ?? null),
            'min_stock' => $this->normaliseQuantity($item, $data['min_stock'] ?? null),
            'is_draft' => (bool) ($data['is_draft'] ?? $item->is_draft),
            'is_active' => true,
        ]);

        // Loaded so displayLabel() and the resource can reach the type. A variant
        // means almost nothing without its family.
        $variant->setRelation('item', $item);

        Log::info('item_variants.created', ['variant_id' => $variant->id, 'item_id' => $item->id]);

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ItemVariant $variant, array $data): ItemVariant
    {
        $item = $variant->item;

        if ($item === null) {
            throw new ResourceNotFoundException('Item', $variant->item_id);
        }

        $fields = [];

        if (array_key_exists('sku', $data)) {
            $sku = $this->normaliseSku($data['sku']);

            if ($sku !== $variant->sku) {
                if ($sku !== null) {
                    $this->assertSkuAvailable($sku, $variant->id);
                }

                $fields['sku'] = $sku;
            }
        }

        if (array_key_exists('barcode', $data)) {
            $barcode = $this->normaliseBarcode($data['barcode']);

            if ($barcode !== $variant->barcode) {
                if ($barcode !== null) {
                    $this->assertBarcodeAvailable($barcode, $variant->id);
                }

                $fields['barcode'] = $barcode;
            }
        }

        if (array_key_exists('label', $data)) {
            $fields['label'] = $this->trimmed($data['label']);
        }

        if (array_key_exists('attributes', $data)) {
            // Re-validated in full rather than merged: a partial attribute update
            // that dropped a required field would leave a variant nobody can
            // identify, and "I only sent the ones I changed" is indistinguishable
            // from "I meant to remove the rest".
            $attributes = $this->normaliseAttributes($this->categoryOf($item), $data['attributes'] ?? []);
            $fields['attributes'] = $attributes === [] ? null : $attributes;
        }

        if (array_key_exists('sell_price', $data)) {
            $fields['sell_price'] = $this->normaliseAmount($data['sell_price']);
        }

        if (array_key_exists('markup_percent', $data)) {
            $fields['markup_percent'] = $this->normaliseRate($data['markup_percent']);
        }

        if (array_key_exists('reorder_level', $data)) {
            $fields['reorder_level'] = $this->normaliseQuantity($item, $data['reorder_level']);
        }

        if (array_key_exists('min_stock', $data)) {
            $fields['min_stock'] = $this->normaliseQuantity($item, $data['min_stock']);
        }

        foreach (['is_draft', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $fields[$flag] = (bool) $data[$flag];
            }
        }

        if ($fields === []) {
            return $variant;
        }

        $variant = $this->variants->update($variant, $fields);
        $variant->setRelation('item', $item);

        Log::info('item_variants.updated', ['variant_id' => $variant->id, 'fields' => array_keys($fields)]);

        return $variant;
    }

    /**
     * Remove a variant nothing points at — a typo, a duplicate caught early.
     *
     * Once stock has moved through it, this is a refusal rather than a delete.
     * The reasoning is M3's and M5's exactly: the movement history stays, and a
     * movement whose variant vanished loses the name that explains it — "−3" is
     * a number, "−3 × 5 HP / 1440" is a record. The database backs this with
     * `restrictOnDelete` for anything that does not come through here.
     */
    public function delete(ItemVariant $variant): void
    {
        if ($this->stock->hasMovements($variant)) {
            throw ItemInUseException::variantHasStockHistory($variant->id, $variant->displayLabel());
        }

        // And bill lines, which do not always come with a movement — a service
        // variant appears on an invoice and moves no stock at all.
        if ($this->documentLines->countForVariant((int) $variant->id) > 0) {
            throw ItemInUseException::variantHasStockHistory($variant->id, $variant->displayLabel());
        }

        $this->variants->delete($variant);

        Log::info('item_variants.deleted', ['variant_id' => $variant->id]);
    }

    /* ---------------------------------------------------------------------
     | Duplicates
     |-------------------------------------------------------------------- */

    /**
     * Other variants of the same item with the same specification.
     *
     * A warning, never a rule — the same treatment as a duplicate GSTIN in M5, and
     * for a comparable reason: two 5 HP / 1440 rows are *usually* the same motor
     * entered twice, which splits one stock balance in half, but a workshop
     * stocking two brands at identical ratings legitimately has two. So the
     * duplicate is put in front of the user while they can still act on it.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, ItemVariant>
     */
    public function othersMatching(Item $item, array $attributes, ?int $exceptId = null): Collection
    {
        $normalised = $this->normaliseAttributes($this->categoryOf($item), $attributes);

        return $normalised === []
            ? new Collection
            : $this->variants->matchingAttributes($item->id, $normalised, $exceptId);
    }

    /**
     * The category that describes this product, insisting there is one.
     *
     * A product with no category cannot have its specification validated at all —
     * there is nothing to validate it against. Reachable only between the
     * migration that added the column and the backfill that fills it, and refused
     * rather than waved through, because storing an unchecked bag would put
     * values in the catalogue that the form could never show again.
     */
    private function categoryOf(Item $item): ItemCategory
    {
        return $item->category ?? throw new ConflictException(
            sprintf(
                '"%s" is not filed under a category, so there is nothing to say how it should be described. '.
                'Set its category before editing its specification.',
                $item->name,
            ),
            'ITEM_CATEGORY_MISSING',
            ['field' => 'category_id'],
        );
    }

    /* ---------------------------------------------------------------------
     | Attributes
     |-------------------------------------------------------------------- */

    /**
     * Validate a bag against its category's question set and put it in schema
     * order.
     *
     * Ordering matters for two reasons: the derived label reads the way somebody
     * reciting a specification would ("5 HP / 3 ph / 1440 RPM" rather than
     * whichever order the form serialised), and two equivalent variants compare
     * equal as stored JSON.
     *
     * ## Why an inactive field is accepted but never demanded
     *
     * A field switched off stops appearing on the form, so nothing new carries
     * it — but a variant created last year still has a value under its key, and
     * refusing that on the next edit would make an old record uneditable because
     * of a decision taken after it was written. So inactive keys pass through
     * untouched and unvalidated, and only *active* fields are required.
     *
     * @param  array<string, mixed>|null  $given
     * @return array<string, string>
     *
     * @throws InvalidItemAttributesException
     */
    public function normaliseAttributes(ItemCategory $category, ?array $given): array
    {
        $active = $category->resolvedAttributes();
        $all = $category->resolvedAttributes(activeOnly: false);

        $given = $given ?? [];

        // Blank is absent. A form submits every field it renders, and treating an
        // untouched optional box as the string "" would store noise that then has
        // to be filtered out everywhere it is read.
        $given = array_filter(
            $given,
            static fn ($value) => $value !== null && trim((string) $value) !== '',
        );

        $knownKeys = $all->map(fn (ItemAttribute $attribute) => $attribute->key)->all();
        $unknown = array_values(array_diff(array_keys($given), $knownKeys));

        if ($unknown !== []) {
            throw InvalidItemAttributesException::unknown(
                $category,
                $unknown,
                $active->map(fn (ItemAttribute $attribute) => $attribute->label)->all(),
            );
        }

        $required = $active
            ->filter(fn (ItemAttribute $attribute) => $attribute->is_required)
            ->map(fn (ItemAttribute $attribute) => $attribute->key)
            ->all();

        $missing = array_values(array_diff($required, array_keys($given)));

        if ($missing !== []) {
            throw InvalidItemAttributesException::missing(
                $category,
                $missing,
                $category->attributeSchema(),
            );
        }

        $normalised = [];

        foreach ($all as $attribute) {
            if (! array_key_exists($attribute->key, $given)) {
                continue;
            }

            $normalised[$attribute->key] = $this->normaliseAttributeValue(
                $category,
                $attribute,
                trim((string) $given[$attribute->key]),
            );
        }

        return $normalised;
    }

    /**
     * One value, checked against the shape its field declares.
     *
     * Stored as text whatever the type — the bag is a JSON map of strings, and
     * has been since M7 — because the type governs what is *accepted*, not how it
     * is kept. A number stored as a JSON number would come back as a float from
     * `json_decode` and start disagreeing with itself at the eighth place, which
     * is the one thing this codebase refuses everywhere.
     *
     * @throws InvalidItemAttributesException
     */
    private function normaliseAttributeValue(ItemCategory $category, ItemAttribute $attribute, string $value): string
    {
        return match ($attribute->data_type) {
            // Only where a fixed set genuinely exists: a motor's phase is 1 or 3
            // and there is no third possibility. Frame size is open, and pinning
            // it to a list would make the product wrong about the next frame.
            AttributeType::Dropdown => $this->assertInOptions($category, $attribute, $value),

            AttributeType::Number => $this->assertWholeNumber($category, $attribute, $value),
            AttributeType::Decimal => $this->assertDecimal($category, $attribute, $value),
            AttributeType::Boolean => $this->normaliseBoolean($value),
            AttributeType::Date => $this->assertDate($category, $attribute, $value),
            AttributeType::Text => $value,
        };
    }

    private function assertInOptions(ItemCategory $category, ItemAttribute $attribute, string $value): string
    {
        $options = $attribute->optionList();

        // An empty list is an unfinished configuration, not a rule that refuses
        // everything. Refusing every value because nobody has typed the choices
        // yet would make the field impossible to fill and impossible to diagnose.
        if ($options === [] || in_array($value, $options, true)) {
            return $value;
        }

        throw InvalidItemAttributesException::badValue(
            $category,
            $attribute->key,
            $attribute->label,
            $value,
            $options,
        );
    }

    private function assertWholeNumber(ItemCategory $category, ItemAttribute $attribute, string $value): string
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw InvalidItemAttributesException::badFormat(
                $category,
                $attribute->key,
                $attribute->label,
                $value,
                'a whole number',
            );
        }

        $this->assertWithinRange($category, $attribute, $value);

        return $value;
    }

    private function assertDecimal(ItemCategory $category, ItemAttribute $attribute, string $value): string
    {
        if (preg_match('/^-?\d+(\.\d{1,3})?$/', $value) !== 1) {
            throw InvalidItemAttributesException::badFormat(
                $category,
                $attribute->key,
                $attribute->label,
                $value,
                'a number, to at most three decimal places',
            );
        }

        $this->assertWithinRange($category, $attribute, $value);

        // Trailing zeros trimmed, so "5.50" and "5.5" are one value rather than
        // two that look identical and compare unequal — which would split a
        // duplicate check and a stock balance the same way two spellings of a
        // name would.
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function assertWithinRange(ItemCategory $category, ItemAttribute $attribute, string $value): void
    {
        $number = (float) $value;
        $min = $attribute->min_value === null ? null : (float) $attribute->min_value;
        $max = $attribute->max_value === null ? null : (float) $attribute->max_value;

        if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            throw InvalidItemAttributesException::outOfRange(
                $category,
                $attribute->key,
                $attribute->label,
                $value,
                $attribute->min_value === null ? null : (string) $attribute->min_value,
                $attribute->max_value === null ? null : (string) $attribute->max_value,
            );
        }
    }

    /**
     * Anything a person or a form might mean by yes, stored as one of two words.
     *
     * Permissive on the way in and strict on the way out: a checkbox posts "1", a
     * spreadsheet import says "TRUE", and somebody typing by hand writes "yes".
     * All three mean the same thing and all three are stored as "yes", so two
     * variants that agree compare equal.
     */
    private function normaliseBoolean(string $value): string
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'on'], true) ? 'yes' : 'no';
    }

    private function assertDate(ItemCategory $category, ItemAttribute $attribute, string $value): string
    {
        $parsed = date_create_immutable($value);

        if ($parsed === false) {
            throw InvalidItemAttributesException::badFormat(
                $category,
                $attribute->key,
                $attribute->label,
                $value,
                'a date',
            );
        }

        // Normalised to ISO, so dates sort as strings and two spellings of one
        // day compare equal.
        return $parsed->format('Y-m-d');
    }

    /* ---------------------------------------------------------------------
     | Normalisation
     |-------------------------------------------------------------------- */

    private function normaliseSku(?string $sku): ?string
    {
        $sku = strtoupper(trim((string) $sku));

        return $sku === '' ? null : $sku;
    }

    /**
     * A barcode, kept exactly as scanned apart from surrounding whitespace.
     *
     * Not upper-cased, unlike a SKU: a SKU is something a person types and a
     * barcode is something a scanner emits, and case-folding a scanned value
     * would make it stop matching the label it was read from.
     */
    private function normaliseBarcode(?string $barcode): ?string
    {
        $barcode = trim((string) $barcode);

        return $barcode === '' ? null : $barcode;
    }

    /**
     * A price as a two-decimal string, parsed through Money so a float from
     * `json_decode` never reaches the column by way of a multiplication.
     */
    private function normaliseAmount(string|float|int|null $amount): ?string
    {
        return Money::ofNullable($amount)?->amount();
    }

    private function normaliseRate(string|float|int|null $rate): ?string
    {
        if ($rate === null || $rate === '') {
            return null;
        }

        return number_format((float) $rate, 2, '.', '');
    }

    /**
     * A quantity, at the scale its unit allows.
     *
     * 2.5 kg of copper is ordinary; 2.5 bearings is a mistake, so a whole-unit item
     * rounds to a whole number rather than storing a fraction that would then be
     * displayed and reordered against. See {@see \App\Support\Units\UnitDefinition}.
     */
    private function normaliseQuantity(Item $item, string|float|int|null $quantity): ?string
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }

        return number_format((float) $quantity, $item->base_uom->quantityScale(), '.', '');
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertSkuAvailable(string $sku, ?int $exceptId = null): void
    {
        if (! $this->variants->skuExists($sku, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "A variant with SKU {$sku} already exists. A SKU that identifies two things is worse than no SKU.",
            'ITEM_SKU_TAKEN',
            ['field' => 'sku'],
        );
    }

    private function assertBarcodeAvailable(string $barcode, ?int $exceptId = null): void
    {
        if (! $this->variants->barcodeExists($barcode, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "A product with barcode {$barcode} already exists. A scanner cannot choose between two, ".
            'so the counter would sell whichever the query happened to return first.',
            'ITEM_BARCODE_TAKEN',
            ['field' => 'barcode'],
        );
    }
}
