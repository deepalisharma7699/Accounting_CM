<?php

namespace Tests\Feature\Inventory;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidReturnException;
use App\Exceptions\Accounting\RecipeException;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Accounting\BillPreviewService;
use App\Services\Accounting\ReturnService;
use App\Services\Inventory\ItemComponentService;
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

        $sale = $this->sell($tenant, $this->customer($tenant), $rewind, '2', '4500.00');

        // One movement per material, and none for the service itself: it has no
        // shelf of its own, which is the whole reason it has a recipe.
        $this->assertCount(2, $sale->stockMovements);

        $this->assertSame('15.000', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame('4.200', $this->stockPositionOf($tenant, $varnish)['quantity']);

        // 5 kg × ₹700 + 0.8 L × ₹300.
        $this->assertSame('3740.00', $this->balanceOf($tenant, SystemAccount::Cogs));

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
        $sale = $this->sell($tenant, $this->customer($tenant), $rewind, '2', '4500.00');

        $this->actingForTenant($tenant, fn () => $this->engine()->reverse($sale, null, 'Wrong motor', null, true));

        $this->assertSame('20.000', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame('5.000', $this->stockPositionOf($tenant, $varnish)['quantity']);
        $this->assertSame($before, $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::Cogs));

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
}
