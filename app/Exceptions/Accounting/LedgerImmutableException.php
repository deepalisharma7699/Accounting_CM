<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * An attempt to update or delete a journal entry or a stock movement.
 *
 * There is no legitimate reason for either, ever. An entry is one half of a
 * balanced pair, so changing one line in isolation unbalances the books, and
 * deleting one destroys the audit trail the ledger exists to be. A stock
 * movement is the same statement about quantities: the position is the sum of
 * the movements, so editing one silently rewrites every stock report ever run
 * and leaves the Inventory account describing stock that was never there.
 *
 * A 500, not a 4xx: nothing a client can send should reach this code, so if it
 * is raised the fault is in the application, and it should look like one.
 */
class LedgerImmutableException extends ApiException
{
    public static function updating(?int $id): self
    {
        return new self(
            message: "Journal entry [{$id}] cannot be modified. Ledger entries are written once; ".
                'corrections are posted as reversing entries.',
            status: 500,
            errorCode: 'LEDGER_IMMUTABLE',
        );
    }

    public static function deleting(?int $id): self
    {
        return new self(
            message: "Journal entry [{$id}] cannot be deleted. The ledger is append-only.",
            status: 500,
            errorCode: 'LEDGER_IMMUTABLE',
        );
    }

    public static function updatingStock(?int $id): self
    {
        return new self(
            message: "Stock movement [{$id}] cannot be modified. Quantity on hand and average cost are ".
                'sums over the movements, so a correction is another movement — never an edit to this one.',
            status: 500,
            errorCode: 'STOCK_LEDGER_IMMUTABLE',
        );
    }

    public static function deletingStock(?int $id): self
    {
        return new self(
            message: "Stock movement [{$id}] cannot be deleted. The stock ledger is append-only.",
            status: 500,
            errorCode: 'STOCK_LEDGER_IMMUTABLE',
        );
    }
}
