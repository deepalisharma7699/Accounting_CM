<?php

namespace App\Repositories\Eloquent;

use App\Enums\DocumentSeries;
use App\Models\DocumentSequence;
use App\Repositories\Contracts\DocumentSequenceRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * The one place in this application that deliberately blocks a concurrent
 * writer.
 *
 * Everywhere else, two people working at once is arranged to be harmless. Here it
 * cannot be: an invoice number has to be taken by exactly one poster, and the
 * only way to guarantee that is for the second reader to wait.
 */
class EloquentDocumentSequenceRepository implements DocumentSequenceRepositoryInterface
{
    public function nextFor(int $tenantId, DocumentSeries $series, string $financialYear): int
    {
        $sequence = $this->locked($tenantId, $series, $financialYear)
            ?? $this->createThenLock($tenantId, $series, $financialYear);

        $number = (int) $sequence->next;

        // Advanced in the same transaction the number is handed out in, so a
        // rollback puts the number back. A posting that fails its balance check
        // must not consume an invoice number — that is exactly the gap in the
        // series this module exists to avoid.
        $sequence->forceFill(['next' => $number + 1])->save();

        return $number;
    }

    public function peekFor(int $tenantId, DocumentSeries $series, string $financialYear): int
    {
        return (int) ($this->query($tenantId, $series, $financialYear)->value('next')
            ?? DocumentSequence::FIRST);
    }

    /**
     * The counter row, held for the rest of the caller's transaction.
     */
    private function locked(int $tenantId, DocumentSeries $series, string $financialYear): ?DocumentSequence
    {
        return $this->query($tenantId, $series, $financialYear)->lockForUpdate()->first();
    }

    /**
     * Open a new series, and come back holding its lock.
     *
     * Two posters can both find no row — neither can lock what does not exist —
     * so both attempt the insert and the unique index decides. The loser reads
     * the winner's row instead of failing: a duplicate key here is the mechanism
     * working, not an error, which is why it is caught rather than propagated.
     *
     * MySQL rolls back only the failed *statement* on a duplicate key, not the
     * enclosing transaction, so the posting this is nested inside survives it.
     */
    private function createThenLock(int $tenantId, DocumentSeries $series, string $financialYear): DocumentSequence
    {
        try {
            DocumentSequence::create([
                'tenant_id' => $tenantId,
                'series' => $series,
                'financial_year' => $financialYear,
                'next' => DocumentSequence::FIRST,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Somebody else opened the series first. Their row is what we want.
        }

        return $this->locked($tenantId, $series, $financialYear)
            // Unreachable: either the insert succeeded or somebody else's did,
            // and both leave a row to lock. Stated rather than assumed, because
            // a null here would surface as a number of zero on a real invoice.
            ?? throw new RuntimeException(
                "The {$series->value} number series for {$financialYear} could not be opened."
            );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<DocumentSequence>
     */
    private function query(int $tenantId, DocumentSeries $series, string $financialYear)
    {
        return DocumentSequence::query()
            // Stated although the global scope already restricts to the current
            // tenant: this runs inside a posting, and a counter read for the
            // wrong workshop would hand out a number that is already on somebody
            // else's invoice.
            ->where('tenant_id', $tenantId)
            ->where('series', $series->value)
            ->where('financial_year', $financialYear);
    }
}
