<?php

namespace App\Services\Accounting\Tax;

use App\Support\Money;
use InvalidArgumentException;
use Stringable;

/**
 * A GST rate, held as a whole number of basis points.
 *
 * The same rule as {@see Money} and {@see \App\Support\Quantity}, and here it
 * matters most of all: **a rate is multiplied by an amount to produce a figure on
 * a government return.** 18% as a float is 0.17999999999999999, and a return that
 * is a rupee out is a return that has to be explained.
 *
 * 18.00% is 1,800 basis points. The scale is two decimal places because that is
 * what `items.gst_rate` stores, and because Indian GST has rates like 0.25% and
 * 3% that a whole-percent integer could not hold.
 *
 * Immutable.
 */
final class GstRate implements Stringable
{
    /** Basis points per whole percent. */
    private const POINTS_PER_PERCENT = 100;

    /** What a percentage is *of*: 100 percent, in basis points. */
    private const POINTS_PER_WHOLE = 100 * self::POINTS_PER_PERCENT;

    private function __construct(private readonly int $points) {}

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * From a percentage as it is stored and sent: "18.00", 18, 18.0.
     *
     * Floats go through their decimal representation rather than a
     * multiplication, exactly as {@see Money::of()} does, so no rounding error
     * survives the boundary.
     */
    public static function of(int|string|float $percent): self
    {
        if (is_int($percent)) {
            return new self(self::assertInRange($percent * self::POINTS_PER_PERCENT));
        }

        if (is_float($percent)) {
            if (! is_finite($percent)) {
                throw new InvalidArgumentException('A GST rate must be a finite number.');
            }

            $percent = sprintf('%.4F', $percent);
        }

        $percent = trim((string) $percent);

        if ($percent === '') {
            return self::zero();
        }

        if (! preg_match('/^(?<whole>\d{1,3})(?:\.(?<fraction>\d+))?$/', $percent, $matches)) {
            throw new InvalidArgumentException("[{$percent}] is not a valid GST rate.");
        }

        // Pad one digit past the scale so the rounding digit always exists.
        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');

        $points = (int) $matches['whole'] * self::POINTS_PER_PERCENT + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $points++;
        }

        return new self(self::assertInRange($points));
    }

    public static function ofNullable(int|string|float|null $percent): self
    {
        return $percent === null || $percent === '' ? self::zero() : self::of($percent);
    }

    /**
     * The tax due on a taxable value, rounded once at the end.
     *
     * Integer arithmetic throughout: paise times basis points, divided back down
     * by ten thousand. ₹4,237.29 at 18% is 423729 × 1800 ÷ 10000 = 76,271 paise,
     * and never 762.7121999999999.
     */
    public function taxOn(Money $taxable): Money
    {
        if ($this->points === 0 || $taxable->isZero()) {
            return Money::zero();
        }

        return Money::fromMinor(self::divideRounded($taxable->minor() * $this->points, self::POINTS_PER_WHOLE));
    }

    /**
     * Half the tax, rounded down — the CGST share of an intra-state supply.
     *
     * Deliberately *not* symmetrical with the SGST half: see
     * {@see GstBreakdown}, which takes the remainder for the second half so the
     * two always add back to the whole.
     */
    public function halfOf(Money $tax): Money
    {
        return Money::fromMinor(intdiv($tax->minor(), 2));
    }

    public function isZero(): bool
    {
        return $this->points === 0;
    }

    public function equals(self $other): bool
    {
        return $this->points === $other->points;
    }

    public function points(): int
    {
        return $this->points;
    }

    /**
     * The rate as a two-decimal string — what goes into a DECIMAL column and
     * what leaves over the API.
     */
    public function percent(): string
    {
        return sprintf('%d.%02d', intdiv($this->points, self::POINTS_PER_PERCENT), $this->points % self::POINTS_PER_PERCENT);
    }

    /**
     * How a rate is written on an invoice: "18%", not "18.00%".
     */
    public function label(): string
    {
        return rtrim(rtrim($this->percent(), '0'), '.').'%';
    }

    public function __toString(): string
    {
        return $this->percent();
    }

    /**
     * Integer division rounded half away from zero, so tax never drifts
     * downwards over a long run of lines.
     */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        $quotient = intdiv(abs($numerator), $denominator);

        if ((abs($numerator) % $denominator) * 2 >= $denominator) {
            $quotient++;
        }

        return $numerator < 0 ? -$quotient : $quotient;
    }

    private static function assertInRange(int $points): int
    {
        if ($points < 0 || $points > self::POINTS_PER_WHOLE) {
            throw new InvalidArgumentException('A GST rate must be between 0% and 100%.');
        }

        return $points;
    }
}
