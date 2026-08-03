<?php

namespace App\Repositories\Eloquent;

use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\Accounting\Posting\PostingLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every read of the ledger goes through here, and every one of them is an
 * Eloquent query — a raw `DB::table('journal_entries')` would bypass the tenant
 * scope, which on MySQL is the entire isolation boundary.
 */
class EloquentJournalEntryRepository implements JournalEntryRepositoryInterface
{
    /**
     * @param  array<int, PostingLine>  $lines
     * @return Collection<int, JournalEntry>
     */
    public function writeFor(Transaction $transaction, array $lines): Collection
    {
        $entries = new Collection;

        foreach (array_values($lines) as $index => $line) {
            $entries->push($transaction->entries()->create([
                // Stamped from the parent rather than from the context, so an
                // entry can never end up in a different workshop's books from
                // the transaction that owns it.
                'tenant_id' => $transaction->tenant_id,
                'account_id' => $line->accountId,
                'line_no' => $index + 1,
                'debit' => $line->debitAmount()->amount(),
                'credit' => $line->creditAmount()->amount(),
                'date' => $transaction->date,
                'memo' => $line->memo,
            ]));
        }

        return $entries;
    }

    public function forAccount(int $accountId, array $filters, int $perPage): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->forAccount($accountId)
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->from($filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->upTo($filters['to']))
            ->with(['transaction:id,type,status,source,date,notes'])
            ->inLedgerOrder()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function totalsForAccount(int $accountId, ?string $from = null, ?string $to = null): array
    {
        $row = JournalEntry::query()
            ->forAccount($accountId)
            ->when(filled($from), fn ($query) => $query->from($from))
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        return [
            'debit' => (string) ($row?->debit_total ?? '0'),
            'credit' => (string) ($row?->credit_total ?? '0'),
        ];
    }

    public function totalsBefore(int $accountId, string $date, int $entryId): array
    {
        $row = JournalEntry::query()
            ->forAccount($accountId)
            // Ledger order is (date, id), so "before this entry" is everything
            // on an earlier day plus everything written earlier the same day.
            ->where(function ($query) use ($date, $entryId) {
                $query->whereDate('date', '<', $date)
                    ->orWhere(fn ($sameDay) => $sameDay->whereDate('date', '=', $date)->where('id', '<', $entryId));
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        return [
            'debit' => (string) ($row?->debit_total ?? '0'),
            'credit' => (string) ($row?->credit_total ?? '0'),
        ];
    }

    public function totalsByAccount(?string $from = null, ?string $to = null): Collection
    {
        return JournalEntry::query()
            ->when(filled($from), fn ($query) => $query->from($from))
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->groupBy('account_id')
            ->get()
            ->map(fn (JournalEntry $row) => [
                'account_id' => (int) $row->account_id,
                'debit' => (string) $row->debit_total,
                'credit' => (string) $row->credit_total,
            ]);
    }

    public function totals(?string $from = null, ?string $to = null): array
    {
        $row = JournalEntry::query()
            ->when(filled($from), fn ($query) => $query->from($from))
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        return [
            'debit' => (string) ($row?->debit_total ?? '0'),
            'credit' => (string) ($row?->credit_total ?? '0'),
        ];
    }

    public function countForAccount(int $accountId): int
    {
        return JournalEntry::query()->forAccount($accountId)->count();
    }

    /* ---------------------------------------------------------------------
     | Party ledgers
     |-------------------------------------------------------------------- */

    public function forParty(int $partyId, array $accountIds, array $filters, int $perPage): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->forParty($partyId)
            ->forAccounts($accountIds)
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->from($filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->upTo($filters['to']))
            ->with(['transaction:id,type,status,source,date,notes,party_id', 'account:id,code,name,type'])
            ->inLedgerOrder()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function totalsForParty(int $partyId, array $accountIds, ?string $from = null, ?string $to = null): Collection
    {
        return JournalEntry::query()
            ->forParty($partyId)
            ->forAccounts($accountIds)
            ->when(filled($from), fn ($query) => $query->from($from))
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->groupBy('account_id')
            ->get()
            ->map(fn (JournalEntry $row) => [
                'account_id' => (int) $row->account_id,
                'debit' => (string) $row->debit_total,
                'credit' => (string) $row->credit_total,
            ]);
    }

    public function totalsBeforeForParty(int $partyId, array $accountIds, string $date, int $entryId): array
    {
        $row = JournalEntry::query()
            ->forParty($partyId)
            ->forAccounts($accountIds)
            // Ledger order is (date, id), so "before this entry" is everything
            // on an earlier day plus everything written earlier the same day.
            ->where(function ($query) use ($date, $entryId) {
                $query->whereDate('date', '<', $date)
                    ->orWhere(fn ($sameDay) => $sameDay->whereDate('date', '=', $date)->where('id', '<', $entryId));
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        return [
            'debit' => (string) ($row?->debit_total ?? '0'),
            'credit' => (string) ($row?->credit_total ?? '0'),
        ];
    }

    public function totalsByParty(array $partyIds, array $accountIds, ?string $to = null): Collection
    {
        if ($partyIds === [] || $accountIds === []) {
            return new Collection;
        }

        return JournalEntry::query()
            // A join rather than whereHas, because the grouping key lives on
            // the *transaction*: party_id has to be selectable, and a subquery
            // cannot be grouped by. Matching tenant_id as well as the foreign
            // key keeps the tenant boundary stated on both sides of the join,
            // column to column, without depending on the context object.
            ->join('transactions', function ($join) {
                $join->on('transactions.id', '=', 'journal_entries.transaction_id')
                    ->on('transactions.tenant_id', '=', 'journal_entries.tenant_id');
            })
            ->whereIn('transactions.party_id', $partyIds)
            ->whereIn('journal_entries.account_id', $accountIds)
            // Qualified: `transactions` has a `date` column too, so an
            // unqualified one is ambiguous and MySQL refuses the query.
            ->when(filled($to), fn ($query) => $query->whereDate('journal_entries.date', '<=', $to))
            ->groupBy('transactions.party_id', 'journal_entries.account_id')
            ->selectRaw(
                'transactions.party_id as party_id, journal_entries.account_id as account_id, '.
                'COALESCE(SUM(journal_entries.debit), 0) as debit_total, '.
                'COALESCE(SUM(journal_entries.credit), 0) as credit_total'
            )
            ->get()
            ->map(fn (JournalEntry $row) => [
                'party_id' => (int) $row->party_id,
                'account_id' => (int) $row->account_id,
                'debit' => (string) $row->debit_total,
                'credit' => (string) $row->credit_total,
            ]);
    }
}
