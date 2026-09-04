<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Correcting an invoice that is already in the books — M20.
 *
 * A purchase has been correctable since M17: reverse it and post the corrected
 * bill as one act. An invoice was refused outright, and the reason was sound
 * rather than an oversight — **a sale's replacement re-issues stock at today's
 * weighted average**, which is not what the original issued at, so
 * reverse-and-repost can restate what a sale cost long after the goods went.
 *
 * The dead end went all the same, because "I typed the rate wrong" is the
 * commonest thing a counter needs to fix and the alternative was a reversal plus
 * a full re-entry that nobody discovers. What replaced it is a correction that
 * is **checked rather than trusted**: it posts when the goods are re-issued at
 * the cost they left at, and it rolls back and names the right repair when they
 * would not be.
 *
 * So the two halves under test are:
 *
 *   * the ordinary case — an invoice fixed before anything else moved — posts,
 *     moves stock by the difference, and leaves the books balanced;
 *   * the dangerous case — a delivery has arrived at a different price since —
 *     is refused whole, with the original still standing.
 *
 * @see \App\Services\Accounting\PostingEngine::revise()
 * @see \App\Exceptions\Accounting\RevisionWouldRestateCostException
 */
class SaleCorrectionTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $bearing;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['DELETE', 'TRANSACTIONS'], ['READ', 'LEDGER'], ['READ', 'PARTIES'],
            ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part');
    }

    /* ---------------------------------------------------------------------
     | Harness
     |-------------------------------------------------------------------- */

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function postSale(Party $customer, string $quantity, string $rate = '600.00', array $payments = []): int
    {
        return (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => $rate,
                ]],
                'payments' => $payments,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     */
    private function correct(int $saleId, Party $customer, ?array $items = null, array $extra = []): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$saleId}/revise", array_merge([
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => $items ?? [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '2',
                    'unit_price' => '600.00',
                ]],
            ], $extra));
    }

    private function quantityOnHand(): string
    {
        return $this->stockPositionOf($this->tenant, $this->bearing)['quantity'];
    }

    /* ---------------------------------------------------------------------
     | The ordinary case: an invoice corrected before anything else moved
     |-------------------------------------------------------------------- */

    #[Test]
    public function correcting_the_rate_on_an_invoice_leaves_the_shelf_where_it_was(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        // The commonest mistake there is: the goods are right, the money is not.
        $sale = $this->postSale($customer, '2', '600.00');

        $this->assertSame('8.000', $this->quantityOnHand());

        $corrected = $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '2',
            'unit_price' => '700.00',
        ]])->assertCreated()->json('data');

        $this->assertSame('700.00', $corrected['items'][0]['unit_price']);

        // Two out, two back, two out again — the shelf is exactly where it was.
        $this->assertSame('8.000', $this->quantityOnHand());
        $this->assertBooksBalance($this->tenant, 'after correcting the rate on an invoice');
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after correcting an invoice rate');
    }

    /**
     * The point of the whole guard, stated as an assertion rather than left
     * implicit: correcting the money must not move the cost of goods sold.
     */
    #[Test]
    public function correcting_the_rate_does_not_move_what_the_sale_cost(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');

        $before = $this->balanceOf($this->tenant, SystemAccount::Cogs);

        $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '2',
            'unit_price' => '700.00',
        ]])->assertCreated();

        $this->assertSame(
            $before,
            $this->balanceOf($this->tenant, SystemAccount::Cogs),
            'Correcting what a customer was charged must not restate what the goods cost.',
        );
    }

    #[Test]
    public function correcting_the_quantity_moves_stock_by_the_difference(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        // Measured before the invoice exists, because the arrival itself moved
        // this account — a stock adjustment books its other side here.
        $shelved = $this->balanceOf($this->tenant, SystemAccount::Cogs);

        $sale = $this->postSale($customer, '2');

        $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '3',
            'unit_price' => '600.00',
        ]])->assertCreated();

        // Ten in, three out — the arithmetic of the two documents, not of the
        // second one alone.
        $this->assertSame('7.000', $this->quantityOnHand());

        // The cost moved in proportion, which is what correcting a quantity
        // ought to do and nothing more: three bearings at the 150 they left at,
        // not two at 150 plus one at whatever today says.
        $this->assertSame(
            '450.00',
            Money::of($this->balanceOf($this->tenant, SystemAccount::Cogs))
                ->minus(Money::of($shelved))
                ->amount(),
        );

        $this->assertBooksBalance($this->tenant, 'after correcting the quantity on an invoice');
    }

    #[Test]
    public function correcting_an_invoice_leaves_both_documents_on_the_record(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2');

        $replacement = (int) $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '3',
            'unit_price' => '600.00',
        ]])->assertCreated()->json('data.id');

        $original = $this->actingForTenant($this->tenant, fn () => Transaction::findOrFail($sale));

        // Nothing is erased. The original is cancelled and says so, and the
        // replacement is a document of its own with its own number.
        $this->assertSame('reversed', $original->status->value);
        $this->assertNotSame($sale, $replacement);

        // Three documents where there was one: the original, the reversal
        // that cancelled it, and the replacement.
        $reversal = $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('reverses_id', $sale)->firstOrFail(),
        );

        $this->assertSame('sale', $reversal->type->value);
        $this->assertSame($sale, (int) $reversal->reverses_id);
    }

    /* ---------------------------------------------------------------------
     | The dangerous case: the cost has moved since
     |-------------------------------------------------------------------- */

    #[Test]
    public function correcting_an_invoice_after_the_cost_has_moved_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');

        // A delivery at a different price. The weighted average moves, and the
        // goods on that invoice can no longer be re-issued at what they left at.
        $this->receiveStock($this->tenant, $this->bearing, '10', '250.00');

        $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '2',
            'unit_price' => '700.00',
        ]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVISION_WOULD_RESTATE_COST');
    }

    /**
     * A refusal that had already reversed the original would be far worse than
     * the mistake: the invoice would be cancelled and its replacement nowhere.
     */
    #[Test]
    public function a_refused_correction_writes_nothing_at_all(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');
        $this->receiveStock($this->tenant, $this->bearing, '10', '250.00');

        $before = $this->actingForTenant($this->tenant, fn () => Transaction::query()->count());
        $quantity = $this->quantityOnHand();

        $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '2',
            'unit_price' => '700.00',
        ]])->assertStatus(422);

        $original = $this->actingForTenant($this->tenant, fn () => Transaction::findOrFail($sale));

        $this->assertSame('posted', $original->status->value, 'The original must still stand.');
        $this->assertSame($quantity, $this->quantityOnHand());
        $this->assertSame($before, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->count(),
        ));
        $this->assertBooksBalance($this->tenant, 'after a refused correction');
    }

    /**
     * The refusal names the part and both figures, because "it cannot be
     * corrected" without a reason is what makes somebody conclude the product is
     * broken rather than careful.
     */
    #[Test]
    public function the_refusal_says_which_part_moved_and_by_how_much(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');
        $this->receiveStock($this->tenant, $this->bearing, '10', '250.00');

        $error = $this->correct($sale, $customer, [[
            'variant_id' => $this->bearing->id,
            'quantity' => '2',
            'unit_price' => '700.00',
        ]])->assertStatus(422)->json('error');

        $this->assertSame('150.00', $error['details']['movements'][0]['was']);
        $this->assertStringContainsString('return', strtolower((string) $error['message']));
    }

    /**
     * A labour-only invoice has no cost of goods at all, so there is nothing the
     * guard could be about — and it is the invoice most often typed wrong, since
     * a rewinding charge is a number somebody decides rather than reads off a
     * part.
     */
    #[Test]
    public function a_labour_only_invoice_is_correctable_whatever_the_shelf_has_done(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $labour = $this->variantFor($this->tenant, 'service');

        $sale = (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $labour->id,
                    'quantity' => '1',
                    'unit_price' => '2500.00',
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        // The shelf moves underneath it, and none of it is anything to do with
        // this invoice.
        $this->receiveStock($this->tenant, $this->bearing, '10', '250.00');

        $this->correct($sale, $customer, [[
            'variant_id' => $labour->id,
            'quantity' => '1',
            'unit_price' => '3000.00',
        ]])->assertCreated()->assertJsonPath('data.items.0.unit_price', '3000.00');

        $this->assertBooksBalance($this->tenant, 'after correcting a labour-only invoice');
    }

    /* ---------------------------------------------------------------------
     | The refusals an invoice shares with a purchase bill
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_invoice_with_money_against_it_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        // Paid at the counter. Reversing it would leave the receipt pointing at
        // a cancelled invoice, which is a decision about money.
        $sale = $this->postSale($customer, '2', '600.00', [
            ['mode' => 'cash', 'amount' => '1416.00'],
        ]);

        $this->correct($sale, $customer)->assertStatus(409);
    }

    #[Test]
    public function an_invoice_with_a_credit_note_against_it_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale}/return", [
                'lines' => [['line_no' => 1, 'quantity' => '1']],
            ])
            ->assertCreated();

        $this->correct($sale, $customer)->assertStatus(409);
    }

    #[Test]
    public function a_credit_note_cannot_itself_be_corrected(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');

        $creditNote = (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale}/return", [
                'lines' => [['line_no' => 1, 'quantity' => '1']],
            ])
            ->assertCreated()
            ->json('data.id');

        // It is already the correction to something else. Correcting it is a
        // decision about which correction stands.
        $this->correct($creditNote, $customer)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_REVISABLE');
    }

    #[Test]
    public function tapping_correct_twice_corrects_once(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->postSale($customer, '2', '600.00');

        $body = [
            'date' => now()->toDateString(),
            'post' => true,
            'party_id' => $customer->id,
            'client_ref' => '3f1c0a52-9b7e-4a1d-8c33-11a2b3c4d5e6',
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => '3',
                'unit_price' => '600.00',
            ]],
        ];

        $first = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale}/revise", $body)
            ->assertCreated()
            ->json('data.id');

        // Without the reference the second tap would find the original already
        // reversed and be refused — an error over a correction that had worked.
        $second = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale}/revise", $body)
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame('7.000', $this->quantityOnHand());
    }
}
