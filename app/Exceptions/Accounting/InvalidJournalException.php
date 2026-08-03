<?php

namespace App\Exceptions\Accounting;

use App\Enums\PaymentMode;
use App\Exceptions\ApiException;

/**
 * A set of lines that is not a journal entry at all — as distinct from one
 * that is a journal entry but does not balance
 * ({@see UnbalancedJournalException}).
 *
 * Each named constructor carries its own error code, so a client can explain
 * the specific problem rather than repeating a generic "invalid entry".
 */
class InvalidJournalException extends ApiException
{
    /**
     * Double entry means at least two accounts. One line can never balance,
     * and a single line that somehow did would be money moving nowhere.
     */
    public static function tooFewLines(int $given): self
    {
        return new self(
            message: 'A journal entry needs at least two lines — one account to debit and one to credit.',
            status: 422,
            errorCode: 'JOURNAL_TOO_FEW_LINES',
            details: ['lines' => $given],
        );
    }

    /**
     * Debit and credit are the two columns of a line; a line belongs in
     * exactly one of them. Covers a line with an amount in both columns and a
     * line with an amount in neither.
     */
    public static function ambiguousSide(int $lineNumber): self
    {
        return new self(
            message: "Line {$lineNumber} must carry an amount in exactly one of the debit and credit columns.",
            status: 422,
            errorCode: 'JOURNAL_LINE_INVALID',
            details: ['line' => $lineNumber, 'field' => 'amount'],
        );
    }

    /**
     * A negative debit is a credit written confusingly, and a zero line moves
     * nothing. Both are refused so that every stored amount is positive and
     * the side alone carries the direction.
     */
    public static function nonPositiveAmount(int $lineNumber): self
    {
        return new self(
            message: "Line {$lineNumber} must carry an amount greater than zero.",
            status: 422,
            errorCode: 'JOURNAL_LINE_INVALID',
            details: ['line' => $lineNumber, 'field' => 'amount'],
        );
    }

    public static function unknownAccount(int $lineNumber, int $accountId): self
    {
        return new self(
            message: "Line {$lineNumber} refers to an account that does not exist in this workshop's chart.",
            status: 422,
            errorCode: 'JOURNAL_ACCOUNT_UNKNOWN',
            details: ['line' => $lineNumber, 'account_id' => $accountId],
        );
    }

    /**
     * An archived account is one the workshop has decided it no longer uses.
     * Its history stays readable; new entries do not go there.
     */
    public static function archivedAccount(int $lineNumber, string $accountName): self
    {
        return new self(
            message: "Line {$lineNumber} posts to \"{$accountName}\", which is archived. Restore the account or choose another.",
            status: 422,
            errorCode: 'JOURNAL_ACCOUNT_ARCHIVED',
            details: ['line' => $lineNumber, 'account' => $accountName],
        );
    }

    /**
     * The counterparty does not exist in this workshop.
     *
     * The tenant scope guarantees the second half: another workshop's party id
     * simply does not resolve, so a transaction can never be attributed across
     * a tenant boundary.
     */
    public static function unknownParty(int $partyId): self
    {
        return new self(
            message: 'This transaction names a party that does not exist in this workshop.',
            status: 422,
            errorCode: 'PARTY_UNKNOWN',
            details: ['field' => 'party_id', 'party_id' => $partyId],
        );
    }

    /**
     * An archived party is one the workshop no longer trades with. Their
     * ledger stays readable — archiving is not deletion — but nothing new is
     * attributed to them.
     */
    public static function archivedParty(int $partyId, string $name): self
    {
        return new self(
            message: "\"{$name}\" is archived, so no new transaction can be recorded against them. ".
                'Restore the party or choose another.',
            status: 422,
            errorCode: 'PARTY_ARCHIVED',
            details: ['field' => 'party_id', 'party_id' => $partyId],
        );
    }

    /**
     * A settlement with no counterparty.
     *
     * A payment attributed to nobody sits in a control account that no statement
     * can ever account for: the money is gone from the bank and nothing says
     * whose debt it settled.
     */
    public static function partyRequired(string $typeLabel): self
    {
        return new self(
            message: "A {$typeLabel} must name the party the money moved to or from.",
            status: 422,
            errorCode: 'PARTY_REQUIRED',
            details: ['field' => 'party_id'],
        );
    }

