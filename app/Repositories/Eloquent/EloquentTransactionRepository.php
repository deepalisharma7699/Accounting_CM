<?php

namespace App\Repositories\Eloquent;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

    public function findByClientRef(string $clientRef): ?Transaction
    {
        return Transaction::where('client_ref', $clientRef)->first();
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

    public function postedForParty(int $partyId, TransactionType $type): Collection
    {
        return Transaction::query()
            ->where('party_id', $partyId)
            ->where('type', $type->value)
            // Posted only — not `inTheBooks()`. A reversed bill has been
            // cancelled by a mirroring entry and owes nothing, so including it
            // would offer the operator a document to settle that is already
            // settled by construction.
            ->where('status', TransactionStatus::Posted->value)
            // What was taken at the counter, so the caller can work out what is
            // still owing without a query per bill.
            ->with('payments')
            ->orderBy('date')
            ->orderBy('id')
            ->get();
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

    /**
     * What has discharged a bill, as SQL — its own counter payments, every
     * receipt allocated to it, and everything returned against it.
     *
     * Three sources, and the third is not money at all: a customer who brought
     * the goods back owes nothing further, and a bill left on the outstanding
     * list because nobody paid for something they no longer have is a bill
     * somebody chases for no reason — M18.
     *
     * A correlated subquery pair rather than a join, because a join would
     * multiply a bill's rows by its payments and turn every other filter on this
     * query into a lie. Written here rather than computed in PHP because these
     * filters decide which rows are on the *page*: filtering after pagination
     * would produce a page count that disagreed with the rows under it, and page
     * 3 of a five-page list showing four rows.
     *
     * Identical in meaning to {@see \App\Services\Accounting\BillService::settlementFor()},
     * which is the one thing to watch when either changes.
     */
    private const PAID_EXPRESSION = "(
        coalesce((select sum(tp.amount) from transaction_payments tp
                   where tp.transaction_id = transactions.id), 0)
      + coalesce((select sum(ta.amount) from transaction_allocations ta
                   where ta.bill_transaction_id = transactions.id), 0)
      + coalesce((select sum(tr.total) from transactions tr
                   where tr.against_transaction_id = transactions.id
                     and tr.status = 'posted'), 0)
    )";

    /**
     * The types a payment status means anything for. A receipt is not unpaid; it
     * is the payment.
     *
     * @var array<int, string>
     */
    private const BILLABLE_TYPES = [
        TransactionType::Sale->value,
        TransactionType::Purchase->value,
    ];

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'date';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return Transaction::query()
            // The document number as well as the note. "INV/26-27/1012" is what
            // a customer quotes down the telephone, and a search box that could
            // not find it would be a search box nobody used — M16.
            //
            // The counterparty too, which is the term actually typed most of
            // the time: somebody holding a paper slip has the customer's name
            // long before they have the invoice number, and every document
            // list's search box says so in its own placeholder. A `whereHas`
            // rather than a join, so a transaction with no party — a journal,
            // an expense — is simply not matched instead of vanishing from
            // every other filter's results as well.
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where(
                fn ($search) => $search
                    ->where('notes', 'like', '%'.$filters['search'].'%')
                    ->orWhere('doc_no', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas(
                        'party',
                        fn ($party) => $party->where('name', 'like', '%'.$filters['search'].'%')
                    )
            ))
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
            // Where the bill stands against what has been paid on it — M16.
            ->when(
                filled($filters['payment_status'] ?? null),
                fn ($query) => $this->constrainToPaymentStatus(
                    $query,
                    PaymentStatus::from((string) $filters['payment_status']),
                    $filters['overdue_on_or_before'] ?? null,
                )
            )
            ->when(
                (bool) ($filters['outstanding'] ?? false),
                fn ($query) => $this->onlyBills($query)->whereRaw('transactions.total > '.self::PAID_EXPRESSION)
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
            // And the document's own rows, which is what a bill list counts —
            // three ledger entries for a single item is the right answer to a
            // different question. See TransactionResource::documentLineCount().
            ->withCount('lines')
            ->orderBy($sort, $direction)
            // A stable tiebreaker: several transactions commonly share a date,
            // and without this their order across pages is whatever the engine
            // happens to return, which can repeat or skip rows.
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Narrow to the documents a payment status is a statement about: posted
     * sales and purchases.
     *
     * Applied by every status filter rather than left to the caller, so
     * `?payment_status=unpaid` cannot return a stock adjustment on the grounds
     * that nobody has paid for it.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Transaction>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function onlyBills($query)
    {
        return $query
            ->whereIn('type', self::BILLABLE_TYPES)
            // Posted only. A draft has settled nothing because nothing has
            // moved, and a reversed bill has been cancelled — neither has a
            // payment status, as distinct from having an unpaid one.
            ->where('status', TransactionStatus::Posted->value);
    }

    /**
     * One payment status, in SQL.
     *
     * `$overdueOnOrBefore` is the date a bill must be dated on or before to have
     * run past the workshop's terms — computed once by the service that knows the
     * tenant, because a repository resolving a setting per query would read the
     * same row for every row it returned. Null where the workshop has set no
     * terms, in which case nothing is overdue and the filter honestly matches
     * nothing rather than falling back to a period nobody agreed to.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Transaction>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function constrainToPaymentStatus($query, PaymentStatus $status, ?string $overdueOnOrBefore)
    {
        $query = $this->onlyBills($query);

        $paid = self::PAID_EXPRESSION;

        // Overdue outranks partial and unpaid: it replaces them rather than
        // sitting alongside, exactly as BillService::statusOf() has it. So the
        // other two have to exclude it, or a forty-day-old part-paid invoice
        // would appear under both.
        $notOverdue = fn ($builder) => $overdueOnOrBefore === null
            ? $builder
            : $builder->whereDate('date', '>', $overdueOnOrBefore);

        return match ($status) {
            PaymentStatus::Paid => $query->whereRaw("transactions.total <= {$paid}"),

            PaymentStatus::Overdue => $overdueOnOrBefore === null
                // No terms means nothing is overdue. `whereRaw('1 = 0')` rather
                // than returning everything: an empty list is the true answer,
                // and silently ignoring the filter would show the operator a
                // page of bills they had asked not to see.
                ? $query->whereRaw('1 = 0')
                : $query
                    ->whereRaw("transactions.total > {$paid}")
                    ->whereDate('date', '<=', $overdueOnOrBefore),

            PaymentStatus::Partial => $notOverdue(
                $query->whereRaw("{$paid} > 0")->whereRaw("transactions.total > {$paid}")
            ),

            PaymentStatus::Unpaid => $notOverdue(
                $query->whereRaw("{$paid} = 0")->where('total', '>', 0)
            ),
        };
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

    public function outstandingBills(array $types): Collection
    {
        $types = array_values(array_intersect($types, self::BILLABLE_TYPES));

        if ($types === []) {
            return collect();
        }

        return $this->onlyBills(Transaction::query())
            ->whereIn('type', $types)
            ->whereRaw('transactions.total > '.self::PAID_EXPRESSION)
            // The same expression again, this time as a column rather than as a
            // filter. Selecting it means the ageing buckets and the "which bills
            // are open" test are one piece of arithmetic — a second subtraction
            // in PHP could round differently and put a bill in a bucket the list
            // it links to would not show.
            ->selectRaw('transactions.*, (transactions.total - '.self::PAID_EXPRESSION.') as due_amount')
            ->with(['party:id,name,roles'])
            // Oldest first: an ageing is read from the far end, and the invoice
            // at the top of it is the one somebody rings about this afternoon.
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }
}
