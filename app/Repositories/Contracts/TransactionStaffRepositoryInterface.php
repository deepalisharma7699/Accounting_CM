<?php

namespace App\Repositories\Contracts;

use App\Models\TransactionStaff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Who did the work an invoice was raised for — M22. Tenant-scoped by the global
 * scope on the model.
 */
interface TransactionStaffRepositoryInterface
{
    /**
     * This document's attribution, with the employee and the trade loaded.
     *
     * @return Collection<int, TransactionStaff>
     */
    public function forTransaction(int $transactionId): Collection;

    /**
     * The same for a page of documents, in one query — so a list of forty
     * invoices does not issue forty.
     *
     * Keyed by transaction id, and a document with no attribution is simply
     * absent rather than present with an empty array: "nobody was recorded" is
     * what the caller sees either way, and inventing keys for it would mean
     * building a row for every id on every listing that never asks.
     *
     * @param  array<int, int>  $transactionIds
     * @return array<int, Collection<int, TransactionStaff>>
     */
    public function forTransactions(array $transactionIds): array;

    /**
     * Replace this document's attribution with exactly these pairs.
     *
     * Whole-document rather than per-row, because that is how the form sends it
     * and because the difference between "the winder box was cleared" and "the
     * winder box was not sent" cannot survive a per-row API. Clearing a picker
     * has to be able to remove the row, and a PATCH that only ever added would
     * make a mis-picked name permanent.
     *
     * @param  array<int, array{employee_id: int, designation_id: int}>  $pairs
     * @return Collection<int, TransactionStaff>
     */
    public function syncFor(int $transactionId, array $pairs): Collection;

    /**
     * What one person got through — M22.
     *
     * How many invoices name them, what those invoices came to, and which
     * trades they were credited in. Reversed documents are excluded — a repair
     * that was billed and then cancelled is not work anybody did.
     *
     * **An invoice counts once**, however many trades on it are theirs: the
     * count is of motors that went out, not of boxes that were ticked.
     *
     * @return array{job_count: int, invoice_value: string, trades: array<int, array{designation: string, jobs: int}>}
     */
    public function workSummaryFor(int $employeeId, ?string $from = null, ?string $to = null): array;

    /**
     * The same summary for a list of people, in one pass.
     *
     * What the Insights people table reads, in place of a query per person.
     * Keyed by employee id, with an entry for **every** id asked about — a
     * person with no invoices against them is a row of zeroes, not a missing
     * key, because that is a real answer and the caller is painting a table.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{job_count: int, invoice_value: string, trades: array<int, array{designation: string, jobs: int}>}>
     */
    public function workSummaryForMany(array $employeeIds, ?string $from = null, ?string $to = null): array;

    /**
     * How much of the period's invoicing carries a name at all.
     *
     * The figure that says whether the table above it can be read as a picture
     * of the workshop or only of the documents somebody remembered to fill in.
     * Both halves come from one place so the ratio cannot be struck between two
     * differently-filtered sets.
     *
     * @return array{credited_invoices: int, credited_value: string, invoices: int}
     */
    public function attributionCoverage(?string $from, ?string $to): array;

    /**
     * The invoices behind those figures, newest first.
     *
     * @return LengthAwarePaginator<int, \App\Models\Transaction>
     */
    public function invoicesFor(int $employeeId, ?string $from, ?string $to, int $perPage): LengthAwarePaginator;
}
