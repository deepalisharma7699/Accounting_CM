<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * A receipt pointed at a bill it cannot legitimately settle — M16.
 *
 * Every case here is refused rather than clamped. Silently allocating ₹5,000 of
 * a ₹6,000 receipt and dropping the rest would leave the customer's ledger
 * correct and the invoice's history a lie about what they handed over; and
 * quietly over-allocating would make a bill look more than paid, which the Paid
 * and Due columns would then have to render as a negative. Both are the kind of
 * wrongness that is invisible for months, so the operator is told at the counter
 * instead.
 */
class InvalidAllocationException extends ApiException
{
    /**
     * More has been pointed at a bill than is left owing on it — brief §27,
     * "payment greater than invoice amount".
     */
    public static function exceedsBillDue(string $docNo, string $amount, string $due): self
    {
        return new self(
            message: sprintf(
                'Only %s is still owing on %s, and %s was allocated to it. Reduce the amount, or put the '.
                'remainder against another bill.',
                $due,
                $docNo,
                $amount,
            ),
            status: 422,
            errorCode: 'ALLOCATION_EXCEEDS_BILL',
            details: ['bill' => $docNo, 'amount' => $amount, 'due' => $due],
        );
    }

    /**
     * The splits add up to more than the receipt itself.
     */
    public static function exceedsSettlement(string $allocated, string $available): self
    {
        return new self(
            message: sprintf(
                'This payment is %s but %s has been allocated across the bills. The two have to agree.',
                $available,
                $allocated,
            ),
            status: 422,
            errorCode: 'ALLOCATION_EXCEEDS_SETTLEMENT',
            details: ['allocated' => $allocated, 'available' => $available],
        );
    }

    /**
     * A settlement pointed at something that is not a bill — a journal, another
     * receipt, an opening balance.
     *
     * Refused because "how much is left on it" has no answer for those: an
     * opening balance is a declaration rather than a demand, and a journal has no
     * total that being paid in full would mean anything against.
     */
    public static function notABill(int $transactionId, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                'A payment can only be allocated to an invoice or a purchase bill, and #%d is a %s.',
                $transactionId,
                strtolower($typeLabel),
            ),
            status: 422,
            errorCode: 'ALLOCATION_TARGET_INVALID',
            details: ['transaction_id' => $transactionId, 'type' => $typeLabel],
        );
    }

    /**
     * The transaction doing the settling is not a settlement.
     */
    public static function notASettlement(int $transactionId, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                'Only a payment or a receipt can settle a bill, and #%d is a %s.',
                $transactionId,
                strtolower($typeLabel),
            ),
            status: 422,
            errorCode: 'ALLOCATION_SOURCE_INVALID',
            details: ['transaction_id' => $transactionId, 'type' => $typeLabel],
        );
    }

    /**
     * A receipt from one customer pointed at another customer's invoice.
     *
     * Refused for the reason the posting engine refuses a role mismatch: it would
     * leave one party's statement showing a payment they never made, and the
     * other's showing a debt they have already settled.
     */
    public static function partyMismatch(string $docNo, string $settlementParty, string $billParty): self
    {
        return new self(
            message: sprintf(
                '%s is %s\'s bill, and this payment is from %s. A payment can only settle bills belonging to '.
                'the same party.',
                $docNo,
                $billParty,
                $settlementParty,
            ),
            status: 422,
            errorCode: 'ALLOCATION_PARTY_MISMATCH',
            details: ['bill' => $docNo, 'settlement_party' => $settlementParty, 'bill_party' => $billParty],
        );
    }

    /**
     * The bill or the settlement has not reached the ledger, or has left it.
     *
     * A draft has settled nothing — nothing has moved — and a reversed document
     * has been cancelled, so allocating to either would attach a real position to
     * a record that carries none.
     */
    public static function notInTheBooks(int $transactionId, string $statusLabel): self
    {
        return new self(
            message: sprintf(
                'Transaction #%d is %s, so nothing can be settled against it.',
                $transactionId,
                strtolower($statusLabel),
            ),
            status: 422,
            errorCode: 'ALLOCATION_NOT_POSTED',
            details: ['transaction_id' => $transactionId, 'status' => $statusLabel],
        );
    }
}
