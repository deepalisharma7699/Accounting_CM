<?php

namespace App\Http\Resources;

use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workshop job, with its parts and its bills when they have been loaded.
 *
 * @mixin WorkshopJob
 */
class WorkshopJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What a human calls it — `JOB/26-27/41`. Unlike an invoice number
            // this exists from the moment the motor is booked in: there is no
            // draft state for a job, so numbering it early leaves no gap in the
            // series.
            'job_no' => $this->job_no,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // So a badge's colour is decided in one place rather than in each
            // screen that renders one — §38.
            'status_tone' => $this->status->tone(),
            // What the pipeline control offers, from the server rather than from
            // a copy in the browser that drifts the day a state is added.
            'next_states' => array_map(
                fn ($next) => ['value' => $next->value, 'label' => $next->label()],
                $this->status->nextStates(),
            ),
            'is_open' => $this->status->isOpen(),
            'is_billable' => $this->status->isBillable(),

            'party_id' => $this->party_id,
            'party' => $this->whenLoaded('party', fn () => $this->party === null ? null : [
                'id' => $this->party->id,
                'name' => $this->party->name,
                'phone' => $this->party->phone,
                'roles' => $this->party->roles ?? [],
            ]),

            /*
            | What came in — a motor, a cooler, a fan, or whatever else was
            | wheeled through the door.
            |
            | `equipment` is the one-line label a screen prints — "Motor 7.5 HP,
            | 3 ph · Crompton CR-1234" — built from whatever was recorded rather
            | than requiring all of it. The individual fields travel beside it
            | because an edit form needs them separately, and because a workshop
            | searching for a serial number needs the serial number.
            */
            'equipment' => $this->equipmentLabel(),
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => $this->item === null ? null : [
                'id' => $this->item->id,
                'name' => $this->item->name,
            ]),

            /*
            | Which kind of thing, and what its kind asked about it.
            |
            | Three keys over one fact, and each has exactly one reader.
            | `job_kind_id` is what the intake form's Kind select is set to and
            | what decides which fields it draws. `kind_label` is the name copied
            | at intake, which is what a list row prints without a join and what
            | survives the kind being renamed or archived. And `specs` is the
            | raw bag — the same flat shape `item_variants.attributes` holds — put
            | back into the form's inputs on an edit.
            |
            | `specs_display` is the same bag resolved through the kind that
            | asked: the label, the value and the unit, in the order the form
            | draws them. Absent where nothing resolved it rather than printed as
            | JSON keys at a counter — see WorkshopJob::resolvedSpecs().
            |
            | One key over two columns, deliberately. §4.6 means this code is
            | deployed before `job_kind_id` exists, and in that window a job's
            | kind is still a category — so the *client* is told one thing and
            | the fallback is decided here, rather than in `pages/jobs.js` where
            | it would be a second place that knows about the window.
            */
            'job_kind_id' => $this->job_kind_id ?? $this->category_id,
            'kind_label' => $this->kind_label,
            'specs' => (object) ($this->specs ?? []),
            'specs_display' => $this->resolvedSpecs(),

            'brand' => $this->brand,
            'model' => $this->model,
            'serial_no' => $this->serial_no,

            'complaint' => $this->complaint,
            'notes' => $this->notes,

            'received_date' => $this->received_date->toDateString(),
            'promised_date' => $this->promised_date?->toDateString(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            // Promised back by now and still on the bench. Computed here rather
            // than in each screen, so "late" means one thing across the product.
            'is_overdue' => $this->status->isOpen()
                && $this->promised_date !== null
                && $this->promised_date->isPast(),

            /*
            | The quotation — §18.
            |
            | Total before tax, and it says so wherever it is rendered: an estimate
            | is a conversation at a counter, not a document with a GST treatment.
            | The invoice raised from it is where the tax is worked out, on the
            | server, once.
            */
            'has_estimate' => $this->hasEstimate(),
            'estimate_total' => $this->estimateTotal()->amount(),
            'estimate_approved_at' => $this->estimate_approved_at?->toIso8601String(),
            'estimate_lines' => $this->when(
                $this->hasEstimate(),
                fn () => array_map(fn (array $line) => [
                    'item_id' => $line['item_id'] ?? null,
                    'variant_id' => $line['variant_id'] ?? null,
                    'description' => $line['description'] ?? '',
                    'quantity' => (string) ($line['quantity'] ?? '0'),
                    'unit' => $line['unit'] ?? null,
                    'unit_price' => Money::of($line['unit_price'] ?? 0)->amount(),
                    'discount' => Money::of($line['discount'] ?? 0)->amount(),
                    'memo' => $line['memo'] ?? null,
                ], $this->estimate_lines ?? []),
            ),

            /*
            | What went into the motor — §17.
            |
            | Loaded on a detail read and counted on a list, because a page of
            | twenty-five jobs does not need every bearing on every one of them to
            | print "4 parts".
            */
            'parts' => $this->whenLoaded('parts', fn () => $this->parts->map(
                fn (WorkshopJobPart $part) => [
                    'id' => $part->id,
                    'item_id' => $part->item_id,
                    'variant_id' => $part->variant_id,
                    'description' => $part->description,
                    'quantity' => $part->quantityValue()->amount(),
                    'unit' => $part->unit->value,
                    // Always sent beside the quantity, never on its own — §38.
                    'unit_symbol' => $part->unit->symbol(),
                    'unit_price' => $part->unitPriceMoney()->amount(),
                    'discount_amount' => $part->discountMoney()->amount(),
                    // Before tax, like the estimate and for the same reason.
                    'line_total' => $part->lineTotal()->amount(),
                    'memo' => $part->memo,
                    // Which invoice took it. What stops the same bearing being
                    // charged for twice, and what a screen greys out.
                    'is_billed' => $part->isBilled(),
                    'transaction_line_id' => $part->transaction_line_id,
                ]
            )->all()),
            'part_count' => $this->whenCounted('parts'),
            // What the next invoice off this job would carry, before tax. The
            // figure the "Generate bill" button is worth pressing for.
            'unbilled_total' => $this->whenLoaded(
                'parts',
                fn () => Money::sum($this->unbilledParts()->map(
                    fn (WorkshopJobPart $part) => $part->lineTotal()
                ))->amount(),
            ),

            /*
            | What has been billed off this job — attached by JobService, not
            | worked out here.
            |
            | It depends on rows a per-row serialiser cannot see, and going to look
            | for them would be a query per row of every listing in the module —
            | the same reason a bill's settlement is attached rather than computed.
            | The paid and due figures are BillService's, so a job's outstanding is
            | the same arithmetic the bills list shows rather than a second opinion
            | about one invoice.
            |
            | Absent where nothing computed it, which is honest: "nothing has been
            | billed" and "nobody asked" are different answers, and zero would
            | claim the first.
            */
            'billed' => $this->when($this->billed !== null, fn () => $this->billed),

            /*
            | How much of the job has reached an invoice — {@see JobBillingState}.
            |
            | A second signal beside the status, never folded into it: a job's
            | status is about the motor and this is about the money, and neither
            | implies the other. Sent with `billed` and absent without it, for the
            | same reason `billed` is — `unbilled` where nobody asked would be a
            | claim about the books rather than an admission that none was made.
            |
            | The tone travels with it so the badge's colour is decided in the
            | enum that owns the concept rather than in each screen that paints
            | one (§38).
            */
            'billing_state' => $this->when(
                $this->billed !== null,
                fn () => $this->billingState()?->value,
            ),
            'billing_state_label' => $this->when(
                $this->billed !== null,
                fn () => $this->billingState()?->label(),
            ),
            'billing_state_tone' => $this->when(
                $this->billed !== null,
                fn () => $this->billingState()?->tone(),
            ),
            'bills' => $this->whenLoaded('bills', fn () => $this->bills->map(fn ($bill) => [
                'id' => $bill->id,
                'doc_no' => $bill->doc_no,
                // What kind of document it is, so the job card can name it the
                // way the customer's copy does — "Invoice INV/26-27/12". The
                // relation already selects the column; printing it from here is
                // what saves the job card a read of the whole transaction.
                'type' => $bill->type->value,
                'type_label' => $bill->type->label(),
                'date' => $bill->date->toDateString(),
                'status' => $bill->status->value,
                'status_label' => $bill->status->label(),
                'total' => Money::of($bill->total)->amount(),
            ])->all()),

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
