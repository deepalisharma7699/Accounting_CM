<?php

namespace App\Enums;

use App\Services\Accounting\Posting\PostingTemplateRegistry;

/**
 * The kind of business event a transaction records.
 *
 * Every type has exactly one posting template, which decides which accounts
 * move and in which direction. A type without a registered template cannot be
 * posted at all — see {@see PostingTemplateRegistry}.
 *
 * Each module adds its own case *and* the template that gives it meaning, in the
 * same slice:
 *
 *   M4  journal                   the manual voucher
 *   M6  payment, receipt          templates D and E
 *   M8  stock_adjustment          template G — the first type to move quantities
 *   M9  sale, purchase            templates A, B and C
 *   M10 expense                   template F
 *   M11 opening                   template H — the go-live declaration
 */
enum TransactionType: string
{
    /**
     * A raw double-entry voucher, written by hand.
     *
     * The one type whose lines come straight from the user rather than being
     * derived from a bill or a payment — which makes it the correction
     * mechanism for everything else, and the reason it is built first.
     */
    case Journal = 'journal';

    /**
     * Money paid out to a supplier, settling what the workshop owes them.
     *
     * `Dr Sundry Creditors / Cr Cash-Bank-UPI` — template D. Pure money
     * movement: no GST and no stock, because both belong to the bill this is
     * settling, not to the settlement of it.
     */
    case Payment = 'payment';

    /**
     * Money collected from a customer, settling what they owe the workshop.
     *
     * `Dr Cash-Bank-UPI / Cr Sundry Debtors` — template E, the mirror of D.
     */
    case Receipt = 'receipt';

    /**
     * A correction to stock against a physical count — template G.
     *
     * The first type that moves a quantity as well as an amount, and the reason
     * it exists in M8 rather than waiting for M9: a stock ledger that cannot be
     * corrected is a stock ledger nobody trusts, and the shelf is the authority
     * on what is on it. A shortage is written off to COGS and an excess is
     * written back, so the Inventory account follows the count exactly.
     */
    case StockAdjustment = 'stock_adjustment';

    /**
     * Goods or labour sold — templates A and B, which are one template.
     *
     * `Dr Sundry Debtors / Cr Sales / Cr GST Output`, plus `Dr COGS / Cr
     * Inventory` for whatever left the shelf. A rewind that bills labour, copper
     * and a bearing on one document is not a different kind of event from a
     * motor sold over the counter — it is the same event with a different mix of
     * lines, and splitting it into two templates would mean maintaining the GST
     * arithmetic twice.
     */
    case Sale = 'sale';

    /**
     * Goods bought in — template C, and the mirror of a sale.
     *
     * `Dr Inventory / Dr GST Input / Cr Sundry Creditors`, and the arrival is
     * what recomputes the weighted average cost.
     */
    case Purchase = 'purchase';

    /**
     * A running cost: rent, electricity, tea, a courier — template F.
     *
     * The simplest thing in the product that is still a real document. It has an
     * expense account, an optional claimable GST input, and a way it was paid,
     * and that is all.
     */
    case Expense = 'expense';

