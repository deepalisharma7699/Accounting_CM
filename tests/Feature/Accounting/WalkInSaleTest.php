<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A sale to somebody who would not give a name.
 *
 * Allowed, on one condition: it is paid in full at the counter, because a
 * balance owed by nobody can never be collected. Everything else about it is an
 * ordinary sale — the stock leaves, the GST is owed — and the way back is to
 * cancel it, never a credit note, because there is no account to credit.
 */
class WalkInSaleTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ?ItemVariant $motor = null;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $payments
     */
    private function sell(array $payments, bool $post = true, ?int $partyId = null): TestResponse
    {
        $motor = $this->motor ??= $this->stockedMotor();

        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', array_filter([
                'date' => now()->toDateString(),
                'post' => $post,
                'party_id' => $partyId,
                'items' => [['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
                'payments' => $payments,
            ], fn ($value) => $value !== null));
    }

    private function stockedMotor(): ItemVariant
    {
        $motor = $this->variantFor($this->tenant, 'motor');

        $this->receiveStock($this->tenant, $motor, '4', '8000.00');

        return $motor;
    }

    #[Test]
    public function a_sale_paid_in_full_may_name_no_customer(): void
    {
        $this->sell([['mode' => 'cash', 'amount' => '11800.00']])
            ->assertCreated()
            ->assertJsonPath('data.party_id', null)
            ->assertJsonPath('data.total', '11800.00')
            ->assertJsonPath('data.items.0.cgst_amount', '900.00')
            ->assertJsonPath('data.movements.0.quantity', '-1.000');

        // The stock left and the tax is owed exactly as on a named sale, and
        // nothing is left in Sundry Debtors against nobody.
        $this->assertSame('3.000', $this->stockPositionOf($this->tenant, $this->motor)['quantity']);
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_walk_in_sale_left_partly_unpaid_is_refused_and_moves_nothing(): void
    {
        $this->sell([['mode' => 'cash', 'amount' => '5000.00']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALK_IN_NOT_PAID_IN_FULL');

        $this->sell([])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALK_IN_NOT_PAID_IN_FULL');

        $this->assertSame('4.000', $this->stockPositionOf($this->tenant, $this->motor)['quantity']);
    }

    #[Test]
    public function a_named_customer_may_still_buy_on_credit(): void
    {
        $customer = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));

        $this->sell([], partyId: $customer->id)
            ->assertCreated()
            ->assertJsonPath('data.party_id', $customer->id);
    }

    #[Test]
    public function a_walk_in_draft_may_be_parked_but_posts_only_when_paid_in_full(): void
    {
        $id = $this->sell([['mode' => 'cash', 'amount' => '1000.00']], post: false)
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/post")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALK_IN_NOT_PAID_IN_FULL');
    }

    #[Test]
    public function a_purchase_still_has_to_name_its_vendor(): void
    {
        $copper = $this->variantFor($this->tenant, 'bulk_material');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'items' => [['variant_id' => $copper->id, 'quantity' => '10', 'unit_price' => '700.00']],
                'payments' => [['mode' => 'cash', 'amount' => '8260.00']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_REQUIRED');
    }

    #[Test]
    public function a_walk_in_sale_is_cancelled_rather_than_returned(): void
    {
        $id = $this->sell([['mode' => 'cash', 'amount' => '11800.00']])->assertCreated()->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/return", ['lines' => [['line_no' => 1, 'quantity' => '1']]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_AGAINST_WALK_IN');

        $cashBefore = $this->balanceOf($this->tenant, SystemAccount::Cash);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/reverse")
            ->assertCreated();

        // The motor is back on the shelf and the cash went out the way it came.
        $this->assertSame('4.000', $this->stockPositionOf($this->tenant, $this->motor)['quantity']);
        $this->assertSame(
            bcsub($cashBefore, '11800.00', 2),
            $this->balanceOf($this->tenant, SystemAccount::Cash),
        );
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }

    private function customer(): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }

    #[Test]
    public function a_walk_in_sale_is_reissued_in_the_customers_name_without_moving_stock_or_cash(): void
    {
        $original = $this->sell([['mode' => 'upi', 'amount' => '11800.00', 'reference' => 'UTR1']])
            ->assertCreated()
            ->json('data');

        $cash = $this->balanceOf($this->tenant, SystemAccount::Cash);
        $customer = $this->customer();

        $reissued = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$original['id']}/assign-customer", ['party_id' => $customer->id])
            ->assertCreated()
            ->assertJsonPath('data.party_id', $customer->id)
            ->assertJsonPath('data.total', '11800.00')
            ->assertJsonPath('data.date', $original['date'])
            ->assertJsonPath('data.payments.0.mode', 'upi')
            ->assertJsonPath('data.payments.0.reference', 'UTR1')
            ->json('data');

        $this->assertNotSame($original['doc_no'], $reissued['doc_no']);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$original['id']}")
            ->assertJsonPath('data.status', 'reversed');

        // One motor still gone, the money still in, nothing owed by anybody.
        $this->assertSame('3.000', $this->stockPositionOf($this->tenant, $this->motor)['quantity']);
        $this->assertSame($cash, $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_sale_that_already_names_its_customer_is_not_reassigned(): void
    {
        $customer = $this->customer();
        $id = $this->sell([], partyId: $customer->id)->assertCreated()->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/assign-customer", ['party_id' => $this->customer()->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TRANSACTION_HAS_CUSTOMER');
    }

    #[Test]
    public function reissuing_is_refused_once_the_cost_the_sale_went_out_at_has_moved(): void
    {
        $id = $this->sell([['mode' => 'cash', 'amount' => '11800.00']])->assertCreated()->json('data.id');

        // A dearer delivery since: reissuing now would cost the motor at a new
        // average and restate cost of goods sold.
        $this->receiveStock($this->tenant, $this->motor, '2', '10000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/assign-customer", ['party_id' => $this->customer()->id])
            ->assertJsonPath('error.code', 'REVISION_WOULD_RESTATE_COST');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertJsonPath('data.status', 'posted');
    }

    #[Test]
    public function the_estimate_states_every_figure_with_the_tax_in_and_adds_up_to_the_total(): void
    {
        $motor = $this->motor = $this->stockedMotor();
        $part = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $part, '10', '50.00');

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'items' => [
                    // ₹10,000 before tax, two of them, ₹500 off.
                    ['variant_id' => $motor->id, 'quantity' => '2', 'unit_price' => '10000.00', 'discount' => '500.00'],
                    // ₹118 with the 18% already in it.
                    ['variant_id' => $part->id, 'quantity' => '3', 'unit_price' => '118.00', 'price_includes_tax' => true],
                ],
                'payments' => [['mode' => 'cash', 'amount' => '23364.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $estimate = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.customer', null)
            ->json('data.estimate');

        $this->assertSame('11800.00', $estimate['lines'][0]['rate']);
        $this->assertSame('590.00', $estimate['lines'][0]['discount']);
        $this->assertSame('23010.00', $estimate['lines'][0]['amount']);
        $this->assertSame('118.00', $estimate['lines'][1]['rate']);
        $this->assertSame('354.00', $estimate['lines'][1]['amount']);

        $this->assertSame(
            $estimate['subtotal'],
            bcadd($estimate['lines'][0]['amount'], $estimate['lines'][1]['amount'], 2),
        );
        $this->assertSame('23364.00', $estimate['total']);
        $this->assertSame('23364.00', $estimate['paid']);

        // The customer's copy never carries what anything cost the workshop.
        foreach ($estimate['lines'] as $line) {
            $this->assertSame(['line_no', 'description', 'memo', 'quantity', 'unit_symbol', 'rate', 'discount', 'amount'], array_keys($line));
        }
    }
}
