<?php

namespace App\Services\Staff;

use App\Enums\PayrollRunStatus;
use App\Enums\TransactionType;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Staff\InvalidPayrollException;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Repositories\Contracts\PayrollRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Services\Accounting\TransactionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Running a month's payroll — M22.
 *
 * ## Preview, then post. There is nothing in between.
 *
 * {@see preview()} is free, reads live attendance and can be re-run all day.
 * {@see post()} recomputes the same sheet from scratch and writes it. There is no
 * stored draft, and that is the design rather than an omission: a parked payroll
 * sheet is a set of figures derived from an attendance register that keeps moving
 * under it, and somebody would open a fortnight-old one, see a total, and pay a
 * month that three subsequent absences had already made wrong — with the stale
 * figure looking exactly as authoritative as a fresh one.
 *
 * The consequence to keep in mind: **what the operator saw is not what is
 * posted.** The recomputation at post is the authority, and the only thing
 * carried over from the screen is the human decision — how much of each person's
 * advance to recover — which is exactly the part a machine cannot re-derive.
 *
 * ## What is owed to whom
 *
 * The gross is {@see PayrollCalculator}'s, and this class never does that
 * arithmetic itself. What it adds is recovery: how much of an outstanding advance
 * comes off this month's pay, capped at what the employee actually earned so no
 * payslip can end with them owing the workshop money.
 *
 * ## Where the advance figure comes from
 *
 * Derived, never stored — the advances posted against somebody less what payroll
 * has already recovered, both read posted-only. A reversed advance stops counting
 * the instant it is cancelled and a reversed run recovers nothing, with nothing
 * anywhere having to remember either. That is the same reasoning
 * {@see \App\Services\Accounting\PartyLedgerService} applies to a party's
 * outstanding, and it holds for the same reason: a stored balance agrees with the
 * truth right up until one of the two is written without the other.
 */
class PayrollService
{
    public function __construct(
        private readonly PayrollRepositoryInterface $payroll,
        private readonly EmployeeRepositoryInterface $employees,
        private readonly AttendanceService $attendance,
        private readonly PayrollCalculator $calculator,
        private readonly TransactionService $transactions,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PayrollRun>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->payroll->paginateRuns($filters, $perPage);
    }

    public function find(int $id): PayrollRun
    {
        return $this->payroll->findRun($id)
            ?? throw new ResourceNotFoundException('Payroll run', $id);
    }

    /**
     * What is currently out with each of these people.
     *
     * `paid − recovered`, floored at zero. The floor is not defensive tidiness:
     * over-recovery is refused at every write path, so a negative here would mean
     * the books had already gone wrong, and reporting it as a *negative advance*
     * would read as "the workshop owes them", which is a different claim
     * entirely and one this figure is not entitled to make.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{paid: string, recovered: string, outstanding: string}>
     */
    public function advanceOutstandingFor(array $employeeIds): array
    {
        $totals = $this->payroll->advanceTotalsFor($employeeIds);

        $positions = [];

        foreach ($totals as $employeeId => $total) {
            $paid = Money::of($total['paid']);
            $recovered = Money::of($total['recovered']);
            $outstanding = $paid->minus($recovered);

            $positions[$employeeId] = [
                'paid' => $paid->amount(),
                'recovered' => $recovered->amount(),
                'outstanding' => $outstanding->isNegative() ? '0.00' : $outstanding->amount(),
            ];
        }

        return $positions;
    }

    /**
     * Every payslip for one employee, most recent first — the drawer's history.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\PayrollLine>
     */
    public function historyFor(int $employeeId, int $limit = 12): \Illuminate\Support\Collection
    {
        return $this->payroll->linesForEmployee($employeeId, $limit);
    }

    /* ---------------------------------------------------------------------
     | The sheet
     |-------------------------------------------------------------------- */

