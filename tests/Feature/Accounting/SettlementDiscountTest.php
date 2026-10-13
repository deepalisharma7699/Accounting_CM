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
 * A receipt or payment that settles a posted invoice for less — `discount`.
 *
 * The invoice is already in the books and its GST already declared, so the
 * fifty rupees a customer is let off cannot come off the sale any more. It is
 * written off after tax: Discount Allowed on a receipt, Discount Received on a
 * vendor payment. What has to stay true:
 *
 *   * **the invoice reads as settled** and the party owes nothing;
 *   * **the money lines are only the money** — the cash book sees ₹1,200;
 *   * **the GST does not move**;
 *   * **a discount closes what is owed and never more**;
 *   * **a reversal takes back both** the money and the write-off.
 */
class SettlementDiscountTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $part;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->part = $this->variantFor($this->tenant, 'part');
        $this->actingForTenant($this->tenant, fn () => $this->part->item->update(['gst_rate' => '18.00']));
        $this->adjustStock($this->tenant, [[$this->part, '100', '100.00']]);
    }

    private function party(PartyRole $role): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [$role->value],
            'state_code' => '27',
        ]));
    }

    /** A bill on credit: ₹1,059.49 at 18% is ₹1,250.20. */
    private function billOnCredit(string $type, Party $party): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$type}", [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $party->id,
                'items' => [['variant_id' => $this->part->id, 'quantity' => '1', 'unit_price' => '1059.49']],
            ])
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function settle(string $type, Party $party, string $amount, ?string $discount, array $extra = []): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$type}", array_merge([
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $party->id,
                'payments' => [['mode' => 'cash', 'amount' => $amount]],
                'discount' => $discount,
            ], $extra));
    }

    private function show(int $id): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->json('data');
    }

    #[Test]
    public function a_receipt_with_a_discount_settles_the_invoice_and_the_customer_owes_nothing(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $bill = $this->billOnCredit('sale', $customer);
        $gstBefore = $this->balanceOf($this->tenant, SystemAccount::GstOutput);
        $cashBefore = $this->balanceOf($this->tenant, SystemAccount::Cash);

        $receipt = $this->settle('receipt', $customer, '1200.00', '50.20')->assertCreated()->json('data');

        // What it cleared off the customer, which is what M16 allocated.
        $this->assertSame('1250.20', $receipt['total']);
        $this->assertSame('50.20', $this->show((int) $receipt['id'])['discount']);

        $this->assertSame('0.00', $this->show((int) $bill['id'])['due']);
        $this->assertSame('0.00', $this->positionOf($this->tenant, $customer)['net']);

        // The money is only the money; the fifty is its own account; the tax stands.
        $this->assertSame('1200.00', bcsub($this->balanceOf($this->tenant, SystemAccount::Cash), $cashBefore, 2));
        $this->assertSame('50.20', $this->balanceOf($this->tenant, SystemAccount::DiscountAllowed));
        $this->assertSame($gstBefore, $this->balanceOf($this->tenant, SystemAccount::GstOutput));

        $this->assertBooksBalance($this->tenant, 'after a receipt with a discount');
    }

    #[Test]
    public function a_vendor_payment_with_a_discount_books_discount_received(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->billOnCredit('purchase', $vendor);

        $this->settle('payment', $vendor, '1200.00', '50.20')->assertCreated();

        $this->assertSame('0.00', $this->show((int) $bill['id'])['due']);

        // Income, which on a credit-normal account reads as a positive balance
        // or a negative one depending on the helper's convention — so it is
        // compared by size.
        $this->assertSame('50.20', ltrim($this->balanceOf($this->tenant, SystemAccount::DiscountReceived), '-'));
        $this->assertBooksBalance($this->tenant, 'after a payment with a discount');
    }

    #[Test]
    public function a_discount_cannot_let_a_customer_off_more_than_they_owe(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->billOnCredit('sale', $customer);

        $this->settle('receipt', $customer, '1200.00', '60.00')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DISCOUNT_EXCEEDS_DUE');

        // And nothing was posted on the way to being refused.
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::DiscountAllowed));
    }

    #[Test]
    public function a_discount_with_nothing_owed_is_refused(): void
    {
        $this->settle('receipt', $this->party(PartyRole::Customer), '100.00', '5.00')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DISCOUNT_EXCEEDS_DUE');
    }

    #[Test]
    public function a_discount_cannot_be_parked_as_a_draft(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->billOnCredit('sale', $customer);

        $this->settle('receipt', $customer, '1200.00', '50.20', ['post' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['discount']]]]);
    }

    #[Test]
    public function reversing_the_receipt_puts_the_whole_balance_back_on_the_customer(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->billOnCredit('sale', $customer);

        $receipt = $this->settle('receipt', $customer, '1200.00', '50.20')->assertCreated()->json('data');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$receipt['id']}/reverse", [
                'date' => now()->toDateString(),
                'reason' => 'Cheque bounced.',
            ])
            ->assertSuccessful();

        // The money and the write-off both come back off — a reversal that took
        // back only the ₹1,200 would leave the customer let off ₹50.20 by a
        // receipt that no longer exists.
        $this->assertSame('1250.20', $this->positionOf($this->tenant, $customer)['net']);
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::DiscountAllowed));
        $this->assertBooksBalance($this->tenant, 'after reversing a receipt with a discount');
    }

    #[Test]
    public function a_receipt_without_a_discount_is_unchanged(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $bill = $this->billOnCredit('sale', $customer);

        $receipt = $this->settle('receipt', $customer, '1200.00', null)->assertCreated()->json('data');

        $this->assertSame('1200.00', $receipt['total']);
        $this->assertSame('0.00', $this->show((int) $receipt['id'])['discount']);
        $this->assertSame('50.20', $this->show((int) $bill['id'])['due']);
    }
}
