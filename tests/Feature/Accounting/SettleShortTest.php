<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TransactionLine;
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
 * A bill paid in full at less than it came to — `settle_total`.
 *
 * "₹1,250, they paid ₹1,200, mark it paid." The fifty rupees is a discount, and
 * because it was agreed at the counter, on the invoice, it comes off *before*
 * tax: the taxable value and the GST fall with it. What has to stay true:
 *
 *   * **the bill comes to exactly what was paid**, so it is settled rather than
 *     left a few paise open;
 *   * **the discount is on the lines**, where a credit note pro-rates it and the
 *     GST return reads it;
 *   * **a purchase's stock arrives at the reduced cost**, because that is what
 *     the shelf actually cost;
 *   * **it is only ever a settlement** — paid at that figure, never above the bill.
 *
 * @see \App\Services\Accounting\Posting\Templates\BillTemplate::withBillDiscount()
 */
class SettleShortTest extends TestCase
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
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->tenant->update(['round_off_invoices' => true]);
        $this->tenant->refresh();
    }

    private function party(PartyRole $role): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [$role->value],
            'state_code' => '27',
        ]));
    }

    private function variantAt(string $gstRate, bool $stocked = true): ItemVariant
    {
        $variant = $this->variantFor($this->tenant, 'part');

        $this->actingForTenant($this->tenant, fn () => $variant->item->update(['gst_rate' => $gstRate]));

        if ($stocked) {
            $this->adjustStock($this->tenant, [[$variant, '1000', '100.00']]);
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function bill(string $type, array $body): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$type}", array_merge([
                'date' => now()->toDateString(),
                'post' => true,
            ], $body));
    }

    /**
     * ₹1,059.49 at 18% is ₹1,250.20 — the example the feature was asked for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linesFor(ItemVariant $variant): array
    {
        return [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1059.49']];
    }

    /**
     * @return array<int, TransactionLine>
     */
    private function linesOf(int $transactionId): array
    {
        return $this->actingForTenant($this->tenant, fn () => TransactionLine::query()
            ->where('transaction_id', $transactionId)
            ->orderBy('line_no')
            ->get()
            ->all());
    }

    #[Test]
    public function a_sale_paid_short_in_full_comes_to_what_was_paid_and_is_settled(): void
    {
        $variant = $this->variantAt('18.00');

        $data = $this->bill('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => $this->linesFor($variant),
            'settle_total' => '1200.00',
            'payments' => [['mode' => 'cash', 'amount' => '1200.00']],
        ])->assertCreated()->json('data');

        $this->assertSame('1200.00', $data['total']);

        $bill = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$data['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame('0.00', $bill['due']);
        $this->assertSame('paid', $bill['payment_status']);

        /*
        | The discount is on the line, before tax. ₹1,200 inclusive at 18% is
        | ₹1,016.95 taxable — so about ₹42.54 came off the taxable value and the
        | GST fell by the rest of the ₹50.20.
        */
        $line = $this->linesOf((int) $data['id'])[0];

        $this->assertTrue((float) $line->discount_amount > 42.0 && (float) $line->discount_amount < 43.0);
        $this->assertSame('1059.49', bcadd((string) $line->taxable_value, (string) $line->discount_amount, 2));

        $lineTotal = bcadd((string) $line->taxable_value, bcadd((string) $line->cgst_amount, (string) $line->sgst_amount, 2), 2);

        // A few paise over ₹1,200 at most — what one more paisa of discount would
        // have overshot — and those paise are the round-off, never the customer's.
        $this->assertTrue(bccomp($lineTotal, '1200.00', 2) >= 0 && bccomp($lineTotal, '1200.10', 2) < 0);

        $this->assertBooksBalance($this->tenant, 'after a sale settled short');
    }

    #[Test]
    public function a_sale_with_several_rates_still_lands_on_the_settled_figure(): void
    {
        $motor = $this->variantAt('18.00');
        $rewind = $this->variantAt('12.00');
        $exempt = $this->variantAt('0.00');

        $data = $this->bill('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [
                ['variant_id' => $motor->id, 'quantity' => '3', 'unit_price' => '1234.57'],
                ['variant_id' => $rewind->id, 'quantity' => '1', 'unit_price' => '999.99', 'price_includes_tax' => true],
                ['variant_id' => $exempt->id, 'quantity' => '2', 'unit_price' => '77.77'],
            ],
            'settle_total' => '5000.00',
            'payments' => [['mode' => 'cash', 'amount' => '3000.00'], ['mode' => 'upi', 'amount' => '2000.00']],
        ])->assertCreated()->json('data');

        $this->assertSame('5000.00', $data['total']);

        // Whatever the solve could not reach is a handful of paise in Round Off.
        $this->assertTrue(abs((float) $this->balanceOf($this->tenant, SystemAccount::RoundOff)) < 0.10);
        $this->assertBooksBalance($this->tenant, 'after a mixed-rate sale settled short');
    }

    #[Test]
    public function a_walk_in_sale_can_be_settled_short(): void
    {
        $variant = $this->variantAt('18.00');

        $this->bill('sale', [
            'items' => $this->linesFor($variant),
            'settle_total' => '1200.00',
            'payments' => [['mode' => 'cash', 'amount' => '1200.00']],
        ])->assertCreated()->assertJsonPath('data.total', '1200.00');
    }

    #[Test]
    public function a_purchase_settled_short_puts_stock_on_the_shelf_at_what_it_really_cost(): void
    {
        $variant = $this->variantAt('18.00', stocked: false);

        // ₹1,059.50 taxable, ₹1,250.21 with the GST, ₹1,250 charged.
        $data = $this->bill('purchase', [
            'party_id' => $this->party(PartyRole::Vendor)->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => '10', 'unit_price' => '105.95']],
            'settle_total' => '1200.00',
            'payments' => [['mode' => 'bank', 'amount' => '1200.00', 'reference' => 'NEFT-1']],
        ])->assertCreated()->json('data');

        $this->assertSame('1200.00', $data['total']);

        $line = $this->linesOf((int) $data['id'])[0];
        $position = $this->stockPositionOf($this->tenant, $variant);

        // §8.2 — the shelf holds the reduced taxable value, not the list price.
        $this->assertSame('10.000', $position['quantity']);
        $this->assertSame((string) $line->taxable_value, $position['value']);
        $this->assertTrue((float) $position['value'] < 1059.50);

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a purchase settled short');
        $this->assertBooksBalance($this->tenant, 'after a purchase settled short');
    }

    #[Test]
    public function a_settled_bill_must_be_paid_at_the_settled_figure(): void
    {
        $variant = $this->variantAt('18.00');

        $this->bill('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => $this->linesFor($variant),
            'settle_total' => '1200.00',
            'payments' => [['mode' => 'cash', 'amount' => '1000.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'SETTLED_SHORT_NOT_PAID_IN_FULL');
    }

    #[Test]
    public function a_bill_cannot_be_settled_above_what_it_comes_to(): void
    {
        $variant = $this->variantAt('18.00');

        // ₹1,250.20 is charged as ₹1,250, so ₹1,250.10 is above the bill.
        $this->bill('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => $this->linesFor($variant),
            'settle_total' => '1250.10',
            'payments' => [['mode' => 'cash', 'amount' => '1250.10']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'SETTLED_ABOVE_DOCUMENT');
    }

    #[Test]
    public function settling_at_the_bills_own_total_gives_nothing_away(): void
    {
        $variant = $this->variantAt('18.00');

        $data = $this->bill('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => $this->linesFor($variant),
            'settle_total' => '1250.00',
            'payments' => [['mode' => 'cash', 'amount' => '1250.00']],
        ])->assertCreated()->json('data');

        $this->assertSame('1250.00', $data['total']);
        $this->assertSame('0.00', (string) $this->linesOf((int) $data['id'])[0]->discount_amount);
    }

    #[Test]
    public function the_preview_says_what_the_bill_came_to_before_it_was_settled(): void
    {
        $variant = $this->variantAt('18.00');

        $totals = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'items' => $this->linesFor($variant),
                'settle_total' => '1200.00',
            ])
            ->assertOk()
            ->json('data.totals');

        $this->assertSame('1200.00', $totals['total']);
        $this->assertSame('1250.00', $totals['before_settlement']);
        $this->assertTrue((float) $totals['discount'] > 42.0);

        // And nothing to say on a bill that was not settled short.
        $plain = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', ['type' => 'sale', 'items' => $this->linesFor($variant)])
            ->assertOk()
            ->json('data.totals');

        $this->assertSame('1250.00', $plain['total']);
        $this->assertSame('-0.20', $plain['round_off']);
        $this->assertNull($plain['before_settlement']);
    }

    #[Test]
    public function a_full_return_of_a_settled_sale_credits_what_was_paid(): void
    {
        $variant = $this->variantAt('18.00');
        $customer = $this->party(PartyRole::Customer);

        $bill = $this->bill('sale', [
            'party_id' => $customer->id,
            'items' => $this->linesFor($variant),
            'settle_total' => '1200.00',
            'payments' => [['mode' => 'cash', 'amount' => '1200.00']],
        ])->assertCreated()->json('data');

        // The discount lives on the line, so the credit note carries it too.
        $note = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill['id']}/return", ['lines' => [['line_no' => 1, 'quantity' => '1']]])
            ->assertCreated()
            ->json('data');

        $this->assertSame('1200.00', $note['total']);
        $this->assertBooksBalance($this->tenant, 'after returning a settled sale');
    }
}
