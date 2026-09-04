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
 * Taking a bill to the nearest rupee — `tenants.round_off_invoices`.
 *
 * An 18% invoice lands on ₹6,701.10 and a counter does not find ten paise, so a
 * workshop that has switched this on charges ₹6,701 and books the difference to
 * {@see SystemAccount::RoundOff}. What has to stay true through all of it:
 *
 *   * **the lines do not move.** The taxable value and the GST are what goes on
 *     a government return, and rounding them to make a total tidy would put a
 *     figure on that return no line supports;
 *   * **the party is asked for the rounded figure**, so paying it settles the
 *     bill rather than leaving ten paise on a statement forever;
 *   * **the books still balance**, which is the whole reason the residue is a
 *     posting rather than a display rule.
 *
 * @see \App\Services\Accounting\Posting\RoundOff
 */
class RoundOffTest extends TestCase
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
    }

    /** Switch the workshop's rounding on. Off is the state every test starts in. */
    private function rounding(): void
    {
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

    /**
     * A stocked variant at a nominated GST rate, with plenty on the shelf so
     * nothing here is refused for a reason these tests are not about.
     */
    private function variantAt(string $gstRate): ItemVariant
    {
        $variant = $this->variantFor($this->tenant, 'part');

        $this->actingForTenant($this->tenant, fn () => $variant->item->update(['gst_rate' => $gstRate]));

        $this->adjustStock($this->tenant, [[$variant, '1000', '100.00']]);

        return $variant;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function sale(array $items, ?Party $customer = null, array $payments = []): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => ($customer ?? $this->party(PartyRole::Customer))->id,
                'items' => $items,
                'payments' => $payments,
            ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function purchase(array $items): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->party(PartyRole::Vendor)->id,
                'items' => $items,
            ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function returnAgainst(int $billId, array $lines): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$billId}/return", ['lines' => $lines]);
    }

    /**
     * The document as it is read back — M16's derived settlement is loaded on a
     * read and not on the response that created it.
     *
     * @return array<string, mixed>
     */
    private function show(int $id): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->json('data');
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

    /* ---------------------------------------------------------------------
     | Off by default
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_bill_is_charged_to_the_paisa_unless_the_workshop_says_otherwise(): void
    {
        $variant = $this->variantAt('18.00');

        // ₹5,678.90 plus 18% is ₹6,701.10 — the shape of nearly every invoice a
        // workshop writes, and the reason this feature exists.
        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '5678.90',
        ]])->assertCreated()->json('data');

        $this->assertSame('6701.10', $data['total']);

        // Not merely zero — the account is not touched at all, so a workshop
        // that never rounds never sees the row on its trial balance.
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
    }

    /* ---------------------------------------------------------------------
     | Rounding down, rounding up, and the fifty-paise edge
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_workshop_that_rounds_charges_the_nearest_rupee(): void
    {
        $this->rounding();

        $variant = $this->variantAt('18.00');

        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '5678.90',
        ]])->assertCreated()->json('data');

        $this->assertSame('6701.00', $data['total']);

        // The ten paise the customer did not pay. A debit, because the workshop
        // gave them up — Round Off is an expense that spends half its life with
        // a credit balance, and this is the half that does not.
        $this->assertSame('0.10', $this->balanceOf($this->tenant, SystemAccount::RoundOff));

        $line = $this->linesOf((int) $data['id'])[0];

        /*
        | The point of the whole design. The taxable value and the tax are what
        | the GST return is filed on, and they are untouched: only the party's
        | side of the voucher moved. Rounding the tax to tidy the total would put
        | ₹1,022 on a return that ₹5,678.90 at 18% does not support.
        */
        $this->assertSame('5678.90', $line->taxable_value);
        // ₹1,022.20 of GST, stored as the halves the return is filed on.
        $this->assertSame('511.10', $line->cgst_amount);
        $this->assertSame('511.10', $line->sgst_amount);
    }

    #[Test]
    public function rounding_up_credits_round_off(): void
    {
        $this->rounding();

        // Zero-rated, so the figure under test is the price itself rather than
        // the tax arithmetic that produced it.
        $variant = $this->variantAt('0.00');

        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '1062.64',
        ]])->assertCreated()->json('data');

        $this->assertSame('1063.00', $data['total']);

        // Thirty-six paise the workshop was paid and did not earn. A credit,
        // which on an expense account reads as a negative balance.
        $this->assertSame('-0.36', $this->balanceOf($this->tenant, SystemAccount::RoundOff));
    }

    #[Test]
    public function fifty_paise_goes_up(): void
    {
        $this->rounding();

        $variant = $this->variantAt('0.00');

        /*
        | Half to even would be defensible arithmetic and indefensible on a
        | counter: nobody can explain why ₹10.50 became ₹10 while ₹11.50 became
        | ₹12. Away from zero at exactly fifty paise, always.
        */
        $this->assertSame('11.00', $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '10.50',
        ]])->assertCreated()->json('data.total'));
    }

    #[Test]
    public function the_books_still_balance_after_a_rounded_bill(): void
    {
        $this->rounding();

        $variant = $this->variantAt('18.00');

        $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '3',
            'unit_price' => '1234.56',
        ]])->assertCreated();

        $this->assertBooksBalance($this->tenant, 'after a bill rounded to the nearest rupee');
    }

    /* ---------------------------------------------------------------------
     | The other direction
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_purchase_rounds_without_disturbing_what_the_stock_cost(): void
    {
        $this->rounding();

        $variant = $this->variantFor($this->tenant, 'part');

        $this->actingForTenant($this->tenant, fn () => $variant->item->update(['gst_rate' => '18.00']));

        // 10 at ₹56.789 is not a price anybody types, but 10 at ₹567.89 is —
        // ₹5,678.90 taxable, ₹1,022.20 tax, ₹6,701.10 owed, ₹6,701 paid.
        $this->purchase([[
            'variant_id' => $variant->id,
            'quantity' => '10',
            'unit_price' => '567.89',
        ]])->assertCreated();

        /*
        | The residue is a credit here, and that is the mirror of a sale rather
        | than an inconsistency: the workshop owes its supplier ten paise less
        | than the invoice adds up to, so it has gained them.
        */
        $this->assertSame('-0.10', $this->balanceOf($this->tenant, SystemAccount::RoundOff));

        /*
        | And the shelf is untouched by any of it — §8.2. Stock arrives at the
        | line's taxable value, which the rounding never moved, so the weighted
        | average is ₹567.89 and not a rounded approximation of it. Getting this
        | wrong would be permanent: there is no average column to correct
        | afterwards.
        */
        $position = $this->stockPositionOf($this->tenant, $variant);

        $this->assertSame('10.000', $position['quantity']);
        $this->assertSame('5678.90', $position['value']);
        $this->assertSame('567.89', $position['average_cost']);

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a rounded purchase');
    }

    /* ---------------------------------------------------------------------
     | What it is actually for
     |-------------------------------------------------------------------- */

    #[Test]
    public function paying_the_rounded_figure_settles_the_bill(): void
    {
        $this->rounding();

        $variant = $this->variantAt('18.00');

        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '5678.90',
        ]], payments: [['mode' => 'cash', 'amount' => '6701.00']])->assertCreated()->json('data');

        $bill = $this->show((int) $data['id']);

        /*
        | The whole purpose. Without the rounding reaching the receivable, a
        | customer who handed over ₹6,701 for an invoice reading ₹6,701 would
        | still owe ten paise — on a statement, in an ageing report, and in the
        | list of who to chase, forever.
        */
        $this->assertSame('0.00', $bill['due']);
        $this->assertSame('paid', $bill['payment_status']);
    }

    #[Test]
    public function the_preview_quotes_the_rounded_total_and_says_what_it_rounded(): void
    {
        $this->rounding();

        $variant = $this->variantAt('18.00');

        $totals = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '5678.90']],
            ])
            ->assertOk()
            ->json('data.totals');

        // The confirmation screen and the invoice are the same call, so they
        // cannot round differently — which is the reason the rounding lives
        // behind `documentTotal()` rather than inside `build()`.
        $this->assertSame('6701.00', $totals['total']);
        $this->assertSame('-0.10', $totals['round_off']);

        // Said separately, so the panel can show a line for it instead of a
        // total that quietly disagrees with the figures above it.
        $this->assertSame('5678.90', $totals['taxable']);
        $this->assertSame('1022.20', $totals['tax']);
    }

    #[Test]
    public function a_workshop_that_does_not_round_is_told_so_rather_than_left_guessing(): void
    {
        $variant = $this->variantAt('18.00');

        $totals = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '5678.90']],
            ])
            ->assertOk()
            ->json('data.totals');

        $this->assertSame('6701.10', $totals['total']);
        $this->assertSame('0.00', $totals['round_off']);
    }

    /* ---------------------------------------------------------------------
     | Credit notes — and the residue they can leave behind
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_full_return_of_a_rounded_invoice_nets_the_customer_to_nothing(): void
    {
        $this->rounding();

        $variant = $this->variantAt('18.00');
        $customer = $this->party(PartyRole::Customer);

        $bill = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '2',
            'unit_price' => '5678.90',
        ]], $customer)->assertCreated()->json('data');

        $note = $this->returnAgainst((int) $bill['id'], [
            ['line_no' => 1, 'quantity' => '2'],
        ])->assertCreated()->json('data');

        /*
        | The credit note is priced from the same lines, so it reaches the same
        | unrounded figure and rounds the same way. Everything came back and the
        | customer owes nothing — which is only true because both documents
        | round, and is the reason this workshop's setting covers both.
        */
        $this->assertSame($bill['total'], $note['total']);
        $this->assertSame('0.00', $this->positionOf($this->tenant, $customer)['net']);
    }

    #[Test]
    public function two_part_returns_of_one_invoice_can_leave_a_rupee_on_the_customer(): void
    {
        $this->rounding();

        $variant = $this->variantAt('0.00');
        $customer = $this->party(PartyRole::Customer);

        // ₹1,000.50 charged as ₹1,001.
        $bill = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '2',
            'unit_price' => '500.25',
        ]], $customer)->assertCreated()->json('data');

        $this->assertSame('1001.00', $bill['total']);

        // Back one at a time, as a workshop takes them back.
        foreach ([1, 2] as $ignored) {
            $note = $this->returnAgainst((int) $bill['id'], [
                ['line_no' => 1, 'quantity' => '1'],
            ])->assertCreated()->json('data');

            // ₹500.25 each, rounded down to ₹500.
            $this->assertSame('500.00', $note['total']);
        }

        /*
        | **The consequence, asserted rather than discovered later.**
        |
        | ₹1,001 charged, ₹1,000 credited: the customer is left owing a rupee on
        | an invoice they returned in full. Every document rounded correctly and
        | on its own, which is exactly the problem — the two halves each lost
        | twenty-five paise, and nothing anywhere is tracking what the original
        | rounding did.
        |
        | It is bounded — under fifty paise per credit note — and it lands on the
        | party's balance where the workshop can see it and write it off, rather
        | than silently in an account nobody reads. It is not a defect in the
        | apportionment: it is what rounding a number twice means, and the only
        | alternatives are not rounding credit notes at all (a note reading
        | ₹500.25 among documents that are all whole rupees) or making each note
        | depend on what earlier ones did (a total that changes with the order
        | the goods came back in).
        |
        | See docs/billing-module.md.
        */
        $this->assertSame('1.00', $this->positionOf($this->tenant, $customer)['receivable']);

        // And the books are still square, which is what the Round Off postings
        // are there to guarantee whatever the residue does to a balance.
        $this->assertBooksBalance($this->tenant, 'after two part returns of a rounded invoice');
    }
}
