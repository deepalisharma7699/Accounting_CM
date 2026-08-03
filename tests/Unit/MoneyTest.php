<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule the whole ledger rests on: money is never a float.
 *
 * No database — this is arithmetic, and it either holds or it does not.
 */
class MoneyTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | The float problem, which is the reason this class exists
     |-------------------------------------------------------------------- */

    #[Test]
    public function nought_point_one_plus_nought_point_two_is_exactly_nought_point_three(): void
    {
        // The canonical demonstration: in binary floating point this is
        // 0.30000000000000004, and a balance check written against floats
        // rejects an entry that is demonstrably correct.
        $this->assertNotSame(0.3, 0.1 + 0.2);

        $sum = Money::of('0.10')->plus(Money::of('0.20'));

        $this->assertSame('0.30', $sum->amount());
        $this->assertTrue($sum->equals(Money::of('0.30')));
    }

    #[Test]
    public function a_hundred_tenth_of_a_rupee_entries_total_exactly_ten_rupees(): void
    {
        $total = Money::sum(array_fill(0, 100, Money::of('0.10')));

        $this->assertSame('10.00', $total->amount());
        $this->assertSame(1000, $total->minor());
    }

    #[Test]
    public function a_long_run_of_thirds_never_drifts(): void
    {
        // 3333 lines of 33.33 — the kind of volume a year of billing reaches,
        // and where a float's error becomes visible in the rupees column.
        $total = Money::sum(array_fill(0, 3333, Money::of('33.33')));

        // 3333 × 33.33, to the paisa.
        $this->assertSame('111088.89', $total->amount());
    }

    /* ---------------------------------------------------------------------
     | Parsing
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_parses_the_forms_money_actually_arrives_in(): void
    {
        $this->assertSame('1234.50', Money::of('1234.50')->amount());
        $this->assertSame('1234.50', Money::of('1234.5')->amount());
        $this->assertSame('1234.00', Money::of('1234')->amount());
        $this->assertSame('1234.00', Money::of(1234)->amount());
        // json_decode produces a float for an unquoted 1234.50.
        $this->assertSame('1234.50', Money::of(1234.50)->amount());
        // What a DECIMAL(15,2) column hands back.
        $this->assertSame('1234.50', Money::of('1234.500000')->amount());
        $this->assertSame('0.00', Money::of('0')->amount());
        $this->assertSame('-45.60', Money::of('-45.6')->amount());
        $this->assertSame('45.60', Money::of('+45.6')->amount());
    }

    #[Test]
    public function a_float_is_converted_through_its_decimal_representation(): void
    {
        // Multiplying 0.29 by 100 in binary gives 28.999999999999996, which
        // truncates to 28 paise. Going through the string does not.
        $this->assertSame(29, Money::of(0.29)->minor());
        $this->assertSame(70, Money::of(0.70)->minor());
        $this->assertSame(115, Money::of(1.15)->minor());
    }

    #[Test]
    public function it_rounds_half_away_from_zero_at_two_places(): void
    {
        $this->assertSame('1.01', Money::of('1.005')->amount());
        $this->assertSame('1.00', Money::of('1.004')->amount());
        $this->assertSame('-1.01', Money::of('-1.005')->amount());
        $this->assertSame('2.35', Money::of('2.345')->amount());
    }

    #[Test]
    public function it_refuses_anything_that_is_not_an_amount(): void
    {
        foreach (['', 'abc', '1,234.50', '1.2.3', '₹100', '1e5', ' '] as $rubbish) {
            try {
                Money::of($rubbish);
                $this->fail("[{$rubbish}] should not have parsed as an amount.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_refuses_an_amount_larger_than_the_column_can_hold(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // DECIMAL(15, 2) tops out at 13 integer digits.
        Money::of('99999999999999.99');
    }

    /* ---------------------------------------------------------------------
     | Arithmetic and comparison
     |-------------------------------------------------------------------- */

    #[Test]
    public function arithmetic_is_exact_and_immutable(): void
    {
        $a = Money::of('100.00');
        $b = Money::of('33.33');

        $this->assertSame('133.33', $a->plus($b)->amount());
        $this->assertSame('66.67', $a->minus($b)->amount());
        $this->assertSame('-100.00', $a->negated()->amount());
        $this->assertSame('100.00', $a->negated()->absolute()->amount());

        // Every operation returns a new instance.
        $this->assertSame('100.00', $a->amount());
    }

    #[Test]
    public function it_compares_by_value(): void
    {
        $this->assertTrue(Money::of('10.00')->equals(Money::of('10')));
        $this->assertFalse(Money::of('10.00')->equals(Money::of('10.01')));

        $this->assertSame(-1, Money::of('9.99')->compareTo(Money::of('10.00')));
        $this->assertSame(0, Money::of('10.00')->compareTo(Money::of('10.00')));
        $this->assertSame(1, Money::of('10.01')->compareTo(Money::of('10.00')));

        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::of('0.01')->isPositive());
        $this->assertTrue(Money::of('-0.01')->isNegative());
    }

    #[Test]
    public function an_empty_sum_is_zero_rather_than_an_error(): void
    {
        // An untouched workshop's trial balance is 0 = 0, which is a correct
        // answer and not a missing one.
        $this->assertTrue(Money::sum([])->isZero());
    }

    /* ---------------------------------------------------------------------
     | Output
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_serialises_as_a_decimal_string_not_a_number(): void
    {
        // A JSON number is parsed back into a binary float by every client that
        // receives it, which is how a total ends up a paisa out in a browser.
        $this->assertSame('{"amount":"1234.50"}', json_encode(['amount' => Money::of('1234.5')]));
        $this->assertSame('1234.50', (string) Money::of('1234.5'));
    }

    #[Test]
    public function small_and_negative_amounts_format_with_both_places(): void
    {
        $this->assertSame('0.05', Money::of('0.05')->amount());
        $this->assertSame('0.00', Money::zero()->amount());
        $this->assertSame('-0.05', Money::of('-0.05')->amount());
        $this->assertSame('-1234567.89', Money::of('-1234567.89')->amount());
    }

    #[Test]
    public function null_survives_the_optional_parser(): void
    {
        $this->assertNull(Money::ofNullable(null));
        $this->assertNull(Money::ofNullable(''));
        $this->assertSame('5.00', Money::ofNullable('5')?->amount());
    }
}
