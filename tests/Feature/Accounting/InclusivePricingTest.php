<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Accounting\InvoiceDocumentService;
use App\Services\Accounting\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A rate quoted with the GST already in it.
 *
 * The counter prices both ways — "₹10,000 plus tax" for a job it is quoting and
 * "₹11,800" for a part with the figure printed on the box — and until this
 * existed only the first could be typed. What made that more than an
 * inconvenience is the purchase side: stock is valued at the taxable value, so a
 * supplier's inclusive rate entered as exclusive inflated the weighted average
 * by the whole rate, permanently and silently.
 *
 * Every scenario asserts the two invariants a bill can break quietly: the trial
 * balance reconciles, and the shelf agrees with the Inventory account.
 */
class InclusivePricingTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function customer(Tenant $tenant): Party
    {
        return $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }

    private function vendor(Tenant $tenant): Party
    {
        return $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Vendor->value],
            'state_code' => '27',
        ]));
    }

    /** A variant whose item says its price is quoted with the tax in it. */
    private function inclusiveVariant(Tenant $tenant, string $category = 'part'): ItemVariant
    {
        $variant = $this->variantFor($tenant, $category);

        $this->actingForTenant($tenant, fn () => $variant->item->update(['price_includes_tax' => true]));

        return $variant->setRelation('item', $variant->item->fresh());
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function bill(Tenant $tenant, TransactionType $type, Party $party, array $items, array $extra = []): Transaction
    {
        return $this->actingForTenant($tenant, fn () => $this->engine()->postComposed($type, [
            'date' => now()->toDateString(),
            'party_id' => $party->id,
            'items' => $items,
            ...$extra,
        ]));
    }

    /** @param  array<string, mixed>  $overrides */
    private function line(ItemVariant $variant, string $quantity, string $price, array $overrides = []): array
    {
        return [
            'variant_id' => $variant->id,
            'item_id' => $variant->item_id,
            'quantity' => $quantity,
            'unit_price' => $price,
            ...$overrides,
        ];
    }

    /* ---------------------------------------------------------------------
     | The figure quoted is the figure charged
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_sale_quoted_inclusive_charges_the_price_that_was_quoted(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $motor, '4', '8000.00');

        // ₹11,800 with the tax in it: ₹10,000 of goods and ₹1,800 of GST, and the
        // customer is asked for the ₹11,800 that was said out loud.
        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($motor, '1', '11800.00', ['price_includes_tax' => true]),
        ]);

        $this->assertSame('11800.00', $sale->total);
        $this->assertSame('11800.00', $this->balanceOf($tenant, SystemAccount::Receivables));
        $this->assertSame('10000.00', $this->balanceOf($tenant, SystemAccount::Sales));
        $this->assertSame('1800.00', $this->balanceOf($tenant, SystemAccount::GstOutput));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function the_same_supply_reaches_the_books_identically_whichever_way_it_was_quoted(): void
    {
        // The invariant that matters for the GST return: how somebody typed a
        // line may not change what is reported. ₹10,000 + tax and ₹11,800 all-in
        // are one sale, and the stored taxable value and split must be the same.
        [$exclusive] = $this->tenantWithUser();
        [$inclusive] = $this->tenantWithUser();

        $rows = [];

        foreach ([[$exclusive, '10000.00', false], [$inclusive, '11800.00', true]] as [$tenant, $price, $quotedInclusive]) {
            $motor = $this->variantFor($tenant, 'motor');
            $this->receiveStock($tenant, $motor, '4', '8000.00');

            $sale = $this->bill($tenant, TransactionType::Sale, $this->customer($tenant), [
                $this->line($motor, '1', $price, ['price_includes_tax' => $quotedInclusive]),
            ]);

            $line = $this->actingForTenant($tenant, fn () => $sale->lines()->orderBy('line_no')->firstOrFail());

            $rows[] = [
                'total' => $sale->total,
                'taxable' => $line->taxable_value,
                'cgst' => $line->cgst_amount,
                'sgst' => $line->sgst_amount,
                'line_total' => $line->line_total,
            ];

            $this->assertBooksBalance($tenant);
        }

        $this->assertSame($rows[0], $rows[1]);

        // The one thing that legitimately differs is what was typed, which is why
        // the basis has to be stored beside it — the figures above cannot say it.
        $this->assertSame('10000.00', $this->firstLineOf($exclusive)->unit_price);
        $this->assertSame('11800.00', $this->firstLineOf($inclusive)->unit_price);
    }

    /* ---------------------------------------------------------------------
     | The costing fix
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_purchase_quoted_inclusive_values_stock_net_of_the_tax_inside_it(): void
    {
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');
        $vendor = $this->vendor($tenant);

        // 10 kg at ₹826 a kilo with the tax in it — ₹700 of copper and ₹126 of
        // claimable GST. Entered as exclusive this same rate would have carried
        // the shelf at ₹8,260 and inflated every later margin by 18%.
        $this->bill($tenant, TransactionType::Purchase, $vendor, [
            $this->line($copper, '10', '826.00', ['price_includes_tax' => true]),
        ]);

        $this->assertSame([
            'quantity' => '10.000',
            'value' => '7000.00',
            'average_cost' => '700.00',
        ], $this->stockPositionOf($tenant, $copper));

        $this->assertSame('7000.00', $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertSame('1260.00', $this->balanceOf($tenant, SystemAccount::GstInput));
        $this->assertSame('8260.00', $this->balanceOf($tenant, SystemAccount::Payables));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    /* ---------------------------------------------------------------------
     | Where the basis comes from
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_line_that_says_nothing_takes_the_items_own_default(): void
    {
        [$tenant] = $this->tenantWithUser();
        $part = $this->inclusiveVariant($tenant);
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $part, '10', '50.00');

        // No `price_includes_tax` on the line at all — the shape an API caller
        // that predates the toggle sends.
        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($part, '1', '118.00'),
        ]);

        $this->assertSame('118.00', $sale->total);
        $this->assertSame('100.00', $this->firstLineOf($tenant)->taxable_value);
        $this->assertTrue((bool) $this->firstLineOf($tenant)->price_includes_tax);
    }

    #[Test]
    public function a_line_may_overrule_the_item_in_either_direction(): void
    {
        [$tenant] = $this->tenantWithUser();
        $inclusiveByDefault = $this->inclusiveVariant($tenant);
        $exclusiveByDefault = $this->variantFor($tenant, 'part');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $inclusiveByDefault, '10', '50.00');
        $this->receiveStock($tenant, $exclusiveByDefault, '10', '50.00');

        // A shop that prices parts at MRP still quotes the odd one before tax,
        // and one that does not still buys the occasional pre-priced box.
        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($inclusiveByDefault, '1', '100.00', ['price_includes_tax' => false]),
            $this->line($exclusiveByDefault, '1', '118.00', ['price_includes_tax' => true]),
        ]);

        $lines = $this->actingForTenant($tenant, fn () => $sale->lines()->orderBy('line_no')->get());

        $this->assertSame('100.00', $lines[0]->taxable_value);
        $this->assertSame('118.00', $lines[0]->line_total);
        $this->assertFalse((bool) $lines[0]->price_includes_tax);

        $this->assertSame('100.00', $lines[1]->taxable_value);
        $this->assertSame('118.00', $lines[1]->line_total);
        $this->assertTrue((bool) $lines[1]->price_includes_tax);

        $this->assertSame('236.00', $sale->total);
        $this->assertBooksBalance($tenant);
    }

    /* ---------------------------------------------------------------------
     | Discounts, in the terms the line was quoted in
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_discount_on_an_inclusive_line_comes_off_what_is_actually_paid(): void
    {
        [$tenant] = $this->tenantWithUser();
        $part = $this->variantFor($tenant, 'part');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $part, '10', '50.00');

        // ₹180 off a ₹1,180 all-in price leaves ₹1,000 to pay, which is what
        // somebody taking ₹180 off it meant. The tax then follows from that.
        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($part, '1', '1180.00', ['price_includes_tax' => true, 'discount' => '180.00']),
        ]);

        $this->assertSame('1000.00', $sale->total);
        $this->assertSame('847.46', $this->firstLineOf($tenant)->taxable_value);
        $this->assertSame('152.54', $this->balanceOf($tenant, SystemAccount::GstOutput));
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_bill_discount_spread_over_inclusive_lines_still_lands_on_the_total(): void
    {
        [$tenant] = $this->tenantWithUser();
        $part = $this->variantFor($tenant, 'part');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $part, '10', '50.00');

        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($part, '1', '590.00', ['price_includes_tax' => true]),
            $this->line($part, '1', '590.00', ['price_includes_tax' => true]),
        ], ['bill_discount' => '180.00']);

        // ₹1,180 all-in less ₹180 off the bill is ₹1,000, apportioned to the
        // paisa across the two lines.
        $this->assertSame('1000.00', $sale->total);
        $this->assertSame('1000.00', $this->balanceOf($tenant, SystemAccount::Receivables));
        $this->assertBooksBalance($tenant);
    }

    /* ---------------------------------------------------------------------
     | Credit notes
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_credit_note_restates_the_basis_its_invoice_was_written_on(): void
    {
        [$tenant] = $this->tenantWithUser();
        $part = $this->variantFor($tenant, 'part');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $part, '10', '50.00');

        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($part, '2', '118.00', ['price_includes_tax' => true]),
        ]);

        $this->assertSame('236.00', $sale->total);
        $this->assertSame('36.00', $this->balanceOf($tenant, SystemAccount::GstOutput));

        // Everything comes back. Extracting where the invoice extracted is what
        // makes the pair net out; adding tax on top of ₹118 instead would credit
        // ₹42.48 of GST against the ₹36 that was ever charged.
        $this->actingForTenant($tenant, function () use ($sale) {
            $service = app(ReturnService::class);

            $service->returnAgainst($sale, array_map(fn (array $row) => [
                'line_no' => $row['line']->line_no,
                'quantity' => $row['remaining']->amount(),
            ], $service->returnableLines($sale)));
        });

        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::GstOutput));
        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::Receivables));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    /* ---------------------------------------------------------------------
     | The customer's copy
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_invoice_prints_a_rate_before_tax_even_where_the_price_had_tax_in_it(): void
    {
        [$tenant] = $this->tenantWithUser();
        $part = $this->variantFor($tenant, 'part');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $part, '10', '50.00');

        $sale = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($part, '2', '118.00', ['price_includes_tax' => true]),
        ]);

        $document = $this->actingForTenant($tenant, fn () => app(InvoiceDocumentService::class)->for($sale));

        $line = $document['lines'][0];

        /*
        | The rate column on a tax invoice is a rate excluding tax, and it has to
        | multiply into the taxable value beside it. Printing the ₹118 that was
        | typed against a taxable value of ₹200 would hand the recipient a row
        | that does not add up, on the document that is their evidence for an
        | input tax credit.
        */
        $this->assertSame('100.00', $line['unit_price']);
        $this->assertSame('200.00', $line['taxable_value']);
        $this->assertSame('236.00', $line['line_total']);
        $this->assertSame('236.00', $document['totals']['total']);

        // And an ordinary line is untouched — the conversion happens only where
        // there is something to convert.
        $plain = $this->bill($tenant, TransactionType::Sale, $customer, [
            $this->line($part, '2', '100.00'),
        ]);

        $plainDocument = $this->actingForTenant($tenant, fn () => app(InvoiceDocumentService::class)->for($plain));

        $this->assertSame('100.00', $plainDocument['lines'][0]['unit_price']);
        $this->assertSame('236.00', $plainDocument['totals']['total']);
    }

    /**
     * The first line of this tenant's first document — read back from the
     * database rather than from the object that posted it, so what is asserted
     * is what was actually stored.
     */
    private function firstLineOf(Tenant $tenant): \App\Models\TransactionLine
    {
        return $this->actingForTenant(
            $tenant,
            fn () => \App\Models\TransactionLine::query()->orderBy('transaction_id')->orderBy('line_no')->firstOrFail(),
        );
    }
}
