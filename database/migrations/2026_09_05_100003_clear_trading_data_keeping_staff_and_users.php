<?php

use App\Enums\AuditResource;
use App\Enums\DocumentSeries;
use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Empty the trading side of the books for a go-live, and leave the people alone.
 *
 * What goes: every counterparty, every product, every position on the shelf, and
 * every document that moved either — sales, purchases, credit and debit notes,
 * receipts, payments, expenses, manual journals, stock adjustments, opening
 * balances, job cards, and the ledger entries behind all of them.
 *
 * What stays: users, roles, permissions, passkeys, tenants, the chart of
 * accounts, the catalogue's vocabulary (categories, attributes, brands, units),
 * and the whole of staff — employees, designations, attendance, payroll runs and
 * advances, **including their money.**
 *
 * ## This is destructive and it does not come back
 *
 * There is no `down()` that could restore a row, so there is not one that
 * pretends to. Take a database dump before running it. It is written to be run
 * exactly once, on a server whose test data is finished with.
 *
 * ## Three decisions in here are load-bearing
 *
 * **Payroll and advances survive as transactions**, not merely as `employees`
 * and `payroll_runs`. A payroll run *is* a voucher — `Dr Salary Expense / Cr
 * Staff Advance / Cr Cash` — and what is out with somebody is derived from
 * posted advances less posted recoveries. Delete the transaction and the run
 * keeps its breakdown while the ledger forgets it happened, and every advance
 * balance silently becomes what nobody was owed. So the doomed set is defined by
 * *exclusion* — everything that is not {@see TransactionType::Payroll} or
 * {@see TransactionType::StaffAdvance} — and each child table is scoped to that
 * set rather than truncated. `journal_entries` and `transaction_payments` in
 * particular must not be emptied wholesale: half of a payroll voucher is worse
 * than none.
 *
 * The consequence is stated rather than repaired: Cash and Salary Expense will
 * carry wages with no sales behind them, so the trial balance opens with the
 * staff side already moved. That is the honest picture of "the books started
 * empty except for what we have already paid people".
 *
 * **Two document series are kept.** `document_sequences` is otherwise cleared so
 * invoice and purchase numbering restarts at 1001, but ADV and SAL are the
 * series the surviving vouchers already carry — resetting those would issue a
 * second ADV/26-27/1001 against an advance slip somebody has signed.
 *
 * **Self-references are nulled before the delete, never relied on.**
 * `transactions.reverses_id`, `transactions.against_transaction_id` and
 * `transaction_lines.against_line_id` all point within their own table under
 * `restrictOnDelete`, so a single multi-row DELETE can fail on the row order the
 * engine happens to choose. Nulling first makes the delete order irrelevant.
 *
 * Foreign keys are left **enabled** throughout, deliberately. The order below is
 * the real dependency order; if something in this schema references a row this
 * migration did not expect, the right outcome is a loud failure inside the
 * transaction, not a silently orphaned row discovered months later.
 *
 * Raw queries rather than Eloquent, for the reason the catalogue backfill gives:
 * the models carry a tenant scope, and a migration is in no tenant's context.
 * This clears every tenant.
 */
