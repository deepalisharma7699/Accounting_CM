<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * A return that does not correspond to anything the workshop supplied — M18.
 *
 * Every case here is refused rather than clamped, and the reason is the same one
 * that governs allocations: silently crediting three bearings when four were
 * asked for leaves the ledger correct and the customer's copy of the credit note
 * a lie about what they handed over. The operator is told at the counter, where
 * the goods are still on the desk and the mistake is cheap.
 */
class InvalidReturnException extends ApiException
{
    /**
     * The document being returned against is not one that supplied anything.
     */
    public static function notReturnable(int $transactionId, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                'Only a sale or a purchase can be returned against, and #%d is a %s.',
                $transactionId,
                strtolower($typeLabel),
            ),
            status: 422,
            errorCode: 'RETURN_TARGET_INVALID',
            details: ['transaction_id' => $transactionId, 'type' => $typeLabel],
        );
    }

    /**
     * The bill is a draft, or has been reversed.
     *
     * A draft supplied nothing — nothing has moved — and a reversed bill has
     * already been cancelled whole, so there is nothing left on it to take back.
     * Returning against either would put real stock and real money against a
     * document that is not asking for any.
     */
    public static function notInTheBooks(int $transactionId, string $statusLabel): self
    {
        return new self(
            message: sprintf(
                'Transaction #%d is %s, so nothing can be returned against it.',
                $transactionId,
                strtolower($statusLabel),
            ),
            status: 422,
            errorCode: 'RETURN_NOT_POSTED',
            details: ['transaction_id' => $transactionId, 'status' => $statusLabel],
        );
    }

    /**
     * A line number that is not on the bill.
     */
    public static function unknownLine(string $docNo, int $lineNo): self
    {
        return new self(
            message: sprintf('%s has no line %d.', $docNo, $lineNo),
            status: 422,
            errorCode: 'RETURN_LINE_UNKNOWN',
            details: ['bill' => $docNo, 'line' => $lineNo],
        );
    }

    /**
     * More has been returned than was billed — counting what came back earlier.
     *
     * The brief's scenario 6, and the one refusal that has to be stated in units
     * rather than money: somebody standing at a counter with bearings in their
     * hand needs to know how many of them the system will take.
     */
    public static function exceedsRemaining(
        string $docNo,
        int $lineNo,
        string $description,
        string $wanted,
        string $remaining,
        string $unit,
    ): self {
        return new self(
            message: sprintf(
                'Only %s %s of %s can still be returned on line %d of %s, and %s was entered.',
                $remaining,
                $unit,
                $description,
                $lineNo,
                $docNo,
                $wanted,
            ),
            status: 422,
            errorCode: 'RETURN_EXCEEDS_REMAINING',
            details: [
                'bill' => $docNo,
                'line' => $lineNo,
                'requested' => $wanted,
                'remaining' => $remaining,
                'unit' => $unit,
            ],
        );
    }

    /**
     * A return that takes nothing back.
     *
     * Refused rather than posted as an empty document, because a credit note for
     * nothing is a number issued out of the series for no reason — and the
     * series is the one thing in this module that has to be explicable line by
     * line.
     */
    public static function nothingToReturn(string $docNo): self
    {
        return new self(
            message: sprintf('Nothing was entered to return against %s.', $docNo),
            status: 422,
            errorCode: 'RETURN_EMPTY',
            details: ['bill' => $docNo],
        );
    }
}
