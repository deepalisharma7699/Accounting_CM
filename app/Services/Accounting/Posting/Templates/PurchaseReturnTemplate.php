<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\StockMovementType;
use App\Enums\TransactionType;

/**
 * The debit note — M18, and the mirror of a credit note.
 *
 * ```
 * Dr Sundry Creditors   debit-note total, tax included
 * Cr Inventory          per stock line, at the ORIGINAL cost
 * Cr COGS               anything bought and not stocked, sent back
 * Cr GST Input          the tax, no longer claimable
 * ```
 *
 * Nine lines for the same reason {@see SalesReturnTemplate} is nine: everything
 * hard about it is already correct in {@see PurchaseTemplate}, and a second copy
 * of the tax arithmetic is a second answer.
 *
 * ## Why the input tax is given back
 *
 * The claim was made on the strength of a supply that has now been undone. Goods
 * returned to the supplier were never really bought, so the input credit against
 * them was never really earned — leaving `GST Input` alone would have the
 * workshop setting off tax it is no longer entitled to, which is the sort of
 * error a department finds and charges interest on.
 *
 * ## Why the stock leaves at what it arrived at
 *
 * Not at today's weighted average, which has moved since — see
 * {@see ReturnsStockAtOriginalCost}. Sending back the ten bearings that arrived
 * at ₹400 must take exactly ₹4,000 out of Inventory, even if a later delivery at
 * ₹450 has since lifted the average, or the account is left carrying value
 * against stock that is on its way to the supplier.
 *
 * Note that this is the one return that can be *refused* for stock: sending back
 * five bearings when the shelf holds three would take the position negative, and
 * M17's D6 applies to it exactly as it applies to a sale.
 */
class PurchaseReturnTemplate extends PurchaseTemplate
{
    use ReturnsStockAtOriginalCost;

    public function type(): TransactionType
    {
        return TransactionType::PurchaseReturn;
    }

    protected function isReturn(): bool
    {
        return true;
    }

    /** Sending goods back reduces what we owe the supplier, so their side is a debit. */
    protected function controlIsDebit(): bool
    {
        return true;
    }

    protected function returnMovementType(): StockMovementType
    {
        return StockMovementType::Out;
    }
}
