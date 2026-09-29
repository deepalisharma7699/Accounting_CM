<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /**
     * The floor is a second level, and a shortage of its own.
     *
     * `min_stock` had a column, a validator, a form field and a place in every
     * payload for as long as `reorder_level` has — and **nothing read it**. A
     * workshop could write "never below 5" against a part, watch it go to 2, and
     * find no screen in the product that mentioned it. A figure somebody is
     * asked for and no screen ever uses is worse than one that was never asked
     * for: they believe it is doing something.
     *
     * The two levels answer different questions. The migration that added the
     * second says it plainest — a shop orders at 20 and panics at 5 — so this
     * walks a shelf down through both.
     *
     * The comparison is **strictly** below where `is_low` is at-or-below, which
     * is the one place the two could reasonably have been written the same. A
     * trigger has to fire on the day the shelf reaches it. A floor is a line the
     * stock is still standing on when it is exactly there, and a workshop told
     * it had broken its own rule at precisely the number it wrote down would
     * stop reading the alarm.
     */
    #[Test]
    public function the_floor_is_a_second_level_and_a_shortage_of_its_own(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part', reorderLevel: '20', minStock: '5');
        $headers = $this->authHeader($this->owner);

        $row = function (ItemVariant $variant) use ($headers) {
            return collect(
                $this->withHeaders($headers)->getJson('/api/v1/stock')->assertOk()->json('data')
            )->firstWhere('variant_id', $variant->id);
        };

        // Six on the shelf: past the trigger, above the floor.
        $this->receiveStock($this->tenant, $bearing, '6', '400.00');

        $six = $row($bearing);

        $this->assertSame('20.000', $six['reorder_level']);
        $this->assertSame('5.000', $six['min_stock']);
        $this->assertTrue($six['is_low']);
        $this->assertFalse($six['is_below_minimum']);

        // Five. Exactly the figure they wrote down, and not yet under it.
        $this->issueStock($this->tenant, $bearing, '1');

        $this->assertFalse($row($bearing)['is_below_minimum']);

        // Four. Now it is under.
        $this->issueStock($this->tenant, $bearing, '1');

        $four = $row($bearing);

        $this->assertTrue($four['is_below_minimum']);
        // Still low as well — both are true of one shelf, and the row carries
        // both verdicts rather than one status the client has to unpick.
        $this->assertTrue($four['is_low']);

        /*
        | And the case that had no reporting anywhere: a floor with no trigger
        | above it.
        |
        | `is_low` is false, correctly — nobody said what low means for this one.
        | Before the floor was read, that made it indistinguishable from a full
        | shelf on every screen in the product.
        */
        $capacitor = $this->variantFor($this->tenant, 'part', minStock: '5');
        $this->receiveStock($this->tenant, $capacitor, '2', '90.00');

        $short = $row($capacitor);

        $this->assertFalse($short['is_low']);
        $this->assertTrue($short['is_below_minimum']);
        $this->assertNull($short['reorder_level']);

        // The filter answers for it, and the vocabulary the client builds that
        // filter from names it — the meta endpoint exists so a screen does not
        // keep its own copy of this list.
        $filtered = $this->withHeaders($headers)
            ->getJson('/api/v1/stock?status=below_minimum')
            ->assertOk();

        $ids = collect($filtered->json('data'))->pluck('variant_id')->all();

        $this->assertContains($bearing->id, $ids);
        $this->assertContains($capacitor->id, $ids);

        $this->assertContains('below_minimum', collect(
            $this->withHeaders($headers)->getJson('/api/v1/stock/meta')->assertOk()->json('data.statuses')
        )->pluck('value')->all());
    }

    /**
     * A negative position is nobody's shortage.
     *
     * It is a data problem, and putting it on a purchasing worklist as well
     * would be one variant on two lists with two different fixes — so both
     * levels stand down for it, whichever of them was set.
     */
    #[Test]
    public function a_negative_position_is_under_no_level_because_it_is_not_a_shortage(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part', reorderLevel: '20', minStock: '5');

        $this->receiveStock($this->tenant, $bearing, '2', '400.00');
        $this->issueStock($this->tenant, $bearing, '5');

        $row = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/stock')->assertOk()->json('data')
        )->firstWhere('variant_id', $bearing->id);

        $this->assertTrue($row['is_negative']);
        $this->assertFalse($row['is_low']);
        $this->assertFalse($row['is_below_minimum']);
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
     | Searching — the bill form's item picker runs on this endpoint
     |-------------------------------------------------------------------- */

    /**
     * Typing the specification narrows the family instead of emptying it.
     *
     * The reported case: twenty capacitors under one family called "Capacitor",
     * each variant labelled with its capacitance. `capacitor` returned the
     * endpoint's first page and `capacitor 36` returned **nothing at all** —
     * matched as one phrase, `items.name` is "Capacitor" and does not contain
     * "capacitor 36", `item_variants.label` is "36 MFD" and does not either, and
     * no column anywhere holds both words. So the one thing a person does to cut
     * twenty rows down to one was the one thing that guaranteed an empty picker.
     */
    #[Test]
    public function a_search_narrows_on_every_word_rather_than_matching_the_whole_phrase(): void
    {
        $this->capacitors();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?search=capacitor+36')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('36 MFD', $response->json('data.0.display_label'));

        // Order is not significance: the family name and the spec are matched
        // independently, so either way round finds the same row.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?search=36+capacitor')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // And a word that matches nothing removes the row rather than being
        // ignored — the AND is real.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?search=capacitor+36+crompton')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A capped page says so, over the whole matched set.
     *
     * The picker asks for twelve and draws its "n more not shown" line off this
     * figure. It has to count every match rather than the slice, or the notice
     * would report nought on exactly the search that needed it.
     */
    #[Test]
    public function a_capped_search_reports_how_many_matched_in_total(): void
    {
        $this->capacitors();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?search=capacitor&per_page=12')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.pagination.total', 20)
            ->assertJsonPath('meta.pagination.has_more', true);
    }

    /**
     * A typed wildcard is a character, not an operator.
     *
     * `%` and `_` are LIKE's own, so `MFD_A` had matched `MFD-A` and `MFD A`
     * as well — a SKU search that answered with three different parts.
     */
    #[Test]
    public function a_search_matches_likes_wildcards_literally(): void
    {
        $this->actingForTenant($this->tenant, function () {
            $item = Item::factory()->ofCategory('part')->create(['name' => 'Capacitor clamp']);

            ItemVariant::factory()->for($item)->create(['label' => 'MFD-A']);
            ItemVariant::factory()->for($item)->create(['label' => 'MFD_A']);
        });

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?search=MFD_A')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('MFD_A', $response->json('data.0.display_label'));
    }

    /**
     * One family, twenty specifications — the shape of the reported case.
     *
     * Labelled rather than left to `derivedLabel()`, because what is stored in
     * `item_variants.label` is what the search can currently see at all; the
     * attributes bag is not reached by any query, which is the next thing to fix.
     *
     * @return array<int, string>
     */
    private function capacitors(): array
    {
        $labels = [
            '2.5 MFD', '4 MFD', '6 MFD', '8 MFD', '9 MFD', '12 MFD', '16 MFD',
            '18 MFD', '20 MFD', '24 MFD', '25 MFD', '30 MFD', '36 MFD', '40 MFD',
            '45 MFD', '50 MFD', '60 MFD', '72 MFD', '80 MFD', '100 MFD',
        ];

        $this->actingForTenant($this->tenant, function () use ($labels) {
            $item = Item::factory()->ofCategory('part')->create(['name' => 'Capacitor']);

            foreach ($labels as $label) {
                ItemVariant::factory()->for($item)->create(['label' => $label]);
            }
        });

        return $labels;
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

    /**
     * The stalled request, retried — and the shelf moves once.
     *
     * Every write form in this application sends a `client_ref` minted per
     * document and reused on every attempt, and this is the endpoint where
     * getting it wrong costs the most: a duplicated sale is a wrong figure
     * somebody eventually notices, a duplicated count is a shelf that silently
     * disagrees with itself and an Inventory account that agrees with the
     * wrong one.
     *
     * The count form only started sending one in P5. This is the contract it now
     * depends on, asserted against the movement rather than against a status
     * code (§8.2).
     */
    #[Test]
    public function a_repeated_count_corrects_the_shelf_once(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');

        $payload = [
            'date' => now()->toDateString(),
            'notes' => 'Stock-take, March',
            'post' => true,
            'client_ref' => (string) Str::uuid(),
            'adjustments' => [
                ['variant_id' => $bearing->id, 'quantity' => '6', 'unit_cost' => '450.00'],
            ],
        ];

        $first = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/stock-adjustment', $payload)
            ->assertCreated()
            ->json('data');

        $second = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/stock-adjustment', $payload)
            // 200 rather than 201: nothing was created this time, and the status
            // is how the client tells the two apart.
            ->assertOk()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);

        // One document, and — the part that matters — six bearings, not twelve.
        $this->assertSame(1, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('type', 'stock_adjustment')->count()
        ));

        $this->assertSame('6.000', $this->stockPositionOf($this->tenant, $bearing)['quantity']);
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }

    /**
     * The Items host posts the document the Stock host posts.
     *
     * `components/stock-adjust.js` has two modes over one form. Stock counts a
     * shelf — several lines, the signed difference typed straight in. Items
     * corrects one variant from its drawer — the operator types *what is on the
     * shelf* and the component subtracts the position to get the difference.
     *
     * The conversion is arithmetic in the browser, which nothing here can run.
     * What this asserts is the other half, and the half that would break: the
     * endpoint takes the document that arithmetic produces, unchanged, and makes
     * the same kind of document out of it. There was **no server change** for the
     * second host, so nothing else records that the contract is now shared — and
     * a field added for the count screen alone would 422 a drawer nobody tests
     * by hand.
     *
     * Three properties of the variant host's payload are the ones at risk:
     * exactly one line, a **computed** signed difference rather than a typed one,
     * and `unit_cost: null` on a reduction — because what is missing off a shelf
     * is written off at what the books were carrying it at, never at a rate the
     * counter chose. `notes` arrives null as well; that form does not ask.
     */
    #[Test]
    public function the_items_host_posts_the_same_document_as_the_stock_host(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');
        $headers = $this->authHeader($this->owner);

        // Ten on the shelf at 450, so there is a book average for a shortage to
        // be valued at.
        $this->receiveStock($this->tenant, $bearing, '10', '450.00');

        // The Stock host: the operator typed the difference, and left the cost
        // box empty because the count found fewer than the books say.
        $counted = $this->withHeaders($headers)
            ->postJson('/api/v1/transactions/stock-adjustment', [
                'date' => now()->toDateString(),
                'notes' => 'Stock-take, March',
                'post' => true,
                'client_ref' => (string) Str::uuid(),
                'adjustments' => [
                    ['variant_id' => $bearing->id, 'quantity' => '-2', 'unit_cost' => null],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $bearing)['quantity']);

        /*
        | The Items host, from the drawer of the same variant.
        |
        | Eight on the books; the operator counted eleven. The component sends
        | the difference it worked out — never the eleven — and a rate, because
        | this time stock was *found* and found stock has to be valued at
        | something.
        */
        $shelved = $this->withHeaders($headers)
            ->postJson('/api/v1/transactions/stock-adjustment', [
                'date' => now()->toDateString(),
                'notes' => null,
                'post' => true,
                'client_ref' => (string) Str::uuid(),
                'adjustments' => [
                    ['variant_id' => $bearing->id, 'quantity' => '3', 'unit_cost' => '460.00'],
                ],
            ])
            ->assertCreated()
            ->json('data');

        // Two documents of one kind. Neither host has a type, a template or a
        // route of its own, and this is what says so.
        $this->assertSame('stock_adjustment', $counted['type']);
        $this->assertSame($counted['type'], $shelved['type']);
        $this->assertNotSame($counted['id'], $shelved['id']);

        /*
        | And the shelf reads what the operator counted.
        |
        | Eleven, at 8 x 450 plus 3 x 460 — the shortage taken out at the book
        | average of 450 and the surplus brought in at the rate that was typed.
        | §8.2: the stock impact, not that the request succeeded.
        */
        $position = $this->stockPositionOf($this->tenant, $bearing);

        $this->assertSame('11.000', $position['quantity']);
        $this->assertSame('4980.00', $position['value']);

        $this->actingForTenant($this->tenant, fn () => $this->assertSame(
            3,
            Transaction::query()->where('type', 'stock_adjustment')->count(),
        ));

        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
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

    /* ---------------------------------------------------------------------
     | Asking about named variants
     |
     | The bill form's "4 PCS on hand" is captured when a line is picked, and a
     | posting anywhere else moves it. This is how the open document brings every
     | line back up to date in one request instead of one request per line.
     |-------------------------------------------------------------------- */

    #[Test]
    public function naming_variants_answers_for_exactly_those(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');
        $copper = $this->variantFor($this->tenant, 'bulk_material');
        $unasked = $this->variantFor($this->tenant, 'part');

        $this->receiveStock($this->tenant, $bearing, '4', '400.00');
        $this->receiveStock($this->tenant, $copper, '10', '700.00');
        $this->receiveStock($this->tenant, $unasked, '9', '100.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/stock?variant_ids[]={$bearing->id}&variant_ids[]={$copper->id}")
            ->assertOk();

        $rows = collect($response->json('data'));

        $this->assertCount(2, $rows);
        $this->assertSame('4.000', $rows->firstWhere('variant_id', $bearing->id)['quantity']);
        $this->assertSame('10.000', $rows->firstWhere('variant_id', $copper->id)['quantity']);
        $this->assertNull($rows->firstWhere('variant_id', $unasked->id));
    }

    #[Test]
    public function naming_an_archived_variant_still_answers_for_it(): void
    {
        /*
        | A bill written last week can carry a line for something archived since,
        | and the default list deliberately hides those. Dropping it here would
        | answer a question about two lines with one position and say nothing
        | about the other — leaving the stale figure on screen looking current.
        */
        $bearing = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $bearing, '4', '400.00');

        $this->actingForTenant($this->tenant, fn () => ItemVariant::whereKey($bearing->id)->update(['is_active' => false]));

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/stock?variant_ids[]={$bearing->id}")
            ->assertOk();

        $this->assertSame('4.000', collect($response->json('data'))->firstWhere('variant_id', $bearing->id)['quantity']);
    }

    #[Test]
    public function another_workshops_variant_cannot_be_named(): void
    {
        [$other] = $this->tenantWithUser();
        $theirs = $this->variantFor($other, 'part');
        $this->receiveStock($other, $theirs, '7', '100.00');

        $mine = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $mine, '2', '100.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/stock?variant_ids[]={$theirs->id}&variant_ids[]={$mine->id}")
            ->assertOk();

        $rows = collect($response->json('data'));

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows->first()['variant_id']);
    }

    #[Test]
    public function the_named_variant_list_is_bounded(): void
    {
        // Not a way to ask for the whole report in one go: the cap is what keeps
        // it a lookup for the lines on one document.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock?'.collect(range(1, 201))->map(fn ($id) => "variant_ids[]={$id}")->implode('&'))
            ->assertStatus(422);
    }
}
