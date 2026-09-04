<?php

namespace App\Services\Accounting;

use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidReturnException;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\User;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * "The customer brought one of the four bearings back" — M18.
 *
 * The brief's scenarios 5 and 6, and the gap the audit found: before this, the
 * only way to undo any part of a sale was to reverse the whole document. That is
 * the wrong tool. A reversal says the sale did not happen; a return says three
 * of the four bearings are still the customer's and one is back on the shelf.
 * Reversing and re-billing would work, and it would leave the ledger claiming
 * the original invoice was an error — which is not a thing anybody should have to
 * explain to a customer holding it.
 *
 * ## What this service actually does
 *
 * Turns `{lines: [{line_no, quantity}]}` into the payload
 * `TransactionType::SalesReturn` composes from. Everything downstream is the
 * existing bill engine with its signs inverted — see
 * {@see \App\Services\Accounting\Posting\Templates\SalesReturnTemplate}. This
 * class contributes the three things the engine cannot work out on its own:
 *
 *   1. **what is still returnable**, which is what was billed less what has come
 *      back already;
 *   2. **the original prices, discounts and tax rate**, so a credit note credits
 *      what was actually charged rather than what the item costs today;
 *   3. **what the returned stock is worth**, taken from the movement the original
 *      line produced.
 *
 * ## Why the value is computed here and not in the template
 *
 * Because it depends on the earlier returns, which a template cannot see. Three
 * bearings went out at ₹1,200; one came back at ₹400; the remaining two are worth
 * exactly ₹800 — and it has to be exactly ₹800, not two-thirds of ₹1,200 rounded
 * a second time, or the last return on a line leaves paise of stock value behind
 * with no stock under it. It is the same "share of what remains" arithmetic
 * {@see \App\Services\Inventory\StockLedgerService} uses to value an issue, for
 * the same reason.
 */
