<?php

namespace App\Services\Accounting;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Support\AmountInWords;
use App\Support\Money;

/**
 * The invoice as a document — what is printed, and what the customer is shown.
 *
 * ## Why this is not TransactionResource
 *
 * Because the audiences are different, and one of them is outside the workshop.
 * {@see \App\Http\Resources\TransactionResource} answers "what is this
 * transaction" for somebody holding a grant: it carries the cost of every line,
 * the margin, whether the sale went out below cost, the ledger entries and the
 * stock movements. None of that may reach a customer, and the way to be sure it
 * never does is not to add a flag to that resource deciding when to omit them —
 * it is to build the customer's document from a different list of fields, so
 * that adding a field to the internal one cannot leak it.
 *
 * That is the whole reason this class exists. `cost`, `margin`, `below_cost`,
 * `entries` and `movements` are absent, and there is no branch in this file that
 * could include them.
 *
 * ## One shape, two readers
 *
 * The identical array is returned to the drawer (`GET /transactions/{id}/invoice`,
 * which is what Print renders) and embedded in the public page at `/i/{token}`.
 * It has to be identical: the copy the workshop prints and the copy the customer
 * opens are the same document, and a difference between them is a dispute.
 */
class InvoiceDocumentService
{
    public function __construct(
        private readonly BillService $bills,
        private readonly TenantRepositoryInterface $tenants,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Transaction $transaction, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenants->findById((int) $transaction->tenant_id);

        // The lines the *document* carries — M9 — as opposed to what it did to
        // the books. Loaded here rather than assumed, because both callers reach
        // this holding a bare transaction.
        if (! $transaction->relationLoaded('lines')) {
            $transaction->setRelation('lines', $this->bills->linesFor($transaction));
        }

        /*
        | The party, in full, and unconditionally.
        |
        | Not `if (! relationLoaded(...))`, and that is not belt and braces. The
        | repository behind `TransactionService::find()` loads `party:id,name,
        | roles` — a *column-limited* eager load, which is right for a voucher
        | screen and wrong here. The relation reports itself as loaded, every
        | column this document needs comes back null, and the invoice prints
        | without the customer's GSTIN: a document that looks complete, is not,
        | and denies the recipient their input tax credit.
        |
        | One query, on a page that is about one document. Cheap, against a
        | failure nothing on the screen would show.
        */
        $transaction->load('party');

        if (! $transaction->relationLoaded('payments')) {
            $transaction->load('payments');
        }

        $tax = $this->bills->taxSummaryFor($transaction);
        $settlement = $this->bills->settlementFor($transaction);

        return [
            'workshop' => $this->workshop($tenant),
            'document' => $this->document($transaction, $tenant),
            'customer' => $this->customer($transaction),
            'lines' => $this->lines($transaction),
            'totals' => $this->totals($transaction, $tax),
            'received' => $this->received($transaction),
            'settlement' => $settlement === null ? null : [
                'paid' => $settlement['paid'],
                'credited' => $settlement['credited'],
                'due' => $settlement['due'],
                'status' => $settlement['status']->value,
                'status_label' => $settlement['status']->label(),
                'status_tone' => $settlement['status']->tone(),
                'due_date' => $settlement['due_date'],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The two parties
     |-------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function workshop(?Tenant $tenant): array
    {
        return [
            'name' => $tenant?->name ?? '',
            'gstin' => $tenant?->gstin,
            'address' => $tenant?->address,
            'state_code' => $tenant?->state_code,
        ];
    }

    /**
     * The customer's copy of their own details.
     *
     * Null where the document has no counterparty at all. A counter sale to
     * somebody who did not give a name is a legitimate invoice — it simply has
     * no "Billed to" block, and inventing "Cash Customer" would put a party on a
     * document that has none.
     *
     * @return array<string, mixed>|null
     */
    private function customer(Transaction $transaction): ?array
    {
        $party = $transaction->party;

        if ($party === null) {
            return null;
        }

        return [
            'name' => $party->name,
            'gstin' => $party->gstin,
            'address' => $party->address,
            'state_code' => $party->state_code,
            'phone' => $party->phone,
            // Deliberately no `notes`. A workshop's private note about a
            // customer — "pays late", "argues about labour" — lives on the party
            // record, and this document is handed to that customer.
        ];
    }

    /* ---------------------------------------------------------------------
     | The document
     |-------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function document(Transaction $transaction, ?Tenant $tenant): array
    {
        return [
            'id' => (int) $transaction->id,
            'type' => $transaction->type->value,
            'heading' => $this->headingFor($transaction, $tenant),
            'doc_no' => $transaction->doc_no,
            'date' => $transaction->date->toDateString(),
            'notes' => $transaction->notes,
            // Which invoice a credit note takes back, so a customer holding both
            // can match them. Null on an invoice.
            'against_transaction_id' => $transaction->against_transaction_id,
        ];
    }

    /**
     * What the page calls itself.
     *
     * "Tax Invoice" rather than "Sale", because that is the phrase the document
     * has to carry: it is the recipient's evidence for an input tax credit, and
     * a page headed "Sale" is not that. A workshop with no GSTIN is making no
     * taxable supply and its document is a **Bill of Supply** — printing "Tax
     * Invoice" over it would be a claim it is not entitled to make, on a page it
     * hands to somebody who may act on it.
     */
    private function headingFor(Transaction $transaction, ?Tenant $tenant): string
    {
        if ($transaction->type->isReturn()) {
            return 'Credit Note';
        }

        return $tenant?->gstin === null ? 'Bill of Supply' : 'Tax Invoice';
    }

    /**
     * The lines, with every internal figure left out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(Transaction $transaction): array
    {
        return $transaction->lines->map(fn ($line) => [
            'line_no' => $line->line_no,
            'description' => $line->description,
            'hsn_sac' => $line->hsn_sac,
            'quantity' => $line->quantityValue()->amount(),
            'unit_symbol' => $line->unit->symbol(),
            'unit_price' => $line->unitPriceMoney()->amount(),
            'discount_amount' => $line->discountMoney()->amount(),
            'taxable_value' => $line->taxableMoney()->amount(),
            'gst_rate' => (string) $line->gst_rate,
            'cgst_amount' => (string) $line->cgst_amount,
            'sgst_amount' => (string) $line->sgst_amount,
            'igst_amount' => (string) $line->igst_amount,
            'line_total' => $line->totalMoney()->amount(),
            'memo' => $line->memo,
        ])->all();
    }

    /* ---------------------------------------------------------------------
     | The figures
     |-------------------------------------------------------------------- */

    /**
     * @param  array{taxable: string, cgst: string, sgst: string, igst: string, tax: string, total: string, inter_state: bool}  $tax
     * @return array<string, mixed>
     */
    private function totals(Transaction $transaction, array $tax): array
    {
        $discount = Money::sum($transaction->lines->map(fn ($line) => $line->discountMoney()));

        // What the lines came to before anything was taken off. Reconstructed
        // rather than stored, for the same reason there is no `bill_discount`
        // column: the lines already carry it, and a second copy is a second
        // thing to be wrong.
        $gross = Money::of($tax['taxable'])->plus($discount);

        /*
        | The rounding, restated from two figures that already exist.
        |
        | `transactions.total` is what the customer was charged — the posting
        | engine's `documentTotal()`, rounded when the workshop rounds. The tax
        | summary's total is the lines added up, which never is. The difference
        | is by definition the round-off line, so nothing here has to know
        | whether the setting was on, and a document posted before it was
        | switched on still prints correctly years later.
        */
        $charged = Money::of($transaction->total);
        $roundOff = $charged->minus(Money::of($tax['total']));

        return [
            'gross' => $gross->amount(),
            'discount' => $discount->amount(),
            'taxable' => $tax['taxable'],
            'cgst' => $tax['cgst'],
            'sgst' => $tax['sgst'],
            'igst' => $tax['igst'],
            'tax' => $tax['tax'],
            'inter_state' => $tax['inter_state'],
            'round_off' => $roundOff->amount(),
            'total' => $charged->amount(),
            'in_words' => AmountInWords::rupees($charged),
        ];
    }

    /**
     * What was handed over at the counter when the document was written.
     *
     * The document's own split, not everything ever settled against it: a
     * customer reading their copy is looking for "I paid ₹2,000 cash that day",
     * and later receipts are their own documents with their own numbers. The
     * running position is `settlement`, above.
     *
     * @return array<int, array<string, string|null>>
     */
    private function received(Transaction $transaction): array
    {
        return $transaction->payments->map(fn ($payment) => [
            'mode' => $payment->mode->value,
            'mode_label' => $payment->mode->label(),
            'amount' => $payment->amountMoney()->amount(),
            'reference' => $payment->reference,
        ])->all();
    }
}
