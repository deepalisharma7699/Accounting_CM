<?php

namespace App\Repositories\Eloquent;

use App\Enums\PayrollRunStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Repositories\Contracts\PayrollRepositoryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentPayrollRepository implements PayrollRepositoryInterface
{
    /* ---------------------------------------------------------------------
     | Runs
     |-------------------------------------------------------------------- */

    public function findRun(int $id): ?PayrollRun
    {
        return PayrollRun::with(['lines', 'transaction', 'creator'])->find($id);
    }

    public function runForTransaction(int $transactionId): ?PayrollRun
    {
        return PayrollRun::with(['lines', 'transaction', 'creator'])
            ->where('transaction_id', $transactionId)
            ->first();
    }

    public function liveRunForMonth(DateTimeInterface|string $month): ?PayrollRun
    {
        return PayrollRun::query()
            ->live()
            ->whereDate('period_month', CarbonImmutable::parse($month)->startOfMonth()->toDateString())
            ->first();
    }

    public function paginateRuns(array $filters, int $perPage): LengthAwarePaginator
    {
        return PayrollRun::query()
            ->with(['transaction:id,doc_no,date,status,total', 'creator:id,name'])
            // The sheet's totals are summed from the lines, so they are counted
            // here rather than loaded: a list of twelve runs must not pull four
            // hundred payslips to print twelve numbers.
            ->withCount('lines')
            ->withSum('lines as gross_total', 'gross')
            ->withSum('lines as recovered_total', 'advance_recovered')
            ->withSum('lines as net_total', 'net')
            ->when(
                filled($filters['status'] ?? null)
                    && PayrollRunStatus::tryFrom((string) $filters['status']) !== null,
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                filled($filters['from'] ?? null),
                fn ($query) => $query->whereDate('period_month', '>=', $filters['from'])
            )
            ->when(
                filled($filters['to'] ?? null),
                fn ($query) => $query->whereDate('period_month', '<=', $filters['to'])
            )
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function createRun(array $attributes): PayrollRun
    {
        return PayrollRun::create($attributes);
    }

    /**
     * One insert for the whole sheet.
     *
     * `insert()` goes round the model — no `creating` event, so `tenant_id` is
     * written explicitly, and the run it belongs to was created through the
     * scoped model a moment earlier inside the same database transaction.
     */
    public function writeLines(PayrollRun $run, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $tenantId = (int) $run->tenant_id;
        $now = CarbonImmutable::now();

        PayrollLine::query()->insert(array_map(fn (array $line) => array_merge($line, [
            'tenant_id' => $tenantId,
            'payroll_run_id' => $run->id,
            'attendance' => json_encode($line['attendance'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]), array_values($lines)));
    }

    public function linesForEmployee(int $employeeId, int $limit = 12): Collection
    {
        return PayrollLine::query()
            ->with('run:id,period_month,status,posted_at')
            ->where('employee_id', $employeeId)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->orderByDesc('payroll_runs.period_month')
            ->limit($limit)
            ->select('payroll_lines.*')
            ->get();
    }

    /* ---------------------------------------------------------------------
     | Advances
     |-------------------------------------------------------------------- */

    public function advanceTotalsFor(array $employeeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        $totals = array_fill_keys($ids, ['paid' => '0.00', 'recovered' => '0.00']);

        if ($ids === []) {
            return $totals;
        }

        /*
        | Posted only, on both sides.
        |
        | A reversed advance is money that came back, and a reversed run
        | recovered nothing. Counting either would leave a workshop chasing a
        | deduction that no longer exists — and the employee would be the one to
        | find out.
        */
        $paid = Transaction::query()
            ->whereIn('employee_id', $ids)
            ->where('type', TransactionType::StaffAdvance->value)
            ->where('status', TransactionStatus::Posted->value)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(total) as paid')
            ->pluck('paid', 'employee_id');

        $recovered = PayrollLine::query()
            ->whereIn('payroll_lines.employee_id', $ids)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->where('payroll_runs.status', PayrollRunStatus::Posted->value)
            ->groupBy('payroll_lines.employee_id')
            ->selectRaw('payroll_lines.employee_id, SUM(payroll_lines.advance_recovered) as recovered')
            ->pluck('recovered', 'employee_id');

        foreach ($ids as $id) {
            $totals[$id] = [
                // Decimal strings all the way out — the aggregate arrives as one
                // from the driver, and turning it into a float here is exactly
                // the boundary Money exists to defend.
                'paid' => (string) ($paid[$id] ?? '0.00'),
                'recovered' => (string) ($recovered[$id] ?? '0.00'),
            ];
        }

        return $totals;
    }

    public function paginateAdvances(array $filters, int $perPage): LengthAwarePaginator
    {
        return Transaction::query()
            ->with(['employee:id,name', 'payments', 'creator:id,name'])
            ->where('type', TransactionType::StaffAdvance->value)
            ->when(
                ($filters['employee_id'] ?? null) !== null,
                fn ($query) => $query->where('employee_id', (int) $filters['employee_id'])
            )
            ->when(
                filled($filters['status'] ?? null)
                    && TransactionStatus::tryFrom((string) $filters['status']) !== null,
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                filled($filters['from'] ?? null),
                fn ($query) => $query->whereDate('date', '>=', $filters['from'])
            )
            ->when(
                filled($filters['to'] ?? null),
                fn ($query) => $query->whereDate('date', '<=', $filters['to'])
            )
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
