<?php

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Services\Accounting\Tax\GstBreakdown;
use App\Services\Inventory\StockLedgerService;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Reading a bill back: what it was worth, what it cost, and what somebody ought
 * to look at.
 *
 * Everything here is **derived on read**, and none of it is stored. A margin is
 * the line's taxable value minus the value of the stock movement behind it — one
 * join, no duplicate. The tax summary is a sum over the lines. The warnings are
 * questions asked of today's stock position.
 *
 * ## Why warnings are computed here and not at posting
 *
 * Because they have to appear more than once. A bill sold below cost is worth
 * flagging when it is posted *and* every time somebody opens it afterwards —
 * "why is this month's margin down" is asked long after the toast has gone. A
 * warning raised only at the moment of writing is a warning half the workshop
 * misses.
 *
 * And because none of them is a refusal. Selling below cost is a real decision;
 * so is billing something the shelf says is not there. The roadmap is explicit:
 * both post.
 */
class BillService
{
    public function __construct(
        private readonly TransactionLineRepositoryInterface $lines,
        private readonly StockLedgerService $stock,
    ) {}

    /**
     * A bill's lines, with the stock movement behind each one loaded — which is
     * what makes {@see \App\Models\TransactionLine::margin()} answerable without
     * a query per line.
     *
     * @return Collection<int, \App\Models\TransactionLine>
     */
    public function linesFor(Transaction $transaction): Collection
    {
        return $this->lines->forTransaction((int) $transaction->id);
    }

    /**
     * The invoice footer: taxable value, the tax split three ways, and the total.
     *
     * Summed from the lines rather than recomputed on the total, and the two are
     * not the same thing: a bill with an 18% motor and a 12% service has no
     * single rate, and applying one to the sum would produce a figure matching no
     * line on the document.
     *
     * @return array{taxable: string, cgst: string, sgst: string, igst: string, tax: string, total: string, inter_state: bool}
     */
    public function taxSummaryFor(Transaction $transaction): array
    {
        $lines = $transaction->relationLoaded('lines') ? $transaction->lines : $this->linesFor($transaction);

        $taxable = Money::zero();
        $cgst = Money::zero();
        $sgst = Money::zero();
        $igst = Money::zero();

        foreach ($lines as $line) {
            $taxable = $taxable->plus($line->taxableMoney());
            $cgst = $cgst->plus(Money::of($line->cgst_amount));
            $sgst = $sgst->plus(Money::of($line->sgst_amount));
            $igst = $igst->plus(Money::of($line->igst_amount));
        }

        $tax = $cgst->plus($sgst)->plus($igst);

        return [
            'taxable' => $taxable->amount(),
            'cgst' => $cgst->amount(),
            'sgst' => $sgst->amount(),
            'igst' => $igst->amount(),
            'tax' => $tax->amount(),
            'total' => $taxable->plus($tax)->amount(),
            // One shape or the other, never a mixture — the whole document has
            // one place of supply.
            'inter_state' => ! $igst->isZero(),
        ];
    }

    /**
     * What the bill made: revenue less the cost of what left the shelf.
     *
     * **Null on a purchase**, deliberately. Buying something does not earn
     * anything, and reporting a "margin" of minus the whole invoice would be a
     * figure nobody could use. Null on a bill with no stock lines too — a
     * labour-only invoice has no cost of goods, and calling that a 100% margin
     * would flatter the workshop's most valuable work.
     *
     * @return array{revenue: string, cost: string, margin: string, margin_percent: string}|null
     */
    public function marginFor(Transaction $transaction): ?array
    {
        if ($transaction->type !== TransactionType::Sale) {
            return null;
        }

        $lines = $transaction->relationLoaded('lines') ? $transaction->lines : $this->linesFor($transaction);

        $revenue = Money::zero();
        $cost = Money::zero();
        $costed = false;

        foreach ($lines as $line) {
            $revenue = $revenue->plus($line->taxableMoney());

            $lineCost = $line->cost();

            if ($lineCost !== null) {
                $cost = $cost->plus($lineCost);
                $costed = true;
            }
        }

        if (! $costed) {
            return null;
        }

        $margin = $revenue->minus($cost);

        return [
            'revenue' => $revenue->amount(),
            'cost' => $cost->amount(),
            'margin' => $margin->amount(),
            'margin_percent' => $this->percentOf($margin, $revenue),
        ];
    }

    /**
     * Everything about this bill somebody should look at — and nothing that
     * should have stopped it.
     *
     * @return array<int, array{code: string, message: string, line?: int}>
     */
    public function warningsFor(Transaction $transaction): array
    {
        $lines = $transaction->relationLoaded('lines') ? $transaction->lines : $this->linesFor($transaction);
        $warnings = [];

        foreach ($lines as $line) {
            if ($transaction->type === TransactionType::Sale && $line->isBelowCost()) {
                $warnings[] = [
                    'code' => 'BILL_LINE_BELOW_COST',
                    'line' => (int) $line->line_no,
                    'message' => sprintf(
                        '%s was sold for %s but cost %s. That is a loss of %s on the line — deliberate when '.
                        'clearing old stock, and worth a second look when it is not.',
                        $line->description,
                        $line->taxableMoney()->amount(),
                        $line->cost()?->amount() ?? '0.00',
                        $line->margin()?->absolute()->amount() ?? '0.00',
                    ),
                ];
            }

            // Asked of the position *now* rather than of the movement, because
            // that is the question worth answering: the bill is posted either
            // way, and what somebody has to act on is that the shelf disagrees
            // with the books today.
            if ($line->is_stock && $line->variant !== null) {
                $position = $this->stock->positionFor($line->variant);

                if ($position->isNegative()) {
                    $warnings[] = [
                        'code' => 'STOCK_NEGATIVE',
                        'line' => (int) $line->line_no,
                        'message' => sprintf(
                            '%s is now at %s — more has been issued than received. The purchase behind it is '.
                            'probably not entered yet.',
                            $line->description,
                            $position->quantity->trimmed(),
                        ),
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * A margin as a percentage of revenue, to two decimals.
     *
     * "0.00" against zero revenue rather than a division: a bill given away
     * entirely has no percentage, and inventing one would put an infinity on a
     * screen.
     */
    private function percentOf(Money $part, Money $whole): string
    {
        if ($whole->isZero()) {
            return '0.00';
        }

        // Integer arithmetic to two decimals of a percent: paise × 10,000 ÷ paise.
        $points = intdiv($part->minor() * 10000, $whole->minor());

        return sprintf('%s%d.%02d', $points < 0 ? '-' : '', intdiv(abs($points), 100), abs($points) % 100);
    }

    /**
     * The totals of several breakdowns — exposed so a preview can print the same
     * footer the posted bill will.
     *
     * @param  array<int, GstBreakdown>  $breakdowns
     * @return array{taxable: Money, cgst: Money, sgst: Money, igst: Money, tax: Money, total: Money}
     */
    public function totalsOf(array $breakdowns): array
    {
        return GstBreakdown::totals($breakdowns);
    }
}
