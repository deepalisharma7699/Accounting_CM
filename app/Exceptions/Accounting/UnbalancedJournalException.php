<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;
use App\Support\Money;

/**
 * Total debits did not equal total credits, so nothing was written.
 *
 * The single most important refusal in the product. Every other number the
 * system reports is a sum over the ledger, so one unbalanced set of entries
 * makes the trial balance wrong for the rest of the workshop's life — and
 * invisibly, because no screen shows the discrepancy until somebody runs a
 * report months later.
 */
class UnbalancedJournalException extends ApiException
{
    public static function between(Money $debits, Money $credits): self
    {
        $difference = $debits->minus($credits);

        return new self(
            message: sprintf(
                'Debits and credits must be equal. Debits total %s, credits total %s — a difference of %s.',
                $debits->amount(),
                $credits->amount(),
                $difference->absolute()->amount(),
            ),
            status: 422,
            errorCode: 'JOURNAL_UNBALANCED',
            details: [
                'debits' => $debits->amount(),
                'credits' => $credits->amount(),
                'difference' => $difference->amount(),
            ],
        );
    }
}
