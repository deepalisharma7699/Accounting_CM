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

        if ($parent->item?->tracksStock()) {
            throw RecipeException::parentHoldsStock($this->nameOf($parent));
        }

        $prepared = $this->validate($parent, $rows);

        return DB::transaction(function () use ($parent, $prepared) {
            ItemComponent::query()->where('parent_variant_id', $parent->id)->delete();

            $written = new Collection;

            foreach ($prepared as $row) {
                $written->push(ItemComponent::create([
                    // Stamped from the parent rather than from the context, the
                    // rule every child table here follows: a recipe row can
                    // never end up in a different workshop from the variant it
                    // belongs to.
                    'tenant_id' => $parent->tenant_id,
                    'parent_variant_id' => $parent->id,
                    'component_variant_id' => $row['component_variant_id'],
                    'quantity' => $row['quantity']->amount(),
                ]));
            }

            return $written;
        });
    }

    /**
     * Check every row, and resolve each material once.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{component_variant_id: int, quantity: Quantity}>
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

            $seen[$variantId] = true;
            $prepared[] = ['component_variant_id' => $variantId, 'quantity' => $quantity];
        }

        return $prepared;
    }

    private function nameOf(ItemVariant $variant): string
    {
        $family = $variant->item?->name;
        $label = $variant->displayLabel();

        return trim(($family === null ? '' : $family.' · ').$label, ' ·');
    }
}
