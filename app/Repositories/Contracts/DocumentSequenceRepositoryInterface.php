<?php

namespace App\Repositories\Contracts;

use App\Enums\DocumentSeries;

interface DocumentSequenceRepositoryInterface
{
    /**
     * Take the next number in a series and advance the counter, under a write
     * lock held for the rest of the caller's database transaction.
     *
     * **Must be called from inside a transaction.** Outside one the lock is
     * released when the statement ends, and the number returned is a guess that
     * a concurrent poster may already have taken.
     */
    public function nextFor(int $tenantId, DocumentSeries $series, string $financialYear): int;

    /**
     * What {@see nextFor()} would return, without taking it or locking anything.
     * Advisory: correct at the instant it is read and not a moment longer.
     */
    public function peekFor(int $tenantId, DocumentSeries $series, string $financialYear): int;
}
