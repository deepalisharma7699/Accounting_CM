<?php

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Models\ItemVariant;
use App\Services\Accounting\Posting\BillLine;
use App\Services\Accounting\Posting\CarriesDocumentLines;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\PostingTemplateRegistry;
use App\Services\Accounting\Posting\StatesItsOwnTotal;
use App\Services\Accounting\Tax\GstBreakdown;
use App\Services\Inventory\StockLedgerService;
use App\Support\Money;
use App\Support\Quantity;

/**
 * What a bill will come to, before anybody commits to it — M17, and the brief's
 * §12.
 *
 * The confirmation screen is supposed to show the operator what they are about
 * to post. It could compute that in the browser, and a great many products do —
 * which is how a customer comes to be shown ₹11,800 and charged ₹11,799.99,
 * because JavaScript numbers are floats and the server's are not. Everything
 * here goes through the same {@see BillLine} the posting would build, so the
 * confirmation is the server's arithmetic rather than a second implementation of
 * it that agrees most of the time.
 *
 * ## Writes nothing, locks nothing
 *
 * A preview is advisory by nature. It takes no row locks — holding them across
 * somebody reading a screen would be far worse than a preview a concurrent sale
 * moved by a rupee — and it is honest about the consequence: the stock figures
 * it reports are true at the instant it answers and are re-checked, under a
 * lock, when the bill is actually posted.
 *
 * ## Why the stock refusals are collected rather than thrown
 *
 * Because the operator wants to see all of them. {@see StockLedgerService::issue()}
 * refuses on the first shortfall, which is right at the moment of posting and
 * wrong here: somebody correcting a six-line bill should be told about both
 * lines that are short, not told about the first, fix it, and be told about the
 * second.
 *
 * They are also accumulated **per variant across lines**, which the per-issue
 * check cannot see: two lines of three bearings each are six bearings, and a
 * bill for six out of a shelf of five is short whatever either line says on its
 * own.
 */
class BillPreviewService
{
    public function __construct(
        private readonly PostingTemplateRegistry $templates,
        private readonly StockLedgerService $stock,
    ) {}

    /**
     * Price a bill payload without posting it.
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *     type: string,
     *     lines: array<int, array<string, mixed>>,
     *     totals: array{gross: string, discount: string, taxable: string, cgst: string, sgst: string, igst: string, tax: string, round_off: string, total: string, inter_state: bool},
     *     stock: array<int, array<string, mixed>>,
     *     can_post: bool
     * }
     *
     * @throws InvalidJournalException
     */
    public function preview(TransactionType $type, array $input): array
    {
        $template = $this->templates->for($type);

        if (! $template instanceof CarriesDocumentLines) {
            throw InvalidJournalException::noTemplate($type->value);
        }

        // The same call the posting engine makes, on the same payload — which is
        // what makes the confirmation and the invoice the one computation. Note
        // that it deliberately does *not* build the ledger lines: those need the
        // cost of goods sold, which needs a lock, which a preview must not take.
        $lines = $template->documentLinesFrom($input);
        $shortfalls = $this->shortfallsIn($lines, $type);

        return [
            'type' => $type->value,
            'lines' => array_map(fn (BillLine $line) => $this->lineRow($line), $lines),
            /*
            | The template is handed in rather than the totals being finished
            | here, because it is the one that knows what the document is
            | *charged* at — the workshop may round to the rupee, and that
            | decision belongs beside the posting that books the difference.
            | Asking it here is what keeps the confirmation and the invoice from
            | disagreeing by a rupee.
            */
            'totals' => $this->totalsOf($lines, $template, $input),
            'stock' => $shortfalls,
            // What the confirmation button reads. False only where the workshop
            // has said it will not bill stock it does not have — under the
            // permissive setting the shortfalls are still reported, because
            // somebody should see them, and the bill still posts.
            'can_post' => $shortfalls === [] || $this->stock->allowsNegativeStock(),
        ];
    }

