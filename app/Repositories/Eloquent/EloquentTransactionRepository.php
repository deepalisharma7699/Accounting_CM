<?php

namespace App\Repositories\Eloquent;

use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    /**
     * Columns a client may sort by, so nothing user-supplied reaches ORDER BY.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['date', 'total', 'created_at'];

    public function findById(int $id): ?Transaction
    {
        return Transaction::find($id);
    }

    public function findWithEntries(int $id): ?Transaction
    {
        return Transaction::with([
            'entries.account',
            // The modes and references behind the settlement lines — the part of
            // a payment voucher the ledger cannot express on its own.
            'payments',
            'creator:id,name',
            'party:id,name,roles',
            'reverses:id,date',
            'reversal:id,reverses_id,date',
            /*
            | What quantities the posting moved, and at what valuation. Loaded
            | here rather than left to a second request because it is the same
            | question as the voucher — "what did this bill do" is one answer
            | covering the books and the shelf, and a screen that had to ask
            | twice would show the two halves arriving at different moments.
            |
            | Empty for anything that does not move stock, which is correct
            | rather than missing: a labour-only invoice moved nothing.
            */
            'stockMovements',
        ])->find($id);
    }

    public function create(array $attributes): Transaction
    {
        return Transaction::create($attributes);
    }

    public function update(Transaction $transaction, array $attributes): Transaction
    {
        $transaction->fill($attributes)->save();

        return $transaction->refresh();
    }

    public function delete(Transaction $transaction): bool
    {
        return (bool) $transaction->delete();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'date';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return Transaction::query()
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('notes', 'like', '%'.$filters['search'].'%'))
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            // Several types at once — what a tab means, as opposed to what an
            // enum case means. Narrows alongside `type` rather than replacing
            // it, so asking for both and getting nothing is honest.
            ->when(
                filled($filters['types'] ?? null),
                fn ($query) => $query->whereIn('type', $filters['types'])
            )
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['source'] ?? null), fn ($query) => $query->where('source', $filters['source']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('date', '<=', $filters['to']))
            // "Every transaction that touched this account" — the drill-down
            // from a ledger line back to the events behind it. whereHas rather
            // than a join, so a transaction with two lines on one account is
            // still listed once.
            ->when(
                filled($filters['account_id'] ?? null),
                fn ($query) => $query->whereHas(
                    'entries',
                    fn ($entries) => $entries->where('account_id', $filters['account_id'])
                )
            )
            // Everything one party was involved in — the drill-down from a
            // party statement back to the events behind it.
            ->when(
                filled($filters['party_id'] ?? null),
                fn ($query) => $query->where('party_id', $filters['party_id'])
            )
            /*
            | The tenders behind each transaction — how the money moved, and how
            | much of the document it covered.
            |
            | Eager-loaded where the ledger lines deliberately are not, and the
            | asymmetry is the point. A split is a handful of rows per
            | transaction at most and it is what a listing is asked for by name:
            | "paid by cheque", "₹2,000 of ₹5,000 settled". Lines are unbounded
            | and a listing only ever reports how many there are, which is what
            | the count below is for.
            |
            | Note what the sum of these is *not*: it is money taken on this
            | document, not this document's share of everything the party has
            | since paid. A settlement here reduces a party's balance rather than
            | a named invoice's, so no such share exists — see
            | TransactionResource::paidOnDocument().
            */
            ->with(['creator:id,name', 'party:id,name,roles', 'payments'])
            // A count rather than the lines themselves: the list shows "4
            // lines", and loading every entry of every transaction to learn
            // that would be the classic listing-page mistake.
            ->withCount('entries')
            ->orderBy($sort, $direction)
            // A stable tiebreaker: several transactions commonly share a date,
            // and without this their order across pages is whatever the engine
            // happens to return, which can repeat or skip rows.
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function dayBook(?string $from, ?string $to, int $perPage): LengthAwarePaginator
    {
        return Transaction::query()
            ->inTheBooks()
            ->when(filled($from), fn ($query) => $query->whereDate('date', '>=', $from))
            ->when(filled($to), fn ($query) => $query->whereDate('date', '<=', $to))
            // Every line of every voucher, which is what makes this a day book
            // rather than a list. Eager-loaded, or a page of forty vouchers
            // would be several hundred queries.
            ->with(['entries.account:id,code,name,type', 'party:id,name,roles', 'creator:id,name'])
            // Forwards: a day book is read the way the day happened. The
            // transaction list is the other way round, and both are right for
            // what they are.
            ->orderBy('date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function countsByTypeAndStatus(): array
    {
        return [
            'types' => Transaction::query()
                ->selectRaw('type, count(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type')
                ->map(fn ($count) => (int) $count)
                ->all(),

            'statuses' => Transaction::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count)
                ->all(),
        ];
    }

    public function drafts(int $perPage): LengthAwarePaginator
    {
        return Transaction::query()
            ->drafts()
            ->with(['party:id,name,roles', 'creator:id,name'])
            // Oldest first, which is the opposite of every other listing here
            // and deliberately so: a worklist is ordered by what needs attention
            // most, and the draft nobody has touched for three weeks is the one
            // whose prices have moved under it.
            ->orderBy('date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
