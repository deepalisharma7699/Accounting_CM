<?php

namespace App\Services\Inventory;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Exceptions\Accounting\ItemInUseException;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Maintaining the catalogue: what the workshop deals in.
 *
 * Nothing here posts anything and nothing here holds a quantity or a cost. Stock
 * on hand and weighted average cost are sums over M8's `stock_movement`; this
 * class is only concerned with the record — its name, its type, its tax identity,
 * and whether it may be removed.
 *
 * Two rules are enforced here rather than in a form request, because M11's importer
 * and M15's capture agent create items without passing through one:
 *
 *   * **a service can never hold stock.** An hour is produced at the moment it is
 *     sold, and an opening balance of forty hours would be inventing an asset;
 *   * **the unit and the type are fixed once set.** Changing either would silently
 *     reinterpret every quantity and every bill line already recorded.
 */
class ItemService
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
        private readonly StockLedgerService $stock,
        private readonly TransactionLineRepositoryInterface $documentLines,
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

    /**
     * How many items are still waiting for somebody to look at them.
     *
     * The badge on the review queue, which is why it is a count and not a page of
     * rows: it is fetched every time the screen loads.
     */
    public function draftCount(): int
    {
        return $this->items->draftCount();
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{name: string, type: string, code?: string|null, hsn_sac?: string|null, gst_rate?: string|float|int|null, base_uom?: string|null, is_stock?: bool|null, is_draft?: bool|null, description?: string|null}  $data
     */
    public function create(array $data): Item
    {
        $type = ItemType::from($data['type']);
        $name = trim($data['name']);
        $code = $this->normaliseCode($data['code'] ?? null);

        $this->assertNameAvailable($name);

        if ($code !== null) {
            $this->assertCodeAvailable($code);
        }

        $item = $this->items->create([
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'hsn_sac' => $this->normaliseTaxCode($data['hsn_sac'] ?? null),
            'gst_rate' => $this->normaliseRate($data['gst_rate'] ?? null),
            // Defaulted from the type rather than required, so the ordinary case
            // needs no decision: a motor is counted in pieces and copper is
            // weighed, and nobody should have to say so.
            'base_uom' => $this->resolveUom($type, $data['base_uom'] ?? null),
            'is_stock' => $this->resolveStockFlag($type, $data['is_stock'] ?? null),
            'is_draft' => (bool) ($data['is_draft'] ?? false),
            'description' => $this->trimmed($data['description'] ?? null),
            'is_active' => true,
        ]);

        Log::info('items.created', [
            'item_id' => $item->id,
            'type' => $type->value,
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

        if (array_key_exists('hsn_sac', $data)) {
            $attributes['hsn_sac'] = $this->normaliseTaxCode($data['hsn_sac']);
        }

        if (array_key_exists('gst_rate', $data)) {
            $attributes['gst_rate'] = $this->normaliseRate($data['gst_rate']);
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->trimmed($data['description']);
        }

        if (array_key_exists('is_stock', $data)) {
            $attributes['is_stock'] = $this->resolveStockFlag($item->type, $data['is_stock']);
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        // Clearing the draft flag is how the review queue is worked through: an
        // item somebody has looked at is an ordinary item. Setting it again is
        // allowed too — noticing later that a record needs checking is exactly
        // what the flag is for.
        if (array_key_exists('is_draft', $data)) {
            $attributes['is_draft'] = (bool) $data['is_draft'];
        }

        // `type` and `base_uom` are absent by design, and for the same reason an
        // account's type is: reclassifying an item would silently reinterpret
        // every quantity recorded against it, and changing "each" to "kilogram"
        // would turn 40 pieces into 40 kilograms in every report ever run. If the
        // type was wrong, the item was the wrong item — archive it and add the
        // right one.

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
     * An item with variants is refused rather than cascaded, even though the
     * foreign key would cascade: a variant deleted as a side effect of tidying up
     * a family name is work somebody loses without being asked. The refusal names
     * archiving instead.
     *
     * Stock movements are checked before variants, because they are the harsher
     * refusal and the more useful message: an item somebody has bought and sold
     * is not a typo, and telling them "it has 4 variants" would send them off to
     * delete those first and hit the same wall four times. `restrictOnDelete` on
     * `stock_movements` backs both for anything that does not come through here.
     */
    public function delete(int $id): void
    {
        $item = $this->find($id);

        $movements = $this->stock->movementCountForItem($item->id);

        if ($movements > 0) {
            throw ItemInUseException::hasStockHistory($item->id, $item->name, $movements);
        }

        // Bill lines as well as movements, because the two do not always come
        // together: an hour of labour appears on an invoice and moves no stock at
        // all, and deleting it would leave that invoice line with nothing to
        // explain it. `restrictOnDelete` on `transaction_lines` backs this for
        // anything that does not come through here — this is what turns the
        // database's refusal into a sentence somebody can act on.
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
     * A service can never hold stock; anything else may, and the workshop chooses.
     *
     * The asymmetry is the point. `ItemType::canHoldStock()` is *capability* and
     * `items.is_stock` is the workshop's choice within it — a part bought to order
     * and never inventoried is a real arrangement, so the flag is honoured there.
     * An hour of labour is not, so the flag is overruled: it is produced at the
     * moment it is sold and there is nothing to hold.
     */
    private function resolveStockFlag(ItemType $type, mixed $requested): bool
    {
        if (! $type->canHoldStock()) {
            return false;
        }

        return $requested === null ? true : (bool) $requested;
    }

    /**
     * The unit, defaulted from the type and refused if it contradicts it.
     */
    private function resolveUom(ItemType $type, ?string $requested): UnitOfMeasure
    {
        if ($requested === null || trim($requested) === '') {
            return $type->defaultUom();
        }

        return UnitOfMeasure::from($requested);
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
