<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Posting\MovesStock;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\StockChange;
use App\Services\Inventory\StockLedgerService;
use App\Support\Money;
use App\Support\Quantity;

/**
 * Template G — a stock-take correction.
 *
 * The shelf is the authority on what is on it. When the count disagrees with the
 * books, this is how the books are made to agree with the count: a quantity
 * moves, and the value of that quantity is written off to — or written back
 * from — cost of goods sold.
 *
 * ## Why COGS, and not a "stock loss" account of its own
 *
 * Because a shortage found at a stock-take is, in almost every case, stock that
 * *was* consumed and nobody recorded: the bearing fitted on a Saturday, the
 * metre of copper cut off and not booked to a job. Charging it to cost of goods
 * sold puts it where the rest of that consumption already is, which is the only
 * place a margin calculation would look for it. A separate account would report
 * a healthier gross margin than the workshop actually earns, and hide the
 * shortfall one screen further away.
 *
 * The alternative is worth naming, because it is a real accounting practice: a
 * dedicated "Inventory write-off" expense is the right answer once a workshop is
 * large enough that shrinkage is a number somebody manages. That is a chart-of-
 * accounts decision, and M3 already lets a workshop add the account — what this
 * template will not do is invent it for them before anybody has asked.
 *
 * ## Why one pair of lines per movement
 *
 * A stock-take that finds two fewer bearings and one more motor is two
 * corrections that happen to have been counted on the same afternoon. Netting
 * them into a single Inventory line would make the voucher unable to say what
 * was actually found — and, when the two happened to be worth the same, would
 * net to zero and post nothing at all. Several lines on one account are still
 * one sum as far as the balance check is concerned; that is M6's reasoning about
 * a cheque and a transfer both landing in Bank, applied to a different table.
 */
class StockAdjustmentTemplate implements MovesStock, PostingTemplate
{
    /**
     * Memoised, because {@see build()} and the engine's own read of
     * {@see stockChangesFrom()} happen inside one `compose()` on one instance —
     * and valuing the same issue twice is not merely a wasted query. Under
     * concurrency it is two answers.
     *
     * @var array<int, StockChange>|null
     */
    private ?array $changes = null;

    public function __construct(
        protected readonly ChartOfAccountService $accounts,
        protected readonly StockLedgerService $stock,
        protected readonly ItemVariantRepositoryInterface $variants,
    ) {}

    public function type(): TransactionType
    {
        return TransactionType::StockAdjustment;
    }

    /**
     * @param  array{adjustments?: array<int, array<string, mixed>>}  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidStockMovementException
     */
    public function build(array $input): array
    {
        $changes = $this->stockChangesFrom($input);

        $inventory = $this->accounts->system(SystemAccount::Inventory)->id;
        $cogs = $this->accounts->system(SystemAccount::Cogs)->id;

        $lines = [];

        foreach ($changes as $change) {
            $value = $change->value->absolute();

            // A movement worth nothing contributes nothing to the books. That is
            // not an error on its own — a workshop may legitimately carry a free
            // sample at zero — but an adjustment where *every* movement is
            // worthless is refused below, because it would leave the quantities
            // changed with no accounting trace at all.
            if ($value->isZero()) {
                continue;
            }

            $memo = $change->memo;

            $lines[] = $change->isIncrease()
                ? PostingLine::debit($inventory, $value, $memo)
                : PostingLine::debit($cogs, $value, $memo);

            $lines[] = $change->isIncrease()
                ? PostingLine::credit($cogs, $value, $memo)
                : PostingLine::credit($inventory, $value, $memo);
        }

        if ($lines === []) {
            throw InvalidStockMovementException::valuelessAdjustment();
        }

        return $lines;
    }

    /**
     * @param  array{adjustments?: array<int, array<string, mixed>>}  $input
     * @return array<int, StockChange>
     *
     * @throws InvalidStockMovementException
     */
    public function stockChangesFrom(array $input): array
    {
        if ($this->changes !== null) {
            return $this->changes;
        }

        $rows = array_values((array) ($input['adjustments'] ?? []));

        if ($rows === []) {
            throw InvalidStockMovementException::noAdjustments();
        }

        $changes = [];

        foreach ($rows as $row) {
            // Already a value object when the engine is re-reading a batch it
            // composed; an array when it arrived from a client or a stored draft.
            if ($row instanceof StockChange) {
                $changes[] = $row;

                continue;
            }

            $row = (array) $row;
            $variant = $this->resolveVariant((int) ($row['variant_id'] ?? 0));

            $changes[] = $this->stock->adjustment(
                $variant,
                Quantity::of($row['quantity'] ?? 0),
                // A stated cost is honoured only for stock that was *found*. A
                // shortage is written off at what the books were carrying it at,
                // which is not the counter's number to choose — see
                // StockLedgerService::adjustment().
                Money::ofNullable($row['unit_cost'] ?? null),
                $this->memoFor($variant, $row['memo'] ?? null),
            );
        }

        return $this->changes = $changes;
    }

    private function resolveVariant(int $id): ItemVariant
    {
        return $this->variants->findWithItem($id)
            ?? throw InvalidStockMovementException::unknownVariant($id);
    }

    /**
     * Every line says which variant it is about.
     *
     * A stock adjustment posts to two accounts the whole workshop shares, so
     * without this the Inventory ledger would read as a column of unexplained
     * corrections — which is exactly the state a stock-take is meant to end.
     */
    private function memoFor(ItemVariant $variant, mixed $note): string
    {
        $label = $variant->displayLabel();
        $note = trim((string) ($note ?? ''));

        return $note === '' ? $label : sprintf('%s · %s', $label, $note);
    }
}
