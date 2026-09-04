<?php

namespace App\Repositories\Contracts;

use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Accounting\Posting\BillLine;
use Illuminate\Support\Collection;

/**
 * A bill's own lines. Note what is absent, as on every other write-once table in
 * this schema: there is no update. A posted transaction is immutable, and a
 * mistake on an invoice is corrected by reversing it and issuing another.
 */
interface TransactionLineRepositoryInterface
{
    /**
     * Write a transaction's document lines. Called only by the posting engine,
     * and only inside the database transaction that created the parent row.
     *
     * Returns the written lines keyed by `line_no`, because the engine has to
     * pair each one with the stock movement it produced — and the movement
     * cannot carry the id of a row that did not exist when it was composed.
     *
     * @param  array<int, BillLine>  $lines
     * @return Collection<int, TransactionLine>
     */
    public function writeFor(Transaction $transaction, array $lines): Collection;

    /**
     * Every line of one transaction, with the stock movement behind each — which
     * is where a margin comes from.
     *
     * @return Collection<int, TransactionLine>
     */
    public function forTransaction(int $transactionId): Collection;

    /**
     * Every credit-note line taken against a bill's lines — M18.
     *
     * Reached through `against_line_id`, which is why that column exists:
     * "how much of line 3 has come back?" has to be a sum over one key, and an
     * invoice very often carries the same variant twice at two prices.
     *
     * The stock movement behind each is loaded, because the caller needs both
     * halves — how many came back, and what they were credited at.
     *
     * @return Collection<int, TransactionLine>
     */
    public function returnedAgainstBill(int $billId): Collection;

    /**
     * How many bill lines name a variant — what turns deleting one into a
     * refusal, alongside its stock history.
     */
    public function countForVariant(int $variantId): int;

    public function countForItem(int $itemId): int;

    /**
     * The GST summary — M12: taxable value and tax, grouped by transaction type
     * and rate.
     *
     * Read from the *document* lines rather than from the ledger, and that is
     * the whole reason this query exists here. Phase 1 has one GST Output
     * account and one GST Input account, so the journal knows what tax was
     * charged but not at what rate, nor how it split into CGST, SGST and IGST —
     * and a return is filed rate by rate. This table is the only place that
     * exists, which M9 said when it wrote the columns.
     *
     * Reversed transactions are included and their own reversing lines with
     * them, so a cancelled invoice nets to nothing here exactly as it does in
     * the ledger. Drafts are absent structurally: a draft has no rows in this
     * table at all.
     *
     * @return Collection<int, array{type: string, gst_rate: string, taxable: string, cgst: string, sgst: string, igst: string, lines: int}>
     */
    public function gstTotals(?string $from = null, ?string $to = null): Collection;
}
