<?php

namespace App\Enums;

/**
 * The four things a workshop can declare it already had at go-live.
 *
 * Four rather than three, and the fourth is the one people forget: a workshop
 * that declares its stock and its debts but not the ₹40,000 in the till has
 * given the product a picture in which it opened with no money. The figure ends
 * up in Opening Balance Equity as a shortfall nobody can explain, and the first
 * cash payment sends the Cash account negative.
 *
 * Nothing here is a transaction type. All four post as
 * {@see TransactionType::Opening} against Opening Balance Equity — the kind
 * decides which account moves and what has to be resolved before it can, not
 * what the accounting is.
 */
enum OpeningRowKind: string
{
    /**
     * What was on the shelf. The only kind that moves a quantity, and the only
     * one that has to resolve a variant rather than an account.
     */
    case Stock = 'stock';

    /** What a customer already owed the workshop. */
    case Receivable = 'receivable';

    /** What the workshop already owed a supplier. */
    case Payable = 'payable';

    /**
     * A balance on any other account of the workshop's own: cash in the till,
     * money in the bank, a loan outstanding.
     *
     * The open-ended one, and deliberately so. A workshop's go-live position is
     * whatever its previous books said, and enumerating the possibilities here
     * would mean a migration every time one of them turned out to keep something
     * this product had not thought of.
     */
    case Balance = 'balance';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Opening stock',
            self::Receivable => 'Customer owes',
            self::Payable => 'Owed to supplier',
            self::Balance => 'Account balance',
        };
    }

    /**
     * The control account this kind posts against, where the kind fixes one.
     *
     * Null for {@see Stock}, whose account is Inventory and is reached through
     * the movements rather than named, and for {@see Balance}, whose whole point
     * is that the row names the account.
     */
    public function controlAccount(): ?SystemAccount
    {
        return match ($this) {
            self::Receivable => SystemAccount::Receivables,
            self::Payable => SystemAccount::Payables,
            self::Stock, self::Balance => null,
        };
    }

    /**
     * The role the counterparty has to hold, where the kind names one.
     */
    public function partyRole(): ?PartyRole
    {
        return match ($this) {
            self::Receivable => PartyRole::Customer,
            self::Payable => PartyRole::Vendor,
            self::Stock, self::Balance => null,
        };
    }

    public function needsParty(): bool
    {
        return $this->partyRole() !== null;
    }

    /**
     * Which side of the named account the declaration opens on.
     *
     * A receivable is a debit — the customer owes the workshop — and a payable is
     * a credit. Null where the row decides: an account balance defaults to the
     * account's own normal side, so that a bank overdrawn at go-live can still be
     * declared truthfully.
     */
    public function side(): ?BalanceSide
    {
        return match ($this) {
            self::Receivable => BalanceSide::Debit,
            self::Payable => BalanceSide::Credit,
            self::Stock, self::Balance => null,
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
