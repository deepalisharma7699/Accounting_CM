<?php

namespace App\Services\Accounting\Posting;

use App\Services\Accounting\PostingEngine;

/**
 * A posting template whose transaction is a *document* — a bill with items,
 * quantities and prices — as well as a set of ledger entries.
 *
 * The third of the three "and also record this" interfaces, alongside
 * {@see SettlesThroughPaymentModes} and {@see MovesStock}, and it exists for the
 * identical reason: {@see PostingEngine} must write what the template actually
 * computed, not a second reading of the same payload.
 *
 * A bill's ledger lines are aggregates — one credit to Sales for every goods line
 * on the invoice — so the individual lines are not recoverable from them at all.
 * Nor is the CGST/SGST/IGST split, which Phase 1's single GST account cannot
 * represent. Those live in `transaction_lines` or nowhere.
 */
interface CarriesDocumentLines
{
    /**
     * The document's own lines, priced and taxed.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, BillLine>
     */
    public function documentLinesFrom(array $input): array;
}
