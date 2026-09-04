<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Returns — M18, and the brief's §36 scenarios 5 and 6.
 *
 * The thing this module exists to establish is that a return is *not* a
 * reversal. The invoice stays posted and stays true; some of what it supplied
 * has come back, and it can come back again next week until nothing is left.
 * Nearly every assertion below is a variation on that.
 *
 * The other thing worth watching is the money: a credit note has to give back
 * exactly what was charged, and put the stock back at exactly what it left at.
 * "Exactly" is doing real work in both halves — a rounding either way leaves
 * value in the Inventory account with no stock under it.
 */
class ReturnTest extends TestCase
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
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part');
    }

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function sell(Party $customer, array $items, array $payments = []): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', array_filter([
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => $items,
                'payments' => $payments,
            ]))
            ->assertCreated()
            ->json('data');
    }

    /**
     * Stock bought in rather than adjusted in, so COGS starts at zero and the
     * assertions below say what they mean. An adjustment credits COGS with the
     * whole value of what it found, which is correct and would drown out the
     * figures these tests are actually about.
     *
     * @return array<string, mixed>
     */
    private function buy(string $quantity, string $unitPrice): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->party(PartyRole::Vendor)->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]],
            ])
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function returnAgainst(int $billId, array $lines): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$billId}/return", ['lines' => $lines]);
    }

    private function show(int $id): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->json('data');
    }

    /* ---------------------------------------------------------------------
     | §36 scenario 5 — a customer returns part of a bill
     |-------------------------------------------------------------------- */

    #[Test]
    public function returning_one_of_four_bearings_credits_a_quarter_of_the_line(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        // Four at ₹600 = ₹2,400 + 18% = ₹2,832.
        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $note = $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])
            ->assertCreated()
            ->json('data');

        $this->assertSame('sales_return', $note['type']);
        $this->assertSame('708.00', $note['total']);
        $this->assertSame($bill['id'], $note['against_transaction_id']);

        // Its own series, not a continuation of the invoice run.
        $this->assertStringStartsWith('CN/', (string) $note['doc_no']);

        // The invoice is untouched: still posted, still worth ₹2,832. Three of
        // the four bearings are still the customer's.
        $after = $this->show($bill['id']);
        $this->assertSame('posted', $after['status']);
        $this->assertSame('2832.00', $after['total']);

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function the_returned_bearing_goes_back_on_the_shelf_at_what_it_left_at(): void
    {
        $customer = $this->party(PartyRole::Customer);

        // Two deliveries at different rates, so the weighted average is not
        // either of them and a re-valuation would be visible.
        $this->receiveStock($this->tenant, $this->bearing, '4', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        // The shelf is empty and the average has nothing to average, then a new
        // delivery arrives dearer.
        $this->receiveStock($this->tenant, $this->bearing, '4', '900.00');

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();

        // Five on the shelf: four at ₹900 and the returned one at ₹400 — the
        // price it actually left at, not today's ₹900 average.
        $position = $this->stockPositionOf($this->tenant, $this->bearing);

        $this->assertSame('5.000', $position['quantity']);
        $this->assertSame('4000.00', $position['value']);

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a partial sales return');
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_credit_note_gives_back_the_revenue_the_tax_and_the_cost(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->buy('10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();

        // Sold four, took one back: three at ₹600 of revenue, tax on three, and
        // three bearings' worth of cost.
        $this->assertSame('1800.00', $this->balanceOf($this->tenant, SystemAccount::Sales));
        $this->assertSame('324.00', $this->balanceOf($this->tenant, SystemAccount::GstOutput));
        $this->assertSame('1200.00', $this->balanceOf($this->tenant, SystemAccount::Cogs));

        // The purchase left ₹4,720 owed to the supplier on the payables side;
        // the customer's ₹2,124 is what is left of the invoice after the credit.
        $this->assertSame('2124.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
    }

    /**
     * A whole return and a reversal reach the same balances — and are different
     * records. That is the distinction M18 exists to draw.
     */
    #[Test]
    public function returning_everything_leaves_the_invoice_posted_where_a_reversal_would_not(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->buy('10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '4']])->assertCreated();

        foreach ([SystemAccount::Receivables, SystemAccount::Sales, SystemAccount::GstOutput, SystemAccount::Cogs] as $account) {
            $this->assertSame('0.00', $this->balanceOf($this->tenant, $account), "{$account->value} should net to nothing.");
        }

        // The invoice is still posted, and still says what it said. It was a
        // real sale that was wholly returned, which is not the same fact as a
        // sale that never happened.
        $this->assertSame('posted', $this->show($bill['id'])['status']);

        $this->assertSame('10.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a full sales return');
        $this->assertBooksBalance($this->tenant);
    }

    /* ---------------------------------------------------------------------
     | §36 scenario 6 — returning more than was billed
     |-------------------------------------------------------------------- */

    #[Test]
    public function returning_more_than_was_billed_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '5']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_EXCEEDS_REMAINING')
            ->assertJsonPath('error.details.remaining', '4');
    }

    #[Test]
    public function a_second_return_is_measured_against_what_is_left(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '3']])->assertCreated();

        // One left. Asking for two is refused, and the message says how many.
        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '2']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_EXCEEDS_REMAINING')
            ->assertJsonPath('error.details.remaining', '1');

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();

        // And now nothing is.
        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.remaining', '0');
    }

    #[Test]
    public function two_partial_returns_credit_the_whole_cost_and_no_more(): void
    {
        $customer = $this->party(PartyRole::Customer);

        // A cost that does not divide evenly, so a share rounded twice would
        // leave a paisa behind and this test would see it.
        $this->buy('3', '333.33');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '3', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();
        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '2']])->assertCreated();

        // Everything is back, and the Inventory account is back to exactly what
        // it started at — no paise stranded by rounding a share twice.
        $this->assertSame('3.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Cogs));

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after two partial returns');
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function two_rows_naming_one_line_are_added_together_before_being_checked(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        // Three and three is six against a line of four. Unfolded, each row
        // would be measured against the full remainder and both would pass.
        $this->returnAgainst($bill['id'], [
            ['line_no' => 1, 'quantity' => '3'],
            ['line_no' => 1, 'quantity' => '3'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_EXCEEDS_REMAINING');
    }

    /* ---------------------------------------------------------------------
     | What the invoice is now worth — against M16
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_return_reduces_what_is_due_on_the_invoice(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->assertSame('2832.00', $this->show($bill['id'])['due']);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();

        $after = $this->show($bill['id']);

        // ₹708 credited back. Reported beside `paid` rather than folded into it,
        // because nobody handed over any money — the goods came back.
        $this->assertSame('708.00', $after['credited']);
        $this->assertSame('0.00', $after['paid']);
        $this->assertSame('2124.00', $after['due']);
        $this->assertSame('partial', $after['payment_status']);
    }

    #[Test]
    public function an_invoice_returned_in_full_is_settled_without_a_rupee_moving(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '4']])->assertCreated();

        $after = $this->show($bill['id']);

        $this->assertSame('0.00', $after['due']);
        // Not on anybody's chasing list, which is the point of counting a credit
        // note towards the status at all.
        $this->assertSame('paid', $after['payment_status']);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?type=sale&outstanding=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_part_paid_invoice_that_is_part_returned_reports_both(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell(
            $customer,
            [['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00']],
            [['mode' => 'cash', 'amount' => '1000.00']],
        );

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();

        $after = $this->show($bill['id']);

        $this->assertSame('1000.00', $after['paid']);
        $this->assertSame('708.00', $after['credited']);
        $this->assertSame('1124.00', $after['due']);
    }

    /* ---------------------------------------------------------------------
     | Purchases, and the refusals
     |-------------------------------------------------------------------- */

    #[Test]
    public function goods_sent_back_to_a_supplier_leave_at_what_they_arrived_at(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        $bill = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '10', 'unit_price' => '400.00']],
            ])
            ->assertCreated()
            ->json('data');

        $note = $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '4']])
            ->assertCreated()
            ->json('data');

        $this->assertSame('purchase_return', $note['type']);
        $this->assertStringStartsWith('DN/', (string) $note['doc_no']);

        // Six left on the shelf at ₹400, and the input tax on four given back.
        $this->assertSame('6.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertSame('2400.00', $this->balanceOf($this->tenant, SystemAccount::Inventory));
        $this->assertSame('432.00', $this->balanceOf($this->tenant, SystemAccount::GstInput));

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a purchase return');
        $this->assertBooksBalance($this->tenant);
    }

    /**
     * M17's D6 applies to a purchase return exactly as it applies to a sale:
     * sending back what the shelf does not hold would take the position negative.
     */
    #[Test]
    public function a_purchase_return_is_refused_when_the_stock_has_already_been_sold(): void
    {
        $both = $this->party(PartyRole::Customer, PartyRole::Vendor);

        $bill = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $both->id,
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '10', 'unit_price' => '400.00']],
            ])
            ->assertCreated()
            ->json('data');

        // Eight of the ten are sold on, so only two can go back.
        $this->sell($both, [['variant_id' => $this->bearing->id, 'quantity' => '8', 'unit_price' => '600.00']]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '5']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');
    }

    #[Test]
    public function a_draft_cannot_be_returned_against(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->returnAgainst($draft, [['line_no' => 1, 'quantity' => '1']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_NOT_POSTED');
    }

    #[Test]
    public function a_receipt_cannot_be_returned_against(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $receipt = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '500.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->returnAgainst($receipt, [['line_no' => 1, 'quantity' => '1']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_TARGET_INVALID');
    }

    #[Test]
    public function a_line_that_is_not_on_the_bill_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 7, 'quantity' => '1']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_LINE_UNKNOWN');
    }

    /* ---------------------------------------------------------------------
     | The screen behind it
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_returnable_endpoint_says_what_is_left_on_each_line(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $labour = $this->serviceVariantFor($this->tenant);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
            ['variant_id' => $labour->id, 'quantity' => '2', 'unit_price' => '800.00'],
        ]);

        $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])->assertCreated();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$bill['id']}/returnable")
            ->assertOk();

        $response->assertJsonPath('data.0.billed', '4.000')
            ->assertJsonPath('data.0.returned', '1.000')
            ->assertJsonPath('data.0.remaining', '3.000')
            // Labour can be credited back too — an hour billed in error is as
            // real a mistake as a bearing — and it simply moves no stock.
            ->assertJsonPath('data.1.remaining', '2.000');

        $response->assertJsonCount(1, 'meta.returns');
    }

    #[Test]
    public function labour_can_be_credited_back_and_moves_no_stock(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $labour = $this->serviceVariantFor($this->tenant);

        $bill = $this->sell($customer, [
            ['variant_id' => $labour->id, 'quantity' => '2', 'unit_price' => '800.00'],
        ]);

        $note = $this->returnAgainst($bill['id'], [['line_no' => 1, 'quantity' => '1']])
            ->assertCreated()
            ->json('data');

        // ₹800 + 18% given back off Service Income, and nothing at all off the
        // shelf: an hour is produced at the moment it is sold and there is
        // nothing to put back.
        $this->assertSame('944.00', $note['total']);
        $this->assertSame('800.00', $this->balanceOf($this->tenant, SystemAccount::ServiceIncome));
        $this->assertSame([], $note['movements'] ?? []);

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_repeated_return_submission_does_not_put_the_stock_back_twice(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->sell($customer, [
            ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
        ]);

        $ref = (string) \Illuminate\Support\Str::uuid();
        $payload = ['client_ref' => $ref, 'lines' => [['line_no' => 1, 'quantity' => '1']]];

        $first = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill['id']}/return", $payload)
            ->assertCreated()
            ->json('data.id');

        $second = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill['id']}/return", $payload)
            ->assertOk()
            ->json('data.id');

        $this->assertSame($first, $second);

        // Seven on the shelf, not eight — which is the failure a duplicate
        // credit note would cause and the reason M17's guard matters most here.
        $this->assertSame('7.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertSame(1, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('type', 'sales_return')->count()
        ));
    }
}
