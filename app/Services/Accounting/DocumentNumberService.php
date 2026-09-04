<?php

namespace App\Services\Accounting;

use App\Enums\DocumentSeries;
use App\Enums\TransactionType;
use App\Models\Tenant;
use App\Repositories\Contracts\DocumentSequenceRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Hands out the number printed on a document — `INV/26-27/1001`.
 *
 * The whole of this class is one careful thing: making sure two people posting
 * at the same instant cannot be given the same invoice number. Every other
 * mistake in this application is correctable by addition — a wrong amount is
 * reversed, a wrong party is reversed and re-entered — but two invoices bearing
 * one number is a record that cannot be repaired, because the workshop has
 * already handed both of them to different customers.
 *
 * ## How the race is closed
 *
 * `SELECT … FOR UPDATE` on the counter row, inside the caller's database
 * transaction. The second poster's read *blocks* until the first commits, so it
 * reads the incremented value rather than the stale one. That is the only
 * arrangement that works: `MAX(doc_no) + 1` and an application-level lock both
 * have a window between reading and writing, and the window is exactly where two
 * clerks at two counters land.
 *
 * The consequence is a requirement on callers, and it is not optional: this must
 * be called from inside the same `DB::transaction` that writes the transaction
 * row. Called on its own, the lock is released at the end of the statement and
 * the number it returned is a guess. {@see PostingEngine} is the only caller.
 *
 * ## Why the number is assigned at posting and never before
 *
 * A draft has no number. Numbering a draft would mean that discarding it left a
 * gap in the series, and a gap is something somebody has to explain to an
 * auditor — "invoice 1004 does not exist" reads exactly like a suppressed sale,
 * whatever the truth was.
 */
class DocumentNumberService
{
    public function __construct(
        private readonly DocumentSequenceRepositoryInterface $sequences,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
    ) {}

    /**
     * The next number in this type's series for the financial year the document
     * is dated in, with the counter advanced.
     *
     * Dated in, not issued in. A bill entered on 5 April but dated 28 March
     * belongs to the year that closed, and putting it in the new year's series
     * would make both years' invoice runs non-consecutive at once.
     *
     * @throws \App\Exceptions\Tenancy\MissingTenantContextException
     */
    public function assign(TransactionType $type, DateTimeInterface $date): string
    {
        return $this->assignSeries($type->documentSeries(), $date);
    }

    /**
     * The same, for a document that is not a transaction — M19's job cards.
     *
     * The transaction-shaped entry point above delegates here rather than the
     * other way round, because the series is the thing the counter is actually
     * keyed on; a transaction type merely names one. A workshop job has no
     * type, no template and no journal entries, and still needs a number two
     * people cannot be given at once.
     *
     * The requirement on callers is unchanged and still not optional: **inside
     * the same `DB::transaction` as the row being numbered**. Called on its own
     * the lock is released at the end of the statement and the number is a guess.
     *
     * @throws \App\Exceptions\Tenancy\MissingTenantContextException
     */
    public function assignSeries(DocumentSeries $series, DateTimeInterface $date): string
    {
        $tenantId = $this->context->requireTenant('numbering a document');
        $financialYear = $this->financialYearLabel($this->tenants->findById($tenantId), $date);

        return $series->format(
            $financialYear,
            $this->sequences->nextFor($tenantId, $series, $financialYear),
        );
    }

    /**
     * The financial year a date falls in, as it is printed: "26-27".
     *
     * Read off the workshop's own `financial_year_start_month`, so a workshop
     * that has been set to a calendar year gets "26-27" spanning January to
     * December — which is what its accountant asked for when they changed the
     * setting.
     *
     * Falls back to April–March where the tenant row cannot be read at all. That
     * is unreachable in practice, since the posting engine has already required a
     * tenant by the time this runs; the fallback exists so a numbering failure
     * can never be the thing that stops a workshop billing.
     */
    private function financialYearLabel(?Tenant $tenant, DateTimeInterface $date): string
    {
        [$start, $end] = $tenant?->financialYearFor($date)
            ?? $this->defaultFinancialYearFor($date);

        return $start->format('y').'-'.$end->format('y');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function defaultFinancialYearFor(DateTimeInterface $date): array
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $start = $date->setDate($date->year, 4, 1);

        if ($date->lessThan($start)) {
            $start = $start->subYear();
        }

        return [$start, $start->addYear()->subDay()];
    }

    /**
     * What the next number in a series *would* be, without taking it.
     *
     * Advisory only, and labelled as such wherever it is shown: it takes no lock,
     * so a concurrent posting can consume the number between this answer and the
     * screen it is rendered on. That is acceptable for the one thing it is for —
     * telling somebody at the counter roughly which invoice they are about to
     * write — and unacceptable for anything else.
     */
    public function peek(TransactionType $type, DateTimeInterface $date): string
    {
        $series = $type->documentSeries();
        $tenantId = $this->context->requireTenant('numbering a document');
        $financialYear = $this->financialYearLabel($this->tenants->findById($tenantId), $date);

        return $series->format(
            $financialYear,
            $this->sequences->peekFor($tenantId, $series, $financialYear),
        );
    }
}
