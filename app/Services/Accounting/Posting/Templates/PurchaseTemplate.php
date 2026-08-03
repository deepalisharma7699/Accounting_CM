<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\StockMovementType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Services\Accounting\Posting\BillLine;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\StockChange;
use App\Support\Money;

/**
 * Template C — goods bought in, and the mirror of a sale.
 *
 * ```
 * Dr Inventory          per stock line, at taxable value
 * Dr COGS               anything bought and not stocked
 * Dr GST Input          the tax, claimable
 * Cr Sundry Creditors   invoice total, tax included
 * ```
 *
 * ## Why inventory is valued net of tax
 *
 * The GST paid on a purchase is not a cost — it is claimable against the tax
 * charged on sales, so it sits in an asset account of its own until it is set
 * off. Adding it to the cost of the stock would inflate the weighted average by
 * the rate, and every margin computed afterwards would be wrong by the same
 * amount. **This is the arrival that recomputes the weighted average**, so it is
 * the one place getting the basis wrong is permanent.
 *
 * A workshop below the registration threshold has no output tax to set it off
 * against, and for them the right answer is different — the tax genuinely is part
 * of what the stock cost. Phase 1 does not model that: an unregistered workshop
 * has no GSTIN, so its items carry a zero rate, and the two treatments coincide.
 *
 * ## Why a non-stock line is expensed to COGS
 *
 * Everything on a purchase bill either becomes inventory or is consumed. A part
 * bought to order for a specific job is consumed the moment it arrives, and it
 * belongs where the rest of that consumption is — beside the copper and the
 * bearings the job also used, so the margin on the job is right.
 *
 * Running costs are not purchases and do not come through here. Rent, electricity
 * and the tea bill are M10's expense — template F — which is exactly the
 * distinction between "what we bought to sell" and "what it costs to be open".
 */
class PurchaseTemplate extends BillTemplate
{
    public function type(): TransactionType
    {
        return TransactionType::Purchase;
    }

    protected function controlAccount(): SystemAccount
    {
        return SystemAccount::Payables;
    }

    /** We owe the supplier, so their side is a credit. */
    protected function controlIsDebit(): bool
    {
        return false;
    }

    protected function taxAccount(): SystemAccount
    {
        return SystemAccount::GstInput;
    }

    /**
     * @param  array<int, BillLine>  $lines
     * @param  array<int, StockChange>  $changes
     * @return array<int, PostingLine>
     */
    protected function bodyLines(array $lines, array $changes): array
    {
        $posting = [];
        $expensed = Money::zero();

        foreach ($lines as $line) {
            $change = $changes[$line->lineNo] ?? null;

            if ($change === null) {
                // Bought and not stocked: consumed on arrival. Aggregated,
                // because unlike the Inventory lines below there is no movement
                // for each one to pair with.
                $expensed = $expensed->plus($line->taxable());

                continue;
            }

            // One line per movement, memoed with what arrived — so the Inventory
            // ledger reads as "what came in and what it cost" rather than a
            // column of totals, and so each line pairs with the movement that
            // recomputed the average.
            $posting[] = PostingLine::debit(
                $this->accounts->system(SystemAccount::Inventory)->id,
                $change->value->absolute(),
                $line->description,
            );
        }

        if (! $expensed->isZero()) {
            $posting[] = PostingLine::debit(
                $this->accounts->system(SystemAccount::Cogs)->id,
                $expensed,
                'Bought in, not stocked',
            );
        }

        return $posting;
    }

    /**
     * Stock arriving at what the invoice says it cost — net of claimable tax.
     *
     * This is the movement that changes the weighted average, and it does so
     * without anybody computing an average: the position is the sum of the
     * movements, and the average is the sum divided by the sum.
     */
    protected function stockChangeFor(BillLine $line): StockChange
    {
        return $this->stock->receipt(
            $line->variant,
            $line->quantity,
            $line->taxable(),
            StockMovementType::In,
            $line->description,
        );
    }
}