    /**
     * What the workshop already had on the day the books opened — template H.
     *
     * `Dr Inventory / Cr Opening Balance Equity` for the stock on the shelf,
     * `Dr Sundry Debtors / Cr OBE` for what customers already owed, and
     * `Cr Sundry Creditors / Dr OBE` for what was already owed to suppliers.
     *
     * The one type whose other side is always equity, and that is what it means:
     * an opening balance is not a transaction with anybody. Nothing was bought,
     * nothing was sold and no GST was charged — the workshop is simply declaring
     * what it was worth before this product started keeping the record. Routing
     * it through a purchase instead would report a workshop's first month as an
     * enormous acquisition and claim input tax that was never paid.
     */
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Journal => 'Journal Entry',
            self::Payment => 'Vendor Payment',
            self::Receipt => 'Customer Receipt',
            self::StockAdjustment => 'Stock Adjustment',
            self::Sale => 'Sale',
            self::Purchase => 'Purchase',
            self::Expense => 'Expense',
            self::Opening => 'Opening Balance',
        };
    }

    /**
     * True when the user supplies the accounts directly instead of the
     * template deriving them. Only a manual journal does.
     */
    public function acceptsRawLines(): bool
    {
        return $this === self::Journal;
    }

    /**
     * True when the transaction is **defined** by how it was settled, and is
     * therefore meaningless without a payment split that equals its total.
     *
     * A payment, a receipt and an expense are all "money moved" — take the split
     * away and there is no event left. A bill is not: a sale on thirty-day terms
     * is a complete document with nothing paid against it, which is why it
     * merely {@see acceptsPaymentSplit()}.
     */
    public function isSettlement(): bool
    {
        return match ($this) {
            self::Payment, self::Receipt, self::Expense => true,
            default => false,
        };
    }

    /**
     * True when the transaction may carry a payment split at all.
     *
     * The superset of {@see isSettlement()}: a bill accepts one so that "paid
     * ₹2,000 cash of a ₹5,000 invoice" is one document rather than two, which is
     * how a counter sale actually happens. What it may not do is exceed the
     * document — the remainder is settled later, with a receipt.
     */
    public function acceptsPaymentSplit(): bool
    {
        return match ($this) {
            // An opening balance is not settled: no money moved on the day the
            // books opened, which is the whole reason its other side is equity.
            // A workshop's cash in hand at go-live arrives as an opening balance
            // *on the Cash account*, not as a payment.
            self::Journal, self::StockAdjustment, self::Opening => false,
            default => true,
        };
    }

    /**
     * True when the transaction moves quantities as well as money.
     *
     * Note that this is *permission*, not obligation. A labour-only bill is a
     * sale that moves no stock at all, and refusing it would be refusing the
     * rewinding trade's most common document.
     */
    public function movesStock(): bool
    {
        return match ($this) {
            self::StockAdjustment, self::Sale, self::Purchase, self::Opening => true,
            default => false,
        };
    }

    /**
     * True when the ledger lines are *derived* from a request rather than typed,
     * so a draft has to hold the request and be re-composed when it is posted.
     *
     * This is what stops a bill parked for a fortnight from posting a cost of
     * goods sold that was true when it was started. See
     * `transactions.draft_payload`.
     */
    public function composesFromPayload(): bool
    {
        return match ($this) {
            self::StockAdjustment, self::Sale, self::Purchase, self::Expense, self::Opening => true,
            default => false,
        };
    }

    /**
     * True when the transaction carries its own document lines — items,
     * quantities and prices — as opposed to accounts or payment modes.
     */
    public function hasDocumentLines(): bool
    {
        return match ($this) {
            self::Sale, self::Purchase => true,
            default => false,
        };
    }

    /**
     * The role the counterparty must hold, where the type implies one.
     *
     * A payment debits Sundry Creditors, which *is* the claim "we owed this
     * business money" — so the party must be a vendor, and a receipt's party
     * must be a customer. Refusing the mismatch is not bureaucracy: paying a
     * customer-only party into the payables control account invents a supplier
     * relationship that does not exist, and leaves a credit position on a record
     * nobody would think to look at.
     *
     * A sale and a purchase make the same claim as a receipt and a payment
     * respectively, and are held to it for the same reason.
     *
     * This is the one place a role gates a *write*. Roles still never filter a
     * read — see PartyLedgerService — because the two are different questions:
     * which accounts a statement covers, versus whether this posting is claiming
     * something true about the relationship.
     *
     * Null for a manual journal, whose accounts the user chooses outright; for a
     * stock adjustment, which is between the workshop and its own shelf; and for
     * an expense, which is very often paid to somebody nobody will ever record.
     */
    public function requiredPartyRole(): ?PartyRole
    {
        return match ($this) {
            self::Payment, self::Purchase => PartyRole::Vendor,
            self::Receipt, self::Sale => PartyRole::Customer,
            // Null for an opening balance, and it is the only interesting one of
            // the three. An opening balance *does* imply a role — a debit to
            // Sundry Debtors says this party was a customer — but which one
            // depends on the row rather than on the type, so the claim is
            // checked where the side is known, in OpeningBalanceTemplate. A
            // fixed answer here would have to be wrong for half of them.
            self::Journal, self::StockAdjustment, self::Expense, self::Opening => null,
        };
    }

    /**
     * Whether the type is meaningless without a counterparty.
     *
     * A settlement always has one: money moved to or from somebody, and a
     * payment attributed to nobody would sit in a control account that no
     * statement could ever account for. A bill always has one for the same
     * reason — an invoice made out to nobody cannot be collected. A journal
     * legitimately has none: a depreciation entry has no other side to it.
     */
    public function requiresParty(): bool
    {
        return $this->requiredPartyRole() !== null;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
