<?php

namespace App\Services\Staff;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\PayrollRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Accounting\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Money handed to an employee against a salary not yet earned — M22.
 *
 * ## Posted outright, never parked
 *
 * There is no draft. An advance is cash leaving the till at the moment somebody
 * asks for it, and a draft advance would be a promise sitting in a queue while
 * the money is already in a pocket. `post` is forced on the way through for that
 * reason — the caller does not get to choose, because there is no version of
 * this event that has not happened yet.
 *
 * ## Correcting one is a reversal
 *
 * A posted transaction refuses writes, so an advance typed wrong is reversed and
 * re-entered rather than edited — the ledger's rule, applied unchanged. Note
 * what makes this safe: recovery reads *posted* advances only, so a reversed one
 * stops counting against the employee the moment it is cancelled, with nothing
 * to remember.
 *
 * ## What it does not do
 *
 * It does not recover anything. Recovery is a decision taken on a payroll sheet,
 * against a month's earnings, by somebody looking at both figures — see
 * {@see PayrollService}. An advance that recovered itself on a schedule would be
 * a deduction nobody agreed to on a payslip nobody had seen.
 */
class AdvanceService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransactionRepositoryInterface $repository,
        private readonly PayrollRepositoryInterface $payroll,
        private readonly EmployeeService $employees,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->payroll->paginateAdvances($filters, $perPage);
    }

    public function find(int $id): Transaction
    {
        $advance = $this->repository->findById($id);

        if ($advance === null || $advance->type !== TransactionType::StaffAdvance) {
            throw new ResourceNotFoundException('Staff advance', $id);
        }

        return $advance;
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Pay an advance, and stamp who it went to.
     *
     * The stamp is written inside the same database transaction as the posting —
     * see `Transaction::STAMPABLE_ONCE_POSTED` — so the money and the person it
     * went to commit together or not at all. An advance that posted without its
     * employee would be an unattributable debit in Staff Advance that no payroll
     * could ever recover.
     *
     * @param  array{employee_id: int, date?: string|null, payments?: array<int, array<string, mixed>>, notes?: string|null, client_ref?: string|null}  $data
     */
    public function pay(array $data, ?User $actor = null): Transaction
    {
        $employee = $this->employees->find((int) $data['employee_id']);

        $payload = [
            'date' => $data['date'] ?? CarbonImmutable::today()->toDateString(),
            'payments' => $data['payments'] ?? [],
            'notes' => $this->notesFor($employee, $data['notes'] ?? null),
            'client_ref' => $data['client_ref'] ?? null,
            // Not a choice the caller gets to make — see the class note.
            'post' => true,
        ];

        return DB::transaction(function () use ($payload, $employee, $actor) {
            $advance = $this->transactions->create(TransactionType::StaffAdvance, $payload, $actor);

            /*
            | A repeat of a request that already went through — M17's client_ref
            | path. The first attempt did all of this; stamping again would try
            | to write an employee onto a transaction that already names one,
            | which the model refuses outright.
            */
            if (! $advance->wasRecentlyCreated) {
                return $advance;
            }

            $advance->forceFill(['employee_id' => (int) $employee->id])->save();

            Log::info('staff.advance.paid', [
                'transaction_id' => $advance->id,
                'employee_id' => $employee->id,
                'total' => $advance->total,
            ]);

            return $advance->refresh()->load(['payments', 'employee', 'entries.account']);
        });
    }

    /**
     * Cancel an advance — a wrong amount, the wrong person, money that never
     * actually left the till.
     *
     * A reversal rather than a delete or an edit, because the entries are in the
     * books: the original stays visible alongside its mirror image, which is what
     * a book of account does. The employee's outstanding drops by the amount the
     * same instant, because outstanding is derived from posted advances and this
     * one is no longer posted.
     */
    public function reverse(int $id, ?string $date = null, ?string $reason = null, ?User $actor = null): Transaction
    {
        $advance = $this->find($id);

        if ($advance->status === TransactionStatus::Reversed) {
            throw TransactionImmutableException::reversed($advance->id);
        }

        $reversal = $this->transactions->reverse(
            $advance->id,
            $date,
            $reason ?? sprintf('Advance to %s cancelled', $advance->employee?->name ?? 'staff'),
            $actor,
        );

        Log::info('staff.advance.reversed', [
            'transaction_id' => $advance->id,
            'reversal_id' => $reversal->id,
            'employee_id' => $advance->employee_id,
        ]);

        return $reversal;
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * The voucher's memo, with the name in it.
     *
     * The ledger line would otherwise read "Dr Staff Advance 5,000" and be a
     * number rather than a record — the same reason a settlement line carries a
     * cheque number. The name is on `employee_id` too, but a journal read
     * straight out of the books should not need a join to be legible.
     */
    private function notesFor(Employee $employee, ?string $notes): string
    {
        $notes = trim((string) ($notes ?? ''));

        return $notes === ''
            ? sprintf('Advance to %s', $employee->name)
            : sprintf('Advance to %s — %s', $employee->name, $notes);
    }
}
