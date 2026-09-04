<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\BalanceSide;
use App\Enums\StockMovementType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Services\Accounting\Posting\BillLine;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\StockChange;
use App\Support\Money;

/**
 * Templates A and B — goods sold, labour billed, or both on one document.
 *
 * ```
 * Dr Sundry Debtors     invoice total, tax included
 * Cr Sales              goods, at taxable value
 * Cr Service Income     labour, at taxable value
 * Cr GST Output         the tax
 * Dr COGS / Cr Inventory   per stock line, at weighted average cost
 * ```
 *
 * ## Why revenue splits two ways and cost does not
 *
 * A rewinding shop's single most useful question is whether it makes its money
 * from parts or from skill, and the only place that can be answered is the
 * revenue side. So Sales and Service Income are kept apart, aggregated one line
 * each — they are the same kind of thing within themselves, unlike a settlement's
 * modes, so there is nothing lost by summing them.
 *
 * Cost is *not* aggregated: one Dr COGS / Cr Inventory pair per stock line, each
 * memoed with the variant. That is what makes the Inventory ledger readable as
 * "what left the shelf and what it was worth" rather than a column of totals, and
 * it is what pairs each line with the movement that gives it a margin.
 *
 * ## Selling below cost
 *
 * Posts, and warns. Clearing old stock below cost is a real decision, and so is a
 * job quoted before the copper price moved. What the workshop must not be allowed
 * to do is *not notice* — see {@see \App\Services\Accounting\BillService}.
 */
class SaleTemplate extends BillTemplate
{
    public function type(): TransactionType
    {
        return TransactionType::Sale;
    }

    protected function controlAccount(): SystemAccount
    {
        return SystemAccount::Receivables;
    }

    /** The customer owes us, so their side is a debit. */
    protected function controlIsDebit(): bool
    {
        return true;
    }

    protected function taxAccount(): SystemAccount
    {
        return SystemAccount::GstOutput;
    }

    /**
     * @param  array<int, BillLine>  $lines
     * @param  array<int, StockChange>  $changes
     * @return array<int, PostingLine>
     */
    protected function bodyLines(array $lines, array $changes): array
    {
        $goods = Money::zero();
        $labour = Money::zero();
        $posting = [];

        foreach ($lines as $line) {
            if ($line->isService()) {
                $labour = $labour->plus($line->taxable());
            } else {
                $goods = $goods->plus($line->taxable());
            }

            $change = $changes[$line->lineNo] ?? null;

            if ($change === null) {
                continue;
            }

            $cost = $change->value->absolute();

            // A line whose stock is carried at nothing — a free sample, or
            // something adjusted in at zero — posts no cost. Zero is refused by
            // the engine as a line amount, and a "cost" of nothing is honestly
            // no cost rather than a rounding to be papered over.
            if ($cost->isZero()) {
                continue;
            }

            // Debit COGS and credit Inventory as goods leave — and exactly the
            // other way round on a sales return, which is the whole of what M18
            // added here. See BillTemplate::sideFor().
            $posting[] = PostingLine::on(
                $this->sideFor(BalanceSide::Debit),
                $this->accounts->system(SystemAccount::Cogs)->id,
                $cost,
                $line->description,
            );

            $posting[] = PostingLine::on(
                $this->sideFor(BalanceSide::Credit),
                $this->accounts->system(SystemAccount::Inventory)->id,
                $cost,
                $line->description,
            );
        }

        $revenue = [];
        $revenueSide = $this->sideFor(BalanceSide::Credit);

        if (! $goods->isZero()) {
            $revenue[] = PostingLine::on(
                $revenueSide,
                $this->accounts->system(SystemAccount::Sales)->id,
                $goods,
                'Goods',
            );
        }

        if (! $labour->isZero()) {
            $revenue[] = PostingLine::on(
                $revenueSide,
                $this->accounts->system(SystemAccount::ServiceIncome)->id,
                $labour,
                'Labour',
            );
        }

        return array_merge($revenue, $posting);
    }

    /**
     * Stock leaving, valued at the weighted average at this moment.
     *
     * Memoised by the stock ledger's own lock discipline rather than here: the
     * service takes a write lock on the variant when it is called inside a
     * database transaction, which is exactly when this answer is about to be
     * written. See StockLedgerService::issue().
     */
    protected function stockChangeFor(BillLine $line): StockChange
    {
        return $this->stock->issue(
            $line->variant,
            $line->quantity,
            StockMovementType::Out,
            $line->description,
        );
    }
}