    /**
     * Compute a month, without writing anything.
     *
     * What comes back is one row per person who was on the payroll for any part
     * of the month, with what they earned, what is out with them, and what this
     * run *suggests* recovering — the whole outstanding advance, capped at what
     * they earned. A suggestion rather than a rule: a workshop that agreed ₹5,000
     * a month against a ₹20,000 advance types 5,000 over it, and the rest stays
     * outstanding.
     *
     * Somebody who earned nothing is still returned, marked, rather than dropped.
     * A daily-wage helper computing to zero because nobody marked their days is
     * the single likeliest thing to be wrong with a month, and a row that
     * silently vanished would take the evidence with it.
     *
     * @param  array<int, mixed>  $recoveries  employee id => the amount to recover,
     *                             where the operator has overridden the suggestion.
     * @return array{
     *     period: string,
     *     period_label: string,
     *     days: int,
     *     existing_run: PayrollRun|null,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{gross: string, advance_recovered: string, net: string, headcount: int, paid_headcount: int}
     * }
     */
    public function preview(DateTimeInterface|string $month, array $recoveries = []): array
    {
        $start = PayrollCalculator::monthStart($month);
        $end = $start->endOfMonth()->startOfDay();

        $employees = $this->employees->inServiceBetween($start, $end);
        $marks = $this->attendance->marksBetween($start, $end, $employees->pluck('id')->all());

        $computed = $this->calculator->forMonth($employees, $start, $marks);
        $advances = $this->advanceOutstandingFor($employees->pluck('id')->all());

        $rows = [];
        $gross = Money::zero();
        $recovered = Money::zero();
        $paidHeadcount = 0;

        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $computation = $computed[$id];

            $outstanding = Money::of($advances[$id]['outstanding'] ?? '0.00');
            $recovery = $this->resolveRecovery(
                $recoveries[$id] ?? null,
                $outstanding,
                $computation->gross,
            );

            $net = $computation->gross->minus($recovery);

            $gross = $gross->plus($computation->gross);
            $recovered = $recovered->plus($recovery);

            if ($computation->gross->isPositive()) {
                $paidHeadcount++;
            }

            $rows[] = [
                'employee' => $employee,
                'computation' => $computation,
                'advance_outstanding' => $outstanding->amount(),
                'advance_recovered' => $recovery->amount(),
                'net' => $net->amount(),
            ];
        }

