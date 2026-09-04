<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
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
 * Which invoice a receipt paid — M16, and the brief's §13, §14 and §23.
 *
 * The thing under test is the arithmetic and the refusals, and one property
 * behind both: an allocation touches no journal entry. Every assertion about a
 * balance below is therefore also an assertion that allocating changed nothing
 * about the money — only about which document the workshop considers it to have
 * discharged.
 */
class AllocationTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $motor;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->motor = $this->variantFor($this->tenant, 'motor');
        $this->receiveStock($this->tenant, $this->motor, '100', '5000.00');
    }

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    /**
     * A sale for a round taxable amount, so the totals below read as the brief
     * writes them. At 18% a ₹10,000 motor invoices at ₹11,800.
     */
    private function sellTo(Party $customer, string $unitPrice, ?string $date = null): Transaction
    {
        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => $date ?? now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->motor->id, 'quantity' => '1', 'unit_price' => $unitPrice]],
            ])
            ->assertCreated()
            ->json('data.id');

        return $this->actingForTenant($this->tenant, fn () => Transaction::findOrFail($id));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $allocations
     */
    private function collect(Party $customer, string $amount, ?array $allocations = null): array
    {
        $payload = [
            'date' => now()->toDateString(),
            'post' => true,
            'party_id' => $customer->id,
            'payments' => [['mode' => 'cash', 'amount' => $amount]],
        ];

        if ($allocations !== null) {
            $payload['allocations'] = $allocations;
        }

        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $payload)
            ->assertCreated()
            ->json();
    }

    private function show(Transaction $bill): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/'.$bill->id)
            ->assertOk()
            ->json('data');
    }

    /* ---------------------------------------------------------------------
     | The arithmetic
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_unsettled_invoice_reports_its_whole_total_as_due(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $bill = $this->sellTo($customer, '10000.00');

        $shown = $this->show($bill);

        $this->assertSame('11800.00', $shown['total']);
        $this->assertSame('0.00', $shown['paid']);
        $this->assertSame('11800.00', $shown['due']);
        $this->assertSame('unpaid', $shown['payment_status']);
    }

    #[Test]
    public function money_taken_at_the_counter_settles_the_bill_it_was_written_on(): void
    {
        $customer = $this->party(PartyRole::Customer);

        // The whole invoice, handed over as the bill was written. No receipt and
        // no allocation exist at all — which is exactly why `paid` cannot be
        // allocations alone.
        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
                'payments' => [['mode' => 'cash', 'amount' => '11800.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $shown = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('11800.00', $shown['paid']);
        $this->assertSame('0.00', $shown['due']);
        $this->assertSame('paid', $shown['payment_status']);
    }

    #[Test]
    public function a_part_payment_leaves_the_invoice_partial(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $bill = $this->sellTo($customer, '10000.00');

        $this->collect($customer, '5000.00');

        $shown = $this->show($bill);

        $this->assertSame('5000.00', $shown['paid']);
        $this->assertSame('6800.00', $shown['due']);
        $this->assertSame('partial', $shown['payment_status']);
    }

    #[Test]
    public function counter_money_and_a_later_receipt_are_added_together(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
                'payments' => [['mode' => 'cash', 'amount' => '1800.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->collect($customer, '10000.00');

        $shown = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->json('data');

        // ₹1,800 at the counter plus a ₹10,000 cheque. Counting either alone is
        // how a bill comes to be chased for money already in the bank.
        $this->assertSame('11800.00', $shown['paid']);
        $this->assertSame('paid', $shown['payment_status']);
    }

    /* ---------------------------------------------------------------------
     | Oldest first
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_receipt_naming_no_bills_settles_the_oldest_first(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $march = $this->sellTo($customer, '10000.00', now()->subDays(40)->toDateString());
        $april = $this->sellTo($customer, '10000.00', now()->subDays(10)->toDateString());

        // ₹15,000 against two ₹11,800 invoices: the first is settled outright
        // and the second takes what is left.
        $this->collect($customer, '15000.00');

        $this->assertSame('paid', $this->show($march)['payment_status']);
        $this->assertSame('0.00', $this->show($march)['due']);

        $this->assertSame('partial', $this->show($april)['payment_status']);
        $this->assertSame('3200.00', $this->show($april)['paid']);
        $this->assertSame('8600.00', $this->show($april)['due']);
    }

    #[Test]
    public function money_beyond_the_open_bills_stays_unapplied(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->sellTo($customer, '10000.00');

        $response = $this->collect($customer, '20000.00');

        // Nothing is refused: the surplus reduces the customer's balance exactly
        // as it always did, and stays available to point at the next invoice.
        $this->assertSame('8200.00', $response['meta']['allocations']['unallocated']);
    }

    /* ---------------------------------------------------------------------
     | Saying which
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_explicit_split_overrides_the_oldest_first_default(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $older = $this->sellTo($customer, '10000.00', now()->subDays(40)->toDateString());
        $newer = $this->sellTo($customer, '10000.00', now()->toDateString());

        // "This cheque is for the second invoice" — which is a thing customers
        // say, and the reason the default is only a default.
        $this->collect($customer, '11800.00', [['bill_transaction_id' => $newer->id]]);

        // Asserted on the amount rather than the status: at the factory's
        // default terms a forty-day-old bill is already overdue, and what this
        // test is about is that nothing was taken off it.
        $this->assertSame('11800.00', $this->show($older)['due']);
        $this->assertSame('paid', $this->show($newer)['payment_status']);
    }

    #[Test]
    public function an_allocation_with_no_amount_takes_whatever_is_owing(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $first = $this->sellTo($customer, '10000.00');
        $second = $this->sellTo($customer, '5000.00');

        // Two invoices ticked off a list, no figures typed: ₹11,800 and ₹5,900.
        $this->collect($customer, '17700.00', [
            ['bill_transaction_id' => $first->id],
            ['bill_transaction_id' => $second->id],
        ]);

        $this->assertSame('paid', $this->show($first)['payment_status']);
        $this->assertSame('paid', $this->show($second)['payment_status']);
    }

    #[Test]
    public function a_posted_receipt_can_be_re_pointed_afterwards(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $wrong = $this->sellTo($customer, '10000.00', now()->subDays(40)->toDateString());
        $right = $this->sellTo($customer, '10000.00');

        $receipt = $this->collect($customer, '11800.00');
        $this->assertSame('paid', $this->show($wrong)['payment_status']);

        // Correcting it is a clerical act, not an accounting one: no reversal, no
        // counter-entry, and the ledger is untouched.
        $before = $this->positionOf($this->tenant, $customer);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/'.$receipt['data']['id'].'/allocate', [
                'allocations' => [['bill_transaction_id' => $right->id]],
            ])
            ->assertOk();

        $this->assertSame($before, $this->positionOf($this->tenant, $customer));
        $this->assertBooksBalance($this->tenant);
    }

    /* ---------------------------------------------------------------------
     | The refusals
     |-------------------------------------------------------------------- */

    #[Test]
    public function allocating_more_than_a_bill_owes_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $bill = $this->sellTo($customer, '10000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '20000.00']],
                // ₹15,000 against an ₹11,800 invoice — the brief's §27,
                // "payment greater than invoice amount".
                'allocations' => [['bill_transaction_id' => $bill->id, 'amount' => '15000.00']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALLOCATION_EXCEEDS_BILL');
    }

    #[Test]
    public function allocating_more_than_the_receipt_holds_is_refused(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $first = $this->sellTo($customer, '10000.00');
        $second = $this->sellTo($customer, '10000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '12000.00']],
                'allocations' => [
                    ['bill_transaction_id' => $first->id, 'amount' => '11800.00'],
                    ['bill_transaction_id' => $second->id, 'amount' => '11800.00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALLOCATION_EXCEEDS_SETTLEMENT');
    }

    #[Test]
    public function a_receipt_cannot_settle_another_customers_invoice(): void
    {
        $alpha = $this->party(PartyRole::Customer);
        $beta = $this->party(PartyRole::Customer);

        $theirs = $this->sellTo($beta, '10000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $alpha->id,
                'payments' => [['mode' => 'cash', 'amount' => '11800.00']],
                'allocations' => [['bill_transaction_id' => $theirs->id]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALLOCATION_PARTY_MISMATCH');
    }

    #[Test]
    public function a_receipt_cannot_be_pointed_at_a_purchase_bill(): void
    {
        $both = $this->party(PartyRole::Customer, PartyRole::Vendor);

        $purchaseId = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $both->id,
                'items' => [['variant_id' => $this->motor->id, 'quantity' => '1', 'unit_price' => '5000.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        // Money *from* a customer cannot discharge what the workshop owes them
        // as a supplier. The two relationships are settled separately, which is
        // the same reason a statement asks which role it is for.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $both->id,
                'payments' => [['mode' => 'cash', 'amount' => '5900.00']],
                'allocations' => [['bill_transaction_id' => $purchaseId]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALLOCATION_TARGET_INVALID');
    }

    #[Test]
    public function a_draft_invoice_cannot_be_settled(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $draftId = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '11800.00']],
                'allocations' => [['bill_transaction_id' => $draftId]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALLOCATION_NOT_POSTED');
    }

    /* ---------------------------------------------------------------------
     | Listing and filtering
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_list_can_be_narrowed_to_what_is_still_owed(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $settled = $this->sellTo($customer, '10000.00', now()->subDays(3)->toDateString());
        $open = $this->sellTo($customer, '10000.00');

        $this->collect($customer, '11800.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?type=sale&outstanding=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?payment_status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $settled->id);
    }

    /**
     * Overdue replaces unpaid rather than sitting beside it, so a bill past the
     * workshop's terms appears under one filter and not the other.
     */
    #[Test]
    public function a_bill_past_the_workshops_terms_is_overdue_rather_than_unpaid(): void
    {
        $this->actingForTenant(
            $this->tenant,
            fn () => $this->tenant->forceFill(['payment_due_days' => 30])->save()
        );

        $customer = $this->party(PartyRole::Customer);

        $old = $this->sellTo($customer, '10000.00', now()->subDays(45)->toDateString());
        $recent = $this->sellTo($customer, '10000.00');

        $this->assertSame('overdue', $this->show($old)['payment_status']);
        $this->assertSame('unpaid', $this->show($recent)['payment_status']);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?payment_status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?payment_status=unpaid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    #[Test]
    public function nothing_is_overdue_where_the_workshop_has_set_no_terms(): void
    {
        $this->actingForTenant(
            $this->tenant,
            fn () => $this->tenant->forceFill(['payment_due_days' => null])->save()
        );

        $customer = $this->party(PartyRole::Customer);
        $old = $this->sellTo($customer, '10000.00', now()->subDays(400)->toDateString());

        // An ageing computed against terms nobody agreed to would only mislead,
        // so a year-old bill is merely unpaid.
        $this->assertSame('unpaid', $this->show($old)['payment_status']);
        $this->assertNull($this->show($old)['due_date']);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?payment_status=overdue')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------------------------------------------------------------------
     | The statement — §14 and §15
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_customer_statement_lists_each_invoice_with_what_is_left_on_it(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $paid = $this->sellTo($customer, '10000.00', now()->subDays(20)->toDateString());
        $partial = $this->sellTo($customer, '20000.00', now()->subDays(10)->toDateString());
        $unpaid = $this->sellTo($customer, '10000.00');

        // ₹11,800 settles the first outright and leaves ₹3,200 on the second.
        $this->collect($customer, '15000.00');

        $statement = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/parties/{$customer->id}/statement")
            ->assertOk()
            ->json();

        // ₹11,800 + ₹23,600 + ₹11,800 billed, ₹15,000 collected.
        $this->assertSame('customer', $statement['meta']['role']);
        $this->assertSame('47200.00', $statement['meta']['totals']['billed']);
        $this->assertSame('15000.00', $statement['meta']['totals']['paid']);
        $this->assertSame('32200.00', $statement['meta']['totals']['outstanding']);

        $rows = collect($statement['data']['bills'])->keyBy('id');

        $this->assertSame('paid', $rows[$paid->id]['payment_status']);
        $this->assertSame('partial', $rows[$partial->id]['payment_status']);
        $this->assertSame('20400.00', $rows[$partial->id]['due']);
        $this->assertSame('unpaid', $rows[$unpaid->id]['payment_status']);

        // And the money side: one receipt, fully applied.
        $this->assertCount(1, $statement['data']['settlements']);
        $this->assertSame('0.00', $statement['data']['settlements'][0]['unallocated']);
    }

    #[Test]
    public function the_statement_totals_agree_with_the_party_ledger(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $this->sellTo($customer, '10000.00');
        $this->collect($customer, '5000.00');

        $statement = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/parties/{$customer->id}/statement")
            ->assertOk()
            ->json('meta.totals');

        // The headline figures are read off the control account rather than
        // summed from the rows, so this and the trial balance can never come
        // apart — including for anything that moved the position without being a
        // bill.
        $this->assertSame(
            $this->positionOf($this->tenant, $customer)['receivable'],
            $statement['outstanding'],
        );

        $this->assertBooksBalance($this->tenant);
    }
}
