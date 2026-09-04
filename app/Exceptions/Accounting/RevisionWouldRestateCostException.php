<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * Correcting this invoice would change what the goods on it cost.
 *
 * ## The problem this refuses
 *
 * A correction is a reversal and a replacement, posted as one act. On a purchase
 * that is exact: the bill *states* its cost, so the replacement arrives at
 * whatever the corrected bill says, which is the whole point of correcting one.
 *
 * A sale is not like that. Stock leaves at the weighted average **on the day it
 * went out**, and the replacement would issue it at the average *now*. When
 * nothing has touched those parts in between the two are the same figure and the
 * correction is exact — which is the ordinary case, an invoice fixed within the
 * hour. But once a delivery has arrived at a different price, the average has
 * moved, and reverse-and-repost would quietly restate the cost of goods sold on
 * an invoice whose goods left the shelf months ago: the margin on it changes,
 * and on a closed period the cost moves between years.
 *
 * Nothing on the screen would show it. The books would balance, the shelf would
 * be right, and the only wrong number would be the one nobody re-reads.
 *
 * ## Why refusing is the right answer rather than pinning the cost
 *
 * Because the correct repair already exists and values the goods properly. Goods
 * that are coming back come back on a **return**, which credits them at a share
 * of what the original issued (M18) — the one place in this application that
 * knows how to put sold stock back at the price it left at. What is left after
 * that is a fresh invoice, priced today, for the goods that really did go.
 *
 * So this refusal names that route rather than offering an acknowledgement. The
 * negative-stock guard offers one because "the shelf reads minus two until a
 * stock count" is a state a workshop can knowingly accept; "the cost of goods
 * sold on last quarter's invoice is now a different number" is not.
 */
class RevisionWouldRestateCostException extends ApiException
{
    /**
     * @param  array<int, array{variant: string, was: string, now: string}>  $movements
     */
    public static function forSale(int $transactionId, array $movements): self
    {
        $first = $movements[0] ?? null;

        return new self(
            message: $first === null
                ? 'This invoice cannot be corrected, because the goods on it would be re-issued at a '.
                  'different cost from the one they left at.'
                : sprintf(
                    'Since this invoice was raised, %s has changed in cost from ₹%s to ₹%s, so correcting '.
                    'it would restate what the sale cost. Take the goods back with a return and raise a '.
                    'fresh invoice instead.',
                    $first['variant'],
                    $first['was'],
                    $first['now'],
                ),
            status: 422,
            errorCode: 'REVISION_WOULD_RESTATE_COST',
            details: ['transaction_id' => $transactionId, 'movements' => $movements],
        );
    }
}
