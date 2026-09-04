<?php

namespace App\Http\Resources;

use App\Models\PayrollRun;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One month's payroll — M22.
 *
 * The totals come from whichever source the caller has already paid for: the
 * aggregate columns a list query selected, or the loaded lines on a single run.
 * Both are the same sum of the same rows, so neither can tell a different story
 * — and the alternative, summing the lines on every row of a list, would pull
 * four hundred payslips to print twelve numbers.
 *
 * @mixin PayrollRun
 */
class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totals = $this->totalsFor();

        return [
            'id' => $this->id,

            'period' => $this->periodKey(),
            'period_label' => $this->periodLabel(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'is_live' => $this->isLive(),

            'notes' => $this->notes,

            'gross' => $totals['gross'],
            'advance_recovered' => $totals['advance_recovered'],
            'net' => $totals['net'],
            'headcount' => $totals['headcount'],

            // The voucher, so a run links to the entries it made. `date` is the
            // day the money moved, which is not the month it pays for — a run
            // for August paid on 7 September is dated both, correctly.
            'transaction_id' => $this->transaction_id,
            'transaction' => $this->whenLoaded('transaction', fn () => $this->transaction === null ? null : [
                'id' => $this->transaction->id,
                'doc_no' => $this->transaction->doc_no,
                'date' => $this->transaction->date?->toDateString(),
                'status' => $this->transaction->status->value,
            ]),
            'paid_on' => $this->whenLoaded(
                'transaction',
                fn () => $this->transaction?->date?->toDateString(),
            ),

            // The payslips, only where they were loaded — the list does not want
            // them and the drawer does.
            'lines' => PayrollLineResource::collection($this->whenLoaded('lines')),

            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The month's totals, from whichever source has already been paid for.
     *
     * @return array{gross: string, advance_recovered: string, net: string, headcount: int}
     */
    private function totalsFor(): array
    {
        // A list query selected these as aggregates — see
        // EloquentPayrollRepository::paginateRuns().
        if ($this->resource->gross_total !== null && ! $this->resource->relationLoaded('lines')) {
            return [
                'gross' => Money::of($this->resource->gross_total)->amount(),
                'advance_recovered' => Money::of($this->resource->recovered_total ?? 0)->amount(),
                'net' => Money::of($this->resource->net_total ?? 0)->amount(),
                'headcount' => (int) ($this->resource->lines_count ?? 0),
            ];
        }

        return $this->resource->totals();
    }
}
