<?php

namespace App\Repositories\Contracts;

use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\Accounting\Posting\PostingLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The ledger. Note what is absent: there is no update and no delete, because
 * an entry is written once and never touched again.
 */
interface JournalEntryRepositoryInterface
{
    /**
     * Write a transaction's lines. Called only by the posting engine, and only
     * inside the database transaction that created the parent row.
     *
     * @param  array<int, PostingLine>  $lines
     * @return Collection<int, JournalEntry>
     */
    public function writeFor(Transaction $transaction, array $lines): Collection;

    /**
     * One account's ledger, in date order.
     *
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function forAccount(int $accountId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Total debits and credits for one account, optionally over a period.
     *
     * @return array{debit: string, credit: string}
     */
    public function totalsForAccount(int $accountId, ?string $from = null, ?string $to = null): array;

    /**
     * Totals for one account over everything that precedes a given entry in
     * ledger order — the cumulative balance a running-balance column starts
     * from, whatever page of the ledger is being shown.
     *
     * @return array{debit: string, credit: string}
     */
    public function totalsBefore(int $accountId, string $date, int $entryId): array;

    /**
     * Totals per account for every account that has been posted to — one query
     * behind the whole trial balance.
     *
     * @return Collection<int, array{account_id: int, debit: string, credit: string}>
     */
    public function totalsByAccount(?string $from = null, ?string $to = null): Collection;

    /* ---------------------------------------------------------------------
     | Party ledgers
     |
     | A party is a property of the transaction, not of the line, so each of
     | these reaches through `transactions.party_id` and restricts to the
     | control accounts that carry a party's position. Everything a party owes
     | or is owed is derived from these three reads; nothing about it is stored.
     |-------------------------------------------------------------------- */

    /**
     * One party's ledger: their entries on the given control accounts, in date
     * order.
     *
     * @param  array<int, int>  $accountIds
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function forParty(int $partyId, array $accountIds, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Totals for one party, split by control account, so a counterparty who is
     * both customer and vendor reports a receivable and a payable rather than
     * one netted figure that hides both.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, array{account_id: int, debit: string, credit: string}>
     */
    public function totalsForParty(int $partyId, array $accountIds, ?string $from = null, ?string $to = null): Collection;

    /**
     * Totals for one party over everything preceding a given entry in ledger
     * order — what a running-balance column opens at on any page.
     *
     * @param  array<int, int>  $accountIds
     * @return array{debit: string, credit: string}
     */
    public function totalsBeforeForParty(int $partyId, array $accountIds, string $date, int $entryId): array;

    /**
     * Totals for many parties at once, split by control account.
     *
     * One query behind a whole page of outstanding figures. Fetching them per
     * row is the mistake this exists to prevent: a listing of fifty parties
     * would otherwise issue fifty sums.
     *
     * @param  array<int, int>  $partyIds
     * @param  array<int, int>  $accountIds
     * @return Collection<int, array{party_id: int, account_id: int, debit: string, credit: string}>
     */
    public function totalsByParty(array $partyIds, array $accountIds, ?string $to = null): Collection;

    /**
     * Debits and credits across the entire ledger. Equal, always.
     *
     * @return array{debit: string, credit: string}
     */
    public function totals(?string $from = null, ?string $to = null): array;

    public function countForAccount(int $accountId): int;
}