    /**
     * @param  array<int, BillLine>  $lines
     * @param  array<string, mixed>  $input
     * @return array{gross: string, discount: string, taxable: string, cgst: string, sgst: string, igst: string, tax: string, round_off: string, total: string, inter_state: bool}
     */
    private function totalsOf(array $lines, PostingTemplate $template, array $input): array
    {
        $totals = GstBreakdown::totals(array_map(fn (BillLine $line) => $line->tax, $lines));
        $gross = Money::sum(array_map(fn (BillLine $line) => $line->gross(), $lines));

        /*
        | What the party is asked for, which is not always what the lines add up
        | to: a workshop that rounds charges ₹1,062 for ₹1,062.36 of goods and
        | tax. Taken from the template — the same method the posting engine uses
        | to fill `transactions.total` — rather than recomputed, so there is one
        | answer to "what does this bill come to" and both screens read it.
        */
        $charged = $template instanceof StatesItsOwnTotal
            ? $template->documentTotal($input)
            : $totals['total'];

        return [
            // What the lines came to before anything was taken off, so a panel
            // showing a discount can show what it was a discount from.
            'gross' => $gross->amount(),
            /*
            | Everything that came off — a line's own discount and its share of
            | one taken off the bill, which by this point are the same figure and
            | deliberately so (see BillDiscount).
            |
            | Read as gross less taxable rather than by summing the lines'
            | discounts, because a discount larger than the line it is on is
            | clamped: summing what was *asked for* would report a reduction
            | bigger than the bill, against a taxable value that only fell to
            | zero.
            */
            'discount' => $gross->minus($totals['taxable'])->amount(),
            'taxable' => $totals['taxable']->amount(),
            'cgst' => $totals['cgst']->amount(),
            'sgst' => $totals['sgst']->amount(),
            'igst' => $totals['igst']->amount(),
            'tax' => $totals['tax']->amount(),
            // Between −0.49 and +0.50, and zero for a workshop that does not
            // round. Reported separately so the panel can show the line the
            // invoice will carry rather than a total that quietly disagrees
            // with the figures above it.
            'round_off' => $charged->minus($totals['total'])->amount(),
            'total' => $charged->amount(),
            // One shape or the other, never a mixture — the whole document has
            // one place of supply.
            'inter_state' => ! $totals['igst']->isZero(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRow(BillLine $line): array
    {
        return [
            'line_no' => $line->lineNo,
            'item_id' => (int) $line->item->id,
            'variant_id' => $line->variant === null ? null : (int) $line->variant->id,
            'description' => $line->description,
            'quantity' => $line->quantity->amount(),
            'unit' => $line->unit->value,
            'unit_symbol' => $line->unit->symbol(),
            'unit_price' => $line->unitPrice->amount(),
            'discount_amount' => $line->discount->amount(),
            'gst_rate' => (string) $line->tax->rate->percent(),
            'taxable_value' => $line->tax->taxable->amount(),
            'cgst_amount' => $line->tax->cgst->amount(),
            'sgst_amount' => $line->tax->sgst->amount(),
            'igst_amount' => $line->tax->igst->amount(),
            'tax_amount' => $line->tax->total()->amount(),
            'line_total' => $line->tax->inclusive()->amount(),
            'is_stock' => $line->movesStock,
            'memo' => $line->memo,
        ];
    }

    /**
     * Every variant this bill would take more of than the shelf holds.
     *
     * Empty for a purchase, and structurally so rather than by a filter: a
     * purchase puts stock *on* the shelf, and there is no such thing as not
     * having enough room.
     *
     * @param  array<int, BillLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function shortfallsIn(array $lines, TransactionType $type): array
    {
        if ($type !== TransactionType::Sale) {
            return [];
        }

        /** @var array<int, array{variant: ItemVariant, wanted: Quantity, lines: array<int, int>}> $wanted */
        $wanted = [];

        foreach ($lines as $line) {
            if (! $line->movesStock || $line->variant === null) {
                continue;
            }

            $id = (int) $line->variant->id;

            // Summed per variant, not per line. Two lines of three bearings are
            // six bearings, and a shelf of five is short — which neither line can
            // tell on its own.
            $wanted[$id] ??= ['variant' => $line->variant, 'wanted' => Quantity::zero(), 'lines' => []];
            $wanted[$id]['wanted'] = $wanted[$id]['wanted']->plus($line->quantity->absolute());
            $wanted[$id]['lines'][] = $line->lineNo;
        }

        $shortfalls = [];

        foreach ($wanted as $row) {
            $available = $this->stock->positionFor($row['variant'])->quantity;

            if ($row['wanted']->isAtMost($available)) {
                continue;
            }

            $shortfalls[] = [
                'variant_id' => (int) $row['variant']->id,
                'label' => $row['variant']->displayLabel(),
                'lines' => $row['lines'],
                'requested' => $row['wanted']->trimmed(),
                'available' => $available->trimmed(),
                'unit' => $row['variant']->item?->base_uom->symbol() ?? '',
                // The brief's own words, so the confirmation screen and the
                // refusal it would get on posting say the same thing.
                'message' => sprintf(
                    'Only %s %s available in stock.',
                    $available->trimmed(),
                    $row['variant']->item?->base_uom->symbol() ?? '',
                ),
            ];
        }

        return $shortfalls;
    }

    /**
     * The grand total on its own, for a caller that wants only the figure.
     *
     * @param  array<int, BillLine>  $lines
     */
    public function grandTotalOf(array $lines): Money
    {
        return BillLine::invoiceTotal($lines);
    }
}