        return [
            'period' => $start->format('Y-m'),
            'period_label' => $start->format('F Y'),
            'days' => $start->daysInMonth,
            // Named rather than refused here: a preview of a month that is
            // already paid is a legitimate thing to look at, and the refusal
            // belongs at the moment somebody tries to post it.
            'existing_run' => $this->payroll->liveRunForMonth($start),
            'rows' => $rows,
            'totals' => [
                'gross' => $gross->amount(),
                'advance_recovered' => $recovered->amount(),
                'net' => $gross->minus($recovered)->amount(),
                'headcount' => count($rows),
                'paid_headcount' => $paidHeadcount,
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | Posting
     |-------------------------------------------------------------------- */

    /**
     * Pay a month.
     *
     * The sheet is recomputed here rather than taken from the request — see the
     * class note. What the caller supplies is the date the money moved, how it
     * moved, and the recovery decisions; everything else is derived from the
     * attendance as it stands at this instant.
     *
     * One database transaction wraps the posting, the run and its payslips.
     * Anything less would allow a voucher in the ledger with no breakdown behind
     * it, or a sheet of payslips claiming money that was never posted — and both
     * are discovered a month later by somebody trying to reconcile them.
     *
     * @param  array{
     *     period: string,
     *     date?: string|null,
     *     payments?: array<int, array<string, mixed>>,
     *     recoveries?: array<int, mixed>,
     *     notes?: string|null,
     *     client_ref?: string|null
     * }  $data
     */
    public function post(array $data, ?User $actor = null): PayrollRun
    {
        $month = PayrollCalculator::monthStart($data['period']);

        /*
        | The retry, checked **before** the month guard — M17, and the order is
        | the whole point.
        |
        | A second tap arriving after the first has committed would otherwise be
        | refused with "this month has already been paid", which is true and is
        | the worst possible answer: somebody who pressed Save twice on a patchy
        | connection is told their payroll did not go through *and* that the month
        | is taken, and the obvious next move is to reverse a run that was
        | perfectly good. Returning the run they already made is the only honest
        | reply.
        |
        | Only reachable with a `client_ref`, which every client sends and no
        | server-side caller does.
        */
        $repeat = $this->runForClientRef($data['client_ref'] ?? null);

        if ($repeat !== null) {
            return $repeat;
        }

        $this->assertMonthHasStarted($month);
        $this->assertMonthIsFree($month);

        $sheet = $this->preview($month, $data['recoveries'] ?? []);

        $gross = Money::of($sheet['totals']['gross']);
        $recovered = Money::of($sheet['totals']['advance_recovered']);

        if (! $gross->isPositive()) {
            throw InvalidPayrollException::nothingToPay();
        }

        /*
        | The date the money moved, which is not the month it pays for.
        |
        | Defaulted to today rather than to the end of the month: a workshop
        | paying August wages on 7 September is posting a September cash movement,
        | and dating it 31 August would put the payment in a month that is very
        | possibly already closed.
        */
        $paidOn = trim((string) ($data['date'] ?? '')) !== ''
            ? CarbonImmutable::parse($data['date'])->startOfDay()
            : CarbonImmutable::today();

        $payload = [
            'date' => $paidOn->toDateString(),
            'gross' => $gross->amount(),
            'advance_recovered' => $recovered->amount(),
            'payments' => $data['payments'] ?? [],
            'notes' => $this->notesFor($month, $data['notes'] ?? null),
            'client_ref' => $data['client_ref'] ?? null,
            'post' => true,
        ];

        return DB::transaction(function () use ($payload, $sheet, $month, $paidOn, $actor) {
            $voucher = $this->transactions->create(TransactionType::Payroll, $payload, $actor);

            /*
            | The same retry, arriving close enough that both attempts got past
            | the lookup above. The transaction service returns the row the first
            | one wrote rather than creating a second, and this follows it to the
            | run — writing another sheet against one voucher would pay the month
            | twice out of a total that says otherwise.
            */
            if (! $voucher->wasRecentlyCreated) {
                $existing = $this->payroll->runForTransaction((int) $voucher->id);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $run = $this->payroll->createRun([
                'period_month' => $month->toDateString(),
                'status' => PayrollRunStatus::Posted->value,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'transaction_id' => $voucher->id,
                'posted_at' => CarbonImmutable::now(),
                'created_by' => $actor?->id,
            ]);

            $this->payroll->writeLines($run, $this->linesFrom($sheet));

            Log::info('staff.payroll.posted', [
                'run_id' => $run->id,
                'transaction_id' => $voucher->id,
                'period' => $month->format('Y-m'),
                'paid_on' => $paidOn->toDateString(),
                'gross' => $sheet['totals']['gross'],
                'headcount' => $sheet['totals']['headcount'],
            ]);

            return $run->load(['lines', 'transaction', 'creator']);
        });
    }

    /**
     * Cancel a run.
     *
     * The ledger entries are mirrored rather than deleted — nothing is removed
     * from a book of account — and the run is marked reversed, which frees the
     * month to be run again against the attendance as it now stands.
     *
     * The payslips are **kept**, deliberately. They are the record of what was
     * paid out and then taken back, and an employee who was handed cash against
     * one is entitled to have it still exist. They stop counting for advance
     * recovery the instant the run leaves `posted`, because that read is scoped
     * to live runs — so nothing has to be unwound.
     */
    public function reverse(int $id, ?string $date = null, ?string $reason = null, ?User $actor = null): PayrollRun
    {
        $run = $this->find($id);

        if ($run->status === PayrollRunStatus::Reversed) {
            throw InvalidPayrollException::alreadyReversed($run->id);
        }

        return DB::transaction(function () use ($run, $date, $reason, $actor) {
            if ($run->transaction_id !== null) {
                $this->transactions->reverse(
                    $run->transaction_id,
                    $date,
                    $reason ?? sprintf('%s payroll cancelled', $run->periodLabel()),
                    $actor,
                );
            }

            $run->forceFill(['status' => PayrollRunStatus::Reversed])->save();

            Log::info('staff.payroll.reversed', [
                'run_id' => $run->id,
                'transaction_id' => $run->transaction_id,
                'period' => $run->periodKey(),
            ]);

            return $run->refresh()->load(['lines', 'transaction', 'creator']);
        });
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * The run a `client_ref` already produced, if this request is a repeat.
     *
     * Null for a first attempt, for a reference nothing was posted under, and for
     * every server-side caller — none of which has a client to name a document.
     */
    private function runForClientRef(?string $clientRef): ?PayrollRun
    {
        if ($clientRef === null || $clientRef === '') {
            return null;
        }

        $existing = $this->transactions->findByClientRef($clientRef);

        return $existing === null
            ? null
            : $this->payroll->runForTransaction((int) $existing->id);
    }

    /**
     * One live run per month.
     *
     * Not a unique index, because a reversed run must not block its own
     * replacement — that is the whole point of reversing it, and MySQL has no
     * partial unique index to express "one live run per month". Here instead,
     * where the refusal can name the run that is in the way and say what to do
     * about it.
     */
    private function assertMonthIsFree(CarbonImmutable $month): void
    {
        $existing = $this->payroll->liveRunForMonth($month);

        if ($existing === null) {
            return;
        }

        throw InvalidPayrollException::monthAlreadyRun(
            $existing->id,
            $existing->periodLabel(),
            $existing->transaction?->doc_no,
        );
    }

    /**
     * A month that has not started yet cannot be run.
     *
     * The month in progress **can** be, and that is deliberate rather than an
     * oversight: a workshop paying weekly-ish in cash runs the sheet on the 28th
     * and the last two days are unmarked, which each basis already has a defined
     * answer for. What is refused is October in September, which would compute
     * every daily-wage employee at zero and every monthly one at a full salary
     * for days nobody has worked — and would take October out of circulation, so
     * the real run is refused when it comes round.
     */
    private function assertMonthHasStarted(CarbonImmutable $month): void
    {
        if ($month->greaterThan(CarbonImmutable::today()->startOfMonth())) {
            throw InvalidPayrollException::monthNotStarted($month->format('F Y'));
        }
    }

    /**
     * How much of an advance this month takes back.
     *
     * Defaulted to everything outstanding and capped twice — at what is actually
     * owed, and at what was actually earned. The second cap is the one that
     * matters: without it a ₹20,000 advance against a ₹6,000 month would produce
     * a payslip with a negative net, which is the workshop invoicing its own
     * fitter.
     */
    private function resolveRecovery(mixed $requested, Money $outstanding, Money $gross): Money
    {
        $ceiling = $outstanding->compareTo($gross) > 0 ? $gross : $outstanding;

        if ($requested === null || $requested === '') {
            return $ceiling;
        }

        $wanted = Money::of(is_string($requested) || is_int($requested) || is_float($requested) ? $requested : 0);

        if ($wanted->isNegative()) {
            throw InvalidPayrollException::negativeRecovery();
        }

        return $wanted->compareTo($ceiling) > 0 ? $ceiling : $wanted;
    }

    /**
     * The payslips, from the sheet that was just computed.
     *
     * Only the people who earned something. Somebody who was on the payroll all
     * month and computed to zero — a daily-wage helper nobody marked — gets no
     * payslip, because there is nothing on it: a row saying ₹0.00 would be a
     * document asserting that this is what they were due, which is a claim the
     * workshop should not make on an attendance sheet that was simply never
     * filled in. The preview shows them, flagged, which is where that belongs.
     *
     * @param  array<string, mixed>  $sheet
     * @return array<int, array<string, mixed>>
     */
    private function linesFrom(array $sheet): array
    {
        $lines = [];

        foreach ($sheet['rows'] as $row) {
            /** @var PayrollComputation $computation */
            $computation = $row['computation'];

            if (! $computation->gross->isPositive()) {
                continue;
            }

            $lines[] = array_merge($computation->toLine(), [
                'advance_recovered' => $row['advance_recovered'],
                'net' => $row['net'],
                'notes' => null,
            ]);
        }

        return $lines;
    }

    /**
     * The voucher's memo. "September 2026 payroll — 9 staff", so a journal read
     * straight out of the books says which month it settled without a join.
     */
    private function notesFor(CarbonImmutable $month, ?string $notes): string
    {
        $notes = trim((string) ($notes ?? ''));

        return $notes === ''
            ? sprintf('%s payroll', $month->format('F Y'))
            : sprintf('%s payroll — %s', $month->format('F Y'), $notes);
    }

    /**
     * Whether this employee is somebody payroll should be offered for at all.
     *
     * Kept as a named question rather than inlined, because "who is on the
     * payroll for a month" has three different-looking answers — the day sheet's,
     * the list's, and this one — and only this one is about a period in the past.
     */
    public function isPayableIn(Employee $employee, DateTimeInterface|string $from, DateTimeInterface|string $to): bool
    {
        return $employee->wasInServiceOn($from) || $employee->wasInServiceOn($to);
    }
}
