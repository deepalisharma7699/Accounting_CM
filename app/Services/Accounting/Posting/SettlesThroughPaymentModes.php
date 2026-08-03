<?php

namespace App\Services\Accounting\Posting;

use App\Exceptions\Accounting\InvalidJournalException;
use App\Services\Accounting\PostingEngine;

/**
 * A posting template whose transaction is settled through one or more payment
 * modes, and can therefore say what its settlement split is.
 *
 * Declared as an interface rather than being read off the concrete class so the
 * engine stays coupled only to what it needs: given a payload, hand back the
 * splits that produced the lines. M9's part-paid bill will implement this too —
 * a sale settled half in cash carries the same split as a receipt does, and must
 * record the mode the same way.
 *
 * The splits are derived from the *same* payload the lines were built from, in
 * the same call, so {@see PostingEngine} records what it posted rather than a
 * second reading of the input.
 */
interface SettlesThroughPaymentModes
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<int, PaymentSplit>
     *
     * @throws InvalidJournalException
     */
    public function splitsFrom(array $input): array;
}
