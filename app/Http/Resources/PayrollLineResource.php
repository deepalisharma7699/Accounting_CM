<?php

namespace App\Http\Resources;

use App\Models\PayrollLine;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payslip — M22.
 *
 * Every descriptive field here is read off the line's own snapshot rather than
 * through the `employee` relation, and that is the point of the table: a
 * workshop that raises a wage in November must still see October's payslip
 * saying what October said. `employee_id` is present for navigation — "open this
 * person" — and for nothing else.
 *
 * @mixin PayrollLine
 */
class PayrollLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee_name,
            'designation' => $this->designation,

            'salary_basis' => $this->salary_basis->value,
            'salary_basis_label' => $this->salary_basis->label(),
            'salary_basis_short' => $this->salary_basis->shortLabel(),
            'pay_rate' => Money::of($this->pay_rate)->amount(),

            /*
            | Days, as a number a person reads — 19, or 18.5.
            |
            | The half-days are the storage unit because halves in integers are
            | exact; they are divided once, here, at the boundary. A client that
            | received 38 would have to know to halve it, and one of them
            | eventually would not.
            */
            'paid_days' => $this->paidDays(),
            'period_days' => $this->periodDays(),
            // Not the same as the days paid: a monthly employee is paid for
            // Sundays and did not work them, which is the line a workshop is
            // asked about most often.
            'attended_days' => $this->attendedDays(),

            'attendance' => $this->attendance ?? [],

            'gross' => Money::of($this->gross)->amount(),
            'advance_recovered' => Money::of($this->advance_recovered)->amount(),
            'net' => Money::of($this->net)->amount(),

            'notes' => $this->notes,

            // Only where the run was loaded — a payslip listed inside its own
            // run already knows which month it is, and a per-row query to learn
            // it would be a query per employee.
            'period' => $this->whenLoaded('run', fn () => $this->run?->periodKey()),
            'period_label' => $this->whenLoaded('run', fn () => $this->run?->periodLabel()),
            'run_status' => $this->whenLoaded('run', fn () => $this->run?->status->value),
        ];
    }
}
