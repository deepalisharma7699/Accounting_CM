<?php

namespace App\Enums;

/**
 * The two sides of every accounting entry.
 *
 * Debit and credit are not "money in" and "money out" — they are simply the
 * left and right columns, and what they mean depends on the account. A debit
 * to Cash increases it; a debit to Sales decreases it. See
 * {@see AccountType::normalBalance()}.
 */
enum BalanceSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }

    /**
     * Conventional abbreviation, as it appears on a printed voucher.
     */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Debit => 'Dr',
            self::Credit => 'Cr',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
