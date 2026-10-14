<?php

namespace App\Services\Inventory;

use App\Exceptions\Accounting\RecipeException;
use App\Models\ItemComponent;
use App\Models\ItemVariant;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes: what a made thing consumes, read and written in one place.
 *
 * A rewind is one bill line at one price and three quantities off the shelf.
 * This decides what those quantities are; {@see \App\Services\Accounting\Posting\Templates\BillTemplate}
 * turns them into movements at the moment a bill is posted, and nothing else
 * anywhere may write a stock movement of its own (§4.3).
 *
 * ## Every refusal is made here, on the way in
 *
 * A recipe is read at the counter, with a customer waiting, and the only thing
 * to do about a bad one at that moment is abandon the sale. So the rules are
 * enforced where somebody is editing the recipe and can act on them. See
 * {@see RecipeException}, which carries the whole list and the reason for each.
 *
 * ## It tolerates its own table not existing
 *
 * §4.6: the schema step is SQL an operator runs by hand, in a window they chose,
 * so the code is deployed before the table exists and has to work in that
 * window. {@see isInstalled()} is the single place that is decided. A read then
 * answers "no recipe", which is exactly what was true of every product before
 * this feature, so every screen and every posting behaves as it did yesterday.
 * A *write* refuses out loud, because silently discarding a recipe somebody
 * just typed is the one behaviour that would look like it had worked.
 */
class ItemComponentService
{
    private ?bool $installed = null;

    /**
     * Whether the schema step has been run on this server.
     *
     * Memoised per instance rather than per process: a test that builds the
     * table part-way through a run would otherwise be answered from a cache
     * taken before it existed.
     */
    public function isInstalled(): bool
    {
        return $this->installed ??= Schema::hasTable('item_components');
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * The recipe for one variant, materials loaded.
     *
     * Empty for anything without one, which is almost everything — a recipe is
     * a property of the few things a workshop *makes*.
     *
     * @return Collection<int, ItemComponent>
     */
    public function forVariant(int $variantId): Collection
    {
        if (! $this->isInstalled()) {
            return new Collection;
        }

        return ItemComponent::query()
            ->where('parent_variant_id', $variantId)
            ->with(['componentVariant.item:id,name,base_uom,is_stock,category_id'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Recipes for several variants at once, keyed by parent variant id.
     *
     * One query for the whole set, because a bill with four rewinds on it would
     * otherwise be four round trips inside the posting transaction — and a
     * preview re-runs on a debounce as somebody types.
     *
     * A variant with no recipe is **absent** rather than present-and-empty: the
     * callers all ask "is there one", and an empty collection answering yes is
     * the kind of distinction that gets lost at the third call site.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, Collection<int, ItemComponent>>
     */
    public function forVariants(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));

        if ($variantIds === [] || ! $this->isInstalled()) {
            return [];
        }

        return ItemComponent::query()
            ->whereIn('parent_variant_id', $variantIds)
            ->with(['componentVariant.item:id,name,base_uom,is_stock,category_id'])
            ->orderBy('id')
            ->get()
            ->groupBy('parent_variant_id')
            ->mapWithKeys(fn (Collection $rows, $parentId) => [(int) $parentId => $rows])
            ->all();
    }

    /**
     * Load recipes onto variants that are about to be serialised.
     *
     * Here rather than in the repository, and that is the §4.6 tolerance rather
     * than a layering preference: eager-loading a relation whose table does not
     * exist throws, and on a server where the schema step has not been run yet
     * that would take out the whole Items screen — the opposite of the "works
     * before the SQL is run" the rule asks for. {@see isInstalled()} is the one
     * place that is decided, so it has to be the thing gating the load.
     *
     * @template T of ItemVariant|Collection<int, ItemVariant>
     *
     * @param  T  $variants
     * @return T
     */
    public function attachTo(ItemVariant|Collection $variants): ItemVariant|Collection
    {
        if (! $this->isInstalled()) {
            return $variants;
        }

        $variants->load(['components.componentVariant.item:id,name,base_uom']);

        return $variants;
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Replace a variant's whole recipe with the rows given.
     *
     * A replacement rather than a merge, for the reason an allocation is one:
     * an empty list has to be able to mean "this consumes nothing" rather than
     * "nothing was said", and a merge has no way to express the first.
     *
     * @param  array<int, array{component_variant_id: int|string, quantity: int|string|float}>  $rows
     * @return Collection<int, ItemComponent>
     *
     * @throws RecipeException
     */
    public function sync(ItemVariant $parent, array $rows): Collection
    {
        if (! $this->isInstalled()) {
            // Nothing to store and nothing to clear — but an empty list is the
            // one case where doing nothing is genuinely correct, so it is not
            // an error.
            if ($rows === []) {
                return new Collection;
            }

            throw RecipeException::notInstalled();
        }

        $parent->loadMissing('item.category');

        // Clearing is always allowed, whatever the parent has become since. It
        // is the one way to tidy a recipe left behind on something that now
        // holds stock, and refusing it would leave that row unremovable.
        if ($rows !== []) {
            if ($parent->item?->tracksStock()) {
                throw RecipeException::parentHoldsStock($this->nameOf($parent));
            }

            // One level, asked from this side as well: a variant another recipe
            // already consumes cannot be given a recipe of its own.
            $usedBy = $this->recipesUsing([(int) $parent->id]);

            if ($usedBy !== []) {
                throw RecipeException::parentIsAMaterial($this->nameOf($parent), $usedBy);
            }
        }

        $prepared = $this->validate($parent, $rows);

        return DB::transaction(function () use ($parent, $prepared) {
            /*
            | A diff against what is stored, not a delete-and-recreate.
            |
            | Each row is written through the model so its audit event fires: a
            | bulk delete fires none, which left History with every material
            | "added" again on each save and never a removal or a changed
            | quantity — the trail a recipe is audited for. Rows that did not
            | change are not written at all.
            */
            $existing = ItemComponent::query()
                ->where('parent_variant_id', $parent->id)
                ->with('componentVariant.item.category')
                ->get()
                ->keyBy(fn (ItemComponent $row) => (int) $row->component_variant_id);

            $wanted = [];

            foreach ($prepared as $row) {
                $wanted[$row['component_variant_id']] = true;
            }

            foreach ($existing as $componentId => $row) {
                if (! isset($wanted[$componentId])) {
                    $row->setRelation('parentVariant', $parent);
                    $row->delete();
                }
            }

            foreach ($prepared as $row) {
                $current = $existing->get($row['component_variant_id']);

                if ($current !== null) {
                    if ($current->quantityValue()->amount() !== $row['quantity']->amount()) {
                        $current->setRelation('parentVariant', $parent);
                        $current->update(['quantity' => $row['quantity']->amount()]);
                    }

                    continue;
                }

                $component = new ItemComponent([
                    // Stamped from the parent rather than from the context, the
                    // rule every child table here follows: a recipe row can
                    // never end up in a different workshop from the variant it
                    // belongs to.
                    'tenant_id' => $parent->tenant_id,
                    'parent_variant_id' => $parent->id,
                    'component_variant_id' => $row['component_variant_id'],
                    'quantity' => $row['quantity']->amount(),
                ]);

                // So History names both sides rather than "Recipe #12".
                $component->setRelation('parentVariant', $parent);
                $component->setRelation('componentVariant', $row['variant']);
                $component->save();
            }

            return $this->forVariant((int) $parent->id);
        });
    }

    /**
     * Remove a variant's whole recipe, one audited row at a time.
     *
     * For a variant about to be deleted: the foreign key would cascade the rows
     * away too, but silently, and the recipe would vanish from History with no
     * entry saying so.
     */
    public function clear(ItemVariant $parent): void
    {
        if (! $this->isInstalled()) {
            return;
        }

        ItemComponent::query()
            ->where('parent_variant_id', $parent->id)
            ->with('componentVariant.item.category')
            ->get()
            ->each(function (ItemComponent $row) use ($parent): void {
                $row->setRelation('parentVariant', $parent);
                $row->delete();
            });
    }

    /**
     * The recipes that consume any of these variants — "what uses this".
     *
     * Named rather than counted, because every caller is about to refuse
     * something and the refusal has to say which recipe to go and change.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, string>
     */
    public function recipesUsing(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));

        if ($variantIds === [] || ! $this->isInstalled()) {
            return [];
        }

        return ItemComponent::query()
            ->whereIn('component_variant_id', $variantIds)
            ->with('parentVariant.item.category')
            ->orderBy('parent_variant_id')
            ->get()
            ->map(fn (ItemComponent $row) => $row->parentVariant === null ? null : $this->nameOf($row->parentVariant))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether any of these variants has a recipe of its own.
     *
     * @param  array<int, int>  $variantIds
     */
    public function anyHasRecipe(array $variantIds): bool
    {
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));

        if ($variantIds === [] || ! $this->isInstalled()) {
            return false;
        }

        return ItemComponent::query()->whereIn('parent_variant_id', $variantIds)->exists();
    }

