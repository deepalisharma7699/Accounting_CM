<?php

namespace Tests\Unit;

use App\Enums\UnitOfMeasure;
use App\Support\Money;
use App\Support\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The companion to {@see MoneyTest}, and it exists for the same reason: a
 * quantity is multiplied by a cost to produce a value that goes straight into
 * the Inventory account, so a float here would put a rounding error into the
 * ledger exactly as surely as a float amount would.
 */
class QuantityTest extends TestCase
{
    #[Test]
    public function it_holds_thousandths_as_integers(): void
    {
        $this->assertSame(2500, Quantity::of('2.5')->minor());
        $this->assertSame(3000, Quantity::of(3)->minor());
        $this->assertSame(-1500, Quantity::of('-1.5')->minor());
        $this->assertSame('2.500', Quantity::of(2.5)->amount());
    }

    #[Test]
    public function it_does_not_break_on_the_arithmetic_that_breaks_floats(): void
    {
        // 0.1 + 0.2 !== 0.3 as floats. As thousandths it is 100 + 200 === 300.
        $sum = Quantity::of('0.1')->plus(Quantity::of('0.2'));

        $this->assertTrue($sum->equals(Quantity::of('0.3')));
        $this->assertSame('0.300', $sum->amount());
    }

    #[Test]
    public function it_rounds_half_away_from_zero_at_the_boundary(): void
    {
        $this->assertSame('2.501', Quantity::of('2.5005')->amount());
        $this->assertSame('-2.501', Quantity::of('-2.5005')->amount());
        $this->assertSame('2.500', Quantity::of('2.5004')->amount());
    }

    #[Test]
    public function it_costs_a_quantity_at_a_rate_without_a_float_in_between(): void
    {
        // 2.5 kg at ₹750.50 is 2500 × 75050 ÷ 1000 = 187,625 paise.
        $this->assertSame('1876.25', Quantity::of('2.5')->costAt(Money::of('750.50'))->amount());
        $this->assertSame('0.00', Quantity::zero()->costAt(Money::of('750.50'))->amount());
    }

    #[Test]
    public function it_derives_a_rate_from_a_value_and_returns_zero_for_an_empty_position(): void
    {
        $this->assertSame('750.00', Quantity::of('20')->rateFrom(Money::of('15000.00'))->amount());

        // A position legitimately reaches zero every time a workshop sells out;
        // dividing would raise, and there is no rate to report.
        $this->assertSame('0.00', Quantity::zero()->rateFrom(Money::of('15000.00'))->amount());
    }

    #[Test]
    public function a_share_of_a_value_is_exact_when_the_whole_is_taken(): void
    {
        $position = Quantity::of('10');
        $value = Money::of('7501.00');

        // The rounding case M8 exists to get right: valuing each issue at a
        // rounded average leaves paise behind, and they accumulate in the
        // Inventory account as stock nobody has.
        $first = Quantity::of('3')->shareOf($value, $position);
        $second = Quantity::of('3')->shareOf($value->minus($first), $position->minus(Quantity::of('3')));
        $rest = Quantity::of('4')->shareOf(
            $value->minus($first)->minus($second),
            $position->minus(Quantity::of('6')),
        );

        $this->assertSame(
            $value->amount(),
            $first->plus($second)->plus($rest)->amount(),
            'Issuing an entire position must take exactly its whole value.',
        );
    }

    #[Test]
    public function it_knows_whether_a_fraction_is_meaningful_for_a_unit(): void
    {
        // 2.5 kg of copper is ordinary; 2.5 bearings is a mistake.
        $this->assertTrue(Quantity::of('2.5')->fitsUnit(UnitOfMeasure::Kilogram));
        $this->assertFalse(Quantity::of('2.5')->fitsUnit(UnitOfMeasure::Piece));
        $this->assertTrue(Quantity::of('3')->fitsUnit(UnitOfMeasure::Piece));
    }

    #[Test]
    public function it_prints_the_shortest_honest_rendering_for_a_label(): void
    {
        $this->assertSame('3', Quantity::of('3')->trimmed());
        $this->assertSame('2.5', Quantity::of('2.5')->trimmed());
        $this->assertSame('2.5 kg', Quantity::of('2.5')->withUnit(UnitOfMeasure::Kilogram));
    }

    #[Test]
    public function it_refuses_anything_that_is_not_a_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Quantity::of('two and a half');
    }

    #[Test]
    public function an_empty_sum_is_zero_rather_than_a_failure(): void
    {
        $this->assertSame('0.000', Quantity::sum([])->amount());
    }
}
