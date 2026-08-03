<?php

namespace App\Services\Onboarding;

use App\Enums\OpeningRowKind;
use App\Support\Money;

/**
 * A whole go-live declaration, resolved but not yet posted.
 *
 * What the preview screen renders, and — because the importer builds one of
 * these and then commits it rather than re-deciding anything — what actually
 * lands. The point of that is worth stating: a preview assembled by different
 * code from the commit can be right about something the commit gets wrong, and
 * an owner who agreed to one set of figures would find another in their books.
 *
 * ## The residual
 *
 * The roadmap asks for "trial balance shown, with OBE absorbing any residual",
 * and the honest version of that is not an error check. Every line of an opening
 * balance is posted against Opening Balance Equity, so the books reconcile
 * whatever is imported — there is no residual in the sense of a difference that
 * failed to balance, and a screen that implied there might be would be teaching
 * people to distrust a number that is always correct.
 *
 * What OBE ends up holding is the **owner's stake at go-live**: assets declared,
 * less liabilities declared. That is the residual, it is a real and interesting
 * figure, and the only way it comes out wrong is if something was left out of
 * the file — which is exactly what showing it prominently is for. A workshop
 * that forgot its ₹40,000 of cash sees an owner's stake ₹40,000 short of what
 * they know it is, on the screen, before they agree to anything.
 */
final class OpeningPlan
{
    /**
     * @param  array<int, PlannedRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly string $date,
        public readonly ?string $filename = null,
        public readonly string $fingerprint = '',
    ) {}

    /* ---------------------------------------------------------------------
     | Rows
     |-------------------------------------------------------------------- */

    /**
     * @return array<int, PlannedRow>
     */
    public function ready(): array
    {
        return array_values(array_filter($this->rows, fn (PlannedRow $row) => $row->isReady()));
    }

    /**
     * @return array<int, PlannedRow>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->rows, fn (PlannedRow $row) => $row->isError()));
    }

    /**
     * @return array<int, PlannedRow>
     */
    public function skipped(): array
    {
        return array_values(array_filter($this->rows, fn (PlannedRow $row) => $row->isSkipped()));
    }

    /**
     * Ready rows of one kind, which is how the importer groups its postings:
     * all the stock on one transaction, one transaction per party, and the
     * account balances on one more.
     *
     * @return array<int, PlannedRow>
     */
    public function readyOfKind(OpeningRowKind $kind): array
    {
        return array_values(array_filter(
            $this->ready(),
            fn (PlannedRow $row) => $row->row->kind === $kind,
        ));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function hasNothingToPost(): bool
    {
        return $this->ready() === [];
    }

    /* ---------------------------------------------------------------------
     | Totals
     |-------------------------------------------------------------------- */

    public function totalFor(OpeningRowKind $kind): Money
    {
        return Money::sum(array_map(
            fn (PlannedRow $row) => $row->amount(),
            $this->readyOfKind($kind),
        ));
    }

    /**
     * What the workshop is declaring it owns: stock, receivables, and any
     * account balance that opens on the debit side.
     */
    public function assets(): Money
    {
        $total = $this->totalFor(OpeningRowKind::Stock)
            ->plus($this->totalFor(OpeningRowKind::Receivable));

        foreach ($this->readyOfKind(OpeningRowKind::Balance) as $row) {
            if ($row->opensOnDebit()) {
                $total = $total->plus($row->amount());
            }
        }

        return $total;
    }

    /**
     * What it is declaring it owes: payables, and any account balance opening on
     * the credit side — an overdraft, a loan, tax not yet paid over.
     */
    public function liabilities(): Money
    {
        $total = $this->totalFor(OpeningRowKind::Payable);

        foreach ($this->readyOfKind(OpeningRowKind::Balance) as $row) {
            if (! $row->opensOnDebit()) {
                $total = $total->plus($row->amount());
            }
        }

        return $total;
    }

    /**
     * The owner's stake at go-live — what Opening Balance Equity will hold once
     * this plan is posted. See the note at the top of this class.
     */
    public function ownersStake(): Money
    {
        return $this->assets()->minus($this->liabilities());
    }

    /* ---------------------------------------------------------------------
     | Counts
     |-------------------------------------------------------------------- */

    /**
     * Records this plan will bring into existence, counted by kind.
     *
     * @return array<string, int>
     */
    public function creations(): array
    {
        $counts = [];

        foreach ($this->ready() as $row) {
            foreach ($row->creates as $what) {
                $counts[$what] = ($counts[$what] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function creationsOf(string $what): int
    {
        return $this->creations()[$what] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'date' => $this->date,
            'filename' => $this->filename,

            'rows' => count($this->rows),
            'ready' => count($this->ready()),
            'skipped' => count($this->skipped()),
            'errors' => count($this->errors()),

            'stock_value' => $this->totalFor(OpeningRowKind::Stock)->amount(),
            'receivable_total' => $this->totalFor(OpeningRowKind::Receivable)->amount(),
            'payable_total' => $this->totalFor(OpeningRowKind::Payable)->amount(),
            'other_total' => $this->totalFor(OpeningRowKind::Balance)->amount(),

            'assets' => $this->assets()->amount(),
            'liabilities' => $this->liabilities()->amount(),
            // The figure the screen leads on. See the class note: this is the
            // owner's stake, not a difference that failed to balance.
            'owners_stake' => $this->ownersStake()->amount(),

            'items_created' => $this->creationsOf(PlannedRow::CREATES_ITEM),
            'variants_created' => $this->creationsOf(PlannedRow::CREATES_VARIANT),
            'parties_created' => $this->creationsOf(PlannedRow::CREATES_PARTY),
        ];
    }
}
