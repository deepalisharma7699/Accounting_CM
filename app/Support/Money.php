<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money, held as a whole number of paise.
 *
 * The rule the whole ledger rests on is that money is never a float. A binary
 * float cannot represent 0.10, so `0.1 + 0.2 !== 0.3`, and a balance check
 * written against floats fails on entries that are demonstrably correct — or,
 * worse, passes on entries that are not. Every arithmetic operation here is
 * integer arithmetic on minor units, and the only place a decimal string is
 * produced is at the boundary, for storage or display.
 *
 * The column type is `DECIMAL(15, 2)`, so the largest representable amount is
 * ₹9,999,999,999,999.99 — comfortably inside PHP's 64-bit integer range even
 * after multiplying by 100.
 *
 * Immutable: every operation returns a new instance.
 */
final class Money implements JsonSerializable, Stringable
{
    /** Decimal places. Paise, and the scale of every money column. */
    public const SCALE = 2;

    private const MINOR_PER_UNIT = 100;

    /** Matches the DECIMAL(15, 2) columns: 13 integer digits, 2 decimal. */
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
     * From a whole number of paise — the form everything internal uses.
     */
    public static function fromMinor(int $minor): self
    {
        self::assertInRange($minor);

        return new self($minor);
    }

    /**
     * Parse an amount arriving from outside: a JSON body, a DECIMAL column, a
     * CSV cell.
     *
     * Floats are accepted because `json_decode` produces them for `100.50` and
     * refusing would only push a lossy conversion into every caller — but they
     * are converted through their decimal *representation*, never by
     * multiplying, so no rounding error survives the boundary.
     */
    public static function of(int|string|float $amount): self
    {
        if (is_int($amount)) {
            return self::fromMinor($amount * self::MINOR_PER_UNIT);
        }

        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('An amount must be a finite number.');
            }

            // %F, not (string): the cast uses scientific notation past a
            // certain magnitude, which the parser below would reject.
            $amount = sprintf('%.6F', $amount);
        }

        return self::parse(trim($amount));
    }

    /**
     * Like {@see of()}, but null in, null out — for optional fields.
     */
    public static function ofNullable(int|string|float|null $amount): ?self
    {
        return $amount === null || $amount === '' ? null : self::of($amount);
    }

    private static function parse(string $amount): self
    {
        if (! preg_match('/^(?<sign>[-+]?)(?<whole>\d{1,15})(?:\.(?<fraction>\d+))?$/', $amount, $matches)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid amount.");
        }

        $fraction = $matches['fraction'] ?? '';

        // Pad to one digit past the scale so the rounding digit always exists,
        // then round half away from zero — the convention every invoice uses.
        $fraction = str_pad($fraction, self::SCALE + 1, '0');

        $minor = (int) $matches['whole'] * self::MINOR_PER_UNIT
            + (int) substr($fraction, 0, self::SCALE);

        if ((int) $fraction[self::SCALE] >= 5) {
            $minor++;
        }

        return self::fromMinor($matches['sign'] === '-' ? -$minor : $minor);
    }

    /**
     * The total of a list of amounts. Empty in, zero out — an empty ledger
     * balances at 0 = 0 rather than failing.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->minor;
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

    /* ---------------------------------------------------------------------
     | Output
     |-------------------------------------------------------------------- */

    /**
     * Whole paise. The canonical internal representation.
     */
    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * A fixed-point decimal string: "1234.50". What goes into a DECIMAL column
     * and what leaves over the API — a JSON number would be parsed back into a
     * float by every client that received it.
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
            throw new InvalidArgumentException(
                'The amount is larger than this ledger can hold.'
            );
        }
    }
}
