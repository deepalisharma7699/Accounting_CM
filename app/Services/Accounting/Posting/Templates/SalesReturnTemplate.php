<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\StockMovementType;
use App\Enums\TransactionType;

/**
 * The credit note — M18. A sale with every side inverted, on the returned
 * quantities only.
 *
 * ```
 * Cr Sundry Debtors     credit-note total, tax included
 * Dr Sales              goods returned, at taxable value
 * Dr Service Income     labour credited
 * Dr GST Output         the tax, given back
 * Dr Inventory / Cr COGS   per stock line, at the ORIGINAL cost
 * ```
 *
 * ## Why this is nine lines and not a template of its own
 *
 * Because a credit note *is* an invoice read backwards. The tax arithmetic, the
 * intra/inter-state split, the discount handling, the revenue split between
 * Sales and Service Income, the document total — all identical, and all already
 * correct in {@see SaleTemplate}. Writing them again would mean two places for
 * the GST rounding to drift apart, and one of them ends up on a government
 * return. So the only things stated here are the three that genuinely differ:
 * the type, which side the party sits on, and where the stock is valued from.
 *
 * ## Why the tax is given back
 *
 * Because it was collected on the department's behalf and the supply did not
 * happen. Leaving `GST Output` alone would have the workshop remitting tax on a
 * bearing that is back on its own shelf, out of its own pocket. Under GST this
 * document is the credit note that adjusts the original invoice's liability, and
 * it takes a number in a series of its own for exactly that reason —
 * `CN/26-27/1001`.
 *
 * ## Why the customer's side is a credit
 *
 * The sale debited Sundry Debtors: *they owe us*. Taking goods back reduces what
 * they owe, so their side is the other way. The invoice itself stays posted and
 * stays true — three of the four bearings are still theirs — and what it is now
 * worth is answered by `BillService::settlementFor()`, which subtracts what has
 * been credited.
 */
class SalesReturnTemplate extends SaleTemplate
{
    use ReturnsStockAtOriginalCost;

    public function type(): TransactionType
    {
        return TransactionType::SalesReturn;
    }

    protected function isReturn(): bool
    {
        return true;
    }

    /** Taking goods back reduces what the customer owes, so their side is a credit. */
    protected function controlIsDebit(): bool
    {
        return false;
    }

    /**
     * Back onto the shelf. An ordinary `in`, not an `adjust`: the customer
     * physically brought the bearing back, and a stock card that could not tell a
     * genuine return from a clerical correction would be a stock card nobody
     * could reconcile.
     */
    protected function returnMovementType(): StockMovementType
    {
        return StockMovementType::In;
    }
}
