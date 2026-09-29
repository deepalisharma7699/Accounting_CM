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

            /*
            | A pair per *movement*, not per line. One for an ordinary line,
            | which moves one variant — and one for each material where the line
            | supplies something made, because a rewind takes copper, varnish
            | and sleeve off three different shelves and the Inventory ledger has
            | to be able to say which.
            |
            | Summing them into one pair would post the right total and lose
            | that, which is the same argument the docblock above makes against
            | aggregating cost across lines, one level down.
            */
            foreach ($changes[$line->lineNo] ?? [] as $change) {
                $cost = $change->value->absolute();

                // A line whose stock is carried at nothing — a free sample, or
                // something adjusted in at zero — posts no cost. Zero is refused
                // by the engine as a line amount, and a "cost" of nothing is
                // honestly no cost rather than a rounding to be papered over.
                if ($cost->isZero()) {
                    continue;
                }

                // The movement's own memo where it has one, which is what names
                // the material; the line's description otherwise. A ledger
                // reading "Copper Wire 22 SWG · 5 HP rewind" says where the
                // value went, where three rows all reading "5 HP rewind" would
                // not.
                $memo = $change->memo ?? $line->description;

                // Debit COGS and credit Inventory as goods leave — and exactly
                // the other way round on a sales return, which is the whole of
                // what M18 added here. See BillTemplate::sideFor().
                $posting[] = PostingLine::on(
                    $this->sideFor(BalanceSide::Debit),
                    $this->accounts->system(SystemAccount::Cogs)->id,
                    $cost,
                    $memo,
                );

                $posting[] = PostingLine::on(
                    $this->sideFor(BalanceSide::Credit),
                    $this->accounts->system(SystemAccount::Inventory)->id,
                    $cost,
                    $memo,
                );
            }
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

    /**
     * What a rewind takes off the shelf.
     *
     * A workshop sells winding as one line at one price — "5 HP rewind,
     * ₹4,500" — and producing it consumes copper, varnish and sleeve. The line
     * itself holds no stock, so before recipes existed it issued nothing at
     * all: the wire was bought, was never taken out, and the shelf, the
     * Inventory account and every margin the workshop read were wrong together.
     *
     * One issue per material, each valued by the stock ledger exactly as any
     * other issue is — at the weighted average under the same lock, inside the
     * same `compose()`. Nothing here decides a cost, and nothing here writes a
     * movement: it says which quantities move, and {@see \App\Services\Accounting\PostingEngine}
     * writes them beside the entries that value them (§4.3).
     *
     * The Inventory and COGS lines need no change to know about any of this:
     * {@see BillTemplate::build()} derives them from `StockChange::totalValue()`
     * over whatever `stockChangesFrom()` produced, and the engine asserts the
     * two agree before anything is written.
     *
     * **A line naming no variant has no recipe**, and legitimately: a recipe
     * hangs off the variant because a 5 HP rewind and a 10 HP rewind are the
     * same service and different amounts of copper. A line that did not say
     * which one it was could not be expanded into anything, and guessing a
     * default here would issue a quantity nobody chose.
     *
     * @return array<int, StockChange>
     */
    protected function recipeChangesFor(BillLine $line): array
    {
        $recipe = $this->components->forVariant((int) $line->variant->id);

        if ($recipe->isEmpty()) {
            return [];
        }

        $changes = [];

        foreach ($recipe as $component) {
            $material = $component->componentVariant;

            if ($material === null) {
                continue;
            }

            $changes[] = $this->stock->issue(
                $material,
                // Scaled to the number being made, in integer thousandths:
                // three rewinds at 2.5 kg each is 7.5 kg, and `2.5 * 3` in a
                // float is not reliably that.
                $component->quantityFor($line->quantity),
                StockMovementType::Out,
                // Memoed with what it went into, because the stock card is
                // where somebody asks where a fortnight of copper went and the
                // line's own description is the service, not the material.
                sprintf('%s · %s', $material->displayLabel(), $line->description),
            );
        }

        return $changes;
    }
}
