<?php

namespace Tests\Unit;

use App\Services\Accounting\Tax\GstBreakdown;
use App\Services\Accounting\Tax\GstRate;
use App\Services\Accounting\Tax\PlaceOfSupply;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tax arithmetic, on its own.
 *
 * This is the one number in the product that ends up on a government return, so
 * it gets a unit test of its own rather than only being exercised through a
 * bill: a return that is a rupee out is a return that has to be explained.
 */
class GstTest extends TestCase
{
    #[Test]
    public function a_rate_is_basis_points_and_never_a_float(): void
    {
        $this->assertSame(1800, GstRate::of('18.00')->points());
        $this->assertSame(1800, GstRate::of(18)->points());
        $this->assertSame(1800, GstRate::of(18.0)->points());
        $this->assertSame(25, GstRate::of('0.25')->points());
        $this->assertSame(0, GstRate::ofNullable(null)->points());
    }

    #[Test]
    public function it_prints_a_rate_the_way_an_invoice_does(): void
    {
        $this->assertSame('18.00', GstRate::of(18)->percent());
        $this->assertSame('18%', GstRate::of(18)->label());
        $this->assertSame('0.25%', GstRate::of('0.25')->label());
    }

    #[Test]
    public function tax_is_integer_arithmetic_rounded_once(): void
    {
        // ₹4,237.29 at 18% is 423729 × 1800 ÷ 10000 = 76,271.22 paise → ₹762.71.
        $this->assertSame('762.71', GstRate::of(18)->taxOn(Money::of('4237.29'))->amount());
        $this->assertSame('0.00', GstRate::zero()->taxOn(Money::of('4237.29'))->amount());
        $this->assertSame('0.00', GstRate::of(18)->taxOn(Money::zero())->amount());
    }

    #[Test]
    public function it_refuses_a_rate_outside_nought_to_a_hundred(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GstRate::of('101.00');
    }

    /* ---------------------------------------------------------------------
     | Place of supply
     |-------------------------------------------------------------------- */

    #[Test]
    public function two_different_states_are_inter_state(): void
    {
        $this->assertTrue(PlaceOfSupply::between('27', '29')->isInterState());
        $this->assertFalse(PlaceOfSupply::between('27', '27')->isInterState());
    }

    #[Test]
    public function an_unknown_counterparty_state_is_treated_as_local(): void
    {
        // An unregistered walk-in's place of supply is the workshop's counter.
        // Defaulting the other way would put IGST on the commonest document the
        // trade has.
        $this->assertFalse(PlaceOfSupply::between('27', null)->isInterState());
        $this->assertFalse(PlaceOfSupply::between('27', '')->isInterState());
        $this->assertFalse(PlaceOfSupply::between('27', 'invalid')->isInterState());

        // And a workshop with no GSTIN of its own has no basis to charge
        // inter-state tax at all.
        $this->assertFalse(PlaceOfSupply::between(null, '29')->isInterState());
    }

    /* ---------------------------------------------------------------------
     | The split
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_intra_state_supply_splits_in_two_without_losing_a_paisa(): void
    {
        $breakdown = GstBreakdown::on(
            Money::of('4237.29'),
            GstRate::of(18),
            PlaceOfSupply::between('27', '27'),
        );

        // ₹762.71 does not halve evenly. Rounding both halves would give
        // ₹762.72 — a paisa the invoice total does not contain.
        $this->assertSame('381.35', $breakdown->cgst->amount());
        $this->assertSame('381.36', $breakdown->sgst->amount());
        $this->assertSame('0.00', $breakdown->igst->amount());
        $this->assertSame('762.71', $breakdown->total()->amount());
        $this->assertSame('5000.00', $breakdown->inclusive()->amount());
    }

    #[Test]
    public function an_inter_state_supply_is_one_charge(): void
    {
        $breakdown = GstBreakdown::on(
            Money::of('10000.00'),
            GstRate::of(18),
            PlaceOfSupply::between('27', '29'),
        );

        $this->assertSame('0.00', $breakdown->cgst->amount());
        $this->assertSame('0.00', $breakdown->sgst->amount());
        $this->assertSame('1800.00', $breakdown->igst->amount());
        $this->assertTrue($breakdown->interState);
    }

    #[Test]
    public function totals_are_summed_from_the_lines_and_not_recomputed_on_the_whole(): void
    {
        $place = PlaceOfSupply::between('27', '27');

        // A bill with an 18% motor and a 12% service has no single rate, and
        // applying one to the sum would produce a figure matching no line.
        $totals = GstBreakdown::totals([
            GstBreakdown::on(Money::of('10000.00'), GstRate::of(18), $place),
            GstBreakdown::on(Money::of('5000.00'), GstRate::of(12), $place),
        ]);

        $this->assertSame('15000.00', $totals['taxable']->amount());
        $this->assertSame('2400.00', $totals['tax']->amount());
        $this->assertSame('17400.00', $totals['total']->amount());
        $this->assertSame(
            $totals['tax']->amount(),
            $totals['cgst']->plus($totals['sgst'])->plus($totals['igst'])->amount(),
        );
    }

    #[Test]
    public function a_line_with_no_rate_carries_no_tax(): void
    {
        $breakdown = GstBreakdown::none(Money::of('500.00'));

        $this->assertTrue($breakdown->isZero());
        $this->assertSame('500.00', $breakdown->inclusive()->amount());
    }
}
