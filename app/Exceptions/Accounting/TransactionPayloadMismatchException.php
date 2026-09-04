<?php

namespace App\Exceptions\Accounting;

use App\Enums\TransactionType;
use App\Exceptions\ApiException;

/**
 * An edit written in the wrong vocabulary for the draft it is editing.
 *
 * Each kind of document is described in its own terms — a journal by its lines,
 * a settlement by the ways the money moved, a bill by its items, a stock
 * adjustment by what the count found — and each posting template reads only the
 * one it understands. Sending another would therefore be *ignored* rather than
 * applied, and the caller would be told the draft was updated while nothing
 * about it was. Silently discarding an edit to a financial document is worse
 * than refusing it, so this refuses it and says which field the type actually
 * wants.
 */
class TransactionPayloadMismatchException extends ApiException
{
    /**
     * The vocabulary each type is written in, for the "send this instead"
     * half of every message below. Naming it once means a new type cannot be
     * added with a message that quietly recommends the wrong field.
     */
    private static function vocabularyOf(TransactionType $type): string
    {
        return match (true) {
            $type->acceptsRawLines() => 'lines',
            $type->hasDocumentLines() => 'items',
            $type === TransactionType::StockAdjustment => 'adjustments',
            default => 'payments',
        };
    }

    public static function itemsNotAccepted(TransactionType $type): self
    {
        return new self(
            message: sprintf(
                'A %s does not have item lines. Send `%s` instead.',
                strtolower($type->label()),
                self::vocabularyOf($type),
            ),
            status: 422,
            errorCode: 'TRANSACTION_ITEMS_NOT_ACCEPTED',
            details: ['field' => 'items', 'type' => $type->value, 'expected' => self::vocabularyOf($type)],
        );
    }

    public static function adjustmentsNotAccepted(TransactionType $type): self
    {
        return new self(
            message: sprintf(
                'A %s does not correct stock against a count. Send `%s` instead.',
                strtolower($type->label()),
                self::vocabularyOf($type),
            ),
            status: 422,
            errorCode: 'TRANSACTION_ADJUSTMENTS_NOT_ACCEPTED',
            details: ['field' => 'adjustments', 'type' => $type->value, 'expected' => self::vocabularyOf($type)],
        );
    }

    public static function linesNotAccepted(TransactionType $type): self
    {
        return new self(
            message: sprintf(
                'A %s does not take journal lines — its accounts are decided by its posting template. '.
                'Send `%s` instead.',
                strtolower($type->label()),
                self::vocabularyOf($type),
            ),
            status: 422,
            errorCode: 'TRANSACTION_LINES_NOT_ACCEPTED',
            details: ['field' => 'lines', 'type' => $type->value, 'expected' => self::vocabularyOf($type)],
        );
    }

    public static function paymentsNotAccepted(TransactionType $type): self
    {
        return new self(
            message: sprintf(
                'A %s is not settled through payment modes. Send `%s` instead.',
                strtolower($type->label()),
                self::vocabularyOf($type),
            ),
            status: 422,
            errorCode: 'TRANSACTION_PAYMENTS_NOT_ACCEPTED',
            details: ['field' => 'payments', 'type' => $type->value, 'expected' => self::vocabularyOf($type)],
        );
    }

    /**
     * Correcting a document that is not a purchase bill.
     *
     * Reverse-and-repost is a repair whose correctness depends on the type: a
     * purchase arrives at its own stated cost, so cancelling one and posting the
     * corrected one puts the Inventory account back exactly where it was. A sale
     * does not — its replacement would issue at today's weighted average rather
     * than the one it originally issued at, leaving a residue in COGS. See
     * {@see \App\Services\Accounting\PostingEngine::revise()}.
     */
    public static function notRevisable(TransactionType $type): self
    {
        return new self(
            message: sprintf(
                'A %s cannot be corrected in place. Reverse it and enter the right document, or — where '.
                'part of it is going back — raise a return against it.',
                strtolower($type->label()),
            ),
            status: 422,
            errorCode: 'TRANSACTION_NOT_REVISABLE',
            details: ['type' => $type->value],
        );
    }
}
