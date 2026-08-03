<?php

namespace App\Enums;

/**
 * The five classes of account in double-entry bookkeeping.
 *
 * Type decides two things the whole ledger depends on: which side of an entry
 * *increases* the account, and which financial statement it belongs to. Both
 * are encoded here rather than scattered through the reporting code.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * The side that increases this account, and therefore the side its
     * balance normally sits on.
     *
     * Assets and expenses are debit-normal; liabilities, equity and income are
     * credit-normal. This is the accounting identity
     * (Assets + Expenses = Liabilities + Equity + Income) expressed as code.
     */
    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::Asset, self::Expense => BalanceSide::Debit,
            self::Liability, self::Equity, self::Income => BalanceSide::Credit,
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normalBalance() === BalanceSide::Debit;
    }

    /**
     * Balance sheet accounts carry forward year to year; profit-and-loss
     * accounts are closed into equity at year end.
     */
    public function isBalanceSheet(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Income, self::Expense => false,
        };
    }

    /**
     * The block of account codes reserved for this type.
     *
     * A conventional numbering scheme, and enforced rather than merely
     * suggested: an expense numbered 1500 would sort into the assets in every
     * report that groups by code, and the mistake would not be visible until
     * the balance sheet looked wrong.
     *
     * @return array{0: int, 1: int}
     */
    public function codeRange(): array
    {
        return match ($this) {
            self::Asset => [1000, 1999],
            self::Liability => [2000, 2999],
            self::Equity => [3000, 3999],
            self::Income => [4000, 4999],
            self::Expense => [5000, 5999],
        };
    }

    public function acceptsCode(string $code): bool
    {
        if (! ctype_digit($code)) {
            return false;
        }

        [$low, $high] = $this->codeRange();

        return (int) $code >= $low && (int) $code <= $high;
    }

    /**
     * The type a numeric code belongs to, or null if it falls outside every
     * reserved block.
     */
    public static function forCode(string $code): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->acceptsCode($code)) {
                return $type;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
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
