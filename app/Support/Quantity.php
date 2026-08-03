<?php

namespace App\Support;

use App\Enums\UnitOfMeasure;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of *stuff*, held as a whole number of thousandths.
 *
 * The companion to {@see Money}, and it exists for the same reason. A quantity
 * is multiplied by a cost to produce a value that goes straight into the ledger,
 * so a float here would put a rounding error into the Inventory account exactly
 * as surely as a float amount would. `0.1 + 0.2` fails for kilograms of copper
 * the same way it fails for rupees.
 *
 * The column type is `DECIMAL(15, 3)` — three decimals, because that is what the
 * trade actually measures to: copper is weighed to the gram and wire is cut to
 * the millimetre. Whether a *fraction* is meaningful at all is a property of the
 * unit rather than of this class; see {@see UnitOfMeasure::isFractional()} and
 * {@see isWhole()}.
 *
 * **Signed, deliberately.** A stock movement's direction is its sign, so that a
 * position is `SUM(quantity)` — one indexed sum rather than a CASE expression
 * that every caller has to remember to write the same way. That is the opposite
 * of {@see Money}, where the side is a separate column, and the reason for the
 * difference is that a ledger line has a side and a stock movement does not: it
 * has a direction, and there is nowhere else to put it.
 *
 * Immutable: every operation returns a new instance.
 */
final class Quantity implements JsonSerializable, Stringable
{
    /** Decimal places, and the scale of every quantity column. */
    public const SCALE = 3;

    private const MINOR_PER_UNIT = 1000;

    /** Matches DECIMAL(15, 3): 12 integer digits, 3 decimal. */
    private const MAX_MINOR = 999_999_999_999_999;

    private function __construct(private readonly int $minor) {}

    /* ---------------------------------------------------------------------
     | Construction
     |-------------------------------------------------------------------- */

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * From a whole number of thousandths — the form everything internal uses.
     */
    public static function fromMinor(int $minor): self
    {
        self::assertInRange($minor);

        return new self($minor);
    }

    /**
     * Parse a quantity arriving from outside: a JSON body, a DECIMAL column, a
     * CSV cell.
     *
     * Floats are accepted because `json_decode` produces one for `2.5`, but they
     * are converted through their decimal *representation* rather than by
     * multiplying, so no rounding error survives the boundary.
     */
    public static function of(int|string|float $quantity): self
    {
        if (is_int($quantity)) {
            return self::fromMinor($quantity * self::MINOR_PER_UNIT);
        }

        if (is_float($quantity)) {
            if (! is_finite($quantity)) {
                throw new InvalidArgumentException('A quantity must be a finite number.');
            }

            // %F, not (string): the cast switches to scientific notation past a
            // certain magnitude, which the parser below would reject.
            $quantity = sprintf('%.6F', $quantity);
        }

        return self::parse(trim($quantity));
    }

    public static function ofNullable(int|string|float|null $quantity): ?self
    {
        return $quantity === null || $quantity === '' ? null : self::of($quantity);
    }

    private static function parse(string $quantity): self
    {
        if (! preg_match('/^(?<sign>[-+]?)(?<whole>\d{1,12})(?:\.(?<fraction>\d+))?$/', $quantity, $matches)) {
            throw new InvalidArgumentException("[{$quantity}] is not a valid quantity.");
        }

        $fraction = $matches['fraction'] ?? '';

        // Pad one digit past the scale so the rounding digit always exists, then
        // round half away from zero — the same convention Money uses.
        $fraction = str_pad($fraction, self::SCALE + 1, '0');

        $minor = (int) $matches['whole'] * self::MINOR_PER_UNIT
            + (int) substr($fraction, 0, self::SCALE);

        if ((int) $fraction[self::SCALE] >= 5) {
            $minor++;
        }

        return self::fromMinor($matches['sign'] === '-' ? -$minor : $minor);
    }

    /**
     * @param  iterable<self>  $quantities
     */
    public static function sum(iterable $quantities): self
    {
        $total = 0;

        foreach ($quantities as $quantity) {
            $total += $quantity->minor;
        }

        return self::fromMinor($total);
    }

    /* ---------------------------------------------------------------------
     | Arithmetic
     |-------------------------------------------------------------------- */

