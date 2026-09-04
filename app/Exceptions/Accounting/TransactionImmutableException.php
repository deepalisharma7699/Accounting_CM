<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * An attempt to change or delete a transaction that is already in the books.
 *
 * Editing a posted transaction would silently rewrite every report that has
 * already been run from it, and deleting one would remove the evidence that it
 * ever happened. Accounting corrects by addition: a reversing entry cancels the
 * original and both remain visible.
 *
 * Raised by the model itself, not only by the service, so no future code path
 * can route around it.
 */
class TransactionImmutableException extends ApiException
{
    /**
     * @param  array<int, string>  $fields
     */
    public static function posted(?int $id, array $fields = []): self
    {
        return new self(
            message: 'A posted transaction cannot be edited. Post a reversing entry to correct it, '.
                'which keeps both the mistake and the correction on the record.',
            status: 403,
            errorCode: 'TRANSACTION_IMMUTABLE',
            details: array_filter([
                'transaction_id' => $id,
                'fields' => $fields === [] ? null : array_values(array_diff($fields, ['updated_at'])),
            ]),
        );
    }

    public static function reversed(?int $id): self
    {
        return new self(
            message: 'This transaction has already been reversed, so there is nothing left to change.',
            status: 409,
            errorCode: 'TRANSACTION_ALREADY_REVERSED',
            details: array_filter(['transaction_id' => $id]),
        );
    }

    public static function deleting(?int $id): self
    {
        return new self(
            message: 'A transaction that has reached the ledger cannot be deleted. Reverse it instead — '.
                'the ledger is a record of what happened, including what was got wrong.',
            status: 403,
            errorCode: 'TRANSACTION_IMMUTABLE',
            details: array_filter(['transaction_id' => $id]),
        );
    }

    /**
     * Posting something that is not a draft: it is either already in the books
     * or has been reversed.
     */
    public static function notADraft(?int $id): self
    {
        return new self(
            message: 'Only a draft can be posted. This transaction is already in the books.',
            status: 409,
            errorCode: 'TRANSACTION_NOT_A_DRAFT',
            details: array_filter(['transaction_id' => $id]),
        );
    }

    /**
     * Reversing a draft. There is nothing in the ledger to cancel — a draft is
     * simply discarded.
     */
    public static function draftNotReversible(?int $id): self
    {
        return new self(
            message: 'A draft has not reached the ledger, so there is nothing to reverse. Discard it instead.',
            status: 409,
            errorCode: 'TRANSACTION_NOT_POSTED',
            details: array_filter(['transaction_id' => $id]),
        );
    }

    /**
     * Correcting a bill that has been paid against.
     *
     * A revision cancels the bill and posts its replacement, and a payment
     * allocated to the cancelled one would be left pointing at a document that
     * no longer owes anything — money attached to nothing, which is the state a
     * settlement's allocation exists to make impossible. Undoing that is a
     * decision about money and has to be made on purpose, so it is asked for
     * rather than done quietly as a side effect of fixing a quantity.
     */
    public static function settled(?int $id, string $paid): self
    {
        return new self(
            message: sprintf(
                '₹%s has already been paid against this bill, so it cannot be corrected in place — the '.
                'payment would be left against a cancelled document. Reverse or unallocate the payment '.
                'first, or raise a debit note for the difference.',
                $paid,
            ),
            status: 409,
            errorCode: 'TRANSACTION_SETTLED',
            details: array_filter(['transaction_id' => $id, 'paid' => $paid]),
        );
    }

    /**
     * Correcting a bill that part of has already gone back.
     *
     * The same orphan {@see settled()} describes, arrived at through goods rather
     * than money: a debit note carries `against_transaction_id` pointing at this
     * bill, and reversing the bill would leave a posted credit note against a
     * cancelled document. The bill has already been corrected once, and which
     * correction stands is a decision somebody has to make rather than a side
     * effect of fixing a quantity.
     */
    public static function returnedAgainst(?int $id, string $credited): self
    {
        return new self(
            message: sprintf(
                '₹%s of this bill has already been sent back on a debit note, so it cannot be corrected in '.
                'place — the debit note would be left against a cancelled document. Reverse the debit note '.
                'first, or raise another one for the difference.',
                $credited,
            ),
            status: 409,
            errorCode: 'TRANSACTION_RETURNED_AGAINST',
            details: array_filter(['transaction_id' => $id, 'credited' => $credited]),
        );
    }
}
