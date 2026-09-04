<?php

namespace App\Services\Reporting\Insights;

use App\Enums\AttendanceStatus;
use App\Enums\PayrollRunStatus;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\StaffAttendance;
use App\Services\Reporting\ReportPeriod;
use App\Services\Staff\PayrollService;
use App\Services\Staff\WorkAttributionService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What the workshop's people cost, and what they got through — M23.
 *
 * ## This panel is gated on STAFF, not on LEDGER
 *
 * Every other panel in this module is the owner's financial position. This one
 * is what individual people are paid, and `PermissionResource::Staff` is the one
 * grant in this application withheld for **privacy** rather than for authority —
 * DATA_ENTRY holds no staff grant at all, not because a clerk cannot be trusted
 * with the list but because what each person earns is not something the person
 * on the till needs. Folding these figures into the overview for anybody holding
 * `READ:LEDGER` would route round that decision, so the controller asks for both
 * grants and {@see \App\Services\Reporting\InsightService} omits the whole
 * section for a caller who holds only one.
 *
 * ## Nothing here is an input to pay
 *
 * `work_attributed` below is throughput — how much invoiced work somebody was
 * credited with — and CLAUDE.md is explicit that attribution must never become
 * an input to wages: the moment a share of the bill lands beside a salary it is
 * a piece rate, and pay is computed from a rate and an attendance sheet in one
 * place. It is reported beside cost because an owner asking "is this bench
 * paying for itself" is asking a real question, and it carries no per-line
 * grain, no hours and no rate for exactly that reason.
 *
 * ## A payroll run is a fact
 *
 * Only posted runs are counted. There is no draft to include — `PayrollService`
 * recomputes on post precisely so that a parked sheet cannot exist — and a
 * reversed run is money that was un-paid, so it is excluded rather than netted:
 * counting a reversal as a negative salary would report a month in which the
 * workshop's wage bill fell.
 */
