<?php

namespace App\Services\Inventory;

use App\Enums\ItemType;
use App\Exceptions\Accounting\InvalidItemAttributesException;
use App\Exceptions\Accounting\ItemInUseException;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Item;
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
 * `ItemType::attributeSchema()` says what a motor or a length of copper wire is
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
        $attributes = $this->normaliseAttributes($item->type, $data['attributes'] ?? []);
        $sku = $this->normaliseSku($data['sku'] ?? null);

        if ($sku !== null) {
            $this->assertSkuAvailable($sku);
        }

        $variant = $this->variants->create([
            'item_id' => $item->id,
            'sku' => $sku,
            'label' => $this->trimmed($data['label'] ?? null),
            'attributes' => $attributes === [] ? null : $attributes,
            'sell_price' => $this->normaliseAmount($data['sell_price'] ?? null),
            'markup_percent' => $this->normaliseRate($data['markup_percent'] ?? null),
            'reorder_level' => $this->normaliseQuantity($item, $data['reorder_level'] ?? null),
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

        if (array_key_exists('label', $data)) {
            $fields['label'] = $this->trimmed($data['label']);
        }

        if (array_key_exists('attributes', $data)) {
            // Re-validated in full rather than merged: a partial attribute update
            // that dropped a required field would leave a variant nobody can
            // identify, and "I only sent the ones I changed" is indistinguishable
            // from "I meant to remove the rest".
            $attributes = $this->normaliseAttributes($item->type, $data['attributes'] ?? []);
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
        $normalised = $this->normaliseAttributes($item->type, $attributes);

        return $normalised === []
            ? new Collection
            : $this->variants->matchingAttributes($item->id, $normalised, $exceptId);
    }

    /* ---------------------------------------------------------------------
     | Attributes
     |-------------------------------------------------------------------- */

    /**
     * Validate a bag against its item type's schema and put it in schema order.
     *
     * Ordering matters for two reasons: the derived label reads the way somebody
     * reciting a specification would ("5 HP / 3 ph / 1440 RPM" rather than
     * whichever order the form serialised), and two equivalent variants compare
     * equal as stored JSON. The same reasoning that orders a party's roles by the
     * enum.
     *
     * @param  array<string, mixed>|null  $given
     * @return array<string, string>
     *
     * @throws InvalidItemAttributesException
     */
    public function normaliseAttributes(ItemType $type, ?array $given): array
    {
        $schema = $type->attributeSchema();
        $given = $given ?? [];

        // Blank is absent. A form submits every field it renders, and treating an
        // untouched optional box as the string "" would store noise that then has
        // to be filtered out everywhere it is read.
        $given = array_filter(
            $given,
            static fn ($value) => $value !== null && trim((string) $value) !== '',
        );

        $unknown = array_diff(array_keys($given), array_keys($schema));

        if ($unknown !== []) {
            throw InvalidItemAttributesException::unknown($type, array_values($unknown), array_keys($schema));
        }

        $missing = array_values(array_diff($type->requiredAttributes(), array_keys($given)));

        if ($missing !== []) {
            throw InvalidItemAttributesException::missing($type, $missing);
        }

        $normalised = [];

        foreach ($schema as $key => $field) {
            if (! array_key_exists($key, $given)) {
                continue;
            }

            $value = trim((string) $given[$key]);

            // Only where a fixed set genuinely exists: a motor's phase is 1 or 3
            // and there is no third possibility. Frame size is open, and pinning
            // it to a list would make the product wrong about the next frame.
            if (isset($field['values']) && ! in_array($value, $field['values'], true)) {
                throw InvalidItemAttributesException::badValue($type, $key, $value, $field['values']);
            }

            $normalised[$key] = $value;
        }

        return $normalised;
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
     * displayed and reordered against. See {@see \App\Enums\UnitOfMeasure}.
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
}
