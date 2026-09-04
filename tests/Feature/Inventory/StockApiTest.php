<?php

namespace Tests\Feature\Inventory;

use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The HTTP surface of stock.
 *
 * The thing to watch beyond the happy path is what is *absent*: there is no
 * POST, PATCH or DELETE under `/stock` at all. Quantities move by posting a
 * transaction, and a route that changed one without writing a transaction would
 * be the second write path this module is built on not having.
 */
class StockApiTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'STOCK'], ['READ', 'ITEMS'], ['READ', 'LEDGER'],
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_stock_list_reports_a_position_per_variant(): void
    {
        $copper = $this->variantFor($this->tenant, 'bulk_material');

        $this->receiveStock($this->tenant, $copper, '10', '700.00');
        $this->receiveStock($this->tenant, $copper, '10', '800.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('variant_id', $copper->id);

        $this->assertSame('20.000', $row['quantity']);
        $this->assertSame('15000.00', $row['value']);
        $this->assertSame('750.00', $row['average_cost']);
        $this->assertFalse($row['is_negative']);
        $this->assertTrue($row['has_stock']);

        // Decimal strings, not JSON numbers — these get multiplied by costs on
        // the other side.
        $this->assertIsString($row['average_cost']);
    }

    #[Test]
    public function a_variant_that_has_never_moved_reports_zero_rather_than_being_missing(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('variant_id', $bearing->id);

        $this->assertNotNull($row, 'A variant with no movements must still appear, with a zero position.');
        $this->assertSame('0.000', $row['quantity']);
        $this->assertFalse($row['has_stock']);
    }

    #[Test]
    public function a_service_never_appears_on_the_stock_screen(): void
    {
        $labour = $this->serviceVariantFor($this->tenant);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock')
            ->assertOk();

        $this->assertNull(collect($response->json('data'))->firstWhere('variant_id', $labour->id));
    }

    #[Test]
    public function the_low_stock_filter_reads_the_reorder_level(): void
    {
        $low = $this->variantFor($this->tenant, 'part', reorderLevel: '5');
        $fine = $this->variantFor($this->tenant, 'part', reorderLevel: '1');

        $this->receiveStock($this->tenant, $low, '4', '400.00');
        $this->receiveStock($this->tenant, $fine, '9', '400.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?status=low')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('variant_id')->all();

        $this->assertContains($low->id, $ids);
        $this->assertNotContains($fine->id, $ids);

        // The counts are over everything the text filters matched, not over the
        // filtered page — a badge that changed when you clicked it would be
        // contradicting the screen it opened.
        $this->assertSame(1, $response->json('meta.totals.low'));
    }

    #[Test]
    public function a_negative_position_is_reported_separately_from_a_low_one(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part', reorderLevel: '5');

        $this->receiveStock($this->tenant, $bearing, '2', '400.00');
        $this->issueStock($this->tenant, $bearing, '5');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('variant_id', $bearing->id);

        $this->assertTrue($row['is_negative']);
        // Not "low". Low stock is a purchasing decision; negative stock is a
        // data problem, and showing them the same way trains people to ignore
        // the second.
        $this->assertFalse($row['is_low']);
        $this->assertSame(1, $response->json('meta.totals.negative'));
    }

    #[Test]
    public function the_summary_reconciles_the_shelf_against_the_inventory_account(): void
    {
        $copper = $this->variantFor($this->tenant, 'bulk_material');

        $this->receiveStock($this->tenant, $copper, '10', '700.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock/summary')
            ->assertOk()
            ->assertJsonPath('data.value', '7000.00')
            ->assertJsonPath('data.inventory_account.balance', '7000.00')
            ->assertJsonPath('data.difference', '0.00')
            ->assertJsonPath('data.reconciles', true);
    }

    #[Test]
    public function the_summary_hides_the_ledger_half_from_somebody_who_cannot_read_the_books(): void
    {
        [$tenant, $clerk] = $this->tenantWithUser([['READ', 'STOCK']]);

        $response = $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/stock/summary')
            ->assertOk();

        // The stock half is legitimately theirs — a clerk billing a bearing has
        // to know whether there is one.
        $this->assertArrayHasKey('value', $response->json('data'));
        $this->assertArrayNotHasKey('inventory_account', $response->json('data'));
    }

    #[Test]
    public function the_stock_card_shows_a_running_balance(): void
    {
        $copper = $this->variantFor($this->tenant, 'bulk_material');

        $this->receiveStock($this->tenant, $copper, '10', '700.00');
        $this->receiveStock($this->tenant, $copper, '10', '800.00');
        $this->issueStock($this->tenant, $copper, '4');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/stock/variants/{$copper->id}")
            ->assertOk();

        $movements = $response->json('data.movements');

        $this->assertCount(3, $movements);
        $this->assertSame('10.000', $movements[0]['balance_quantity']);
        $this->assertSame('750.00', $movements[1]['balance_average_cost']);
        $this->assertSame('16.000', $movements[2]['balance_quantity']);
        $this->assertSame('-3000.00', $movements[2]['value']);

        $this->assertSame('12000.00', $response->json('data.closing.value'));
    }

    /* ---------------------------------------------------------------------
     | Writing — through a transaction, and only through one
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_adjustment_posts_quantities_and_journal_entries_together(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/stock-adjustment', [
                'date' => now()->toDateString(),
                'notes' => 'Opening count',
                'post' => true,
                'adjustments' => [
                    ['variant_id' => $bearing->id, 'quantity' => '6', 'unit_cost' => '450.00', 'memo' => 'Found'],
                ],
            ])
            ->assertCreated();

        $this->assertSame('2700.00', $response->json('data.movements.0.value'));
        $this->assertSame('6.000', $response->json('data.movements.0.quantity'));
        $this->assertCount(2, $response->json('data.lines'));

        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_adjustment_of_zero_is_refused_at_the_form(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/stock-adjustment', [
                'date' => now()->toDateString(),
                'post' => true,
                'adjustments' => [['variant_id' => $bearing->id, 'quantity' => '0']],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function another_workshops_variant_does_not_resolve(): void
    {
        [$other] = $this->tenantWithUser();
        $theirs = $this->variantFor($other, 'part');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/stock-adjustment', [
                'date' => now()->toDateString(),
                'post' => true,
                'adjustments' => [['variant_id' => $theirs->id, 'quantity' => '3', 'unit_cost' => '400.00']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_VARIANT_UNKNOWN');
    }

    #[Test]
    public function reading_stock_needs_its_own_grant(): void
    {
        [, $clerk] = $this->tenantWithUser([['READ', 'ITEMS']]);

        // Holding the catalogue is not holding the position. Knowing the
        // workshop deals in 5 HP motors and knowing four are on the shelf are
        // different questions.
        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/stock')
            ->assertForbidden();
    }

    #[Test]
    public function there_is_no_route_that_writes_stock_directly(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');

        foreach ([
            ['post', '/api/v1/stock'],
            ['patch', "/api/v1/stock/variants/{$bearing->id}"],
            ['delete', "/api/v1/stock/variants/{$bearing->id}"],
        ] as [$method, $url]) {
            $this->withHeaders($this->authHeader($this->owner))
                ->json(strtoupper($method), $url)
                ->assertStatus(405, "There must be no {$method} {$url} — stock moves only by posting a transaction.");
        }
    }

    #[Test]
    public function the_meta_endpoint_publishes_the_movement_vocabulary(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock/meta')
            ->assertOk();

        $this->assertSame(
            ['in', 'out', 'adjust', 'opening'],
            collect($response->json('data.movement_types'))->pluck('value')->all(),
        );
    }

    #[Test]
    public function the_stock_screen_is_scoped_to_the_callers_workshop(): void
    {
        [$other] = $this->tenantWithUser();
        $theirs = $this->variantFor($other, 'part');
        $this->receiveStock($other, $theirs, '10', '400.00');

        $mine = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $mine, '2', '100.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('variant_id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertSame('200.00', $response->json('meta.totals.value'));
    }

    #[Test]
    public function a_variant_id_from_another_workshop_is_a_404_on_the_card(): void
    {
        [$other] = $this->tenantWithUser();
        $theirs = $this->variantFor($other, 'part');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/stock/variants/{$theirs->id}")
            ->assertNotFound();
    }

    #[Test]
    public function an_archived_variant_keeps_its_stock_but_leaves_the_default_list(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $bearing, '4', '400.00');

        $this->actingForTenant($this->tenant, fn () => ItemVariant::whereKey($bearing->id)->update(['is_active' => false]));

        $default = $this->withHeaders($this->authHeader($this->owner))->getJson('/api/v1/stock')->assertOk();
        $this->assertNull(collect($default->json('data'))->firstWhere('variant_id', $bearing->id));

        // But it is still findable, and still worth ₹1,600 — archiving means
        // "no new business", not "this stock evaporated".
        $all = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?is_active=0')
            ->assertOk();

        $row = collect($all->json('data'))->firstWhere('variant_id', $bearing->id);

        $this->assertSame('1600.00', $row['value']);
    }
}
