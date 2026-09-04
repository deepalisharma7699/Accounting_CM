<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InsufficientStockException;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Accounting\ItemInUseException;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Accounting\BillService;
use App\Services\Inventory\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M9's checklist, item by item.
 *
 * Every scenario asserts two invariants on the way out, whatever else it is
 * about: the trial balance reconciles, and the shelf agrees with the Inventory
 * account. Those are the two things a bill can break silently.
 */
class BillPostingTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function customer(Tenant $tenant, ?string $stateCode = '27'): Party
    {
        return $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => $stateCode,
        ]));
    }

    private function vendor(Tenant $tenant, ?string $stateCode = '27'): Party
    {
        return $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Vendor->value],
            'state_code' => $stateCode,
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $payments
     */
    private function bill(
        Tenant $tenant,
        TransactionType $type,
        Party $party,
        array $items,
        array $payments = [],
        ?string $date = null,
        bool $post = true,
    ): Transaction {
        return $this->actingForTenant($tenant, function () use ($type, $party, $items, $payments, $date, $post) {
            $input = [
                'date' => $date ?? now()->toDateString(),
                'party_id' => $party->id,
                'items' => $items,
                'payments' => array_map(fn (array $split) => [
                    'mode' => $split[0],
                    'amount' => $split[1],
                    'reference' => $split[2] ?? null,
                ], $payments),
            ];

            return $post
                ? $this->engine()->postComposed($type, $input)
                : $this->engine()->draft($this->engine()->compose($type, $input), null);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $payments
     */
    private function sell(Tenant $tenant, Party $party, array $items, array $payments = [], ?string $date = null, bool $post = true): Transaction
    {
        return $this->bill($tenant, TransactionType::Sale, $party, $items, $payments, $date, $post);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $payments
     */
    private function buy(Tenant $tenant, Party $party, array $items, array $payments = [], ?string $date = null, bool $post = true): Transaction
    {
        return $this->bill($tenant, TransactionType::Purchase, $party, $items, $payments, $date, $post);
    }

    private function line(ItemVariant $variant, string $quantity, string $price, ?string $discount = null): array
    {
        return array_filter([
            'variant_id' => $variant->id,
            'item_id' => $variant->item_id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount' => $discount,
        ], fn ($value) => $value !== null);
    }

    /* ---------------------------------------------------------------------
     | The roadmap's checklist
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_sale_posts_template_a_exactly(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');
        $customer = $this->customer($tenant);

        $this->receiveStock($tenant, $motor, '4', '8000.00');

        // One motor at ₹10,000 + 18% GST = ₹11,800.
        $this->sell($tenant, $customer, [$this->line($motor, '1', '10000.00')]);

        $this->assertSame('11800.00', $this->balanceOf($tenant, SystemAccount::Receivables));
        $this->assertSame('10000.00', $this->balanceOf($tenant, SystemAccount::Sales));
        $this->assertSame('1800.00', $this->balanceOf($tenant, SystemAccount::GstOutput));

        // Cost of goods sold at the weighted average, and the stock leaves.
        $this->assertSame('3.000', $this->stockPositionOf($tenant, $motor)['quantity']);
        $this->assertSame('24000.00', $this->stockPositionOf($tenant, $motor)['value']);

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_purchase_posts_template_c_and_recomputes_the_weighted_average(): void
    {
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');
        $vendor = $this->vendor($tenant);

        $this->buy($tenant, $vendor, [$this->line($copper, '10', '700.00')]);
        $this->buy($tenant, $vendor, [$this->line($copper, '10', '800.00')]);

        // The roadmap's worked example, arriving the way it actually arrives.
        $this->assertSame([
            'quantity' => '20.000',
            'value' => '15000.00',
            'average_cost' => '750.00',
        ], $this->stockPositionOf($tenant, $copper));

        // Inventory is carried net of claimable tax — adding it would inflate the
        // average by the rate and make every later margin wrong.
        $this->assertSame('15000.00', $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertSame('2700.00', $this->balanceOf($tenant, SystemAccount::GstInput));
        $this->assertSame('17700.00', $this->balanceOf($tenant, SystemAccount::Payables));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_rewinding_job_mixes_labour_copper_and_a_bearing_on_one_document(): void
    {
        [$tenant] = $this->tenantWithUser();
        $customer = $this->customer($tenant);

        $copper = $this->variantFor($tenant, 'bulk_material');
        $bearing = $this->variantFor($tenant, 'part');
        $labour = $this->serviceVariantFor($tenant);

        // Bought in, rather than adjusted in: an adjustment *credits* COGS, so
        // stocking up that way would leave the account already negative and the
        // figure this test is about buried inside it.
        $this->buy($tenant, $this->vendor($tenant), [
            $this->line($copper, '10', '700.00'),
            $this->line($bearing, '4', '400.00'),
        ]);

        $bill = $this->sell($tenant, $customer, [
            $this->line($labour, '3', '500.00'),
            $this->line($copper, '3', '900.00'),
            $this->line($bearing, '1', '600.00'),
        ]);

        // Revenue splits two ways — the single most useful thing a rewinding
        // shop's P&L can tell it is whether the money is in parts or in skill.
        $this->assertSame('1500.00', $this->balanceOf($tenant, SystemAccount::ServiceIncome));
        $this->assertSame('3300.00', $this->balanceOf($tenant, SystemAccount::Sales));

        // Only the two stock lines moved anything.
        $this->assertCount(2, $bill->stockMovements);
        $this->assertSame('7.000', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame('3.000', $this->stockPositionOf($tenant, $bearing)['quantity']);

        // ₹2,100 of copper and ₹400 of bearing.
        $this->assertSame('2500.00', $this->balanceOf($tenant, SystemAccount::Cogs));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_labour_only_bill_posts_with_zero_stock_movement(): void
    {
        [$tenant] = $this->tenantWithUser();
        $customer = $this->customer($tenant);
        $labour = $this->serviceVariantFor($tenant);

        $bill = $this->sell($tenant, $customer, [$this->line($labour, '4', '750.00')]);

        $this->assertCount(0, $bill->stockMovements);
        $this->assertSame('3000.00', $this->balanceOf($tenant, SystemAccount::ServiceIncome));
        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::Cogs));
        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::Inventory));

        // No cost of goods, so no margin — null rather than 100%, which would
        // flatter the workshop's most valuable work.
        $this->assertNull($this->actingForTenant($tenant, fn () => app(BillService::class)->marginFor($bill)));

        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function intra_state_splits_cgst_and_sgst_and_inter_state_uses_igst(): void
    {
        // The workshop is in 27; the two customers are in 27 and 29.
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        $this->receiveStock($tenant, $motor, '4', '8000.00');

        $local = $this->sell($tenant, $this->customer($tenant, '27'), [$this->line($motor, '1', '10000.00')]);
        $away = $this->sell($tenant, $this->customer($tenant, '29'), [$this->line($motor, '1', '10000.00')]);

        $localLine = $this->actingForTenant($tenant, fn () => app(BillService::class)->linesFor($local))->first();
        $awayLine = $this->actingForTenant($tenant, fn () => app(BillService::class)->linesFor($away))->first();

        $this->assertSame('900.00', (string) $localLine->cgst_amount);
        $this->assertSame('900.00', (string) $localLine->sgst_amount);
        $this->assertSame('0.00', (string) $localLine->igst_amount);

        $this->assertSame('0.00', (string) $awayLine->cgst_amount);
        $this->assertSame('1800.00', (string) $awayLine->igst_amount);

        // Both land in one GST Output account: the ledger carries the total and
        // the split lives on the line.
        $this->assertSame('3600.00', $this->balanceOf($tenant, SystemAccount::GstOutput));

        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function an_odd_tax_splits_in_two_without_leaving_a_paisa_behind(): void
    {
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        // ₹4,237.29 at 18% is ₹762.7122 — ₹762.71, which does not halve evenly.
        $bill = $this->sell($tenant, $this->customer($tenant, '27'), [
            ['variant_id' => $labour->id, 'quantity' => '1', 'unit_price' => '4237.29'],
        ]);

        $line = $this->actingForTenant($tenant, fn () => app(BillService::class)->linesFor($bill))->first();

        // The halves must add back to exactly the tax charged, or the invoice
        // total contains a paisa no line accounts for.
        $this->assertSame('381.35', (string) $line->cgst_amount);
        $this->assertSame('381.36', (string) $line->sgst_amount);
        $this->assertSame('762.71', $line->taxMoney()->amount());
        $this->assertSame('5000.00', $line->totalMoney()->amount());

        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function selling_below_cost_warns_and_still_posts(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        $this->receiveStock($tenant, $motor, '2', '9000.00');

        $bill = $this->sell($tenant, $this->customer($tenant), [$this->line($motor, '1', '7500.00')]);

        $warnings = $this->actingForTenant($tenant, fn () => app(BillService::class)->warningsFor($bill));

        $this->assertSame('BILL_LINE_BELOW_COST', $warnings[0]['code']);
        $this->assertTrue($bill->isPosted());

        $margin = $this->actingForTenant($tenant, fn () => app(BillService::class)->marginFor($bill));

        $this->assertSame('-1500.00', $margin['margin']);
        $this->assertSame('-20.00', $margin['margin_percent']);

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function the_trial_balance_reconciles_after_a_hundred_mixed_bills(): void
    {
        [$tenant] = $this->tenantWithUser();

        $customer = $this->customer($tenant, '27');
        $away = $this->customer($tenant, '29');
        $vendor = $this->vendor($tenant);

        $motor = $this->variantFor($tenant, 'motor');
        $copper = $this->variantFor($tenant, 'bulk_material');
        $labour = $this->serviceVariantFor($tenant);

        for ($i = 0; $i < 25; $i++) {
            $this->buy($tenant, $vendor, [
                $this->line($motor, '2', (string) (8000 + $i * 13).'.00'),
                $this->line($copper, '5', (string) (700 + $i * 7).'.50'),
            ]);

            $this->sell($tenant, $i % 2 === 0 ? $customer : $away, [
                $this->line($motor, '1', (string) (11000 + $i * 11).'.00'),
                $this->line($copper, '2.5', (string) (950 + $i * 3).'.25'),
                $this->line($labour, '1', '1250.00'),
            ], $i % 3 === 0 ? [['cash', '5000.00']] : []);
        }

        $this->assertBooksBalance($tenant, 'after a hundred mixed bills');
        $this->assertStockAgreesWithInventoryAccount($tenant, 'after a hundred mixed bills');
    }

    /* ---------------------------------------------------------------------
     | Payment terms
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_bill_may_be_paid_in_full_in_part_or_not_at_all(): void
    {
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        $credit = $this->customer($tenant);
        $partial = $this->customer($tenant);
        $paid = $this->customer($tenant);

        // ₹1,000 + 18% = ₹1,180 each.
        $this->sell($tenant, $credit, [$this->line($labour, '1', '1000.00')]);
        $this->sell($tenant, $partial, [$this->line($labour, '1', '1000.00')], [['cash', '500.00']]);
        $this->sell($tenant, $paid, [$this->line($labour, '1', '1000.00')], [['upi', '1180.00', 'UPI-9911']]);

        $this->assertSame(['receivable' => '1180.00', 'payable' => '0.00', 'net' => '1180.00'], $this->positionOf($tenant, $credit));
        $this->assertSame(['receivable' => '680.00', 'payable' => '0.00', 'net' => '680.00'], $this->positionOf($tenant, $partial));
        $this->assertSame(['receivable' => '0.00', 'payable' => '0.00', 'net' => '0.00'], $this->positionOf($tenant, $paid));

        $this->assertSame('500.00', $this->balanceOf($tenant, SystemAccount::Cash));
        $this->assertSame('1180.00', $this->balanceOf($tenant, SystemAccount::Upi));

        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function collecting_more_than_the_bill_is_worth_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('more than the document');

        // Not a credit balance waiting to be used — that is what a receipt with
        // no invoice behind it produces, which M5 deliberately allows. This is a
        // typo on a document whose own total contradicts it.
        $this->sell($tenant, $this->customer($tenant), [$this->line($labour, '1', '1000.00')], [['cash', '5000.00']]);
    }

    /* ---------------------------------------------------------------------
     | What the engine refuses
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_stocked_item_billed_without_a_variant_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('say which variant');

        $this->sell($tenant, $this->customer($tenant), [
            ['item_id' => $motor->item_id, 'quantity' => '1', 'unit_price' => '10000.00'],
        ]);
    }

    #[Test]
    public function a_line_naming_a_variant_of_another_item_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');
        $bearing = $this->variantFor($tenant, 'part');

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('belongs to a different item');

        $this->sell($tenant, $this->customer($tenant), [
            ['item_id' => $bearing->item_id, 'variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '100.00'],
        ]);
    }

    #[Test]
    public function a_sale_to_a_vendor_only_party_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('is not marked as a customer');

        $this->sell($tenant, $this->vendor($tenant), [$this->line($labour, '1', '500.00')]);
    }

    #[Test]
    public function a_bill_with_no_lines_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('at least one line');

        $this->sell($tenant, $this->customer($tenant), []);
    }

    /* ---------------------------------------------------------------------
     | Documents, drafts and reversals
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_discount_reduces_what_tax_is_due_on(): void
    {
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        $bill = $this->sell($tenant, $this->customer($tenant), [
            $this->line($labour, '2', '1000.00', '200.00'),
        ]);

        $line = $this->actingForTenant($tenant, fn () => app(BillService::class)->linesFor($bill))->first();

        $this->assertSame('1800.00', $line->taxableMoney()->amount());
        $this->assertSame('324.00', $line->taxMoney()->amount());
        $this->assertSame('2124.00', $line->totalMoney()->amount());
        $this->assertSame('2124.00', $bill->totalMoney()->amount());
    }

    #[Test]
    public function the_transaction_total_is_the_invoice_not_the_sum_of_its_debits(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        $this->receiveStock($tenant, $motor, '2', '8000.00');

        $bill = $this->sell($tenant, $this->customer($tenant), [$this->line($motor, '1', '10000.00')]);

        // Debits are ₹11,800 receivable plus ₹8,000 of cost. Reporting ₹19,800
        // as the value of a ₹11,800 invoice would be wrong on every list it
        // appears in.
        $this->assertSame('11800.00', $bill->totalMoney()->amount());
    }

    #[Test]
    public function a_draft_bill_writes_no_lines_and_is_re_priced_when_it_posts(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        $this->receiveStock($tenant, $motor, '2', '8000.00');

        $draft = $this->sell($tenant, $this->customer($tenant), [$this->line($motor, '1', '12000.00')], post: false);

        $this->assertTrue($draft->isDraft());
        $this->assertCount(0, $this->actingForTenant($tenant, fn () => app(BillService::class)->linesFor($draft)));

        // A delivery at a higher price lands before anybody authorises the draft.
        $this->receiveStock($tenant, $motor, '2', '10000.00');

        $posted = $this->actingForTenant($tenant, fn () => $this->engine()->postDraft($draft->fresh()));

        // Costed at ₹9,000 — today's average — not at the ₹8,000 that was true
        // when the draft was started.
        $this->assertSame('-9000.00', $posted->stockMovements->first()->valueMoney()->amount());

        $this->assertStockAgreesWithInventoryAccount($tenant, 'after posting a stale draft bill');
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function reversing_a_sale_returns_the_stock_and_nets_every_account_to_nothing(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        // Bought in rather than adjusted in, so COGS starts at zero and "nets to
        // nothing" means what it says.
        $this->buy($tenant, $this->vendor($tenant), [$this->line($motor, '4', '8000.00')]);

        $bill = $this->sell($tenant, $this->customer($tenant), [$this->line($motor, '1', '10000.00')], [['cash', '5000.00']]);

        $this->actingForTenant($tenant, fn () => $this->engine()->reverse($bill));

        foreach ([SystemAccount::Receivables, SystemAccount::Sales, SystemAccount::GstOutput, SystemAccount::Cogs, SystemAccount::Cash] as $account) {
            $this->assertSame('0.00', $this->balanceOf($tenant, $account), "{$account->value} should net to nothing.");
        }

        $this->assertSame('4.000', $this->stockPositionOf($tenant, $motor)['quantity']);
        $this->assertSame('32000.00', $this->balanceOf($tenant, SystemAccount::Inventory));

        $this->assertStockAgreesWithInventoryAccount($tenant, 'after reversing a sale');
        $this->assertBooksBalance($tenant);
    }

    /**
     * M17's decision D6, which reverses M8's.
     *
     * M8 allowed the sale and warned about it, on the grounds that refusing does
     * not produce the bearing. That is still true, and it is still not what the
     * counter wants — so the refusal is on by default and the workshop can turn
     * it off. Both halves are asserted here and in the test below.
     */
    #[Test]
    public function a_sale_is_refused_when_the_shelf_does_not_hold_the_stock(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '1', '400.00');

        $this->expectException(InsufficientStockException::class);
        // The brief's own words, in the brief's own units.
        $this->expectExceptionMessageMatches('/Only 1 pc available in stock/');

        $this->sell($tenant, $this->customer($tenant), [$this->line($bearing, '3', '600.00')]);
    }

    #[Test]
    public function a_refused_sale_moves_nothing_at_all(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '1', '400.00');

        try {
            $this->sell($tenant, $this->customer($tenant), [$this->line($bearing, '3', '600.00')]);
        } catch (InsufficientStockException) {
            // The point of refusing before the write rather than during it.
        }

        $this->assertSame('1.000', $this->stockPositionOf($tenant, $bearing)['quantity']);
        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::Sales));
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_workshop_that_bills_ahead_of_its_paperwork_can_allow_it(): void
    {
        // The escape hatch M8's reasoning earned. A fitter records Tuesday's sale
        // and the supplier's invoice reaches the office on Friday; this workshop
        // has said so, and the bill posts.
        [$tenant] = $this->tenantWithUser();
        $this->actingForTenant($tenant, fn () => $tenant->forceFill(['allow_negative_stock' => true])->save());

        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '1', '400.00');

        $bill = $this->sell($tenant, $this->customer($tenant), [$this->line($bearing, '3', '600.00')]);

        $this->assertSame('-2.000', $this->stockPositionOf($tenant, $bearing)['quantity']);

        // Allowed is not the same as unremarked: the bill still carries the
        // warning, because somebody has to be told the shelf and the books
        // disagree.
        $warnings = $this->actingForTenant($tenant, fn () => app(BillService::class)->warningsFor($bill));

        $this->assertContains('STOCK_NEGATIVE', array_column($warnings, 'code'));

        // The shortfall is valued at the last rate actually paid, never at zero.
        $this->assertSame('-1200.00', $bill->stockMovements->first()->valueMoney()->amount());

        $this->assertStockAgreesWithInventoryAccount($tenant, 'after selling into a negative position');
        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_service_item_that_has_been_billed_cannot_be_deleted(): void
    {
        // The check that is not covered by the stock one: an hour of labour is
        // billed and moves nothing, so a service item can have a year of
        // invoices behind it and no movements whatsoever.
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        $this->sell($tenant, $this->customer($tenant), [$this->line($labour, '2', '750.00')]);

        $this->expectException(ItemInUseException::class);
        $this->expectExceptionMessage('bill line');

        $this->actingForTenant($tenant, fn () => app(ItemService::class)->delete($labour->item_id));
    }

    #[Test]
    public function a_bills_margin_comes_from_the_movement_rather_than_a_stored_copy(): void
    {
        [$tenant] = $this->tenantWithUser();
        $motor = $this->variantFor($tenant, 'motor');

        $this->receiveStock($tenant, $motor, '2', '8000.00');

        $bill = $this->sell($tenant, $this->customer($tenant), [$this->line($motor, '1', '10000.00')]);

        $line = $this->actingForTenant($tenant, fn () => app(BillService::class)->linesFor($bill))->first();

        $this->assertSame('8000.00', $line->cost()->amount());
        $this->assertSame('2000.00', $line->margin()->amount());

        // And the cost is the movement's, not a column of its own.
        $this->assertSame(
            $line->id,
            $bill->stockMovements->first()->transaction_line_id,
            'Each stock movement must point back at the bill line that produced it.',
        );
    }
}
