<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * An attempt to delete a party that transactions still point at.
 *
 * The ledger keeps a party's name because that name is what makes the entries
 * legible: "Cr Sundry Creditors 12,000" is a number, "Cr Sundry Creditors
 * 12,000 — Bharat Winding Works" is a record. Deleting the party would either
 * orphan those lines or, with a cascading foreign key, quietly take them with
 * it. Neither is acceptable in a book of account, so the party is archived
 * instead and their history stays readable.
 */
class PartyInUseException extends ApiException
{
    public static function hasTransactions(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '%s appears on %d transaction%s, so the record cannot be deleted — its ledger entries would '.
                'lose the name that explains them. Archive the party instead: they stop appearing when you '.
                'choose a party, and everything already posted stays intact.',
                $name,
                $count,
                $count === 1 ? '' : 's',
            ),
            status: 409,
            errorCode: 'PARTY_IN_USE',
            details: [
                'party_id' => $id,
                'transaction_count' => $count,
                // The alternative, named: an error that refuses without saying
                // what to do instead is a dead end.
                'archive_instead' => true,
            ],
        );
    }
}
