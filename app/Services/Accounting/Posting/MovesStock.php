<?php

namespace App\Services\Accounting\Posting;

use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Services\Accounting\PostingEngine;

/**
 * A posting template whose transaction moves quantities as well as money.
 *
 * The stock counterpart of {@see SettlesThroughPaymentModes}, and it exists for
 * the same reason: the engine has to record what the template *did*, not a
 * second reading of the same payload that could differ from it. A purchase whose
 * Inventory line said ₹15,000 while its stock movement said ₹14,999 would leave
 * the money ledger and the stock ledger permanently a rupee apart, and nothing
 * downstream could tell which one was right.
 *
 * So the template produces the movements once, `build()` derives the Inventory
 * line from their total, and {@see PostingEngine} asserts the two agree before
 * anything is written. Implementations memoise, because both calls happen inside
 * one `compose()` on one instance and valuing an issue twice is a query — and,
 * under concurrency, potentially two different answers.
 */
interface MovesStock
{
    /**
     * The quantities this transaction moves, valued.
     *
     * Empty is a legitimate answer: a bill for labour alone moves no stock, and
     * that is the rewinding trade's most common document.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, StockChange>
     *
     * @throws InvalidStockMovementException
     */
    public function stockChangesFrom(array $input): array;
}
