<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\StockMovementType;
use App\Services\Accounting\Posting\BillLine;
use App\Services\Accounting\Posting\StockChange;
use App\Support\Money;

/**
 * The half of a return template that is not simply "the bill, inverted" — M18.
 *
 * Everything else about a credit note falls out of
 * {@see BillTemplate::sideFor()}: the same tax arithmetic, the same discount
 * handling, the same document total, every line on the opposite side. The one
 * thing that genuinely cannot be derived from the original document is what the
 * returned stock is *worth*.
 *
 * ## Why the value arrives on the payload
 *
 * Because it depends on what has already come back, and a template cannot see
 * that. Three bearings went out at ₹1,200; one came back last week at ₹400; this
 * return of the remaining two is worth ₹800 — and it has to be exactly ₹800, not
 * two-thirds of ₹1,200 rounded again, or the last return of a line leaves paise
 * of stock value behind with no stock under it.
 *
 * `ReturnService` computes it as a share of what remains, exactly as the stock
 * ledger values an issue as a share of the position, and passes it down as
 * `stock_value` on each item row. The template takes it as given and never
 * guesses: valuing at today's weighted average would put the bearing back at a
 * price it never left at, and the Inventory account would hold the difference for
 * ever against stock that is physically on the shelf.
 *
 * @see StockChange::returning()
 */
trait ReturnsStockAtOriginalCost
{
    /**
     * Memoised for the same reason the parent memoises its own: `build()` and
     * the engine's read of the movements happen inside one `compose()` on one
     * instance, and the Inventory line must describe the movement it is paired
     * with rather than a second computation of it.
     *
     * @var array<int, StockChange>|null
     */
    private ?array $returnChanges = null;

    /**
     * Which way this document moves stock: back onto the shelf for a sales
     * return, off it for a purchase return.
     */
    abstract protected function returnMovementType(): StockMovementType;

    /**
     * Overrides the parent so each change can be handed the value the return
     * service computed for it, which {@see BillTemplate::stockChangeFor()}
     * has no way to receive.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, StockChange>
     */
    public function stockChangesFrom(array $input): array
    {
        if ($this->returnChanges !== null) {
            return $this->returnChanges;
        }

        $values = $this->valuesByLine($input);
        $changes = [];

        foreach ($this->documentLinesFrom($input) as $line) {
            if (! $line->movesStock || $line->variant === null) {
                continue;
            }

            $changes[] = StockChange::returning(
                $line->variant,
                $line->quantity,
                // Zero where the caller said nothing, which is honest rather
                // than helpful: a line whose original movement was worth nothing
                // comes back worth nothing, and the engine refuses a zero-amount
                // posting line rather than inventing a cost.
                $values[$line->lineNo] ?? Money::zero(),
                $this->returnMovementType(),
                $line->description,
            )->withLineNo($line->lineNo);
        }

        return $this->returnChanges = $changes;
    }

    /**
     * The stock values the return service computed, keyed by line number.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, Money>
     */
    private function valuesByLine(array $input): array
    {
        $values = [];

        foreach (array_values((array) ($input['items'] ?? [])) as $index => $row) {
            if (is_array($row) && isset($row['stock_value'])) {
                $values[$index + 1] = Money::of($row['stock_value']);
            }
        }

        return $values;
    }

    /**
     * Never reached: {@see stockChangesFrom()} above replaces the path that
     * would call it. Declared because the parent requires it, and stated as a
     * failure rather than left to return something plausible — a return valued
     * at today's average is exactly the bug this trait exists to prevent.
     */
    protected function stockChangeFor(BillLine $line): StockChange
    {
        throw new \LogicException(
            'A return values its stock from the original movement, not from the current position.'
        );
    }
}
