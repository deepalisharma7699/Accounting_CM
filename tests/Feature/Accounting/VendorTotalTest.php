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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A vendor's bill is the vendor's to round — `vendor_total` on a purchase.
 *
 * The workshop's round-off policy decides what it issues. What it owes a vendor
 * is whatever the vendor printed, rounded their way, so a purchase may carry the
 * printed total and the difference from the lines — up or down — is booked to
 * Round Off. Within a rupee, because a vendor rounds by paise: anything further
 * is a line that does not match the paper.
 *
 * @see \App\Services\Accounting\Posting\Templates\BillTemplate::charge()
 */
class VendorTotalTest extends TestCase
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

        // Haryana, buying from Delhi — the shape of the bill this was built for.
        $this->tenant->update(['state_code' => '06', 'round_off_invoices' => true]);
        $this->tenant->refresh();
    }

    private function party(PartyRole $role, string $state = '07'): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [$role->value],
            'state_code' => $state,
        ]));
    }

    private function variantAt(string $gstRate, string $category = 'part'): ItemVariant
    {
        $variant = $this->variantFor($this->tenant, $category);

        $this->actingForTenant($this->tenant, fn () => $variant->item->update(['gst_rate' => $gstRate]));

        return $variant;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function purchase(array $items, array $extra = [], bool $post = true): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => $post,
                'party_id' => $this->party(PartyRole::Vendor)->id,
                'items' => $items,
                ...$extra,
            ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function preview(array $body): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', $body)
            ->assertOk()
            ->json('data.totals');
    }

    /** One line of ₹5,678.90 at 18% IGST: ₹6,701.10 exactly. */
    private function oneLine(ItemVariant $variant): array
    {
        return [['variant_id' => $variant->id, 'quantity' => '10', 'unit_price' => '567.89']];
    }

    #[Test]
    public function the_vendor_rounding_up_is_owed_and_booked(): void
    {
        $variant = $this->variantAt('18.00');

        // Our own policy would charge ₹6,701; the vendor printed ₹6,702.
        $data = $this->purchase($this->oneLine($variant), ['vendor_total' => '6702.00'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('6702.00', $data['total']);

        // Rounded up on a purchase: the party's credit grew by ninety paise, so
        // Round Off is debited with them.
        $this->assertSame('0.90', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
        $this->assertSame('1022.20', $this->balanceOf($this->tenant, SystemAccount::GstInput));

        // The shelf never sees the rounding — stock arrives at the taxable value.
        $position = $this->stockPositionOf($this->tenant, $variant);
        $this->assertSame('5678.90', $position['value']);
        $this->assertSame('567.89', $position['average_cost']);

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a purchase at the vendor total');
        $this->assertBooksBalance($this->tenant, 'after a purchase at the vendor total');
    }

    #[Test]
    public function the_vendor_total_applies_even_where_the_workshop_does_not_round(): void
    {
        $this->tenant->update(['round_off_invoices' => false]);

        $data = $this->purchase($this->oneLine($this->variantAt('18.00')), ['vendor_total' => '6701'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('6701.00', $data['total']);
        $this->assertSame('-0.10', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
    }

    #[Test]
    public function an_empty_vendor_total_rounds_by_the_workshop_policy(): void
    {
        $data = $this->purchase($this->oneLine($this->variantAt('18.00')))->assertCreated()->json('data');

        $this->assertSame('6701.00', $data['total']);
    }

    #[Test]
    public function a_vendor_total_more_than_a_rupee_out_is_refused(): void
    {
        $this->purchase($this->oneLine($this->variantAt('18.00')), ['vendor_total' => '6911.10'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VENDOR_TOTAL_TOO_FAR')
            ->assertJsonPath('error.details.lines_total', '6701.10')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['vendor_total']]]]);

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function exactly_a_rupee_out_is_still_a_round_off(): void
    {
        $this->purchase($this->oneLine($this->variantAt('18.00')), ['vendor_total' => '6702.10'])
            ->assertCreated()
            ->assertJsonPath('data.total', '6702.10');
    }

    #[Test]
    public function the_preview_reports_a_gap_instead_of_refusing(): void
    {
        $variant = $this->variantAt('18.00');
        $vendor = $this->party(PartyRole::Vendor);
        $body = ['type' => 'purchase', 'party_id' => $vendor->id, 'items' => $this->oneLine($variant)];

        $far = $this->preview([...$body, 'vendor_total' => '6911.10']);
        $this->assertSame('210.00', $far['vendor_total_gap']);
        $this->assertSame('6701.00', $far['total']);

        $near = $this->preview([...$body, 'vendor_total' => '6702.00']);
        $this->assertNull($near['vendor_total_gap']);
        $this->assertSame('6702.00', $near['total']);
        $this->assertSame('0.90', $near['round_off']);
    }

    #[Test]
    public function a_sale_ignores_a_vendor_total(): void
    {
        $variant = $this->variantAt('18.00');
        $this->adjustStock($this->tenant, [[$variant, '100', '100.00']]);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->party(PartyRole::Customer)->id,
                'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '5678.90']],
                'vendor_total' => '6702.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', '6701.00');
    }

    #[Test]
    public function a_draft_carries_its_vendor_total_to_the_posting(): void
    {
        $id = $this->purchase($this->oneLine($this->variantAt('18.00')), ['vendor_total' => '6702.00'], post: false)
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.bill_terms.vendor_total', '6702.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.total', '6702.00');
    }

    #[Test]
    public function a_draft_edit_refuses_a_vendor_total_on_a_sale(): void
    {
        $variant = $this->variantAt('18.00');

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $this->party(PartyRole::Customer)->id,
                'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '100.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", ['vendor_total' => '118.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_BILL_TERMS_NOT_ACCEPTED');
    }

    /* ---------------------------------------------------------------------
     | Every path a purchase takes: draft, continue, correct
     |-------------------------------------------------------------------- */

    private function api(): self
    {
        return $this->withHeaders($this->authHeader($this->owner));
    }

    #[Test]
    public function continuing_a_draft_can_change_the_vendor_total_before_posting(): void
    {
        $variant = $this->variantAt('18.00');
        $id = $this->purchase($this->oneLine($variant), ['vendor_total' => '6702.00'], post: false)
            ->assertCreated()->json('data.id');

        // A whole restatement, as the form sends it: the vendor reprinted the bill.
        $this->api()->patchJson("/api/v1/transactions/{$id}", [
            'items' => $this->oneLine($variant),
            'vendor_total' => '6700.50',
        ])->assertOk()->assertJsonPath('data.bill_terms.vendor_total', '6700.50');

        $this->api()->postJson("/api/v1/transactions/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.total', '6700.50')
            ->assertJsonPath('data.status', 'posted');

        // Rounded down by sixty paise on a purchase: Round Off is credited.
        $this->assertSame('-0.60', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
        $this->assertBooksBalance($this->tenant, 'after a continued draft');
    }

    #[Test]
    public function clearing_the_vendor_total_on_a_draft_returns_it_to_the_workshop_policy(): void
    {
        $variant = $this->variantAt('18.00');
        $id = $this->purchase($this->oneLine($variant), ['vendor_total' => '6702.00'], post: false)
            ->assertCreated()->json('data.id');

        // The form names it as null when the box is empty, so the stored figure
        // cannot survive into the posting.
        $this->api()->patchJson("/api/v1/transactions/{$id}", ['vendor_total' => null])
            ->assertOk()
            ->assertJsonPath('data.bill_terms.vendor_total', null);

        $this->api()->postJson("/api/v1/transactions/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.total', '6701.00');
    }

    #[Test]
    public function a_draft_whose_lines_drift_from_the_vendor_total_is_refused_on_posting(): void
    {
        $variant = $this->variantAt('18.00');
        $id = $this->purchase($this->oneLine($variant), ['vendor_total' => '6702.00'], post: false)
            ->assertCreated()->json('data.id');

        $this->api()->patchJson("/api/v1/transactions/{$id}", [
            'items' => [['variant_id' => $variant->id, 'quantity' => '11', 'unit_price' => '567.89']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'VENDOR_TOTAL_TOO_FAR');

        // Nothing changed: the draft still holds what it held.
        $this->api()->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.quantity', '10');
    }

    #[Test]
    public function correcting_a_purchase_posts_the_replacement_at_its_vendor_total(): void
    {
        $variant = $this->variantAt('18.00');
        $vendor = $this->party(PartyRole::Vendor);

        $bill = $this->api()->postJson('/api/v1/transactions/purchase', [
            'date' => now()->toDateString(), 'post' => true, 'party_id' => $vendor->id,
            'items' => $this->oneLine($variant), 'vendor_total' => '6702.00',
        ])->assertCreated()->json('data.id');

        // Twelve, not ten: ₹6,814.68 taxable, ₹8,041.32 owed, printed as ₹8,041.
        $revision = $this->api()->postJson("/api/v1/transactions/{$bill}/revise", [
            'date' => now()->toDateString(), 'post' => true, 'party_id' => $vendor->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => '12', 'unit_price' => '567.89']],
            'vendor_total' => '8041.00',
        ])->assertCreated()->assertJsonPath('data.total', '8041.00')->json('data.id');

        $this->api()->getJson("/api/v1/transactions/{$bill}")->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertNotSame($bill, $revision);

        // The original's +0.90 reversed out; the replacement's −0.32 remains.
        $this->assertSame('-0.32', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
        $this->assertSame('8041.00', $this->positionOf($this->tenant, $vendor)['payable']);
        $this->assertSame('12.000', $this->stockPositionOf($this->tenant, $variant)['quantity']);
        $this->assertSame('6814.68', $this->stockPositionOf($this->tenant, $variant)['value']);
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a corrected purchase');
        $this->assertBooksBalance($this->tenant, 'after a corrected purchase');
    }

    #[Test]
    public function a_correction_with_a_mismatched_vendor_total_is_refused_and_leaves_the_original(): void
    {
        $variant = $this->variantAt('18.00');
        $vendor = $this->party(PartyRole::Vendor);

        $bill = $this->api()->postJson('/api/v1/transactions/purchase', [
            'date' => now()->toDateString(), 'post' => true, 'party_id' => $vendor->id,
            'items' => $this->oneLine($variant), 'vendor_total' => '6702.00',
        ])->assertCreated()->json('data.id');

        // The form reloads the posted total; the quantity was changed under it.
        $this->api()->postJson("/api/v1/transactions/{$bill}/revise", [
            'date' => now()->toDateString(), 'post' => true, 'party_id' => $vendor->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => '12', 'unit_price' => '567.89']],
            'vendor_total' => '6702.00',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VENDOR_TOTAL_TOO_FAR');

        $this->api()->getJson("/api/v1/transactions/{$bill}")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.total', '6702.00');
        $this->assertSame('10.000', $this->stockPositionOf($this->tenant, $variant)['quantity']);
    }

    #[Test]
    public function paid_in_full_for_less_works_from_the_vendor_total(): void
    {
        // ₹6,702 printed, ₹6,600 paid and called square: the ₹102 is a discount
        // before tax, measured from the vendor's figure rather than ours.
        $totals = $this->purchase($this->oneLine($this->variantAt('18.00')), [
            'vendor_total' => '6702.00',
            'settle_total' => '6600.00',
            'payments' => [['mode' => 'cash', 'amount' => '6600.00']],
        ])->assertCreated()->json('data');

        $this->assertSame('6600.00', $totals['total']);
        $this->assertBooksBalance($this->tenant, 'after settling a vendor total short');

        // And a settled figure above the vendor's total is refused.
        $this->purchase($this->oneLine($this->variantAt('18.00')), [
            'vendor_total' => '6702.00',
            'settle_total' => '6702.50',
            'payments' => [['mode' => 'cash', 'amount' => '6702.50']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'SETTLED_ABOVE_DOCUMENT');
    }

    /* ---------------------------------------------------------------------
     | A sale through the same three paths, at the nearest rupee
     |-------------------------------------------------------------------- */

    private function stockedAt(string $rate): ItemVariant
    {
        $variant = $this->variantAt($rate);
        $this->adjustStock($this->tenant, [[$variant, '100', '100.00']]);

        return $variant;
    }

    /** ₹1,062.64 at nil rate — rounds up to ₹1,063. */
    private function saleLine(ItemVariant $variant, string $quantity = '1'): array
    {
        return [['variant_id' => $variant->id, 'quantity' => $quantity, 'unit_price' => '1062.64']];
    }

    #[Test]
    public function a_sale_draft_continued_and_posted_rounds_to_the_nearest_rupee(): void
    {
        $variant = $this->stockedAt('0.00');
        $customer = $this->party(PartyRole::Customer, '06');

        $id = $this->api()->postJson('/api/v1/transactions/sale', [
            'date' => now()->toDateString(), 'post' => false, 'party_id' => $customer->id,
            'items' => $this->saleLine($variant),
        ])->assertCreated()->json('data.id');

        $this->api()->patchJson("/api/v1/transactions/{$id}", [
            'items' => $this->saleLine($variant, '2'),
            'bill_discount' => null, 'bill_discount_percent' => null, 'settle_total' => null,
        ])->assertOk();

        // ₹2,125.28 → ₹2,125.
        $this->api()->postJson("/api/v1/transactions/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.total', '2125.00');

        $this->assertSame('0.28', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
        $this->assertBooksBalance($this->tenant, 'after a continued sale draft');
    }

    #[Test]
    public function correcting_a_sale_rounds_the_replacement_to_the_nearest_rupee(): void
    {
        $variant = $this->stockedAt('0.00');
        $customer = $this->party(PartyRole::Customer, '06');

        $sale = $this->api()->postJson('/api/v1/transactions/sale', [
            'date' => now()->toDateString(), 'post' => true, 'party_id' => $customer->id,
            'items' => $this->saleLine($variant),
        ])->assertCreated()->assertJsonPath('data.total', '1063.00')->json('data.id');

        $this->api()->postJson("/api/v1/transactions/{$sale}/revise", [
            'date' => now()->toDateString(), 'post' => true, 'party_id' => $customer->id,
            'items' => $this->saleLine($variant, '3'),
        ])->assertCreated()->assertJsonPath('data.total', '3188.00');

        $this->api()->getJson("/api/v1/transactions/{$sale}")->assertOk()->assertJsonPath('data.status', 'reversed');

        // ₹3,187.92 → ₹3,188: eight paise gained, the original's reversed out.
        $this->assertSame('-0.08', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
        $this->assertSame('3188.00', $this->positionOf($this->tenant, $customer)['receivable']);
        $this->assertSame('97.000', $this->stockPositionOf($this->tenant, $variant)['quantity']);
        $this->assertBooksBalance($this->tenant, 'after a corrected sale');
    }

    #[Test]
    public function a_sale_paid_in_full_at_the_rounded_up_figure_is_settled(): void
    {
        $variant = $this->stockedAt('0.00');

        $id = $this->api()->postJson('/api/v1/transactions/sale', [
            'date' => now()->toDateString(), 'post' => true,
            'party_id' => $this->party(PartyRole::Customer, '06')->id,
            'items' => $this->saleLine($variant),
            'payments' => [['mode' => 'cash', 'amount' => '1063.00']],
        ])->assertCreated()->json('data.id');

        $this->api()->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.due', '0.00')
            ->assertJsonPath('data.payment_status', 'paid');
    }

    #[Test]
    public function a_sale_settled_short_after_rounding_up_books_the_difference(): void
    {
        $variant = $this->stockedAt('0.00');

        // ₹1,063 asked, ₹1,000 taken and called square.
        $this->api()->postJson('/api/v1/transactions/sale', [
            'date' => now()->toDateString(), 'post' => true,
            'party_id' => $this->party(PartyRole::Customer, '06')->id,
            'items' => $this->saleLine($variant),
            'settle_total' => '1000.00',
            'payments' => [['mode' => 'cash', 'amount' => '1000.00']],
        ])->assertCreated()->assertJsonPath('data.total', '1000.00');

        $this->assertBooksBalance($this->tenant, 'after a rounded sale settled short');
    }

    /**
     * Arora Bearing Co., ABC/26-27/0510: ten bearing lines at 28% off each,
     * ₹210 freight added after the discount, IGST at 18% on both, rounded up
     * by nine paise to ₹1,11,215.
     */
    #[Test]
    public function a_real_vendor_bill_posts_at_exactly_its_printed_total(): void
    {
        $bearings = [
            ['300', '57.00'], ['160', '98.00'], ['84', '166.00'], ['54', '213.00'], ['54', '237.00'],
            ['28', '243.00'], ['80', '266.00'], ['39', '323.00'], ['6', '617.00'], ['84', '181.00'],
        ];

        $items = array_map(fn (array $row) => [
            'variant_id' => $this->variantAt('18.00')->id,
            'quantity' => $row[0],
            'unit_price' => $row[1],
            'discount_percent' => '28',
        ], $bearings);

        // Freight is a service: no stock, no discount, taxed at the same 18%.
        $items[] = ['variant_id' => $this->variantAt('18.00', 'service')->id, 'quantity' => '1', 'unit_price' => '210.00'];

        $data = $this->purchase($items, ['vendor_total' => '111215.00'])->assertCreated()->json('data');

        $this->assertSame('111215.00', $data['total']);
        $this->assertSame('16964.99', $this->balanceOf($this->tenant, SystemAccount::GstInput));
        // Rounded up by the vendor: nine paise debited to Round Off.
        $this->assertSame('0.09', $this->balanceOf($this->tenant, SystemAccount::RoundOff));

        // The bearings reach the shelf at their discounted value; the freight,
        // bought and not stocked, is expensed to cost of goods.
        $this->assertSame('94039.92', $this->balanceOf($this->tenant, SystemAccount::Inventory));
        $this->assertSame('210.00', $this->balanceOf($this->tenant, SystemAccount::Cogs));

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after the Arora bill');
        $this->assertBooksBalance($this->tenant, 'after the Arora bill');
    }
}
