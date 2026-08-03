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
}
