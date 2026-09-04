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
        $row = $this->billedWork($employeeId, $from, $to)
            ->selectRaw('COUNT(*) as job_count, COALESCE(SUM(transactions.total), 0) as invoice_value')
            ->first();

        return [
            'job_count' => (int) ($row->job_count ?? 0),
            // Through Money like every other figure that leaves this application,
            // so the drawer and the ledger render rupees the same way.
            'invoice_value' => Money::of($row->invoice_value ?? 0)->amount(),
        ];
    }

    public function invoicesFor(int $employeeId, ?string $from, ?string $to, int $perPage): LengthAwarePaginator
    {
        return Transaction::query()
            ->whereIn('id', $this->billedWork($employeeId, $from, $to)->select('transaction_staff.transaction_id'))
            ->with(['party:id,name'])
            // Newest first, and by id within a day: several invoices commonly
            // carry one date, and a list that reordered itself between two reads
            // of the same page is a list somebody stops trusting.
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * The work this person is credited with, as a query others narrow further.
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
     * @return Builder<TransactionStaff>
     */
    private function billedWork(int $employeeId, ?string $from, ?string $to): Builder
    {
        return TransactionStaff::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_staff.transaction_id')
            ->where('transaction_staff.employee_id', $employeeId)
            ->where('transactions.type', TransactionType::Sale->value)
            ->where('transactions.status', TransactionStatus::Posted->value)
            ->when($from !== null, fn ($query) => $query->where('transactions.date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('transactions.date', '<=', $to));
    }
}
