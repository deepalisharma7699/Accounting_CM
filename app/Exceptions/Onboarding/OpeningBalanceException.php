<?php

namespace App\Exceptions\Onboarding;

use App\Exceptions\ApiException;

/**
 * A go-live declaration that is not one.
 *
 * Every constructor here refuses something the posting engine would otherwise
 * have accepted as arithmetically valid but which is not an opening balance —
 * stock declared as a rupee figure, a receivable owed by nobody, an entry
 * against the equity account that is supposed to be the other side of it.
 *
 * The messages are longer than most in this application, deliberately. An
 * opening balance is entered once, by somebody who has never used the product
 * before, out of whatever record they kept previously — and if they cannot work
 * out what is wrong they will enter something plausible instead, which is a
 * wrong number nobody will ever question again.
 */
class OpeningBalanceException extends ApiException
{
    /**
     * An opening balance with nothing in it.
     */
    public static function nothingToDeclare(): self
    {
        return new self(
            message: 'An opening balance has to declare something: stock on the shelf, money owed, or a balance '.
                'on one of the workshop\'s accounts.',
            status: 422,
            errorCode: 'OPENING_NOTHING_TO_DECLARE',
        );
    }

    /**
     * Opening stock only ever goes one way.
     *
     * A negative opening quantity would claim the workshop went live owing
     * somebody four bearings — and would post as an issue out of a position that
     * does not exist yet, valued at a fallback rate nobody supplied.
     */
    public static function nonPositiveStock(string $variantLabel): self
    {
        return new self(
            message: "Opening stock for {$variantLabel} must be a quantity greater than zero. If the shelf was ".
                'empty at go-live, leave the line out.',
            status: 422,
            errorCode: 'OPENING_STOCK_INVALID',
            details: ['field' => 'quantity', 'variant' => $variantLabel],
        );
    }

    public static function nonPositiveBalance(string $accountName): self
    {
        return new self(
            message: "The opening balance for \"{$accountName}\" must be greater than zero. To open it on the ".
                'other side — an overdrawn bank account, say — set the side rather than the sign.',
            status: 422,
            errorCode: 'OPENING_BALANCE_INVALID',
            details: ['field' => 'amount', 'account' => $accountName],
        );
    }

    public static function unknownSide(int $lineNumber, string $given): self
    {
        return new self(
            message: "Opening line {$lineNumber} opens on \"{$given}\", which is neither a debit nor a credit.",
            status: 422,
            errorCode: 'OPENING_SIDE_UNKNOWN',
            details: ['line' => $lineNumber, 'field' => 'side', 'side' => $given],
        );
    }

    /**
     * Opening Balance Equity is the other side of every opening line, so a line
     * that named it would post the account against itself: two entries that
     * cancel, declaring nothing.
     */
    public static function equityIsNotADeclaration(): self
    {
        return new self(
            message: 'Opening Balance Equity is what every opening line is posted against, so it cannot be '.
                'declared as one. Whatever is left in it once the assets and debts are entered is the '.
                'owner\'s stake at go-live — it is worked out, not typed.',
            status: 422,
            errorCode: 'OPENING_EQUITY_NOT_DECLARABLE',
            details: ['field' => 'account_id'],
        );
    }

    /**
     * Stock value typed straight into the Inventory account would put money in
     * the books with nothing on the shelf behind it — which is precisely the
     * disagreement M8's invariant exists to make impossible.
     */
    public static function inventoryNeedsQuantities(): self
    {
        return new self(
            message: 'Opening stock is declared by listing what is on the shelf, not by typing a figure into the '.
                'Inventory account — otherwise the books would carry a value no quantity backs.',
            status: 422,
            errorCode: 'OPENING_INVENTORY_NEEDS_QUANTITIES',
            details: ['field' => 'account_id'],
        );
    }

    /**
     * A control-account balance with nobody behind it.
     *
     * The total would be right and no statement could account for a rupee of it:
     * the workshop would know ₹40,000 was owed and have no way to find out by
     * whom, which is the same as not knowing.
     */
    public static function controlAccountNeedsParty(string $accountName): self
    {
        return new self(
            message: "An opening balance on \"{$accountName}\" has to say who it is with. Money owed by nobody in ".
                'particular cannot be chased, settled or reconciled.',
            status: 422,
            errorCode: 'OPENING_PARTY_REQUIRED',
            details: ['field' => 'party_id', 'account' => $accountName],
        );
    }

    /* ---------------------------------------------------------------------
     | The importer
     |-------------------------------------------------------------------- */

    /**
     * The same file, submitted twice.
     *
     * Caught by content rather than by name, because the second attempt is
     * almost always a browser refresh or a double click rather than a second
     * upload — and the per-row duplicate check below would let a file that had
     * been *edited* through while silently skipping the rows it shared.
     */
    public static function alreadyImported(string $when, int $rows): self
    {
        return new self(
            message: "This exact file was already imported on {$when} — {$rows} row(s) of it. Nothing has been ".
                'posted a second time. Edit the file if some of it still needs to go in.',
            status: 409,
            errorCode: 'OPENING_ALREADY_IMPORTED',
            details: ['imported_at' => $when, 'rows' => $rows],
        );
    }

    /**
     * A file with no rows the parser could make anything of.
     */
    public static function nothingToImport(): self
    {
        return new self(
            message: 'There is nothing to import: no row in this file names something to declare.',
            status: 422,
            errorCode: 'OPENING_NOTHING_TO_IMPORT',
        );
    }

    /**
     * Asked to commit a plan that still has rows nobody can act on.
     *
     * Refused whole rather than posted in part, for the reason the engine
     * refuses an unbalanced batch whole: a half-imported opening balance is
     * harder to recover from than none, because the only way to find out what
     * landed is to reconcile the whole thing by hand.
     */
    public static function planHasErrors(int $count): self
    {
        return new self(
            message: "{$count} row(s) cannot be imported as they stand. Fix them in the file and try again — ".
                'nothing has been posted, because a half-imported opening balance is harder to unpick than none.',
            status: 422,
            errorCode: 'OPENING_PLAN_HAS_ERRORS',
            details: ['errors' => $count],
        );
    }

    /**
     * The file names a column layout this parser does not recognise.
     */
    public static function unknownColumns(string $expected): self
    {
        return new self(
            message: "The first row of the file has to name its columns. Expected some of: {$expected}.",
            status: 422,
            errorCode: 'OPENING_COLUMNS_UNKNOWN',
            details: ['expected' => $expected],
        );
    }
}
