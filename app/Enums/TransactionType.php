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
 *   M22 staff_advance, payroll    templates I and J — what the staff are paid
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

    /**
     * Goods a customer brought back — M18, and the credit note it produces.
     *
     * A sale with every side inverted, on the returned quantities only:
     * `Cr Sundry Debtors / Dr Sales / Dr GST Output`, plus `Dr Inventory /
     * Cr COGS` for whatever came back on the shelf.
     *
     * ## Why this is not a reversal
     *
     * A reversal cancels a document whole, at its original values, and moves it
     * to `reversed`. That is right for a mis-posting and wrong for a return: a
     * customer bringing back one of four bearings has not cancelled the invoice,
     * and the other three are still theirs. The sale stays posted and stays
     * true; this records the part that came back, and it can happen again next
     * week.
     *
     * The stock is valued at the **original movement's** cost, not today's
     * average — see `ReturnService`. Re-valuing would leave the Inventory
     * account holding a difference against stock that is physically there.
     */
    case SalesReturn = 'sales_return';

    /**
     * Goods sent back to a supplier — M18, and the mirror of a sales return.
     *
     * `Dr Sundry Creditors / Cr Inventory / Cr GST Input`. The stock leaves at
     * what it arrived at, so the pair nets to nothing in the Inventory account
     * even though the weighted average has moved since.
     */
    case PurchaseReturn = 'purchase_return';

    /**
     * Money handed to an employee against a salary not yet earned — M22.
     *
     * `Dr Staff Advance / Cr Cash-Bank-UPI`. An **asset**, not an expense: the
     * workshop is owed the money back, and it comes back by being deducted from
     * the next payroll rather than by anybody paying it in. Booking it straight
     * to Salary Expense would report the cost twice — once when the advance went
     * out and again when the month was run.
     *
     * No party and no stock; the counterparty is an employee, who is a row in
     * `employees` rather than in `parties`. See `transactions.employee_id`.
     */
    case StaffAdvance = 'staff_advance';

    /**
     * A month's salaries, posted as one voucher — M22.
     *
     * ```
     * Dr Salary Expense     the month's gross, every employee together
     * Cr Staff Advance      whatever was recovered from earlier advances
     * Cr Cash / Bank / UPI  what actually went out
     * ```
     *
     * One transaction for the whole run rather than one per employee, because
     * that is what the event is: a workshop pays its staff on the 7th, and the
     * ledger should say so once. Who got what is `payroll_lines`, which is the
     * payslip — the ledger holds the money and the run holds the breakdown, and
     * neither is a second copy of the other.
     *
     * Settled in full at posting, exactly as an expense is. A workshop that pays
     * on the 7th dates the run the 7th; there is no salary-payable liability,
     * deliberately — see `docs/staff-module.md`.
     */
    case Payroll = 'payroll';

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
            self::SalesReturn => 'Sales Return',
            self::PurchaseReturn => 'Purchase Return',
            self::StaffAdvance => 'Staff Advance',
            self::Payroll => 'Payroll',
        };
    }

    /**
     * The document this type takes back, where it takes one back — M18.
     *
     * Null for everything that is not a return, which is what
     * {@see isReturn()} is built on.
     */
    public function returnsAgainst(): ?self
    {
        return match ($this) {
            self::SalesReturn => self::Sale,
            self::PurchaseReturn => self::Purchase,
            default => null,
        };
    }

    public function isReturn(): bool
    {
        return $this->returnsAgainst() !== null;
    }

    /**
     * The two documents the Purchase module writes: the bill and the debit note
     * that sends part of it back.
     *
     * Held here rather than as a list in the module, because the rules that read
     * it are server-side invariants — the negative-stock refusal on a reversal,
     * and the revision that reverses and re-posts as one act. Both mean "a
     * document that put stock on the shelf and can take it back off", and a
     * second copy of that list in a controller is how one of them gets a case the
     * other does not.
     */
    public function isPurchaseDocument(): bool
    {
        return $this === self::Purchase || $this === self::PurchaseReturn;
    }

    /**
     * The two documents the workshop issues *to a customer*: the tax invoice and
     * the credit note that takes part of it back.
     *
     * The mirror of {@see isPurchaseDocument()}, and held here for the same
     * reason — it is what "may be published as a link the customer can open"
     * means, and a second copy of the list in the sharing service is how one of
     * them ends up shareable and the other not.
     *
     * A purchase is excluded although it is equally a document with items on it:
     * the vendor wrote it, the vendor's own numbering is on it, and a workshop
     * publishing somebody else's invoice under its own letterhead is not a
     * feature. A debit note is the workshop's own and could defensibly be here
     * one day; it is left out until the Purchase module asks for it, because a
     * link nothing issues is a public route nobody tests.
     */
    public function isCustomerDocument(): bool
    {
        return $this === self::Sale || $this === self::SalesReturn;
    }

    /**
     * The documents that can be **corrected** rather than only reversed — the
     * bill a workshop typed wrong, on either side of the counter.
     *
     * A note is excluded from both, and not by oversight: a credit or debit note
     * is already the correction to something else, so correcting one is a
     * decision about which correction stands — which is a reversal and a fresh
     * note, deliberately taken.
     *
     * The two that are in here are not corrected on the same terms. A purchase
     * may have its cost changed, because a purchase *states* its cost; a sale
     * may not, because a sale's cost is the weighted average on the day it went
     * out, and reverse-and-repost would re-issue it at today's. See
     * {@see \App\Services\Accounting\PostingEngine::revise()}, which refuses
     * the second case rather than quietly restating it.
     */
    public function isCorrectableBill(): bool
    {
        return $this === self::Sale || $this === self::Purchase;
    }

    /**
     * The return type that takes this document back, where one exists.
     */
    public function returnType(): ?self
    {
        return match ($this) {
            self::Sale => self::SalesReturn,
            self::Purchase => self::PurchaseReturn,
            default => null,
        };
    }

    /**
     * The numbering series a posted transaction of this type takes its document
     * number from — M16.
     *
     * Every type has one. It is tempting to number only the documents that leave
     * the building, but a stock adjustment somebody has to explain at a stock
     * take is referred to by *something*, and "transaction 4,182" is a database
     * key rather than a reference. Separate series rather than one shared
     * counter, because GST requires the invoice series to be consecutive and it
     * cannot be if a receipt can take the next number.
     */
    public function documentSeries(): DocumentSeries
    {
        return match ($this) {
            self::Sale => DocumentSeries::Invoice,
            self::Purchase => DocumentSeries::Purchase,
            self::Receipt => DocumentSeries::Receipt,
            self::Payment => DocumentSeries::Payment,
            self::Expense => DocumentSeries::Expense,
            self::Journal => DocumentSeries::Journal,
            self::StockAdjustment => DocumentSeries::Adjustment,
            self::Opening => DocumentSeries::Opening,
            // Series of their own, not a continuation of the invoice run. GST
            // requires credit notes to be numbered separately from the invoices
            // they credit, and a customer holding CN/26-27/1001 against
            // INV/26-27/1001 can at least see which is which.
            self::SalesReturn => DocumentSeries::CreditNote,
            self::PurchaseReturn => DocumentSeries::DebitNote,
            // Series of their own for the same reason every other pair has one:
            // "which advance was that" is asked about a slip somebody signed, and
            // ADV/26-27/12 is an answer where "transaction 4,182" is not.
            self::StaffAdvance => DocumentSeries::StaffAdvance,
            self::Payroll => DocumentSeries::Payroll,
        };
    }

    /**
     * The kind of bill this type settles, where it settles one — M16.
     *
     * A receipt is money from a customer, so it can only be pointed at sales;
     * a payment is money to a supplier, so only at purchases. Null for an
     * expense, which is settled at the moment it is recorded and has no earlier
     * document to be allocated against.
     */
    public function settlesBillType(): ?self
    {
        return match ($this) {
            self::Receipt => self::Sale,
            self::Payment => self::Purchase,
            default => null,
        };
    }

    /**
     * True when the type is a document money can be settled *against* — an
     * invoice or a purchase bill.
     *
     * The other side of {@see isSettlement()}: a receipt allocates to these and
     * to nothing else. A journal is excluded although it can move Receivables,
     * because a hand-written voucher has no total that "paid in full" would
     * mean anything against.
     */
    public function isBillable(): bool
    {
        return match ($this) {
            self::Sale, self::Purchase => true,
            default => false,
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
            /*
            | M22. An advance is money moving and nothing else — take the split
            | away and there is no advance left.
            |
            | A **payroll run is not**, and the distinction is the one this
            | method is for. What a run *is* is the month's wage bill; the split
            | is how the remainder was handed over after advances were recovered
            | against it. A month where every rupee earned had already been
            | advanced is a complete and meaningful payroll that moves no cash at
            | all — `Dr Salary Expense / Cr Staff Advance` and nothing else — and
            | calling it a settlement would refuse to post it. It merely
            | {@see acceptsPaymentSplit()}, on a bill's terms.
            */
            self::Payment, self::Receipt, self::Expense, self::StaffAdvance => true,
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
            // A return moves stock the other way: back onto the shelf from a
            // customer, off it to a supplier.
            self::SalesReturn, self::PurchaseReturn => true,
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
            self::SalesReturn, self::PurchaseReturn => true,
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
            // A credit note is a document with items on it, exactly as the
            // invoice it credits is — and it has to be, because the customer
            // gets a copy.
            self::SalesReturn, self::PurchaseReturn => true,
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
            // Mirrored from the document each takes back, and for the same
            // reason: crediting Sundry Debtors *is* the claim that this party
            // was a customer of ours, whichever way the goods are travelling.
            self::SalesReturn => PartyRole::Customer,
            self::PurchaseReturn => PartyRole::Vendor,
            // Null for an opening balance, and it is the only interesting one of
            // the three. An opening balance *does* imply a role — a debit to
            // Sundry Debtors says this party was a customer — but which one
            // depends on the row rather than on the type, so the claim is
            // checked where the side is known, in OpeningBalanceTemplate. A
            // fixed answer here would have to be wrong for half of them.
            // M22. An advance and a payroll run have a counterparty — an
            // employee — but not a *party*: staff are not customers or suppliers,
            // they sit in their own table, and neither control account is
            // involved. `transactions.employee_id` carries that side.
            self::Journal, self::StockAdjustment, self::Expense, self::Opening,
            self::StaffAdvance, self::Payroll => null,
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