class ReturnService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransactionLineRepositoryInterface $lines,
    ) {}

    /**
     * Post a return against a bill.
     *
     * @param  array<int, array{line_no: int|string, quantity: int|string|float}>  $requested
     *
     * @throws InvalidReturnException
     */
    public function returnAgainst(
        Transaction $bill,
        array $requested,
        ?string $date = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $clientRef = null,
    ): Transaction {
        $this->assertReturnable($bill);

        $type = $bill->type->returnType();
        $items = $this->itemsFor($bill, $requested);

        if ($items === []) {
            throw InvalidReturnException::nothingToReturn($this->describe($bill));
        }

        return $this->transactions->create($type, [
            // Dated today by default rather than on the invoice's date, and for
            // the same reason a reversal is: the goods came back when they came
            // back, and back-dating one into a closed month would move a figure
            // in a report somebody has already read.
            'date' => $date ?? now()->toDateString(),
            'notes' => $notes ?? sprintf('Return against %s', $this->describe($bill)),
            'post' => true,
            'party_id' => $bill->party_id,
            'items' => $items,
            // Pinned from the original, so a credit note against a February
            // invoice keeps February's tax shape even if the customer's address
            // has been corrected since.
            'inter_state' => $this->isInterState($bill),
            'against_transaction_id' => (int) $bill->id,
            'client_ref' => $clientRef,
        ], $actor);
    }

    /* ---------------------------------------------------------------------
     | What is left to return
     |-------------------------------------------------------------------- */

    /**
     * Each line of a bill with what has come back and what still could — the
     * list a "returns" screen renders, and what the refusals are measured
     * against.
     *
     * @return array<int, array{line: TransactionLine, returned: Quantity, remaining: Quantity}>
     */
    public function returnableLines(Transaction $bill): array
    {
        $returned = $this->returnedQuantities($bill);
        $rows = [];

        foreach ($this->lines->forTransaction((int) $bill->id) as $line) {
            $already = $returned[(int) $line->id] ?? Quantity::zero();

            $rows[] = [
                'line' => $line,
                'returned' => $already,
                'remaining' => $line->quantityValue()->minus($already),
            ];
        }

        return $rows;
    }

    /**
     * How much of each of a bill's lines has already come back, keyed by line id.
     *
     * One query over the credit notes' own lines, joined by `against_line_id` —
     * which is why that column exists. Matching by variant instead would credit
     * the wrong price on the very common bill that carries the same bearing twice.
     *
     * @return array<int, Quantity>
     */
    public function returnedQuantities(Transaction $bill): array
    {
        $totals = [];

        foreach ($this->lines->returnedAgainstBill((int) $bill->id) as $row) {
            $totals[(int) $row->against_line_id] = ($totals[(int) $row->against_line_id] ?? Quantity::zero())
                ->plus($row->quantityValue());
        }

        return $totals;
    }

    /**
     * What of the original movement is left unreturned, in value.
     *
     * The counterpart of the quantity above, and the reason both are needed: a
     * quantity says how many bearings may still come back, and this says what
     * they are worth. Taking a share of *what remains* rather than of the
     * original means the last return on a line takes exactly the balance, with
     * no rounding residue left in the Inventory account.
     *
     * @return array<int, Money>
     */
    public function returnedValues(Transaction $bill): array
    {
        $totals = [];

        foreach ($this->lines->returnedAgainstBill((int) $bill->id) as $row) {
            $movement = $row->stockMovement;

            if ($movement === null) {
                continue;
            }

            $id = (int) $row->against_line_id;
            $totals[$id] = ($totals[$id] ?? Money::zero())->plus($movement->valueMoney()->absolute());
        }

        return $totals;
    }

    /* ---------------------------------------------------------------------
     | Building the credit note
     |-------------------------------------------------------------------- */

    /**
     * The `items` payload for the return, one row per line being credited.
     *
     * @param  array<int, array{line_no: int|string, quantity: int|string|float}>  $requested
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidReturnException
     */
    private function itemsFor(Transaction $bill, array $requested): array
    {
        $byLineNo = $this->lines->forTransaction((int) $bill->id)->keyBy(fn (TransactionLine $line) => (int) $line->line_no);
        $returnedQty = $this->returnedQuantities($bill);
        $returnedValue = $this->returnedValues($bill);

        $items = [];

        foreach ($this->foldRequested($requested) as $lineNo => $quantity) {
            /** @var TransactionLine|null $line */
            $line = $byLineNo->get($lineNo);

            if ($line === null) {
                throw InvalidReturnException::unknownLine($this->describe($bill), $lineNo);
            }

            // A line asked for at zero is dropped rather than refused: somebody
            // ticking four lines and typing a quantity into two of them meant
            // those two.
            if (! $quantity->isPositive()) {
                continue;
            }

            $alreadyQty = $returnedQty[(int) $line->id] ?? Quantity::zero();
            $remaining = $line->quantityValue()->minus($alreadyQty);

            if (! $quantity->isAtMost($remaining)) {
                throw InvalidReturnException::exceedsRemaining(
                    $this->describe($bill),
                    (int) $line->line_no,
                    $line->description,
                    $quantity->trimmed(),
                    $remaining->trimmed(),
                    $line->unit->symbol(),
                );
            }

            $items[] = [
                'item_id' => $line->item_id,
                'variant_id' => $line->variant_id,
                'quantity' => $quantity->amount(),
                // The price that was actually charged, not the item's list price
                // today — a credit note has to give back what was taken.
                'unit_price' => $line->unitPriceMoney()->amount(),
                // Pro-rated, so a half return of a discounted line credits half
                // the discount. Anything else would refund the customer a
                // different rate from the one they paid.
                'discount' => $quantity
                    ->shareOf($line->discountMoney(), $line->quantityValue())
                    ->amount(),
                'gst_rate' => (string) $line->gst_rate,
                'memo' => $line->memo,
                'against_line_id' => (int) $line->id,
                'stock_value' => $this->valueOfReturn(
                    $line,
                    $quantity,
                    $alreadyQty,
                    $returnedValue[(int) $line->id] ?? Money::zero(),
                )->amount(),
            ];
        }

        return $items;
    }

    /**
     * What this return's share of the original movement is worth.
     *
     * The share of what *remains*, not of the original: three bearings issued at
     * ₹1,000 and one already returned at ₹333.33 leaves ₹666.67 against two, and
     * a final return of both takes exactly that. Computing each return
     * independently as a fraction of ₹1,000 would leave a paisa behind on the
     * last one — permanently, in the Inventory account, against stock that is
     * physically on the shelf.
     *
     * Zero for a labour line, which has no movement behind it and nothing to put
     * back.
     */
    private function valueOfReturn(
        TransactionLine $line,
        Quantity $quantity,
        Quantity $alreadyReturned,
        Money $alreadyCredited,
    ): Money {
        $movement = $line->stockMovement;

        if ($movement === null) {
            return Money::zero();
        }

        $remainingQty = $line->quantityValue()->minus($alreadyReturned);
        $remainingValue = $movement->valueMoney()->absolute()->minus($alreadyCredited);

        if (! $remainingQty->isPositive() || ! $remainingValue->isPositive()) {
            return Money::zero();
        }

        return $quantity->shareOf($remainingValue, $remainingQty);
    }

    /**
     * The requested lines, summed by line number.
     *
     * Two rows naming line 3 are one return of the combined quantity — which
     * matters because the remaining-quantity check runs once per line, and two
     * unfolded rows would each be measured against the full remainder and both
     * pass.
     *
     * @param  array<int, array{line_no: int|string, quantity: int|string|float}>  $requested
     * @return array<int, Quantity>
     */
    private function foldRequested(array $requested): array
    {
        $folded = [];

        foreach ($requested as $row) {
            $lineNo = (int) ($row['line_no'] ?? 0);
            $quantity = Quantity::of($row['quantity'] ?? 0)->absolute();

            $folded[$lineNo] = ($folded[$lineNo] ?? Quantity::zero())->plus($quantity);
        }

        return $folded;
    }

    /* ---------------------------------------------------------------------
     | The refusals
     |-------------------------------------------------------------------- */

    /**
     * @throws InvalidReturnException
     */
    private function assertReturnable(Transaction $bill): void
    {
        if ($bill->type->returnType() === null) {
            throw InvalidReturnException::notReturnable((int) $bill->id, $bill->type->label());
        }

        // Posted only. A draft supplied nothing, and a reversed bill has already
        // been cancelled whole — there is nothing left on either to take back.
        if (! $bill->isPosted()) {
            throw InvalidReturnException::notInTheBooks((int) $bill->id, $bill->status->label());
        }
    }

    /**
     * Whether the original charged IGST, so the credit note charges it too.
     *
     * Read off the lines rather than off the party, because the party's state
     * code may have been corrected since and the credit note has to match the
     * invoice it is crediting — see `PlaceOfSupply::matching()`.
     */
    private function isInterState(Transaction $bill): bool
    {
        foreach ($this->lines->forTransaction((int) $bill->id) as $line) {
            if ($line->isInterState()) {
                return true;
            }
        }

        return false;
    }

    private function describe(Transaction $bill): string
    {
        return $bill->doc_no ?? "#{$bill->id}";
    }

    /**
     * A bill's returns, newest first — what a screen lists under "returned".
     *
     * @return Collection<int, Transaction>
     */
    public function returnsFor(Transaction $bill): Collection
    {
        return $bill->returns()->orderByDesc('date')->orderByDesc('id')->get();
    }
}