return new class extends Migration
{
    /**
     * The transaction types that are not trading, and survive.
     *
     * @var list<string>
     */
    private const KEEP_TRANSACTION_TYPES = [
        TransactionType::Payroll->value,
        TransactionType::StaffAdvance->value,
    ];

    /**
     * The numbering series belonging to those types. Everything else restarts.
     *
     * @var list<string>
     */
    private const KEEP_SERIES = [
        DocumentSeries::StaffAdvance->value,
        DocumentSeries::Payroll->value,
    ];

    /**
     * Audit rows whose subject no longer exists after this runs.
     *
     * The catalogue masters are not in here because they are not deleted, and
     * `user`, `employee`, `staff_designation`, `passkey` and `workspace` are not
     * in here because their subjects survive untouched.
     *
     * @var list<string>
     */
    private const CLEAR_AUDIT_RESOURCES = [
        AuditResource::Party->value,
        AuditResource::Item->value,
        AuditResource::Variant->value,
        AuditResource::SaleAttribution->value,
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->detachSelfReferences();
            $this->deleteTradingDocuments();
            $this->deleteCatalogueAndCounterparties();
            $this->resetNumberingAndAudit();
        });
    }

    /**
     * Break the within-table links first, so no DELETE below depends on the
     * order the engine chooses to remove rows in.
     */
    private function detachSelfReferences(): void
    {
        DB::table('transactions')
            ->whereNotIn('type', self::KEEP_TRANSACTION_TYPES)
            ->update([
                'reverses_id' => null,
                'against_transaction_id' => null,
                // Nulled here rather than ordered around: it lets `workshop_jobs`
                // be removed after the transactions that billed them.
                'workshop_job_id' => null,
            ]);

        // A credit note's line points at the invoice line it credits.
        DB::table('transaction_lines')->whereNotNull('against_line_id')
            ->update(['against_line_id' => null]);
    }

    /**
     * Everything hanging off a doomed transaction, deepest first.
     */
    private function deleteTradingDocuments(): void
    {
        // Who did the work on a sale. The employees themselves are untouched.
        DB::table('transaction_staff')->whereIn('transaction_id', $this->doomed())->delete();

        // The stock ledger. Every movement belongs to a stock-moving type and
        // none of those survive, but scoping it keeps the claim true if one
        // ever does.
        DB::table('stock_movements')->whereIn('transaction_id', $this->doomed())->delete();

        // Before `transaction_lines`, which it references, and before `items`.
        DB::table('workshop_job_parts')->delete();

        DB::table('transaction_lines')->whereIn('transaction_id', $this->doomed())->delete();

        DB::table('invoice_shares')->whereIn('transaction_id', $this->doomed())->delete();

        DB::table('transaction_allocations')
            ->whereIn('settlement_transaction_id', $this->doomed())
            ->orWhereIn('bill_transaction_id', $this->doomed())
            ->delete();

        // Scoped, not truncated: a payroll run's split is how the month was
        // handed over, and an advance is nothing without one.
        DB::table('transaction_payments')->whereIn('transaction_id', $this->doomed())->delete();

        // Scoped for the same reason. Removing a payroll voucher's entries
        // would leave the run posted and the ledger silent about it.
        DB::table('journal_entries')->whereIn('transaction_id', $this->doomed())->delete();

        DB::table('transactions')->whereNotIn('type', self::KEEP_TRANSACTION_TYPES)->delete();

        // After the transactions that billed them, and before the parties they
        // were raised for.
        DB::table('workshop_jobs')->delete();

        // The go-live declarations themselves. The transactions they created are
        // already gone.
        DB::table('opening_imports')->delete();
    }

    /**
     * The products and the people traded with.
     *
     * `item_categories`, `item_attributes`, `item_brands` and `units` are
     * deliberately kept: they are the vocabulary an admin configured, not
     * trading data, and a fresh item form is worth more with them than without.
     */
    private function deleteCatalogueAndCounterparties(): void
    {
        DB::table('item_variants')->delete();
        DB::table('items')->delete();
        DB::table('parties')->delete();
    }

    private function resetNumberingAndAudit(): void
    {
        DB::table('document_sequences')->whereNotIn('series', self::KEEP_SERIES)->delete();

        DB::table('audit_logs')->whereIn('resource', self::CLEAR_AUDIT_RESOURCES)->delete();
    }

    /**
     * The doomed transactions, as a subquery rather than a plucked id list, so a
     * server with years of documents does not load them all into memory.
     *
     * @return Closure(Builder): void
     */
    private function doomed(): Closure
    {
        return fn ($query) => $query
            ->select('id')
            ->from('transactions')
            ->whereNotIn('type', self::KEEP_TRANSACTION_TYPES);
    }

    /**
     * Deleted rows do not come back, and a `down()` that quietly succeeded would
     * be worse than none: it would un-record the migration, and the next
     * `migrate` would run this again over whatever real trading data the
     * workshop had entered since.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Migration 2026_09_05_100003 deleted trading data and cannot be reversed. '
            .'Restore from the dump taken before it was run. To roll back the rest of '
            .'this batch, delete this migration\'s row from the `migrations` table by hand.'
        );
    }
};
