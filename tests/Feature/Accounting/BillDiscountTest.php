<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
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
 * Discounts — a percentage off a line, and money off the whole bill.
 *
 * Two things are being established here, and they are not the same thing.
 *
 * The first is that **a percentage is the server's arithmetic**. A browser that
 * worked out "10% of ₹4,237.29" would be applying float arithmetic to a
 * customer's invoice, and it would be doing it to a figure the server then takes
 * as gospel — a discount is not merely displayed from the client's number, it is
 * *applied* from it. So the payload carries a percentage and the server resolves
 * it, and the two ways of stating a discount are refused as a pair.
 *
 * The second is that **a discount on the whole bill has to reach the lines**.
 * GST is charged per line at the line's own rate, so ₹1,000 off the bottom of a
 * bill carrying an 18% motor and a 12% rewind reduces the taxable value of both
 * — by how much is a question only apportionment answers. The assertions about
 * paise below are the whole point rather than pedantry: a split that does not
 * add back to what was given leaves either a customer short-changed or a bill
 * that does not add up.
 *
 * @see \App\Services\Accounting\Posting\BillDiscount
 */
class BillDiscountTest extends TestCase
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

    private function customer(): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }

    /**
     * A stocked variant at a nominated GST rate, so the tax assertions can be
     * exact — with enough on the shelf that nothing here is refused for a
     * reason these tests are not about.
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
     * @param  array<string, mixed>  $extra
     */
    private function sale(array $items, array $extra = []): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', array_merge([
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->customer()->id,
                'items' => $items,
            ], $extra));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function purchase(array $items, array $extra = []): TestResponse
    {
        $vendor = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Vendor->value],
            'state_code' => '27',
        ]));

        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', array_merge([
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => $items,
            ], $extra));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function preview(array $items, array $extra = []): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', array_merge([
                'type' => 'sale',
                'items' => $items,
            ], $extra))
            ->assertOk()
            ->json('data');
    }

    /**
     * Assert a 422 naming one field.
     *
     * `assertJsonValidationErrors` looks for Laravel's default envelope, and
     * this application answers with its own — `error.details.fields` — so the
     * built-in assertion passes on the status and then finds nothing.
     */
    private function assertRefusedFor(TestResponse $response, string $field): void
    {
        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertArrayHasKey($field, $response->json('error.details.fields'));
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
     | A percentage off one line
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_line_percentage_is_resolved_on_the_server(): void
    {
        $variant = $this->variantAt('18.00');

        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '10',
            'unit_price' => '100.00',
            'discount_percent' => '10',
        ]])->assertCreated()->json('data');

        $line = $this->linesOf((int) $data['id'])[0];

        // 10 × ₹100 is ₹1,000; a tenth of it is ₹100; ₹900 is taxed.
        $this->assertSame('100.00', $line->discount_amount);
        $this->assertSame('900.00', $line->taxable_value);
        $this->assertSame('1062.00', $data['total']);
    }

    #[Test]
    public function a_percentage_of_an_awkward_amount_is_rounded_once(): void
    {
        $variant = $this->variantAt('0.00');

        // ₹4,237.29 is where a float stops being able to count: 10% of it comes
        // out as 423.72900000000004 in the browser that used to work it out.
        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '4237.29',
            'discount_percent' => '10',
        ]])->assertCreated()->json('data');

        $line = $this->linesOf((int) $data['id'])[0];

        $this->assertSame('423.73', $line->discount_amount);
        $this->assertSame('3813.56', $line->taxable_value);
    }

    #[Test]
    public function a_line_cannot_be_discounted_in_rupees_and_percent_at_once(): void
    {
        $variant = $this->variantAt('18.00');

        $this->assertRefusedFor($this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount' => '10.00',
            'discount_percent' => '10',
        ]]), 'items.0.discount_percent');
    }

    #[Test]
    public function a_discount_of_more_than_a_hundred_percent_is_refused(): void
    {
        $variant = $this->variantAt('18.00');

        $this->assertRefusedFor($this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount_percent' => '120',
        ]]), 'items.0.discount_percent');
    }

    /* ---------------------------------------------------------------------
     | Money off the whole bill
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_bill_discount_is_shared_between_the_lines_it_is_off(): void
    {
        $variant = $this->variantAt('0.00');

        $data = $this->sale([
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '600.00'],
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '400.00'],
        ], ['bill_discount' => '100.00'])->assertCreated()->json('data');

        [$first, $second] = $this->linesOf((int) $data['id']);

        // Pro rata on what each line is taxed on — 60/40, not 50/50.
        $this->assertSame('60.00', $first->discount_amount);
        $this->assertSame('40.00', $second->discount_amount);
        $this->assertSame('900.00', $data['total']);
    }

    #[Test]
    public function the_odd_paise_of_a_bill_discount_are_handed_out_rather_than_lost(): void
    {
        $variant = $this->variantAt('0.00');

        // ₹1,000 over three equal lines is ₹333.333… each. Rounding every share
        // the same way gives ₹999.99 or ₹1,000.02, and neither is what the
        // customer was promised.
        $data = $this->sale([
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1000.00'],
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1000.00'],
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1000.00'],
        ], ['bill_discount' => '1000.00'])->assertCreated()->json('data');

        $lines = $this->linesOf((int) $data['id']);
        $given = array_sum(array_map(fn (TransactionLine $line) => (int) round((float) $line->discount_amount * 100), $lines));

        $this->assertSame(100000, $given, 'the shares must add back to exactly ₹1,000.00');
        $this->assertSame('2000.00', $data['total']);

        // One line takes the odd paisa; the other two take the floor.
        $this->assertEqualsCanonicalizing(
            ['333.34', '333.33', '333.33'],
            array_map(fn (TransactionLine $line) => $line->discount_amount, $lines),
        );
    }

    #[Test]
    public function a_bill_discount_falls_before_the_tax_and_not_after_it(): void
    {
        $variant = $this->variantAt('18.00');

        $data = $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '1000.00',
        ]], ['bill_discount' => '100.00'])->assertCreated()->json('data');

        $line = $this->linesOf((int) $data['id'])[0];

        // ₹900 taxable, ₹162 of GST — not ₹180 of GST and then ₹100 off, which
        // would charge the customer tax on money they were never asked for.
        $this->assertSame('900.00', $line->taxable_value);
        $this->assertSame('1062.00', $data['total']);
    }

    #[Test]
    public function each_line_keeps_its_own_rate_after_the_bill_discount_is_shared(): void
    {
        $motor = $this->variantAt('18.00');
        $rewind = $this->variantAt('12.00');

        $data = $this->sale([
            ['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '1000.00'],
            ['variant_id' => $rewind->id, 'quantity' => '1', 'unit_price' => '1000.00'],
        ], ['bill_discount_percent' => '10'])->assertCreated()->json('data');

        [$first, $second] = $this->linesOf((int) $data['id']);

        $this->assertSame('900.00', $first->taxable_value);
        $this->assertSame('900.00', $second->taxable_value);

        // 18% of 900 is 162; 12% of 900 is 108. A single rate applied to the
        // discounted total would match neither line.
        $this->assertSame('162.00', bcadd($first->cgst_amount, $first->sgst_amount, 2));
        $this->assertSame('108.00', bcadd($second->cgst_amount, $second->sgst_amount, 2));
        $this->assertSame('2070.00', $data['total']);
    }

    #[Test]
    public function a_bill_discount_stacks_on_top_of_a_line_discount(): void
    {
        $variant = $this->variantAt('0.00');

        $data = $this->sale([
            // ₹1,000 less ₹500 of its own leaves ₹500 to share in.
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1000.00', 'discount' => '500.00'],
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '500.00'],
        ], ['bill_discount' => '100.00'])->assertCreated()->json('data');

        [$first, $second] = $this->linesOf((int) $data['id']);

        // Shared over what is left to discount, not over the gross — the first
        // line has already given half of itself away.
        $this->assertSame('550.00', $first->discount_amount);
        $this->assertSame('50.00', $second->discount_amount);
        $this->assertSame('900.00', $data['total']);
    }

    #[Test]
    public function a_discount_bigger_than_the_bill_is_clamped_rather_than_going_negative(): void
    {
        $variant = $this->variantAt('18.00');

        // Priced, not posted — the preview is where the clamp is observable.
        $totals = $this->preview([
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1000.00'],
        ], ['bill_discount' => '5000.00'])['totals'];

        // ₹5,000 off a ₹1,000 bill leaves nothing, never minus ₹4,000 — which
        // would put a negative taxable value on an invoice and tax owed *to* the
        // customer.
        $this->assertSame('0.00', $totals['taxable']);
        $this->assertSame('0.00', $totals['tax']);
        $this->assertSame('0.00', $totals['total']);
        $this->assertSame('1000.00', $totals['discount']);
    }

    #[Test]
    public function a_bill_discounted_to_nothing_cannot_be_posted(): void
    {
        $variant = $this->variantAt('18.00');

        /*
        | Refused by the posting engine, which has always held that a voucher
        | carrying no amount is not a document — long before there was a discount
        | to reach zero by. It is left refusing rather than relaxed here: a bill
        | worth nothing creates no receivable, no revenue and no tax, so the only
        | thing it would actually record is the stock leaving, and that is what a
        | stock adjustment is for.
        |
        | What matters for this phase is *which* refusal it is. The clamp above
        | is what keeps it this one rather than a negative invoice the ledger
        | would happily accept.
        */
        $this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '1000.00',
        ]], ['bill_discount_percent' => '100'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOURNAL_LINE_INVALID');
    }

    #[Test]
    public function the_bill_discount_cannot_be_stated_both_ways(): void
    {
        $variant = $this->variantAt('18.00');

        $this->assertRefusedFor($this->sale([[
            'variant_id' => $variant->id,
            'quantity' => '1',
            'unit_price' => '100.00',
        ]], [
            'bill_discount' => '10.00',
            'bill_discount_percent' => '10',
        ]), 'bill_discount_percent');
    }

    /* ---------------------------------------------------------------------
     | The confirmation and the invoice are one computation
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_preview_quotes_exactly_what_the_posting_charges(): void
    {
        $motor = $this->variantAt('18.00');
        $rewind = $this->variantAt('12.00');

        $items = [
            ['variant_id' => $motor->id, 'quantity' => '3', 'unit_price' => '4237.29'],
            ['variant_id' => $rewind->id, 'quantity' => '1', 'unit_price' => '812.55', 'discount_percent' => '7.5'],
        ];

        $quoted = $this->preview($items, ['bill_discount_percent' => '12.5']);
        $charged = $this->sale($items, ['bill_discount_percent' => '12.5'])->assertCreated()->json('data');

        $this->assertSame($quoted['totals']['total'], $charged['total']);
    }

    #[Test]
    public function the_preview_says_what_came_off_and_what_it_came_off_from(): void
    {
        $variant = $this->variantAt('0.00');

        $totals = $this->preview([
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1000.00', 'discount' => '100.00'],
        ], ['bill_discount' => '150.00'])['totals'];

        $this->assertSame('1000.00', $totals['gross']);
        $this->assertSame('250.00', $totals['discount']);
        $this->assertSame('750.00', $totals['taxable']);
    }

    #[Test]
    public function a_discount_that_swallows_a_line_is_reported_as_what_actually_came_off(): void
    {
        $variant = $this->variantAt('0.00');

        // ₹900 asked off a ₹500 line. Only ₹500 can come off it, and the
        // reported discount has to be what the bill actually fell by — a figure
        // larger than the bill would make the panel's own arithmetic wrong.
        $totals = $this->preview([
            ['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '500.00', 'discount' => '900.00'],
        ])['totals'];

        $this->assertSame('500.00', $totals['gross']);
        $this->assertSame('500.00', $totals['discount']);
        $this->assertSame('0.00', $totals['taxable']);
    }

    /* ---------------------------------------------------------------------
     | What a return gives back
     |-------------------------------------------------------------------- */

    /* ---------------------------------------------------------------------
     | What it does to the shelf — §8.2
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_discounted_purchase_puts_stock_on_the_shelf_at_the_discounted_cost(): void
    {
        /*
        | The one place a discount is not merely a figure on a document.
        |
        | A purchase arrives at its line's *taxable value*, and that arrival is
        | what recomputes the weighted average — so money off the bill is money
        | off the cost basis, permanently and with nothing to correct it
        | afterwards. That is the right answer: a trade discount genuinely
        | lowers what the goods cost. What would be wrong is the discount
        | reaching the invoice and not the shelf, which is exactly what a
        | footer-level deduction would have done.
        */
        $variant = $this->variantFor($this->tenant, 'part');

        $this->actingForTenant($this->tenant, fn () => $variant->item->update(['gst_rate' => '18.00']));

        // Nothing on the shelf yet, so the average is this arrival and no other.
        $this->assertSame('0.00', $this->stockPositionOf($this->tenant, $variant)['value']);

        // 10 at ₹100 is ₹1,000, less ₹200 off the bill.
        $this->purchase([[
            'variant_id' => $variant->id,
            'quantity' => '10',
            'unit_price' => '100.00',
        ]], ['bill_discount' => '200.00'])->assertCreated();

        $position = $this->stockPositionOf($this->tenant, $variant);

        $this->assertSame('10.000', $position['quantity']);
        // ₹800, not ₹1,000 — and not ₹944 either, because the 18% is claimable
        // input tax and was never part of what the goods cost.
        $this->assertSame('800.00', $position['value']);
        $this->assertSame('80.00', $position['average_cost']);

        $this->assertStockAgreesWithInventoryAccount(
            $this->tenant,
            'after a purchase carrying a bill discount',
        );
    }

    #[Test]
    public function a_return_credits_the_share_of_the_bill_discount_the_line_was_given(): void
    {
        $variant = $this->variantAt('18.00');

        $sale = $this->sale([
            ['variant_id' => $variant->id, 'quantity' => '4', 'unit_price' => '1000.00'],
        ], ['bill_discount' => '400.00'])->assertCreated()->json('data');

        // ₹4,000 less ₹400 is ₹3,600 taxable — ₹900 a unit, all in.
        $note = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale['id']}/return", [
                'lines' => [['line_no' => 1, 'quantity' => '1']],
            ])
            ->assertCreated()
            ->json('data');

        $credited = $this->linesOf((int) $note['id'])[0];

        // A quarter of the line came back, so a quarter of its discount does
        // too. Crediting the undiscounted ₹1,000 would refund the customer more
        // than they ever paid.
        $this->assertSame('100.00', $credited->discount_amount);
        $this->assertSame('900.00', $credited->taxable_value);
        $this->assertSame('1062.00', $note['total']);
    }
}
