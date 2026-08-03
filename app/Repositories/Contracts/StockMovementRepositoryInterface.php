<?php

namespace App\Repositories\Contracts;

use App\Models\StockMovement;
use App\Models\Transaction;
use App\Services\Accounting\Posting\StockChange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The stock ledger. Note what is absent, exactly as it is on
 * {@see JournalEntryRepositoryInterface}: there is no update and no delete,
 * because a movement is written once and never touched again.
 *
 * Every position here is returned as a pair of decimal strings — a quantity and
 * a value — rather than as a quantity and an average. The average is the second
 * divided by the first and is computed once, in the service, so no caller can
 * arrive at a slightly different one.
 */
interface StockMovementRepositoryInterface
{
    /**
     * Write a transaction's stock movements. Called only by the posting engine,
     * and only inside the database transaction that created the parent row.
     *
     * @param  array<int, StockChange>  $changes
     * @param  Collection<int, \App\Models\TransactionLine>  $documentLines  The
     *         bill's lines, keyed by line number, so each movement can be pointed
     *         at the line that produced it. Empty for anything that is not a
     *         document — a stock adjustment moves quantities with no bill behind
     *         them.
     * @return Collection<int, StockMovement>
     */
    public function writeFor(Transaction $transaction, array $changes, ?Collection $documentLines = null): Collection;

    /**
     * Take a write lock on the variants a posting is about to move, so two
     * simultaneous issues of the same thing cannot both value at the pre-issue
     * average and leave the Inventory account describing stock that was sold
     * twice.
     *
     * Locks the *variant* rows rather than the movements, because the thing
     * being serialised is the arrival of a new movement — and a row that does
     * not exist yet cannot be locked.
     *
     * @param  array<int, int>  $variantIds
     */
    public function lockForValuation(array $variantIds): void;

    /**
     * One variant's position: everything that has moved, summed.
     *
     * @param  string|null  $to  An "as at" date, or null for the position now.
     * @return array{quantity: string, value: string}
     */
    public function positionFor(int $variantId, ?string $to = null): array;

    /**
     * Positions for many variants at once — one query behind a whole page of a
     * stock summary. Fetching them per row is the mistake this exists to
     * prevent.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, array{variant_id: int, quantity: string, value: string}>
     */
    public function positionsFor(array $variantIds, ?string $to = null): Collection;

    /**
     * The rate the most recent arrival was struck at.
     *
     * What an issue is valued at when there is nothing on hand to average — see
     * {@see \App\Services\Inventory\StockLedgerService::valuationFor()}. Null
     * when the variant has never been bought at all.
     */
    public function lastArrivalCostFor(int $variantId): ?string;

    /**
     * One variant's stock card, in date order.
     *
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function forVariant(int $variantId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * The position over everything preceding a given movement in stock order —
     * what a running-balance column on a stock card opens at, whatever page is
     * being shown.
     *
     * @return array{quantity: string, value: string}
     */
    public function positionBefore(int $variantId, string $date, int $movementId): array;

    /**
     * The whole workshop's stock value, for reconciling against the Inventory
     * account.
     *
     * @return array{quantity: string, value: string}
     */
    public function totals(?string $to = null): array;

    /**
     * How many movements exist for a variant — the check that turns deleting one
     * into a refusal.
     */
    public function countForVariant(int $variantId): int;

    public function countForItem(int $itemId): int;

    /**
     * The variant ids that have ever moved, out of the ones given. Cheaper than
     * a count per row when all a caller needs is "which of these are in use".
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public function movedVariantIds(array $variantIds): array;
}
