<?php

namespace App\Http\Resources;

use App\Enums\PaymentMode;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transaction, with its lines when they have been loaded.
 *
 * The `lines` key is deliberately the same shape whether the transaction is a
 * draft or is posted — written entries in one case, intended ones in the other
 * — so a client renders a voucher without branching on its status. The `status`
 * field says which it is looking at.
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What a human calls it — `INV/26-27/1012`. Null on a draft, which
            // has not earned one: a number that could be discarded is a gap in
            // the series somebody has to explain. M16.
            'doc_no' => $this->doc_no,
            // Echoed back so a client can confirm the document it is holding is
            // the one its retry was about — M17. Null for anything created
            // server-side, which legitimately had no client to name it.
            'client_ref' => $this->client_ref,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),

            'date' => $this->date->toDateString(),
            'total' => Money::of($this->total)->amount(),
            'notes' => $this->notes,

            /*
            | What was settled on this document, and what is left on it — M16.
            |
            | Two figures add up to `paid`, and they are different things: money
            | taken at the counter when the bill was written, which lives on the
            | document's own split, and every later receipt allocated to it. Until
            | `transaction_allocations` existed only the first was knowable, so
            | this key meant far less than its name — a thirty-day invoice paid by
            | cheque read as unpaid for ever. It now means what it says.
            |
            | Computed by BillService and attached by TransactionService, not
            | worked out here: it depends on rows a per-row serialiser cannot see,
            | and going to look for them would be a query per row of every listing
            | in the application.
            |
            | Absent for anything that is not a posted bill — a draft, a reversed
            | invoice, a receipt, a journal. Zero there would read as "nothing has
            | been paid", which invites somebody to chase it.
            */
            'paid' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['paid'],
            ),
            // What returns have taken back off it — M18. Beside `paid` rather
            // than folded into it, because nobody handed over any money: the
            // goods came back. Both reduce what is due.
            'credited' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['credited'],
            ),
            'due' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['due'],
            ),
            'payment_status' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['status']->value,
            ),
            'payment_status_label' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['status']->label(),
            ),
            // So a badge's colour is decided in one place rather than in each
            // screen that renders one — §38.
            'payment_status_tone' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['status']->tone(),
            ),
            // When the workshop's own terms say it should have been settled.
            // Null where it has set none, and then nothing is ever overdue.
            'due_date' => $this->when(
                $this->settlement !== null,
                fn () => $this->settlement['due_date'],
            ),

            /*
            | Money taken on the document itself, kept separate from `paid`.
            |
            | The distinction matters at the counter: "₹2,000 of this ₹5,000 bill
            | was handed over when it was written" is a different fact from
            | "₹2,000 of it has been settled since", and a screen printing a
            | receipt needs the first.
            |
            | Null for types that cannot carry a split at all — a journal, a stock
            | adjustment, an opening balance.
            */
            'paid_on_document' => $this->when(
                $this->type->acceptsPaymentSplit(),
                fn () => $this->paidOnDocument()->amount(),
            ),

            // The id is always present so a form can round-trip it; the name
            // only when the relation was loaded, so a listing does not issue a
            // query per row to learn it.
            'party_id' => $this->party_id,
            'party' => $this->whenLoaded('party', fn () => $this->party === null ? null : [
                'id' => $this->party->id,
                'name' => $this->party->name,
                'roles' => $this->party->roles ?? [],
            ]),

            // The employee a staff advance was handed to — M22, and the same
            // rule as the party above: the id always, the name only where the
            // relation was loaded. Set on `staff_advance` and on nothing else; a
            // payroll run pays everybody at once and names none of them here.
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee === null ? null : [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
            ]),

            /*
            | Who did the work — M22, and on a sale only.
            |
            | The workshop's own record, and it stays that way. It is not on the
            | customer's copy of the invoice and must never be: whose hands were
            | on the motor is the workshop's business, and a customer who learns
            | that the apprentice wound it has been handed an argument about the
            | price. `InvoiceDocumentService` builds the customer's document from
            | its own list of fields for precisely this reason — so there is no
            | branch anywhere that could let this through.
            |
            | Absent where nothing attached it, rather than an empty array: a
            | listing that did not ask should not read as forty invoices with
            | nobody on them. See Transaction::$staffAttribution.
            */
            'staff' => $this->when(
                $this->staffAttribution !== null,
                fn () => $this->staffAttribution->map(fn ($row) => [
                    'designation_id' => (int) $row->designation_id,
                    'designation' => $row->designation?->name,
                    'employee_id' => (int) $row->employee_id,
                    'employee' => $row->employee?->name,
                ])->values()->all(),
            ),

            'is_draft' => $this->isDraft(),
            'is_editable' => $this->status->isEditable(),

            // Which way a reversal points, both ways. A client showing a
            // transaction needs to say either "reversed by #12" or "this
            // reverses #7", and neither is derivable from the other end.
            'reverses_id' => $this->reverses_id,
            'reversal_id' => $this->whenLoaded('reversal', fn () => $this->reversal?->id),

            // The bill a credit note takes part of back — M18, and never set
            // alongside `reverses_id`. Cancelling a document and crediting part
            // of one are different acts, and a row claiming both would be
            // readable as neither.
            'against_transaction_id' => $this->against_transaction_id,

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            /*
            | When the record was last written to. Only interesting for a draft,
            | and interesting *because* it is a draft: a worklist is read by what
            | has gone stale, and "started three weeks ago" and "edited an hour
            | ago" are different situations that `created_at` cannot tell apart.
            |
            | Sent for everything rather than only drafts, because a posted
            | transaction's is equally true — it simply stops moving, which is
            | what a posted transaction does.
            */
            'updated_at' => $this->updated_at?->toIso8601String(),

            'lines' => $this->when(
                $this->relationLoaded('entries') || $this->isDraft(),
                fn () => $this->lines(),
            ),
            // Always present, because a list needs it and a list does not load
            // the lines themselves — the repository counts them instead.
            //
            // This is the count of *ledger entries* — a purchase of one item at
            // 18% is three of them, Dr Inventory / Dr GST Input / Cr Payables.
            // It is what the Journal means by a line, and it is not what a
            // purchase list means: see `item_line_count` below.
            'line_count' => $this->lineCount(),

            // The count of rows on the document itself — what somebody scanning
            // a bill list is counting when they say "lines". Null where it was
            // not asked for, rather than zero, for the same reason as above.
            'item_line_count' => $this->documentLineCount(),

            // How the money moved, for a payment or a receipt. Same shape whether
            // the transaction is a draft or is posted, exactly as `lines` is, so
            // a client renders the voucher without branching on its status.
            'payments' => $this->when(
                $this->type->acceptsPaymentSplit() && ($this->relationLoaded('payments') || $this->isDraft()),
                fn () => $this->settlementSplit(),
            ),

            // The document itself — M9. What was billed, as distinct from what it
            // did to the books, and the only place the CGST/SGST/IGST split
            // exists: Phase 1 has one GST account, so the ledger carries the
            // total and the split lives here or nowhere.
            'items' => $this->when(
                $this->type->hasDocumentLines()
                    && ($this->relationLoaded('lines') || $this->isDraft()),
                fn () => $this->documentLines(),
            ),

            // What quantities moved — M8. Posted transactions only, and that is
            // not an omission: a draft has no movements anywhere, because stock
            // is valued at the weighted average *at the moment of posting* and a
            // draft that carried a fortnight-old valuation would be showing a
            // number that was true once.
            'movements' => $this->when(
                $this->type->movesStock() && $this->relationLoaded('stockMovements'),
                fn () => $this->stockMovements->map(fn ($movement) => [
                    'id' => $movement->id,
                    'variant_id' => $movement->variant_id,
                    'item_id' => $movement->item_id,
                    'type' => $movement->type->value,
                    'type_label' => $movement->type->label(),
                    // Signed, exactly as stored — the direction is the sign.
                    'quantity' => $movement->quantityValue()->amount(),
                    'unit_cost' => $movement->unitCostMoney()->amount(),
                    'value' => $movement->valueMoney()->amount(),
                    'memo' => $movement->memo,
                ])->all(),
            ),
        ];
    }

    /**
     * The bill's own lines.
     *
     * A draft's are echoed back from the request it stored, **unpriced and
     * untaxed** — because they have not been priced or taxed yet, and a draft
     * that showed a total would be showing one that will change the moment it is
     * posted. That is deliberate rather than an omission: cost of goods sold is
     * the weighted average at the moment of posting, and the tax follows a rate
     * the workshop may still correct.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentLines(): array
    {
        if ($this->isDraft()) {
            return array_map(fn (array $line, int $index) => [
                'line_no' => $index + 1,
                'item_id' => $line['item_id'] ?? null,
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => (string) ($line['quantity'] ?? '0'),
                'unit_price' => Money::of($line['unit_price'] ?? 0)->amount(),
                'discount_amount' => Money::of($line['discount'] ?? 0)->amount(),
                'memo' => $line['memo'] ?? null,
                // Not yet computed, and saying so beats sending a zero that would
                // be read as "no tax".
                'taxable_value' => null,
                'line_total' => null,
            ], $this->draft_payload['items'] ?? [], array_keys($this->draft_payload['items'] ?? []));
        }

        return $this->lines->map(fn ($line) => [
            'id' => $line->id,
            'line_no' => $line->line_no,
            'item_id' => $line->item_id,
            'variant_id' => $line->variant_id,
            'description' => $line->description,

            'quantity' => $line->quantityValue()->amount(),
            'unit' => $line->unit->value,
            'unit_symbol' => $line->unit->symbol(),
            'unit_price' => $line->unitPriceMoney()->amount(),
            'discount_amount' => $line->discountMoney()->amount(),

            'hsn_sac' => $line->hsn_sac,
            'gst_rate' => (string) $line->gst_rate,
            'cgst_amount' => (string) $line->cgst_amount,
            'sgst_amount' => (string) $line->sgst_amount,
            'igst_amount' => (string) $line->igst_amount,

            'taxable_value' => $line->taxableMoney()->amount(),
            'tax_amount' => $line->taxMoney()->amount(),
            'line_total' => $line->totalMoney()->amount(),

            'is_stock' => $line->is_stock,

            // Null rather than zero where the question does not apply: labour has
            // no cost of goods, and reporting ₹0 would claim a 100% margin on the
            // workshop's most valuable work.
            'cost' => $line->cost()?->amount(),
            'margin' => $line->margin()?->amount(),
            'below_cost' => $line->isBelowCost(),

            'memo' => $line->memo,
        ])->all();
    }

    /**
     * The document's own settled amount, from whichever copy of the split
     * actually exists for it.
     *
     * Three sources. A draft's intended split lives in `draft_payments` as JSON
     * until it is posted; a listing and a voucher read both carry the rows
     * themselves; and a caller that asked for neither may still have added the
     * sum as a subquery, which is honoured so this stays correct if one does.
     *
     * A draft's figure is what the user *said* they were taking, not what was
     * taken — nothing has reached the ledger yet. That is the same caveat the
     * draft's own lines carry, and the `is_draft` flag is what says so.
     */
    private function paidOnDocument(): Money
    {
        if ($this->isDraft()) {
            return Money::sum(array_map(
                fn (array $split) => Money::of($split['amount'] ?? 0),
                $this->draft_payments ?? [],
            ));
        }

        if ($this->relationLoaded('payments')) {
            return Money::sum($this->payments->map(fn ($payment) => $payment->amountMoney()));
        }

        if ($this->payments_sum_amount !== null) {
            return Money::of($this->payments_sum_amount);
        }

        // Neither the sum nor the rows were fetched. Zero would be a claim; this
        // is the one case where the honest answer is that nothing is known, and
        // the caller sees it as an absent figure rather than a settled one.
        return Money::zero();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function settlementSplit(): array
    {
        if ($this->isDraft()) {
            return array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'mode_label' => PaymentMode::tryFrom((string) ($split['mode'] ?? ''))?->label(),
                'amount' => Money::of($split['amount'] ?? 0)->amount(),
                'reference' => $split['reference'] ?? null,
            ], $this->draft_payments ?? []);
        }

        return $this->payments->map(fn ($payment) => [
            'id' => $payment->id,
            'line_no' => $payment->line_no,
            'mode' => $payment->mode->value,
            'mode_label' => $payment->mode->label(),
            'amount' => $payment->amountMoney()->amount(),
            'reference' => $payment->reference,
        ])->all();
    }

    /**
     * Null only when neither the lines nor a count of them was fetched, which
     * an honest payload should say rather than reporting zero.
     */
    private function lineCount(): ?int
    {
        if ($this->isDraft()) {
            return count($this->draft_lines ?? []);
        }

        if ($this->relationLoaded('entries')) {
            return $this->entries->count();
        }

        return $this->entries_count === null ? null : (int) $this->entries_count;
    }

    /**
     * How many rows the *document* carries — items on a bill, not postings in
     * the ledger.
     *
     * Reported apart from {@link lineCount} rather than instead of it, because
     * the two questions are both real and have different answers: the Journal
     * asks how many entries a transaction made, and a purchase list asks how
     * many things were bought. A single-item bill answers 3 and 1, and a list
     * that printed the first under a heading meaning the second read one higher
     * than the rows the document itself showed.
     */
    private function documentLineCount(): ?int
    {
        if ($this->isDraft()) {
            return count($this->draft_lines ?? []);
        }

        if ($this->relationLoaded('lines')) {
            return $this->lines->count();
        }

        return $this->lines_count === null ? null : (int) $this->lines_count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        if ($this->isDraft()) {
            return array_map(fn (array $line) => [
                'account_id' => (int) $line['account_id'],
                'account' => null,
                'debit' => Money::of($line['debit'])->amount(),
                'credit' => Money::of($line['credit'])->amount(),
                'memo' => $line['memo'] ?? null,
            ], $this->draft_lines ?? []);
        }

        return $this->entries->map(fn ($entry) => [
            'id' => $entry->id,
            'line_no' => $entry->line_no,
            'account_id' => $entry->account_id,
            'account' => $entry->relationLoaded('account') && $entry->account !== null
                ? [
                    'id' => $entry->account->id,
                    'code' => $entry->account->code,
                    'name' => $entry->account->name,
                    'type' => $entry->account->type->value,
                ]
                : null,
            'debit' => $entry->debitMoney()->amount(),
            'credit' => $entry->creditMoney()->amount(),
            'memo' => $entry->memo,
        ])->all();
    }
}
