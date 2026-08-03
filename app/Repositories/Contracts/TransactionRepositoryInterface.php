<?php

namespace App\Repositories\Contracts;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TransactionRepositoryInterface
{
    public function findById(int $id): ?Transaction;

    /**
     * A transaction with its ledger lines and their accounts — the drill-down
     * read, eager-loaded so a voucher is one query rather than one per line.
     */
    public function findWithEntries(int $id): ?Transaction;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Transaction;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Transaction $transaction, array $attributes): Transaction;

    /**
     * Only ever a draft — the model itself refuses anything that has reached
     * the ledger.
     */
    public function delete(Transaction $transaction): bool;

    /**
     * @param  array{search?: string|null, type?: string|null, types?: array<int, string>|null, status?: string|null, source?: string|null, from?: string|null, to?: string|null, account_id?: int|null, party_id?: int|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * The day book — M12: every voucher in a period, in date order, with its
     * ledger lines already loaded.
     *
     * Deliberately not `paginate()` with a filter. A day book is read *forwards*
     * — oldest first, the way the day happened — where a transaction list is
     * read newest first, and it carries every line of every voucher where a list
     * carries a count. Bending one query to do both would mean a listing page
     * that sometimes loaded every entry in the workshop.
     *
     * Drafts are excluded structurally: they have no journal entries, so a day
     * book that included them would show vouchers with no lines.
     *
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function dayBook(?string $from, ?string $to, int $perPage): LengthAwarePaginator;

    /**
     * How many transactions there are of each type, and of each status.
     *
     * Two grouped counts rather than one count per figure a caller wants. The
     * transactions screen shows four tab badges, and each tab is a *set* of
     * types — "Sales" covers an invoice and the receipt that settles it — so a
     * caller adds the cases its own tab means. Publishing the raw breakdown
     * instead of the four totals keeps the screen's grouping in the screen,
     * where it can change without a migration on this interface.
     *
     * Deliberately unfiltered: these count the workshop's books, not the
     * current search. A badge that moved when somebody typed in the search box
     * would be answering a different question from the one it appears to.
     *
     * @return array{types: array<string, int>, statuses: array<string, int>}
     */
    public function countsByTypeAndStatus(): array;

    /**
     * The parked-draft worklist — M12.
     *
     * Everything that was started and never authorised, oldest first, because
     * the oldest is the one that has gone stale and the one somebody needs to
     * look at. See ReportService::draftWorklist() for what "stale" means and why
     * it is a warning rather than an expiry.
     *
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function drafts(int $perPage): LengthAwarePaginator;
}
