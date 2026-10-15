<?php

namespace App\Services\Accounting;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Accounting\Tax\GstRate;
use App\Support\AmountInWords;
use App\Support\Money;
use App\Support\Quantity;

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

        $totals = $this->totals($transaction, $tax);

        return [
            'workshop' => $this->workshop($tenant),
            'document' => $this->document($transaction, $tenant),
            'customer' => $this->customer($transaction),
            'lines' => $this->lines($transaction),
            'totals' => $totals,
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
            'estimate' => $this->estimate($transaction, $totals, $settlement),
        ];
    }

    /* ---------------------------------------------------------------------
     | The estimate
     |-------------------------------------------------------------------- */

    /**
     * The same sale as the counter's plain receipt — the "kacha bill".
     *
     * What the customer is handed by default: what they bought and what they
     * paid, with every figure **including** the GST, so the lines add up to the
     * total in front of them. No workshop band, no number, no GSTIN, no HSN and
     * no tax breakup — the GST invoice is the document that carries those, and
     * it is printed from the same sale whenever somebody asks for it.
     *
     * Nothing is recorded differently. The tax is in the books exactly as it is
     * on the invoice; this is a second way of *showing* one posted sale, built
     * from its own short list of fields for the reason the class exists.
     *
     * The amount column is each line's stored total, which is what the totals
     * are summed from — so the column always adds up. The rate is the quoted
     * rate where the line was priced with the tax in it, and the rate plus its
     * tax where it was not, through {@see GstRate::taxOn()}: the same arithmetic
     * the posting engine priced the line with. On an awkward quantity it can be
     * the odd paisa off multiplying into the amount, as the invoice's rate can.
     *
     * Null on a credit note, which has no estimate to be.
     *
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>|null  $settlement
     * @return array<string, mixed>|null
     */
    private function estimate(Transaction $transaction, array $totals, ?array $settlement): ?array
    {
        if ($transaction->type->isReturn()) {
            return null;
        }

        $lines = $this->withLabourCombined($transaction, $transaction->lines->map(fn (TransactionLine $line) => [
            'line_no' => $line->line_no,
            'description' => $line->description,
            'memo' => $line->memo,
            'quantity' => $line->quantityValue()->amount(),
            'unit_symbol' => $line->unit->symbol(),
            'rate' => $this->withTax($line, $line->unitPriceMoney())->amount(),
            'discount' => $this->withTax($line, $line->discountMoney())->amount(),
            'amount' => $line->totalMoney()->amount(),
        ])->all(), function (array $rows) {
            $amount = $this->sumOf($rows, 'amount');
            $discount = $this->sumOf($rows, 'discount');

            return [
                'memo' => null,
                'quantity' => Quantity::of('1')->amount(),
                'unit_symbol' => '',
                // So the row multiplies out: one, at this, less the discount, is the amount.
                'rate' => $amount->plus($discount)->amount(),
                'discount' => $discount->amount(),
                'amount' => $amount->amount(),
            ];
        });

        return [
            'date' => $transaction->date->toDateString(),
            'lines' => $lines,
            'subtotal' => Money::sum($transaction->lines->map(fn ($line) => $line->totalMoney()))->amount(),
            'round_off' => $totals['round_off'],
            'total' => $totals['total'],
            'in_words' => $totals['in_words'],
            'paid' => $settlement['paid'] ?? null,
            'due' => $settlement['due'] ?? null,
        ];
    }

    /**
     * An amount from a line, stated with its GST in — the mirror of
     * {@see beforeTax()}.
     */
    private function withTax(TransactionLine $line, Money $amount): Money
    {
        return $line->price_includes_tax ? $amount : $amount->plus($line->rate()->taxOn($amount));
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
        return $this->withLabourCombined($transaction, $transaction->lines->map(fn ($line) => [
            'line_no' => $line->line_no,
            'description' => $line->description,
            'hsn_sac' => $line->hsn_sac,
            'quantity' => $line->quantityValue()->amount(),
            'unit_symbol' => $line->unit->symbol(),
            /*
            | The rate **before** GST, always — including on a line whose price
            | was quoted with the tax in it.
            |
            | A tax invoice's rate column is a rate excluding tax; it sits beside
            | a taxable value and a tax column and has to be the same kind of
            | figure as the one it explains. Printing the ₹118 that was typed
            | next to a taxable value of ₹100 gives the recipient's accounts
            | department a row that does not multiply out, on the one document
            | whose whole job is to be their evidence for an input tax credit.
            |
            | So the quoted figure is converted here rather than carried through.
            | The **taxable value is the authoritative column** — it is what the
            | return is filed on and it is exact — and on an awkward quantity the
            | rate can be the odd paisa off multiplying into it, which is inherent
            | to quoting inclusive and is why the two are not derived from each
            | other. The customer's total is unaffected either way.
            |
            | The discount is converted with it, so both sides of the subtraction
            | are on the basis the column heading claims.
            */
            'unit_price' => $this->beforeTax($line, $line->unitPriceMoney())->amount(),
            'discount_amount' => $this->beforeTax($line, $line->discountMoney())->amount(),
            'taxable_value' => $line->taxableMoney()->amount(),
            'gst_rate' => (string) $line->gst_rate,
            'cgst_amount' => (string) $line->cgst_amount,
            'sgst_amount' => (string) $line->sgst_amount,
            'igst_amount' => (string) $line->igst_amount,
            'line_total' => $line->totalMoney()->amount(),
            'memo' => $line->memo,
        ])->all(), function (array $rows) {
            $taxable = $this->sumOf($rows, 'taxable_value');
            $discount = $this->sumOf($rows, 'discount_amount');

            return [
                'quantity' => Quantity::of('1')->amount(),
                'unit_symbol' => '',
                // Before tax, like every rate on this document, and chosen so the
                // row multiplies out: one, at this, less the discount, is the
                // taxable value — which is the lines' own, added up.
                'unit_price' => $taxable->plus($discount)->amount(),
                'discount_amount' => $discount->amount(),
                'taxable_value' => $taxable->amount(),
                'cgst_amount' => $this->sumOf($rows, 'cgst_amount')->amount(),
                'sgst_amount' => $this->sumOf($rows, 'sgst_amount')->amount(),
                'igst_amount' => $this->sumOf($rows, 'igst_amount')->amount(),
                'line_total' => $this->sumOf($rows, 'line_total')->amount(),
                'memo' => null,
            ];
        });
    }

    /* ---------------------------------------------------------------------
     | Labour as one row
     |-------------------------------------------------------------------- */

    /**
     * The rows, with the sale's labour drawn as one "Labour charges" row where
     * the counter asked for that — otherwise exactly as given.
     *
     * Only how the document is *drawn*. The lines, the ledger, the GST and every
     * internal screen keep each task, so the workshop still sees what earned it.
     *
     * Three rules keep this a tax invoice rather than a summary of one:
     *
     * - **Labour is a service that took nothing off a shelf.** A rewind consumed
     *   copper through its recipe and keeps its own row; a part bought to order
     *   holds no stock either, but it is goods, and goods never print as labour.
     * - **One row per SAC code and GST rate.** A row of a tax invoice carries one
     *   of each, so two rates make two rows rather than one row that is wrong.
     * - **Figures are added up, never worked out again.** Tax recomputed on the
     *   combined amount can land a paisa away from what the lines posted, and
     *   then the document disagrees with the books behind it.
     *
     * The decision is the one stored on the transaction when it was posted, so a
     * reprint next year draws the same document the customer was handed.
     *
     * @param  array<int, array<string, mixed>>  $rows  one per line, in line order
     * @param  callable(array<int, array<string, mixed>>): array<string, mixed>  $merge
     * @return array<int, array<string, mixed>>
     */
    private function withLabourCombined(Transaction $transaction, array $rows, callable $merge): array
    {
        if (! $transaction->combine_labour) {
            return $rows;
        }

        $transaction->lines->loadMissing('item.category');

        $groupOf = $transaction->lines
            ->filter(fn (TransactionLine $line) => ! $line->is_stock
                && $line->stockMovements->isEmpty()
                && $line->item?->isService())
            ->mapWithKeys(fn (TransactionLine $line) => [
                $line->line_no => ($line->hsn_sac ?? '').'|'.$line->gst_rate,
            ]);

        // A lone task has nothing to be combined with, and keeps its own name.
        $sizes = $groupOf->countBy();
        $groupOf = $groupOf->filter(fn (string $key) => $sizes->get($key) > 1);

        if ($groupOf->isEmpty()) {
            return $rows;
        }

        $groups = [];

        foreach ($rows as $row) {
            if (($key = $groupOf->get($row['line_no'])) !== null) {
                $groups[$key][] = $row;
            }
        }

        $drawn = [];

        foreach ($rows as $row) {
            $key = $groupOf->get($row['line_no']);

            if ($key === null) {
                $drawn[] = $row;
            } elseif (isset($groups[$key])) {
                // Where the first of them stood, so the order still reads as written.
                $drawn[] = array_merge($row, ['description' => 'Labour charges'], $merge($groups[$key]));
                unset($groups[$key]);
            }
        }

        // Numbered as printed: a gap from 2 to 5 reads as rows torn off the page.
        return array_map(
            fn (array $row, int $index) => ['line_no' => $index + 1] + $row,
            $drawn,
            array_keys($drawn),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumOf(array $rows, string $key): Money
    {
        return Money::sum(array_map(fn (array $row) => Money::of($row[$key]), $rows));
    }

    /**
     * An amount from a line, stated before tax.
     *
     * The line's own figure where it was quoted before tax already, and the
     * extracted base where it was not. `GstRate::baseWithin()` is the one place
     * that arithmetic lives — the same call the posting engine made when it
     * priced this line, so the document cannot disagree with the ledger behind
     * it about what the tax was.
     */
    private function beforeTax(TransactionLine $line, Money $amount): Money
    {
        return $line->price_includes_tax ? $line->rate()->baseWithin($amount) : $amount;
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
