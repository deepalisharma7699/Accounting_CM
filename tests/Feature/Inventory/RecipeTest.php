<?php

namespace Tests\Feature\Inventory;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidReturnException;
use App\Exceptions\Accounting\RecipeException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemComponent;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Accounting\BillPreviewService;
use App\Services\Accounting\ReturnService;
use App\Services\Inventory\ItemComponentService;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\ItemVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * What a made thing consumes.
 *
 * A rewind is sold as one line at one price and takes copper, varnish and
 * sleeve off three shelves. Before recipes the catalogue could not say so: a
 * service held no stock of its own, so it issued nothing, and the wire was
 * bought and never taken out. The shelf, the Inventory account and every margin
 * the workshop read moved wrong together and in the same direction.
 *
 * Every scenario here asserts the same invariant on the way out as M9's own
 * checklist does — the shelf agrees with the Inventory account — because that
 * is the one this feature can break silently, and the way it would break is by
 * issuing stock the ledger does not know about.
 */
class RecipeTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function recipes(): ItemComponentService
    {
        return app(ItemComponentService::class);
    }

    private function variants(): ItemVariantService
    {
        return app(ItemVariantService::class);
    }

    /** Give a made thing its recipe, through the write path a screen uses. */
    private function compose(Tenant $tenant, ItemVariant $made, array $rows): void
    {
        $this->actingForTenant($tenant, fn () => $this->variants()->update($made, [
            'components' => array_map(fn (array $row) => [
                'component_variant_id' => $row[0]->id,
                'quantity' => $row[1],
            ], $rows),
        ]));
    }

    private function customer(Tenant $tenant): Party
    {
        return $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }

    private function sell(Tenant $tenant, Party $party, ItemVariant $variant, string $quantity, string $price): Transaction
    {
        return $this->actingForTenant($tenant, fn () => $this->engine()->postComposed(TransactionType::Sale, [
            'date' => now()->toDateString(),
            'party_id' => $party->id,
            'items' => [[
                'item_id' => $variant->item_id,
                'variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_price' => $price,
            ]],
        ]));
    }

    /* ---------------------------------------------------------------------
     | Posting
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_rewind_issues_every_material_it_is_made_of(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $varnish = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->receiveStock($tenant, $copper, '20', '700.00');
        $this->receiveStock($tenant, $varnish, '5', '300.00');

        $this->compose($tenant, $rewind, [[$copper, '2.5'], [$varnish, '0.4']]);

        // Measured from here rather than from nought: `receiveStock()` posts a
        // stock adjustment, which credits COGS with the stock it finds.
        $cogsBefore = $this->balanceOf($tenant, SystemAccount::Cogs);

        $sale = $this->sell($tenant, $this->customer($tenant), $rewind, '2', '4500.00');

        // One movement per material, and none for the service itself: it has no
        // shelf of its own, which is the whole reason it has a recipe.
        $this->assertCount(2, $sale->stockMovements);

        $this->assertSame('15.000', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame('4.200', $this->stockPositionOf($tenant, $varnish)['quantity']);

        // 5 kg × ₹700 + 0.8 L × ₹300.
        $this->assertSame('3740.00', bcsub($this->balanceOf($tenant, SystemAccount::Cogs), $cogsBefore, 2));

        // Revenue is the service's, undivided — the materials are a cost, never
        // a second line on the customer's bill.
        $this->assertSame('9000.00', $this->balanceOf($tenant, SystemAccount::ServiceIncome));

        $this->assertBooksBalance($tenant);
        $this->assertStockAgreesWithInventoryAccount($tenant);
    }

    #[Test]
    public function the_line_costs_every_material_and_not_the_first_one_found(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $varnish = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->receiveStock($tenant, $copper, '20', '700.00');
        $this->receiveStock($tenant, $varnish, '5', '300.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5'], [$varnish, '0.4']]);

        $sale = $this->sell($tenant, $this->customer($tenant), $rewind, '2', '4500.00');

        $line = $this->actingForTenant($tenant, fn () => TransactionLine::with('stockMovements')
            ->where('transaction_id', $sale->id)
            ->first());

        // The guard on `TransactionLine::stockMovements()` being plural. As a
        // `hasOne` this answered ₹240 — the varnish alone — and reported a
        // margin ₹3,500 too high, with nothing on the screen looking wrong.
        $this->assertSame('3740.00', $line->cost()?->amount());
        $this->assertSame('5260.00', $line->margin()?->amount());
    }

    #[Test]
    public function a_quantity_is_scaled_in_thousandths_and_not_in_floats(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->receiveStock($tenant, $copper, '100', '700.00');

        // 12.3 − 4.1 is not 8.2 in a float, and `decimal(15,3)` refuses what
        // comes out of one. Three of these is 36.900 exactly.
        $this->compose($tenant, $rewind, [[$copper, '12.3']]);
        $this->sell($tenant, $this->customer($tenant), $rewind, '3', '4500.00');

        $this->assertSame('63.100', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertStockAgreesWithInventoryAccount($tenant);
    }

    #[Test]
    public function reversing_a_rewind_puts_every_material_back_at_the_cost_it_left_at(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $varnish = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->receiveStock($tenant, $copper, '20', '700.00');
        $this->receiveStock($tenant, $varnish, '5', '300.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5'], [$varnish, '0.4']]);

        $before = $this->balanceOf($tenant, SystemAccount::Inventory);
        $cogsBefore = $this->balanceOf($tenant, SystemAccount::Cogs);
        $sale = $this->sell($tenant, $this->customer($tenant), $rewind, '2', '4500.00');

        $this->actingForTenant($tenant, fn () => $this->engine()->reverse($sale, null, 'Wrong motor', null, true));

        $this->assertSame('20.000', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame('5.000', $this->stockPositionOf($tenant, $varnish)['quantity']);
        $this->assertSame($before, $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertSame($cogsBefore, $this->balanceOf($tenant, SystemAccount::Cogs));

        $this->assertBooksBalance($tenant);
        $this->assertStockAgreesWithInventoryAccount($tenant);
    }

    /* ---------------------------------------------------------------------
     | Saying so before it happens
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_preview_names_the_materials_and_warns_before_the_shelf_runs_out(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->receiveStock($tenant, $copper, '20', '700.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $priced = $this->actingForTenant($tenant, fn () => app(BillPreviewService::class)->preview(
            TransactionType::Sale,
            [
                'date' => now()->toDateString(),
                'party_id' => $this->customer($tenant)->id,
                'items' => [[
                    'item_id' => $rewind->item_id,
                    'variant_id' => $rewind->id,
                    'quantity' => '9',
                    'unit_price' => '4500.00',
                ]],
            ],
        ));

        // The counter is told on the line, before it promises the work.
        $this->assertCount(1, $priced['lines'][0]['consumes']);
        $this->assertSame('22.5', $priced['lines'][0]['consumes'][0]['quantity']);
        // Which material, so the form can tell when it is also billed by hand.
        $this->assertSame($copper->id, $priced['lines'][0]['consumes'][0]['variant_id']);

        // And the shortfall names the *copper*, not the rewind — the rewind has
        // no shelf to be short of.
        $this->assertCount(1, $priced['stock']);
        $this->assertSame($copper->id, $priced['stock'][0]['variant_id']);
        $this->assertFalse($priced['can_post']);
    }

    /* ---------------------------------------------------------------------
     | Correcting one
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_made_line_is_refused_a_credit_note_rather_than_half_credited(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->receiveStock($tenant, $copper, '20', '700.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $sale = $this->sell($tenant, $this->customer($tenant), $rewind, '1', '4500.00');

        $this->expectException(InvalidReturnException::class);

        $this->actingForTenant($tenant, fn () => app(ReturnService::class)
            ->returnAgainst($sale, [['line_no' => 1, 'quantity' => '1']]));
    }

    /* ---------------------------------------------------------------------
     | What may be written down
     |-------------------------------------------------------------------- */

    #[Test]
    public function something_counted_on_a_shelf_cannot_also_be_made_from_a_recipe(): void
    {
        [$tenant] = $this->tenantWithUser();

        $bearing = $this->variantFor($tenant, 'part');
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->expectException(RecipeException::class);

        $this->compose($tenant, $bearing, [[$copper, '1']]);
    }

    #[Test]
    public function a_material_has_to_be_something_that_is_counted(): void
    {
        [$tenant] = $this->tenantWithUser();

        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $labour = $this->serviceVariantFor($tenant, '400.00');

        $this->expectException(RecipeException::class);

        $this->compose($tenant, $rewind, [[$labour, '1']]);
    }

    #[Test]
    public function a_recipe_is_replaced_whole_so_the_last_row_can_be_cleared(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $varnish = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->compose($tenant, $rewind, [[$copper, '2.5'], [$varnish, '0.4']]);
        $this->assertCount(2, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));

        $this->compose($tenant, $rewind, [[$copper, '3']]);
        $this->assertCount(1, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));

        // An empty list means "this consumes nothing", which a merge could not
        // express — it is why the write is a replacement.
        $this->compose($tenant, $rewind, []);
        $this->assertCount(0, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));
    }

    #[Test]
    public function an_edit_that_says_nothing_about_the_recipe_leaves_it_alone(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $this->actingForTenant($tenant, fn () => $this->variants()->update($rewind, ['sell_price' => '4700.00']));

        $this->assertCount(1, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));
    }

    #[Test]
    public function the_same_material_twice_is_refused_rather_than_summed(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->expectException(RecipeException::class);

        // Two rows would both be issued, which is twice what the row somebody
        // was looking at appeared to say.
        $this->compose($tenant, $rewind, [[$copper, '1'], [$copper, '2']]);
    }

    /* ---------------------------------------------------------------------
     | Refused when the recipe is written, not when the rewind is billed
     |-------------------------------------------------------------------- */

    /** The refusal's code, so a test can say which one it expected. */
    private function refusalOf(callable $work): ?string
    {
        try {
            $work();
        } catch (RecipeException $refused) {
            return $refused->errorCode();
        }

        return null;
    }

    private function items(): ItemService
    {
        return app(ItemService::class);
    }

    /** Write a recipe row directly, the way data from before a rule could exist. */
    private function storeRowDirectly(Tenant $tenant, ItemVariant $parent, ItemVariant $material, string $quantity): void
    {
        $this->actingForTenant($tenant, fn () => ItemComponent::create([
            'tenant_id' => $tenant->id,
            'parent_variant_id' => $parent->id,
            'component_variant_id' => $material->id,
            'quantity' => $quantity,
        ]));
    }

    /** @return array<int, AuditLog> */
    private function recipeHistory(Tenant $tenant): array
    {
        return $this->actingForTenant($tenant, fn () => AuditLog::query()
            ->where('resource', AuditResource::Recipe->value)
            ->orderBy('id')
            ->get()
            ->all());
    }

    #[Test]
    public function half_of_something_counted_in_whole_pieces_is_refused_when_the_recipe_is_written(): void
    {
        [$tenant] = $this->tenantWithUser();

        $sleeve = $this->variantFor($tenant, 'part');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->assertSame('piece', $sleeve->item->base_uom->value);

        // The stock ledger refuses 0.5 of a piece at posting, so a recipe that
        // says it would stop every bill for the rewind at the counter.
        $this->assertSame('RECIPE_QUANTITY_FRACTIONAL', $this->refusalOf(
            fn () => $this->compose($tenant, $rewind, [[$sleeve, '0.5']]),
        ));
        $this->assertCount(0, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));

        // A whole number is fine, and a fraction of something weighed still is.
        $copper = $this->variantFor($tenant, 'bulk_material');
        $this->compose($tenant, $rewind, [[$sleeve, '2'], [$copper, '2.5']]);
        $this->assertCount(2, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));
    }

    #[Test]
    public function a_material_cannot_stop_being_counted_while_a_recipe_takes_it_off_the_shelf(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $this->assertSame('RECIPE_MATERIAL_IN_USE', $this->refusalOf(
            fn () => $this->actingForTenant($tenant, fn () => $this->items()->update($copper->item_id, ['is_stock' => false])),
        ));
        $this->assertTrue($this->actingForTenant($tenant, fn () => Item::find($copper->item_id)->is_stock));

        // Once no recipe uses it, the switch is the shop's to make again.
        $this->compose($tenant, $rewind, []);
        $this->actingForTenant($tenant, fn () => $this->items()->update($copper->item_id, ['is_stock' => false]));
        $this->assertFalse($this->actingForTenant($tenant, fn () => Item::find($copper->item_id)->is_stock));
    }

    #[Test]
    public function a_made_thing_cannot_start_being_counted_while_it_has_a_recipe(): void
    {
        [$tenant] = $this->tenantWithUser();

        // A rewind filed under a stock-holding category, with stock tracking
        // off — the other way a workshop sets one up besides "Service".
        $rewind = $this->variantFor($tenant, 'motor');
        $this->actingForTenant($tenant, fn () => Item::whereKey($rewind->item_id)->update(['is_stock' => false]));
        $rewind->unsetRelation('item');

        $copper = $this->variantFor($tenant, 'bulk_material');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $this->assertSame('RECIPE_PARENT_WOULD_HOLD_STOCK', $this->refusalOf(
            fn () => $this->actingForTenant($tenant, fn () => $this->items()->update($rewind->item_id, ['is_stock' => true])),
        ));
        $this->assertFalse($this->actingForTenant($tenant, fn () => Item::find($rewind->item_id)->is_stock));

        $this->compose($tenant, $rewind, []);
        $this->actingForTenant($tenant, fn () => $this->items()->update($rewind->item_id, ['is_stock' => true]));
        $this->assertTrue($this->actingForTenant($tenant, fn () => Item::find($rewind->item_id)->is_stock));
    }

    #[Test]
    public function a_service_asked_to_keep_stock_is_overruled_rather_than_refused_over_its_recipe(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        // A service category can never hold stock, so the request changes
        // nothing — and an edit form that always sends the box must still save.
        $this->actingForTenant($tenant, fn () => $this->items()->update($rewind->item_id, ['is_stock' => true, 'name' => 'Rewind 5 HP']));

        $this->assertSame('Rewind 5 HP', $this->actingForTenant($tenant, fn () => Item::find($rewind->item_id)->name));
        $this->assertCount(1, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($rewind->id)));
    }

    #[Test]
    public function a_recipe_left_on_something_now_stocked_can_still_be_cleared(): void
    {
        [$tenant] = $this->tenantWithUser();

        $bearing = $this->variantFor($tenant, 'part');
        $copper = $this->variantFor($tenant, 'bulk_material');
        $this->storeRowDirectly($tenant, $bearing, $copper, '1.000');

        // Writing one is refused; clearing one is the way out, and is not.
        $this->assertSame('RECIPE_PARENT_HOLDS_STOCK', $this->refusalOf(
            fn () => $this->compose($tenant, $bearing, [[$copper, '2']]),
        ));

        $this->compose($tenant, $bearing, []);
        $this->assertCount(0, $this->actingForTenant($tenant, fn () => $this->recipes()->forVariant($bearing->id)));
    }

    #[Test]
    public function a_material_already_in_a_recipe_cannot_be_given_one_of_its_own(): void
    {
        [$tenant] = $this->tenantWithUser();

        $sleeve = $this->variantFor($tenant, 'part');
        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$sleeve, '1']]);

        // Reached only by data from before the switch was guarded — which is
        // exactly why the recipe write asks again rather than trusting it.
        $this->actingForTenant($tenant, fn () => Item::whereKey($sleeve->item_id)->update(['is_stock' => false]));
        $sleeve->unsetRelation('item');

        $this->assertSame('RECIPE_WOULD_NEST', $this->refusalOf(
            fn () => $this->compose($tenant, $sleeve, [[$copper, '0.2']]),
        ));
    }

    #[Test]
    public function a_material_a_recipe_uses_cannot_be_deleted_and_the_refusal_names_the_recipe(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        try {
            $this->actingForTenant($tenant, fn () => $this->variants()->delete($copper));
            $this->fail('The material was deleted out from under a recipe.');
        } catch (RecipeException $refused) {
            $this->assertSame('RECIPE_MATERIAL_IN_USE', $refused->errorCode());
            $this->assertSame(409, $refused->status());
            $this->assertStringContainsString($rewind->item->name, $refused->getMessage());

            // Named as a product, and never as "Rewind · Rewind" where the
            // variant has no name beyond its product's.
            $this->assertStringContainsString($copper->item->name, $refused->getMessage());
            $this->assertStringNotContainsString(
                $rewind->item->name.' · '.$rewind->item->name,
                $refused->getMessage(),
            );
        }

        $this->assertNotNull($this->actingForTenant($tenant, fn () => ItemVariant::find($copper->id)));
    }

    #[Test]
    public function deleting_a_made_thing_takes_its_recipe_with_it_and_history_says_so(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $this->actingForTenant($tenant, fn () => $this->variants()->delete(ItemVariant::with('item')->find($rewind->id)));

        $this->assertSame(0, $this->actingForTenant($tenant, fn () => ItemComponent::query()->count()));

        $actions = array_map(fn (AuditLog $log) => $log->action, $this->recipeHistory($tenant));
        $this->assertSame([AuditAction::Created, AuditAction::Deleted], $actions);
    }

    #[Test]
    public function history_records_what_changed_on_a_recipe_and_nothing_when_nothing_did(): void
    {
        [$tenant] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $varnish = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->compose($tenant, $rewind, [[$copper, '2.5'], [$varnish, '0.4']]);
        $this->assertCount(2, $this->recipeHistory($tenant));

        // Saving the screen again with the recipe untouched — which every save
        // of a rewind's price does — writes nothing.
        $this->compose($tenant, $rewind, [[$copper, '2.500'], [$varnish, '0.4']]);
        $this->assertCount(2, $this->recipeHistory($tenant));

        $ids = $this->actingForTenant($tenant, fn () => ItemComponent::query()->orderBy('id')->pluck('id')->all());

        // More copper, no varnish: one change and one removal, and the copper
        // row keeps its id rather than being deleted and written again.
        $this->compose($tenant, $rewind, [[$copper, '3']]);

        // The removal is written first, then the change.
        $history = $this->recipeHistory($tenant);
        $this->assertCount(4, $history);
        $this->assertSame(AuditAction::Deleted, $history[2]->action);
        $this->assertSame(AuditAction::Updated, $history[3]->action);
        $this->assertEquals(['from' => '2.500', 'to' => '3.000'], $history[3]->changed_fields['quantity']);
        $this->assertSame(
            [$ids[0]],
            $this->actingForTenant($tenant, fn () => ItemComponent::query()->pluck('id')->all()),
        );

        // Named on the trail by both sides, not "Recipe #12".
        foreach ($history as $entry) {
            $this->assertStringContainsString(' consumes ', $entry->label);
        }
    }

    /* ---------------------------------------------------------------------
     | Over HTTP, the way the Items screen writes one
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_rewind_can_be_created_with_its_recipe_in_one_save(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $varnish = $this->variantFor($tenant, 'bulk_material');

        $created = $this->withHeaders($this->authHeader($owner))
            ->postJson('/api/v1/items', [
                'name' => 'Motor Rewinding',
                'category_id' => $this->categoryId('service', $tenant),
                'gst_rate' => '18',
                'variants' => [
                    ['variant_label' => '5 HP', 'sell_price' => '4500.00', 'components' => [
                        ['component_variant_id' => $copper->id, 'quantity' => '2.5'],
                        ['component_variant_id' => $varnish->id, 'quantity' => '0.4'],
                    ]],
                    // A rating with no recipe yet, and one that never sent the
                    // key at all: both are ordinary.
                    ['variant_label' => '7.5 HP', 'sell_price' => '6000.00', 'components' => []],
                    ['variant_label' => '10 HP', 'sell_price' => '8000.00'],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(3, 'data.variants');

        // Variants come back in label order, so found by label.
        $byLabel = collect($created->json('data.variants'))->keyBy('label');

        $this->assertCount(2, $byLabel['5 HP']['components']);
        $this->assertSame('2.500', $byLabel['5 HP']['components'][0]['quantity']);
        $this->assertSame([], $byLabel['7.5 HP']['components']);
        $this->assertSame([], $byLabel['10 HP']['components']);

        $this->assertSame(2, $this->actingForTenant($tenant, fn () => ItemComponent::query()->count()));
    }

    #[Test]
    public function a_refused_recipe_on_create_leaves_no_product_behind_and_names_its_block(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $sleeve = $this->variantFor($tenant, 'part');
        $before = $this->actingForTenant($tenant, fn () => Item::query()->count());

        $this->withHeaders($this->authHeader($owner))
            ->postJson('/api/v1/items', [
                'name' => 'Motor Rewinding',
                'category_id' => $this->categoryId('service', $tenant),
                'gst_rate' => '18',
                'variants' => [
                    ['variant_label' => '5 HP', 'sell_price' => '4500.00'],
                    ['variant_label' => '7.5 HP', 'components' => [
                        ['component_variant_id' => $sleeve->id, 'quantity' => '0.5'],
                    ]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RECIPE_QUANTITY_FRACTIONAL')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['variants.1.components']]]]);

        $this->assertSame($before, $this->actingForTenant($tenant, fn () => Item::query()->count()));
        $this->assertSame(0, $this->actingForTenant($tenant, fn () => ItemComponent::query()->count()));
    }

    #[Test]
    public function a_recipe_on_a_stocked_product_is_refused_on_create(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->withHeaders($this->authHeader($owner))
            ->postJson('/api/v1/items', [
                'name' => 'Rewound Bush',
                'category_id' => $this->categoryId('part', $tenant),
                'gst_rate' => '18',
                'is_stock' => true,
                'variants' => [['variant_label' => '5 HP', 'attributes' => ['size' => '6205'], 'components' => [
                    ['component_variant_id' => $copper->id, 'quantity' => '2.5'],
                ]]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RECIPE_PARENT_HOLDS_STOCK');

        $this->assertSame(0, $this->actingForTenant($tenant, fn () => Item::query()->where('name', 'Rewound Bush')->count()));
    }

    #[Test]
    public function a_recipe_refusal_on_a_variant_edit_is_pinned_to_the_recipe(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');

        $this->withHeaders($this->authHeader($owner))
            ->patchJson("/api/v1/items/{$rewind->item_id}/variants/{$rewind->id}", [
                'components' => [
                    ['component_variant_id' => $copper->id, 'quantity' => '1'],
                    ['component_variant_id' => $copper->id, 'quantity' => '2'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RECIPE_DUPLICATE_COMPONENT')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['components']]]]);

        $this->withHeaders($this->authHeader($owner))
            ->patchJson("/api/v1/items/{$rewind->item_id}/variants/{$rewind->id}", [
                'components' => [['component_variant_id' => $copper->id, 'quantity' => '2.5']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.components')
            ->assertJsonPath('data.components.0.component_variant_id', $copper->id);
    }

    #[Test]
    public function switching_a_material_off_over_http_is_refused_on_the_stock_box(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $this->withHeaders($this->authHeader($owner))
            ->patchJson("/api/v1/items/{$copper->item_id}", ['is_stock' => false])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RECIPE_MATERIAL_IN_USE')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['is_stock']]]]);
    }

    #[Test]
    public function deleting_a_material_over_http_is_refused_in_words_not_as_a_database_error(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $this->withHeaders($this->authHeader($owner))
            ->deleteJson("/api/v1/items/{$copper->item_id}/variants/{$copper->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RECIPE_MATERIAL_IN_USE');
    }

    /**
     * The edit form is opened from the catalogue list, not from the variant
     * endpoint. Without the recipe on the list the form showed "nothing yet"
     * for a rewind that had one — and its next save sent an empty recipe,
     * which is a replacement, and wiped it.
     */
    #[Test]
    public function the_catalogue_list_and_the_product_carry_each_variants_recipe(): void
    {
        [$tenant, $owner] = $this->tenantWithUser();

        $copper = $this->variantFor($tenant, 'bulk_material');
        $rewind = $this->serviceVariantFor($tenant, '4500.00');
        $this->compose($tenant, $rewind, [[$copper, '2.5']]);

        $listed = $this->withHeaders($this->authHeader($owner))
            ->getJson('/api/v1/items?with_variants=1&per_page=100')
            ->assertOk();

        $row = collect($listed->json('data'))->firstWhere('id', $rewind->item_id);
        $this->assertNotNull($row);
        $this->assertCount(1, $row['variants'][0]['components']);
        $this->assertSame($copper->id, $row['variants'][0]['components'][0]['component_variant_id']);

        // And a stocked product carries an empty list rather than nothing, so
        // the form can tell "no recipe" from "not told".
        $material = collect($listed->json('data'))->firstWhere('id', $copper->item_id);
        $this->assertSame([], $material['variants'][0]['components']);

        $this->withHeaders($this->authHeader($owner))
            ->getJson("/api/v1/items/{$rewind->item_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.variants.0.components')
            ->assertJsonPath('data.variants.0.components.0.quantity', '2.500');
    }
}