class PeopleInsights
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly WorkAttributionService $attribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(ReportPeriod $period, Money $revenue): array
    {
        $cost = $this->cost($period);
        $employees = Employee::query()->with('designation:id,name')->get();

        return [
            'cost' => $cost + [
                // The single most useful staff figure a workshop has, and one
                // nothing in the application has ever shown: wages as a share of
                // what came in. Against revenue rather than against margin,
                // because revenue is the figure an owner has in their head.
                'share_of_revenue' => $this->percentOf(Money::of($cost['gross']), $revenue),
            ],
            'trend' => $this->costTrend($period),
            'advances' => $this->advances($employees),
            'attendance' => $this->attendance($period, $employees),
            'people' => $this->people($period, $employees),
        ];
    }

    /* ---------------------------------------------------------------------
     | What it cost
     |-------------------------------------------------------------------- */

    /**
     * The wage bill for the period, from the posted payroll lines.
     *
     * A run belongs to the **month it is for**, not the day it was posted: a
     * March sheet paid on 3 April is March's cost, and dating it by the posting
     * would move a workshop's whole wage bill into the wrong quarter. That is
     * what `period_month` is on the run, and it is why this reads the run rather
     * than the voucher.
     *
     * @return array<string, string|int>
     */
    public function cost(ReportPeriod $period): array
    {
        $row = $this->lineQuery($period)
            ->selectRaw(implode(', ', [
                'sum(payroll_lines.gross) as gross',
                'sum(payroll_lines.advance_recovered) as recovered',
                'sum(payroll_lines.net) as net',
                'count(*) as payslips',
                'count(distinct payroll_lines.employee_id) as people',
                'count(distinct payroll_lines.payroll_run_id) as runs',
            ]))
            ->first();

        $gross = Money::of($row?->gross ?? 0);
        $people = (int) ($row?->people ?? 0);

        return [
            // Gross, not net. Net is what left the till this month; gross is what
            // the month cost, and the difference is an advance being recovered —
            // money that left the till in an earlier month.
            'gross' => $gross->amount(),
            'net_paid' => Money::of($row?->net ?? 0)->amount(),
            'advance_recovered' => Money::of($row?->recovered ?? 0)->amount(),
            'payslips' => (int) ($row?->payslips ?? 0),
            'runs' => (int) ($row?->runs ?? 0),
            'people' => $people,
            'cost_per_head' => $people > 0
                ? Money::fromMinor(intdiv($gross->minor(), $people))->amount()
                : Money::zero()->amount(),
        ];
    }

    /**
     * The wage bill month by month.
     *
     * Always monthly, whatever the period picker says, because payroll *is*
     * monthly — a weekly bucket would be a row of zeroes and one spike, and a
     * daily one would be absurd. This is the one trend in the module that does
     * not take its granularity from the window, and it is because the underlying
     * fact has a period of its own.
     *
     * @return array{granularity: string, buckets: array<int, array<string, string>>}
     */
    public function costTrend(ReportPeriod $period): array
    {
        $rows = $this->lineQuery($period)
            ->selectRaw('payroll_runs.period_month as month, sum(payroll_lines.gross) as gross')
            ->groupBy('payroll_runs.period_month')
            ->orderBy('payroll_runs.period_month')
            ->get();

        return [
            'granularity' => 'month',
            'buckets' => $rows->map(fn ($row) => [
                'key' => CarbonImmutable::parse((string) $row->month)->format('Y-m'),
                'label' => CarbonImmutable::parse((string) $row->month)->format('M y'),
                'value' => Money::of($row->gross ?? 0)->amount(),
            ])->all(),
        ];
    }

    /* ---------------------------------------------------------------------
     | What is out with people
     |-------------------------------------------------------------------- */

    /**
     * Advances paid and not yet recovered.
     *
     * Straight off {@see PayrollService::advanceOutstandingFor()}, which is where
     * every other screen gets this figure — an advance is an **asset**, derived
     * from posted advances less posted recoveries, so reversing either side moves
     * it with nothing having to remember. Recomputing it here would be a second
     * definition of what somebody owes the workshop.
     *
     * Not filtered by the period, like the ageing and for the same reason: an
     * advance from February is a position, not an event.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    private function advances(\Illuminate\Support\Collection $employees): array
    {
        $positions = $this->payroll->advanceOutstandingFor(
            $employees->map(fn (Employee $employee) => (int) $employee->id)->all()
        );

        $total = Money::zero();
        $rows = [];

        foreach ($employees as $employee) {
            $position = $positions[(int) $employee->id] ?? null;

            if ($position === null) {
                continue;
            }

            $outstanding = Money::of($position['outstanding']);

            if (! $outstanding->isPositive()) {
                continue;
            }

            $total = $total->plus($outstanding);

            $rate = Money::of($employee->pay_rate ?? 0);

            $rows[] = [
                'employee_id' => (int) $employee->id,
                'name' => $employee->name,
                'designation' => $employee->designation?->name,
                'outstanding' => $outstanding->amount(),
                'paid' => $position['paid'],
                'recovered' => $position['recovered'],
                /*
                | How much of a month's pay is already spent.
                |
                | The figure that turns a list of advances into a decision: an
                | advance larger than what somebody earns in a month cannot be
                | recovered from one payslip, and a workshop usually discovers
                | that on payday. Null on a daily wage, where a month has no
                | fixed size and the comparison would be invented.
                */
                'share_of_pay' => $employee->salary_basis?->value === 'monthly' && $rate->isPositive()
                    ? $this->percentOf($outstanding, $rate)
                    : null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => bccomp($b['outstanding'], $a['outstanding'], 2));

        return [
            'outstanding' => $total->amount(),
            'people' => count($rows),
            'rows' => $rows,
        ];
    }

    /* ---------------------------------------------------------------------
     | Who turned up
     |-------------------------------------------------------------------- */

    /**
     * The attendance register, summed over the period.
     *
     * **Unmarked days are reported as unmarked**, never filled in. What silence
     * is worth depends on how somebody is paid — a monthly salary is owed unless
     * something is recorded against it, a daily wage is earned by turning up —
     * and that decision lives in `SalaryBasis::unmarkedDayIsPaid()` and nowhere
     * else. Guessing it here would be making it a second time, in the layer
     * least likely to be looked at when a payslip is queried.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    private function attendance(ReportPeriod $period, \Illuminate\Support\Collection $employees): array
    {
        if ($period->from === null || $period->to === null || $employees->isEmpty()) {
            return ['counts' => [], 'marked_days' => 0, 'present_rate' => null];
        }

        $counts = StaffAttendance::query()
            ->whereDate('date', '>=', $period->from)
            ->whereDate('date', '<=', $period->to)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $marked = (int) $counts->sum();

        // Present and half-days against everything that was marked as a working
        // day. Holidays and week-offs are excluded from the denominator: a shop
        // closed on Sunday has not had an absence.
        $working = 0;
        $attended = 0;

        foreach (AttendanceStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);

            if (in_array($status, [AttendanceStatus::Holiday, AttendanceStatus::WeekOff], true)) {
                continue;
            }

            $working += $count;

            if ($status->isAttended()) {
                $attended += $count;
            }
        }

        return [
            'counts' => array_map(static fn (AttendanceStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'days' => (int) ($counts[$status->value] ?? 0),
            ], AttendanceStatus::cases()),
            'marked_days' => $marked,
            'working_days' => $working,
            'present_rate' => $working > 0
                ? number_format(($attended / $working) * 100, 2, '.', '')
                : null,
        ];
    }

    /* ---------------------------------------------------------------------
     | Person by person
     |-------------------------------------------------------------------- */

    /**
     * Each person's cost for the period beside the work credited to them.
     *
     * The two columns are deliberately not divided into one another. A ratio of
     * invoiced work to wages looks like a productivity score and would be read
     * as one — but attribution carries no line grain and no hours, an invoice
     * names at most one person per trade, and half the workshop's people are
     * never on a document at all. Shown side by side, an owner can see that a
     * winder with no invoices against them is the person who does the stripping;
     * divided, the same fact becomes a number that says they earn nothing.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    private function people(ReportPeriod $period, \Illuminate\Support\Collection $employees): array
    {
        $costs = $this->lineQuery($period)
            ->selectRaw('payroll_lines.employee_id as employee_id, sum(payroll_lines.gross) as gross, count(*) as payslips')
            ->groupBy('payroll_lines.employee_id')
            ->get()
            ->keyBy('employee_id');

        $rows = [];

        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $cost = $costs->get($id);

            // One query per person, and it is the right trade here: a workshop
            // has nine or twenty people, not nine thousand, and the alternative
            // is a fourth grouped query joined across three tables to save a few
            // milliseconds on a screen opened twice a month.
            $work = $this->attribution->workFor($id, $period->from, $period->to);

            $rows[] = [
                'employee_id' => $id,
                'name' => $employee->name,
                'designation' => $employee->designation?->name,
                'is_active' => (bool) $employee->is_active,
                'cost' => Money::of($cost?->gross ?? 0)->amount(),
                'payslips' => (int) ($cost?->payslips ?? 0),
                'work_jobs' => $work['job_count'],
                'work_value' => $work['invoice_value'],
            ];
        }

        usort($rows, static fn (array $a, array $b) => bccomp($b['cost'], $a['cost'], 2)
            ?: bccomp($b['work_value'], $a['work_value'], 2));

        return $rows;
    }

    /* ---------------------------------------------------------------------
     | Plumbing
     |-------------------------------------------------------------------- */

    /**
     * Posted payroll lines whose run is *for* a month inside the window.
     *
     * Through {@see PayrollLine} so the tenant scope binds.
     *
     * @return \Illuminate\Database\Eloquent\Builder<PayrollLine>
     */
    private function lineQuery(ReportPeriod $period): \Illuminate\Database\Eloquent\Builder
    {
        return PayrollLine::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            // A reversed run is money that was un-paid. Excluded rather than
            // netted: counting a reversal as a negative wage would report a
            // month in which the workshop's salary bill fell.
            ->where('payroll_runs.status', PayrollRunStatus::Posted->value)
            ->when($period->from, fn ($query, $from) => $query->whereDate(
                'payroll_runs.period_month',
                '>=',
                CarbonImmutable::parse($from)->startOfMonth()->toDateString(),
            ))
            ->when($period->to, fn ($query, $to) => $query->whereDate(
                'payroll_runs.period_month',
                '<=',
                CarbonImmutable::parse($to)->endOfMonth()->toDateString(),
            ));
    }

    private function percentOf(Money $part, Money $whole): string
    {
        if ($whole->isZero()) {
            return '0.00';
        }

        return number_format(($part->minor() / $whole->minor()) * 100, 2, '.', '');
    }
}