    /**
     * Check every row, and resolve each material once.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{component_variant_id: int, quantity: Quantity, variant: ItemVariant}>
     *
     * @throws RecipeException
     */
    private function validate(ItemVariant $parent, array $rows): array
    {
        $prepared = [];
        $seen = [];

        foreach ($rows as $row) {
            $variantId = (int) ($row['component_variant_id'] ?? 0);

            $variant = ItemVariant::query()->with('item.category')->find($variantId);

            if ($variant === null) {
                throw RecipeException::componentHoldsNoStock("#{$variantId}");
            }

            $name = $this->nameOf($variant);

            if ($variantId === (int) $parent->id) {
                throw RecipeException::componentIsItself($name);
            }

            if (isset($seen[$variantId])) {
                throw RecipeException::duplicateComponent($name);
            }

            if (! $variant->item?->tracksStock()) {
                throw RecipeException::componentHoldsNoStock($name);
            }

            if (! $variant->is_active) {
                throw RecipeException::componentArchived($name);
            }

            // One level. Asked of the database rather than of the loaded
            // relation, because the material was resolved a line ago and its own
            // recipe is not something any caller would have thought to load.
            if (ItemComponent::query()->where('parent_variant_id', $variantId)->exists()) {
                throw RecipeException::componentHasOwnRecipe($name);
            }

            $quantity = Quantity::of($row['quantity'] ?? 0);

            if (! $quantity->isPositive()) {
                throw RecipeException::quantityNotPositive($name);
            }

            // The stock ledger's own rule ({@see StockLedgerService::assertMovable()}),
            // asked here so a recipe it would refuse is never saved: otherwise
            // "0.5 PCS" stores happily and every bill for the rewind is refused
            // at the counter instead.
            $unit = $variant->item->base_uom;

            if (! $quantity->fitsUnit($unit)) {
                throw RecipeException::fractionalQuantity($name, $quantity->trimmed(), $unit->label());
            }

            $seen[$variantId] = true;
            $prepared[] = ['component_variant_id' => $variantId, 'quantity' => $quantity, 'variant' => $variant];
        }

        return $prepared;
    }

    /** What a refusal calls a variant — {@see ItemVariant::fullLabel()}. */
    public function nameOf(ItemVariant $variant): string
    {
        return $variant->fullLabel();
    }
}