    public function plus(self $other): self
    {
        return self::fromMinor($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return self::fromMinor($this->minor - $other->minor);
    }

    public function negated(): self
    {
        return new self(-$this->minor);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor));
    }

    /* ---------------------------------------------------------------------
     | Valuation
     |-------------------------------------------------------------------- */

    /**
     * What this many of something costs at a given rate per unit.
     *
     * Integer arithmetic throughout, rounded once at the end: thousandths times
     * paise, divided back down by a thousand. 2.5 kg at ₹750.50 is 2500 × 75050
     * ÷ 1000 = 187,625 paise, and never 1876.2499999999998.
     */
    public function costAt(Money $unitCost): Money
    {
        return Money::fromMinor(self::divideRounded($this->minor * $unitCost->minor(), self::MINOR_PER_UNIT));
    }

    /**
     * The rate per unit that a total value implies — the weighted average cost,
     * when the value is a stock position's and the quantity is its own.
     *
     * Zero in, zero out: an empty position has no meaningful rate, and the
     * caller decides what to show instead. Dividing would raise here, and a
     * position legitimately reaches zero every time a workshop sells out.
     */
    public function rateFrom(Money $value): Money
    {
        if ($this->minor === 0) {
            return Money::zero();
        }

        return Money::fromMinor(self::divideRounded($value->minor() * self::MINOR_PER_UNIT, $this->minor));
    }

    /**
     * The share of a value that this quantity represents out of a larger one.
     *
     * Used to value a stock issue: 3 kg out of a 10 kg position worth ₹7,500 is
     * ₹2,250. Proportional rather than quantity × average, because the average
     * is itself a rounded number and multiplying by it leaves paise behind on
     * every issue — which accumulate in the Inventory account as stock nobody
     * has.
     */
    public function shareOf(Money $value, self $total): Money
    {
        if ($total->minor === 0) {
            return Money::zero();
        }

        return Money::fromMinor(self::divideRounded($value->minor() * $this->minor, $total->minor));
    }

    /**
     * Integer division rounded half away from zero, so a value never drifts
     * downwards over a long run of issues.
     */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return 0;
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $quotient = intdiv(abs($numerator), abs($denominator));
        $remainder = abs($numerator) % abs($denominator);

        if ($remainder * 2 >= abs($denominator)) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    /* ---------------------------------------------------------------------
     | Comparison
     |-------------------------------------------------------------------- */

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function compareTo(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    public function isAtMost(self $other): bool
    {
        return $this->minor <= $other->minor;
    }

    /**
     * Whether this is a whole number of units — what a counted item demands.
     *
     * 2.5 kg of copper is ordinary; 2.5 bearings is a mistake somebody should be
     * told about before it reaches the stock ledger.
     */
    public function isWhole(): bool
    {
        return $this->minor % self::MINOR_PER_UNIT === 0;
    }

    /**
     * Whether this quantity is expressible in the given unit at all.
     */
    public function fitsUnit(UnitOfMeasure $unit): bool
    {
        return $unit->isFractional() || $this->isWhole();
    }

    /* ---------------------------------------------------------------------
     | Output
     |-------------------------------------------------------------------- */

    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * A fixed-point decimal string: "2.500". What goes into a DECIMAL column and
     * what leaves over the API — a JSON number would be parsed back into a float
     * by every client that received it.
     */
    public function amount(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $minor = abs($this->minor);

        return sprintf(
            '%s%d.%0'.self::SCALE.'d',
            $sign,
            intdiv($minor, self::MINOR_PER_UNIT),
            $minor % self::MINOR_PER_UNIT,
        );
    }

    /**
     * The shortest honest rendering, for a label rather than a column: "3" for
     * three bearings, "2.5" for two and a half kilograms.
     */
    public function trimmed(): string
    {
        $amount = $this->amount();

        return str_contains($amount, '.')
            ? rtrim(rtrim($amount, '0'), '.')
            : $amount;
    }

    /**
     * The quantity with its unit, the way a stock report prints it: "2.5 kg".
     */
    public function withUnit(UnitOfMeasure $unit): string
    {
        return $this->trimmed().' '.$unit->symbol();
    }

    public function jsonSerialize(): string
    {
        return $this->amount();
    }

    public function __toString(): string
    {
        return $this->amount();
    }

    private static function assertInRange(int $minor): void
    {
        if (abs($minor) > self::MAX_MINOR) {
            throw new InvalidArgumentException('The quantity is larger than this stock ledger can hold.');
        }
    }
}