    /**
     * The counterparty does not hold the role the transaction's control account
     * asserts they do.
     *
     * Debiting Sundry Creditors *is* the claim "we owed this business money", so
     * a payment to a party who is not a vendor would invent a supplier
     * relationship — and leave a position on a record nobody would look at. The
     * message names the fix rather than only the refusal.
     */
    public static function partyRoleMismatch(int $partyId, string $name, string $roleLabel, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                '%s is not marked as a %s, so a %s cannot be recorded against them. Add the %s role to the '.
                'party if that is what they are.',
                $name,
                strtolower($roleLabel),
                strtolower($typeLabel),
                strtolower($roleLabel),
            ),
            status: 422,
            errorCode: 'PARTY_ROLE_MISMATCH',
            details: ['field' => 'party_id', 'party_id' => $partyId, 'required_role' => strtolower($roleLabel)],
        );
    }

    /**
     * A settlement type reached the engine with nothing settling it.
     */
    public static function paymentSplitRequired(string $typeLabel): self
    {
        return new self(
            message: "A {$typeLabel} needs at least one line saying how the money moved — cash, bank, UPI or cheque.",
            status: 422,
            errorCode: 'PAYMENT_SPLIT_REQUIRED',
            details: ['field' => 'payments'],
        );
    }

    public static function unknownPaymentMode(int $lineNumber, string $given): self
    {
        return new self(
            message: "Payment line {$lineNumber} names a way of paying this product does not know: cash, bank, UPI or cheque.",
            status: 422,
            errorCode: 'PAYMENT_MODE_UNKNOWN',
            details: ['line' => $lineNumber, 'field' => 'mode', 'mode' => $given],
        );
    }

    public static function nonPositivePayment(int $lineNumber): self
    {
        return new self(
            message: "Payment line {$lineNumber} must carry an amount greater than zero.",
            status: 422,
            errorCode: 'PAYMENT_LINE_INVALID',
            details: ['line' => $lineNumber, 'field' => 'amount'],
        );
    }

    /**
     * A cheque with no number cannot be matched against a bank statement,
     * chased when it bounces, or stopped.
     */
    public static function missingPaymentReference(int $lineNumber, PaymentMode $mode): self
    {
        return new self(
            message: sprintf(
                'Payment line %d is a %s, so it needs its %s.',
                $lineNumber,
                strtolower($mode->label()),
                strtolower($mode->referenceLabel()),
            ),
            status: 422,
            errorCode: 'PAYMENT_REFERENCE_REQUIRED',
            details: ['line' => $lineNumber, 'field' => 'reference', 'mode' => $mode->value],
        );
    }

    /**
     * The settlement rows and the ledger lines would disagree about the amount.
     *
     * Cannot happen through a posting template, which derives one from the
     * other — this exists so that a future caller composing a batch by hand
     * cannot record a ₹5,000 receipt against ₹4,000 of entries and leave the two
     * halves of the same document contradicting each other.
     */
    public static function paymentSplitMismatch(string $splitTotal, string $entryTotal): self
    {
        return new self(
            message: "The payment split totals {$splitTotal} but the entries total {$entryTotal}.",
            status: 422,
            errorCode: 'PAYMENT_SPLIT_MISMATCH',
            details: ['payments_total' => $splitTotal, 'entries_total' => $entryTotal],
        );
    }

    /**
     * A payment split on a type that has no way to settle.
     *
     * A journal's accounts are chosen outright and a stock adjustment moves no
     * money at all, so there is nothing for a split to describe. Refused rather
     * than ignored, for the reason M6 refused `lines` on a settlement draft: a
     * caller told their edit saved while nothing changed is worse off than one
     * told it was rejected.
     */
    public static function paymentSplitNotAccepted(string $typeLabel): self
    {
        return new self(
            message: "A {$typeLabel} is not settled through a payment mode, so it cannot carry a payment split.",
            status: 422,
            errorCode: 'PAYMENT_SPLIT_NOT_ACCEPTED',
            details: ['field' => 'payments'],
        );
    }

    /**
     * More collected against a bill than the bill is worth.
     *
     * Not a credit balance waiting to be used — that is what a *receipt* with no
     * invoice behind it produces, and M5 decided deliberately to allow it. This
     * is a typo on a document whose own total contradicts it, and posting it
     * would leave the customer's receivable negative by an amount nobody meant.
     */
    public static function paymentSplitExceedsDocument(string $splitTotal, string $documentTotal): self
    {
        return new self(
            message: "The payment split totals {$splitTotal}, which is more than the document's {$documentTotal}. ".
                'Record what was actually collected; the rest is settled later with a receipt.',
            status: 422,
            errorCode: 'PAYMENT_SPLIT_EXCEEDS_DOCUMENT',
            details: ['field' => 'payments', 'payments_total' => $splitTotal, 'document_total' => $documentTotal],
        );
    }

    /**
     * Stock movements on a type that does not move stock.
     *
     * A payment settles a debt that a bill already recorded; attaching quantities
     * to it would take the same stock off the shelf twice.
     */
    public static function stockNotAccepted(string $typeLabel): self
    {
        return new self(
            message: "A {$typeLabel} does not move stock, so it cannot carry stock movements.",
            status: 422,
            errorCode: 'STOCK_NOT_ACCEPTED',
            details: ['field' => 'movements'],
        );
    }

    /**
     * The stock movements and the Inventory line would disagree about value.
     *
     * The invariant M8 exists to hold: what the shelf says it is worth and what
     * the Inventory account says it is worth are the same number, because they
     * are written in the same database transaction from the same figure. Cannot
     * happen through a posting template, which derives one from the other — this
     * exists so a caller composing a batch by hand cannot put ₹15,000 of stock
     * on the shelf against ₹14,000 in the books and leave the two halves of the
     * same event contradicting each other for ever.
     */
    public static function stockValueMismatch(string $stockValue, string $inventoryLine): self
    {
        return new self(
            message: "The stock movements are worth {$stockValue} but the Inventory account moves by {$inventoryLine}.",
            status: 422,
            errorCode: 'STOCK_VALUE_MISMATCH',
            details: ['stock_value' => $stockValue, 'inventory_line' => $inventoryLine],
        );
    }

    /**
     * A bill with nothing on it.
     *
     * An invoice for no items is not a document anybody can act on — and if the
     * intention was to record money moving with no goods behind it, that is a
     * receipt or a payment, which M6 already handles.
     */
    public static function billHasNoLines(string $typeLabel): self
    {
        return new self(
            message: "A {$typeLabel} needs at least one line saying what was supplied.",
            status: 422,
            errorCode: 'BILL_HAS_NO_LINES',
            details: ['field' => 'items'],
        );
    }

    public static function billLineUnknownItem(int $lineNumber, int $itemId): self
    {
        return new self(
            message: "Line {$lineNumber} names an item that does not exist in this workshop's catalogue.",
            status: 422,
            errorCode: 'BILL_LINE_ITEM_UNKNOWN',
            details: ['line' => $lineNumber, 'field' => 'item_id', 'item_id' => $itemId],
        );
    }

    /**
     * A line naming one item and a variant belonging to another.
     *
     * The pairing has to mean something: without this the bill would charge for
     * one thing and take a different thing off the shelf, and both halves would
     * look right on their own.
     */
    public static function billLineVariantMismatch(int $lineNumber): self
    {
        return new self(
            message: "Line {$lineNumber} names a variant that belongs to a different item.",
            status: 422,
            errorCode: 'BILL_LINE_VARIANT_MISMATCH',
            details: ['line' => $lineNumber, 'field' => 'variant_id'],
        );
    }

    public static function billLineQuantity(int $lineNumber): self
    {
        return new self(
            message: "Line {$lineNumber} must supply a quantity greater than zero.",
            status: 422,
            errorCode: 'BILL_LINE_QUANTITY_INVALID',
            details: ['line' => $lineNumber, 'field' => 'quantity'],
        );
    }

    /**
     * A stocked item billed without saying which variant.
     *
     * Stock is counted per variant, so a bill that took "a motor" off the shelf
     * would leave the position of every rating unknowable — and the one thing the
     * stock ledger must always be able to answer is how many of *this* there are.
     */
    public static function billLineNeedsVariant(int $lineNumber, string $itemName): self
    {
        return new self(
            message: "Line {$lineNumber} bills {$itemName}, which is held in stock — say which variant, ".
                'because stock is counted per variant and not per family.',
            status: 422,
            errorCode: 'BILL_LINE_VARIANT_REQUIRED',
            details: ['line' => $lineNumber, 'field' => 'variant_id'],
        );
    }

    /**
     * An expense with no amount. Nothing was spent, so nothing happened.
     */
    public static function expenseAmountRequired(): self
    {
        return new self(
            message: 'An expense needs an amount greater than zero.',
            status: 422,
            errorCode: 'EXPENSE_AMOUNT_REQUIRED',
            details: ['field' => 'amount'],
        );
    }

    public static function expenseTaxInvalid(): self
    {
        return new self(
            message: 'Claimable GST on an expense cannot be negative. Leave it out where there is none.',
            status: 422,
            errorCode: 'EXPENSE_TAX_INVALID',
            details: ['field' => 'gst_amount'],
        );
    }

    /**
     * An expense booked somewhere that is not an expense.
     *
     * Refused rather than allowed, because it is not the kind of mistake that
     * surfaces: rent posted to Sundry Debtors reads as a customer owing money,
     * and nobody finds out until somebody tries to collect it.
     */
    public static function expenseAccountWrongType(string $name, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                '"%s" is %s account, not an expense account. Choose an expense account — or add one to the '.
                'chart if this is a cost the workshop has not recorded before.',
                $name,
                in_array(strtolower($typeLabel[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an '.strtolower($typeLabel) : 'a '.strtolower($typeLabel),
            ),
            status: 422,
            errorCode: 'EXPENSE_ACCOUNT_WRONG_TYPE',
            details: ['field' => 'account_id', 'account' => $name, 'type' => $typeLabel],
        );
    }

    /**
     * The type has no posting template, so there is no definition of which
     * accounts it moves. A configuration fault rather than a user error, but
     * it must not post something arbitrary.
     */
    public static function noTemplate(string $type): self
    {
        return new self(
            message: "No posting template is registered for a [{$type}] transaction, so it cannot be posted.",
            status: 422,
            errorCode: 'POSTING_TEMPLATE_MISSING',
            details: ['type' => $type],
        );
    }
}
