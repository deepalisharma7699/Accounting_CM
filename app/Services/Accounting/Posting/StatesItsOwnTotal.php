<?php

namespace App\Services\Accounting\Posting;

use App\Support\Money;

/**
 * A posting template whose document is not worth the sum of its debits.
 *
 * For almost everything, the two are the same number: a ₹5,000 receipt debits
 * ₹5,000 of cash, and the transaction list can say "₹5,000" by adding up one
 * side of the entries. A bill breaks that. A sale of ₹5,000 that took ₹3,200 of
 * stock off the shelf debits the customer ₹5,000 *and* debits cost of goods sold
 * ₹3,200 — both real, both on the same side, and their sum is not what anybody
 * would call the value of the invoice.
 *
 * Rather than teach every list to subtract cost of goods sold, the template that
 * knows what the document is worth says so.
 */
interface StatesItsOwnTotal
{
    /**
     * What the document says it is worth: the invoice total, tax included.
     *
     * @param  array<string, mixed>  $input
     */
    public function documentTotal(array $input): Money;
}
