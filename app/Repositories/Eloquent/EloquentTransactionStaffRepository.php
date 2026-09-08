<?php

namespace App\Repositories\Eloquent;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\TransactionStaff;
use App\Repositories\Contracts\TransactionStaffRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentTransactionStaffRepository implements TransactionStaffRepositoryInterface
{
    public function forTransaction(int $transactionId): Collection
    {
        return TransactionStaff::query()
            ->with(['employee:id,name', 'designation:id,name'])
            ->where('transaction_id', $transactionId)
            ->get();
    }

    public function forTransactions(array $transactionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $transactionIds)));

        if ($ids === []) {
            return [];
        }

        return TransactionStaff::query()
            ->with(['employee:id,name', 'designation:id,name'])
            ->whereIn('transaction_id', $ids)
            ->get()
            ->groupBy('transaction_id')
            ->all();
    }

    public function syncFor(int $transactionId, array $pairs): Collection
    {
        $wanted = [];

        foreach ($pairs as $pair) {
            // Last one wins on a repeated trade. The unique index would refuse
            // the second row anyway, and refusing the *request* over a duplicate
            // the caller cannot see is worse than settling it.
            $wanted[(int) $pair['designation_id']] = (int) $pair['employee_id'];
        }

        $existing = TransactionStaff::where('transaction_id', $transactionId)->get();

        /*
        | Rows are matched, updated and deleted individually rather than being
        | cleared and rewritten.
        |
        | A delete-then-insert would be simpler and would destroy the trail: every
        | correction would read as one attribution deleted and an unrelated one
        | created, and "who changed the fitter on this invoice" — the question the
        | audit exists for — would have no answer. Updating in place makes it one
        | row with a from and a to on it.
        |
        | It also matters that an *unchanged* trade is left completely alone: the
        | `updated` event only fires where something actually moved, so re-saving
        | the same two names writes nothing to the trail.
        */
        foreach ($existing as $row) {
            $designationId = (int) $row->designation_id;

            if (! array_key_exists($designationId, $wanted)) {
                $row->delete();

                continue;
            }

            if ((int) $row->employee_id !== $wanted[$designationId]) {
                $row->employee_id = $wanted[$designationId];
                $row->save();
            }

            unset($wanted[$designationId]);
        }

        foreach ($wanted as $designationId => $employeeId) {
            TransactionStaff::create([
                'transaction_id' => $transactionId,
                'designation_id' => $designationId,
                'employee_id' => $employeeId,
            ]);
        }

        return $this->forTransaction($transactionId);
    }

    public function workSummaryFor(int $employeeId, ?string $from = null, ?string $to = null): array
    {
        return $this->workSummaryForMany([$employeeId], $from, $to)[$employeeId] ?? self::noWork();
    }

    public function workSummaryForMany(array $employeeIds, ?string $from = null, ?string $to = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($ids === []) {
            return [];
        }

        /*
        | One row per person per invoice, and that is the whole of the fix.
        |
        | This counted the rows of `transaction_staff`, which is one row per
        | *trade*. Somebody who fitted a motor and wound it is two rows on one
        | document, so an invoice for 11,800 was reported as two jobs worth
        | 23,600 — not merely a count that is off, but money the workshop never
        | billed. The unique index is (transaction_id, designation_id), so one
        | person covering two benches is the ordinary case in a small shop rather
        | than an edge one.
        |
        | The distinct has to happen before the sum and not after it: a
        | `sum(distinct total)` would collapse two different invoices that come to
        | the same amount, which on a counter charging 500 for a service is a
        | daily occurrence rather than a coincidence.
        |
        | `total` rides along inside the derived table because it is functionally
        | dependent on the transaction, so it cannot split one row into two.
        */
        $totals = DB::query()
            ->fromSub($this->creditedPerPerson($ids, $from, $to), 'credited')
            ->selectRaw(implode(', ', [
                'credited.employee_id as employee_id',
                'count(*) as job_count',
                'coalesce(sum(credited.total), 0) as invoice_value',
            ]))
            ->groupBy('credited.employee_id')
            ->get()
            ->keyBy('employee_id');

        $trades = $this->tradesCredited($ids, $from, $to);

        $summaries = [];

        foreach ($ids as $id) {
            $row = $totals->get($id);

            $summaries[$id] = [
                'job_count' => (int) ($row->job_count ?? 0),
                // Through Money like every other figure that leaves this
                // application, so the drawer and the ledger render rupees the
                // same way.
                'invoice_value' => Money::of($row->invoice_value ?? 0)->amount(),
                'trades' => $trades[$id] ?? [],
            ];
        }

        return $summaries;
    }

    public function attributionCoverage(?string $from, ?string $to): array
    {
        $credited = DB::query()
            ->fromSub($this->creditedInvoices($from, $to), 'credited')
            ->selectRaw('count(*) as invoices, coalesce(sum(credited.total), 0) as value')
            ->first();

        return [
            'credited_invoices' => (int) ($credited->invoices ?? 0),
            'credited_value' => Money::of($credited->value ?? 0)->amount(),
            'invoices' => $this->postedSales($from, $to)->count(),
        ];
    }

    public function invoicesFor(int $employeeId, ?string $from, ?string $to, int $perPage): LengthAwarePaginator
    {
        return Transaction::query()
            ->whereIn('id', $this->billedWork([$employeeId], $from, $to)->select('transaction_staff.transaction_id'))
            ->with(['party:id,name'])
            // Newest first, and by id within a day: several invoices commonly
            // carry one date, and a list that reordered itself between two reads
            // of the same page is a list somebody stops trusting.
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /* ---------------------------------------------------------------------
     | The arithmetic behind those figures
     |-------------------------------------------------------------------- */

    /**
     * Which trades each person was credited in, and on how many invoices each.
     *
     * What the double count was accidentally carrying, put back honestly:
     * "Fitting 8 · Winding 3" says somebody covered two benches, where a job
     * count of 11 against 8 invoices only said the arithmetic was wrong.
     *
     * Counted distinct here too, although the unique index means a person can
     * hold a given trade at most once per invoice — the constraint is on the
     * table and this is the figure, and saying so costs nothing.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, array{designation: string, jobs: int}>>
     */
    private function tradesCredited(array $employeeIds, ?string $from, ?string $to): array
    {
        return $this->billedWork($employeeIds, $from, $to)
            ->join('staff_designations', 'staff_designations.id', '=', 'transaction_staff.designation_id')
            ->selectRaw(implode(', ', [
                'transaction_staff.employee_id as employee_id',
                'staff_designations.name as designation',
                'count(distinct transaction_staff.transaction_id) as jobs',
            ]))
            ->groupBy('transaction_staff.employee_id', 'staff_designations.name')
            ->orderByDesc('jobs')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                'designation' => (string) $row->designation,
                'jobs' => (int) $row->jobs,
            ])->values()->all())
            ->all();
    }

    /**
     * One row per person per invoice they are credited with.
     *
     * A derived table rather than a `distinct` on the aggregate, for the reason
     * {@see workSummaryForMany()} gives.
     *
     * @param  array<int, int>  $employeeIds
     * @return Builder<TransactionStaff>
     */
    private function creditedPerPerson(array $employeeIds, ?string $from, ?string $to): Builder
    {
        return $this->billedWork($employeeIds, $from, $to)
            ->select([
                'transaction_staff.employee_id',
                'transaction_staff.transaction_id',
                'transactions.total',
            ])
            ->distinct();
    }

    /**
     * One row per invoice that names **anybody**.
     *
     * Separate from {@see creditedPerPerson()} over the same rows, and the
     * difference is the single column: an invoice naming a fitter *and* a winder
     * is two rows there and must be one row here. Distinct-ing by person and
     * then counting would be the same mistake this whole change is about, made
     * one level up — and it would put the workshop's stated total above a
     * disclaimer saying the column does not add up to it.
     *
     * @return Builder<TransactionStaff>
     */
    private function creditedInvoices(?string $from, ?string $to): Builder
    {
        return $this->billedWork(null, $from, $to)
            ->select(['transaction_staff.transaction_id', 'transactions.total'])
            ->distinct();
    }

    /**
     * The work these people are credited with, as a query others narrow further.
     *
     * ## What is excluded, and why each one
     *
     * **Drafts.** A parked document is not work that was done; it is a document
     * somebody started. Counting one would let a throughput figure be inflated
     * by writing invoices and never posting them.
     *
     * **Reversed documents.** A repair that was billed and then cancelled is not
     * work anybody did. This is also what keeps a *correction* honest: revising
     * an invoice reverses the original and posts a replacement, so without this
     * the same motor would be counted twice.
     *
     * The reversing entry itself needs no exclusion — it carries no attribution,
     * because attribution is written from the sale form and a reversal is
     * generated by the engine.
     *
     * **Anything that is not a sale.** Nothing else can carry attribution in the
     * first place — {@see \App\Services\Staff\WorkAttributionService} refuses it
     * — and stating it here means the figure stays right if that ever changes.
     *
     * @param  array<int, int>|null  $employeeIds  Null asks about everybody.
     * @return Builder<TransactionStaff>
     */
    private function billedWork(?array $employeeIds, ?string $from, ?string $to): Builder
    {
        return TransactionStaff::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_staff.transaction_id')
            ->when(
                $employeeIds !== null,
                fn ($query) => $query->whereIn('transaction_staff.employee_id', $employeeIds),
            )
            ->where('transactions.type', TransactionType::Sale->value)
            ->where('transactions.status', TransactionStatus::Posted->value)
            ->when($from !== null, fn ($query) => $query->where('transactions.date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('transactions.date', '<=', $to));
    }

    /**
     * Every posted invoice in the window, credited to somebody or not.
     *
     * The denominator of the coverage figure, and it sits beside its numerator
     * on purpose: "23 of 41 invoices name somebody" is only true while both
     * halves exclude the same drafts and the same reversals, and two of these in
     * two files is how one of them quietly stops.
     *
     * @return Builder<Transaction>
     */
    private function postedSales(?string $from, ?string $to): Builder
    {
        return Transaction::query()
            ->where('type', TransactionType::Sale->value)
            ->where('status', TransactionStatus::Posted->value)
            ->when($from !== null, fn ($query) => $query->where('date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('date', '<=', $to));
    }

    /**
     * @return array{job_count: int, invoice_value: string, trades: array<int, array{designation: string, jobs: int}>}
     */
    private static function noWork(): array
    {
        return [
            'job_count' => 0,
            'invoice_value' => Money::zero()->amount(),
            'trades' => [],
        ];
    }
}
