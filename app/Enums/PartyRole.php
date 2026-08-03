<?php

namespace App\Enums;

use App\Models\Party;

/**
 * What a party is to the workshop — and, because a role is not exclusive, what
 * else they may also be.
 *
 * The rewinding trade is full of counterparties who are both: the shop that
 * buys a rewound motor this week sells you scrap copper the next. Modelling
 * that as two records is the classic mistake — it splits one relationship into
 * two balances, and the pair is never netted or even looked at together.
 *
 * Each role names the control account that carries its side of the position.
 * That mapping is the whole reason a party ledger can be derived rather than
 * stored: given a party and their roles, the entries that concern them are
 * exactly the entries on those accounts.
 *
 * @see Party
 */
enum PartyRole: string
{
    /** Sold to. Owes the workshop money, so their position sits in Receivables. */
    case Customer = 'customer';

    /** Bought from. Owed money by the workshop, so their position sits in Payables. */
    case Vendor = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Vendor => 'Vendor',
        };
    }

    /**
     * The control account this role's balance lives in.
     *
     * A control account holds the total of every party in that role; a party
     * ledger is that same total broken out by party. The two agree by
     * construction, because they are the same rows summed two ways.
     */
    public function controlAccount(): SystemAccount
    {
        return match ($this) {
            self::Customer => SystemAccount::Receivables,
            self::Vendor => SystemAccount::Payables,
        };
    }

    /**
     * The side an unsettled position sits on: a customer who owes money is a
     * debit balance, a vendor who is owed money is a credit balance.
     */
    public function normalBalance(): BalanceSide
    {
        return $this->controlAccount()->type()->normalBalance();
    }

    /**
     * What the outstanding figure is called when it sits on its normal side.
     */
    public function positionLabel(): string
    {
        return match ($this) {
            self::Customer => 'Receivable',
            self::Vendor => 'Payable',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The control accounts for a set of roles, de-duplicated.
     *
     * @param  array<int, self>  $roles
     * @return array<int, SystemAccount>
     */
    public static function controlAccountsFor(array $roles): array
    {
        $accounts = [];

        foreach ($roles as $role) {
            $accounts[$role->controlAccount()->value] = $role->controlAccount();
        }

        return array_values($accounts);
    }
}
