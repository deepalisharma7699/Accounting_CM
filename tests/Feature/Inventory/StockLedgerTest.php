<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Exceptions\Accounting\ItemInUseException;
use App\Exceptions\Accounting\LedgerImmutableException;
use App\Models\ItemVariant;
use App\Models\StockMovement;
use App\Services\Accounting\Posting\PostingBatch;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\StockChange;
use App\Services\Inventory\ItemVariantService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M8's invariants, each with its own test.
 *
 * The one this module lives or dies by is
 * {@see InteractsWithStock::assertStockAgreesWithInventoryAccount()}: the shelf
 * and the Inventory account are the same number arrived at two ways, and every
 * scenario here asserts it alongside the trial balance.
 */
class StockLedgerTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Weighted average cost
     |-------------------------------------------------------------------- */

    public function test_the_weighted_average_is_the_roadmaps_worked_example(): void
    {
        // 10 kg at ₹700 then 10 kg at ₹800 is ₹750/kg — neither price paid.
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->receiveStock($tenant, $copper, '10', '700.00');
        $this->receiveStock($tenant, $copper, '10', '800.00');

        $this->assertSame([
            'quantity' => '20.000',
            'value' => '15000.00',
            'average_cost' => '750.00',
        ], $this->stockPositionOf($tenant, $copper));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    public function test_an_issue_values_at_the_current_average_and_does_not_change_it(): void
    {
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->receiveStock($tenant, $copper, '10', '700.00');
        $this->receiveStock($tenant, $copper, '10', '800.00');

        $issue = $this->issueStock($tenant, $copper, '3');

        // 3 kg out of 20 worth ₹15,000 is ₹2,250 — and the remainder is still
        // ₹750/kg, because issuing stock cannot make what is left dearer.
        $this->assertSame('-2250.00', $issue->stockMovements->first()->valueMoney()->amount());

        $this->assertSame([
            'quantity' => '17.000',
            'value' => '12750.00',
            'average_cost' => '750.00',
        ], $this->stockPositionOf($tenant, $copper));

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    public function test_issuing_everything_leaves_the_position_at_exactly_zero(): void
    {
        // The rounding case. 3 kg out of a 10 kg position worth ₹7,501 is not a
        // whole number of paise at any rate, and valuing each issue at a rounded
        // average would leave the Inventory account holding stock nobody has.
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->receiveStock($tenant, $copper, '10', '750.10');

        $this->issueStock($tenant, $copper, '3');
        $this->issueStock($tenant, $copper, '3');
        $this->issueStock($tenant, $copper, '4');

        $this->assertSame([
            'quantity' => '0.000',
            'value' => '0.00',
            // No stock, so no average. The last rate paid is a different
            // question, and StockPosition deliberately does not answer it here.
            'average_cost' => '0.00',
        ], $this->stockPositionOf($tenant, $copper));

        $this->assertSame('0.00', $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertStockAgreesWithInventoryAccount($tenant, 'after selling out');
        $this->assertBooksBalance($tenant);
    }

    public function test_a_fractional_quantity_of_a_counted_item_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->expectException(InvalidStockMovementException::class);

        $this->receiveStock($tenant, $bearing, '2.5', '400.00');
    }

    public function test_a_fractional_quantity_of_a_measured_item_is_ordinary(): void
    {
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->receiveStock($tenant, $copper, '2.5', '700.00');

        $this->assertSame('2.500', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame('1750.00', $this->stockPositionOf($tenant, $copper)['value']);
    }

    /* ---------------------------------------------------------------------
     | Negative stock — allowed, surfaced, never silently priced at zero
     |-------------------------------------------------------------------- */

    public function test_stock_can_go_negative_and_says_so(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '2', '400.00');
        $this->issueStock($tenant, $bearing, '5');

        $position = $this->actingForTenant($tenant, fn () => $this->stock()->positionFor($bearing));

        $this->assertTrue($position->isNegative());
        $this->assertFalse($position->hasStock());
        $this->assertSame('-3.000', $position->quantity->amount());

        // The three that were not there are valued at the last rate actually
        // paid, not at zero: a bearing sold before its invoice was entered still
        // cost something, and a margin of 100% would be a lie.
        $this->assertSame('-1200.00', $position->value->amount());

        $this->assertStockAgreesWithInventoryAccount($tenant, 'while a position is negative');
        $this->assertBooksBalance($tenant);
    }

    public function test_an_issue_against_a_variant_never_bought_posts_at_zero_and_warns(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->assertTrue(
            $this->actingForTenant($tenant, fn () => $this->stock()->wouldGoNegative($bearing, Quantity::of('1'))),
        );

        // Nothing is known about what it costs, so the adjustment is worth
        // nothing — and an adjustment worth nothing is refused rather than
        // posting quantities with no accounting trace behind them.
        $this->expectException(InvalidStockMovementException::class);
        $this->expectExceptionMessage('None of these adjustments changes what the stock is worth');

        $this->issueStock($tenant, $bearing, '1');
    }

    /* ---------------------------------------------------------------------
     | The stock ledger is append-only
     |-------------------------------------------------------------------- */

    public function test_a_movement_cannot_be_edited(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $movement = $this->receiveStock($tenant, $bearing, '4', '400.00')->stockMovements->first();

        $this->expectException(LedgerImmutableException::class);

        $this->actingForTenant($tenant, fn () => $movement->update(['quantity' => '99.000']));
    }

    public function test_a_movement_cannot_be_deleted(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $movement = $this->receiveStock($tenant, $bearing, '4', '400.00')->stockMovements->first();

        $this->expectException(LedgerImmutableException::class);

        $this->actingForTenant($tenant, fn () => $movement->delete());
    }

    public function test_quantity_on_hand_has_no_column_to_be_edited_in(): void
    {
        // The roadmap's first invariant, asserted structurally rather than
        // behaviourally: there is nowhere to store a wrong answer.
        foreach (['qty_on_hand', 'avg_cost', 'quantity', 'stock_value'] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('item_variants', $column),
                "item_variants must not carry a [{$column}] column — the position is a sum over stock_movements.",
            );
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('items', $column),
                "items must not carry a [{$column}] column — the position is a sum over stock_movements.",
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Stock and the books cannot disagree
     |-------------------------------------------------------------------- */

    public function test_a_batch_whose_inventory_line_disagrees_with_its_movements_is_refused(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('the Inventory account moves by');

        $this->actingForTenant($tenant, function () use ($tenant, $bearing) {
            $inventory = $this->accountFor($tenant, SystemAccount::Inventory)->id;
            $cogs = $this->accountFor($tenant, SystemAccount::Cogs)->id;

            // ₹1,600 of stock on the shelf against ₹1,500 in the books. A
            // template could not produce this; a hand-built batch can, which is
            // exactly why the engine checks rather than trusts.
            $this->engine()->post(PostingBatch::of(
                type: TransactionType::StockAdjustment,
                date: now()->toDateString(),
                lines: [
                    PostingLine::debit($inventory, Money::of('1500.00')),
                    PostingLine::credit($cogs, Money::of('1500.00')),
                ],
                movements: [
                    StockChange::arriving($bearing, Quantity::of('4'), Money::of('1600.00')),
                ],
            ));
        });
    }

    public function test_a_type_that_does_not_move_stock_cannot_carry_movements(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessage('does not move stock');

        $this->actingForTenant($tenant, function () use ($tenant, $bearing) {
            $this->engine()->post(PostingBatch::of(
                type: TransactionType::Journal,
                date: now()->toDateString(),
                lines: [
                    PostingLine::debit($this->accountFor($tenant, SystemAccount::Inventory)->id, Money::of('1600.00')),
                    PostingLine::credit($this->accountFor($tenant, SystemAccount::Cogs)->id, Money::of('1600.00')),
                ],
                movements: [
                    StockChange::arriving($bearing, Quantity::of('4'), Money::of('1600.00')),
                ],
            ));
        });
    }

    public function test_a_service_can_never_hold_stock(): void
    {
        [$tenant] = $this->tenantWithUser();
        $labour = $this->serviceVariantFor($tenant);

        $this->expectException(InvalidStockMovementException::class);
        $this->expectExceptionMessage('does not hold in stock');

        $this->receiveStock($tenant, $labour, '4', '800.00');
    }

    /* ---------------------------------------------------------------------
     | Adjustments
     |-------------------------------------------------------------------- */

    public function test_a_shortage_is_written_off_to_cogs_at_the_carrying_value(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '10', '400.00');

        // Stock arriving by adjustment credits COGS — an overage is consumption
        // being taken back — so the interesting figure is the *change*, not the
        // balance. (M9's purchase bill is where stock arrives against a payable
        // instead, and COGS is left alone.)
        $before = Money::of($this->balanceOf($tenant, SystemAccount::Cogs));

        $this->adjustStock($tenant, [[$bearing, '-2']], notes: 'Stock-take, March');

        $after = Money::of($this->balanceOf($tenant, SystemAccount::Cogs));

        $this->assertSame('3200.00', $this->balanceOf($tenant, SystemAccount::Inventory));

        // The two that are missing were consumed by something nobody recorded,
        // so they land where the rest of that consumption already is — at the
        // ₹400 the books were carrying them at, not at any newer price.
        $this->assertSame('800.00', $after->minus($before)->amount());

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    public function test_one_stock_take_can_correct_in_both_directions_at_once(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');
        $motor = $this->variantFor($tenant, 'motor');

        $this->receiveStock($tenant, $bearing, '10', '400.00');
        $this->receiveStock($tenant, $motor, '2', '8000.00');

        // Two fewer bearings and one more motor, counted on the same afternoon.
        $transaction = $this->adjustStock($tenant, [
            [$bearing, '-2'],
            [$motor, '1', '8000.00'],
        ]);

        $this->assertSame('-2.000', $transaction->stockMovements[0]->quantityValue()->amount());
        $this->assertSame('1.000', $transaction->stockMovements[1]->quantityValue()->amount());

        // Both movements are adjustments; the sign is what says which way.
        foreach ($transaction->stockMovements as $movement) {
            $this->assertSame(StockMovementType::Adjust, $movement->type);
        }

        $this->assertSame('8.000', $this->stockPositionOf($tenant, $bearing)['quantity']);
        $this->assertSame('3.000', $this->stockPositionOf($tenant, $motor)['quantity']);

        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    public function test_found_stock_may_be_given_a_cost_and_a_shortage_may_not(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '10', '400.00');

        // A stated rate on a shortage is ignored: the books decide what leaving
        // stock was worth, not the person holding the clipboard.
        $shortage = $this->adjustStock($tenant, [[$bearing, '-1', '9999.00']]);

        $this->assertSame('-400.00', $shortage->stockMovements->first()->valueMoney()->amount());
    }

    /* ---------------------------------------------------------------------
     | Reversal
     |-------------------------------------------------------------------- */

    public function test_reversing_a_stock_transaction_puts_the_quantity_back_at_the_value_it_left_at(): void
    {
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->receiveStock($tenant, $copper, '10', '700.00');
        $issue = $this->issueStock($tenant, $copper, '4');

        // The average moves after the issue, so a reversal that re-valued would
        // leave a residue in the Inventory account.
        $this->receiveStock($tenant, $copper, '10', '900.00');

        $this->actingForTenant($tenant, fn () => $this->engine()->reverse($issue));

        $position = $this->actingForTenant($tenant, fn () => $this->stock()->positionFor($copper));

        // 20 kg back on the shelf: 10 at ₹700 and 10 at ₹900, with the 4 kg
        // issued at ₹700 taken out and put back at ₹700.
        $this->assertSame('20.000', $position->quantity->amount());
        $this->assertSame('16000.00', $position->value->amount());
        $this->assertSame('800.00', $position->averageCost()->amount());

        $this->assertStockAgreesWithInventoryAccount($tenant, 'after a reversal');
        $this->assertBooksBalance($tenant);
    }

    /* ---------------------------------------------------------------------
     | Drafts hold the request, not a stale valuation
     |-------------------------------------------------------------------- */

    public function test_a_draft_moves_no_stock_and_is_re_valued_when_it_is_finally_posted(): void
    {
        [$tenant] = $this->tenantWithUser();
        $copper = $this->variantFor($tenant, 'bulk_material');

        $this->receiveStock($tenant, $copper, '10', '700.00');

        $draft = $this->adjustStock($tenant, [[$copper, '-2']], post: false);

        // Nothing on the shelf has moved, and the only movement in existence is
        // still the receipt — a draft writes none at all.
        $this->assertSame('10.000', $this->stockPositionOf($tenant, $copper)['quantity']);
        $this->assertSame(1, $this->actingForTenant($tenant, fn () => StockMovement::query()->count()));
        $this->assertTrue($draft->isDraft());

        // A delivery lands before anybody authorises the draft.
        $this->receiveStock($tenant, $copper, '10', '900.00');

        $posted = $this->actingForTenant($tenant, fn () => $this->engine()->postDraft($draft->fresh()));

        // Valued at ₹800/kg — today's average — not at the ₹700 that was true
        // when the draft was started.
        $this->assertSame('-1600.00', $posted->stockMovements->first()->valueMoney()->amount());

        $this->assertStockAgreesWithInventoryAccount($tenant, 'after posting a stale draft');
        $this->assertBooksBalance($tenant);
    }

    /* ---------------------------------------------------------------------
     | The catalogue protects its history
     |-------------------------------------------------------------------- */

    public function test_a_variant_with_stock_history_cannot_be_deleted(): void
    {
        [$tenant] = $this->tenantWithUser();
        $bearing = $this->variantFor($tenant, 'part');

        $this->receiveStock($tenant, $bearing, '4', '400.00');

        $this->expectException(ItemInUseException::class);

        $this->actingForTenant($tenant, fn () => app(ItemVariantService::class)->delete($bearing));
    }

    /* ---------------------------------------------------------------------
     | Isolation
     |-------------------------------------------------------------------- */

    public function test_one_workshops_stock_is_invisible_to_another(): void
    {
        [$one] = $this->tenantWithUser();
        [$two] = $this->tenantWithUser();

        $mine = $this->variantFor($one, 'part');
        $theirs = $this->variantFor($two, 'part');

        $this->receiveStock($one, $mine, '10', '400.00');
        $this->receiveStock($two, $theirs, '7', '900.00');

        $this->assertSame('4000.00', $this->stockTotals($one)['value']);
        $this->assertSame('6300.00', $this->stockTotals($two)['value']);

        // And the other workshop's variant does not resolve at all from here.
        $this->assertNull($this->actingForTenant($one, fn () => ItemVariant::find($theirs->id)));

        $this->assertStockAgreesWithInventoryAccount($one);
        $this->assertStockAgreesWithInventoryAccount($two);
    }
}
