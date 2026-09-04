<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which job an invoice came out of — M19.
 *
 * Nullable, and null for almost everything: a motor sold over the counter, a
 * purchase, a receipt and every expense have no job behind them. It is set only
 * on the sale that {@see \App\Services\Workshop\JobService::bill()} raises.
 *
 * ## Why the link is here and not on the job
 *
 * A `bill_transaction_id` column on `workshop_jobs` was the other option and it
 * is the wrong way round twice over. A long repair is legitimately billed more
 * than once — an advance against the estimate, the balance on collection — and a
 * single column could hold neither pair. And a bill raised in error is reversed
 * rather than deleted, so the job would have to know to stop pointing at it,
 * which is a second fact to keep in step with the first.
 *
 * This way the invoice records where it came from, permanently, and the job's
 * bills are a query. It is the same shape as `opening_import_id`, which records
 * which file a posting came from rather than the file recording its postings.
 *
 * ## Write-once, and stamped after posting
 *
 * `Transaction` refuses every change to a posted row except its status, so this
 * joins `opening_import_id` in that model's STAMPABLE_ONCE_POSTED list: it may
 * go from null to set, once, and never move afterwards. `JobService::bill()`
 * stamps it inside the same database transaction as the posting, so an invoice
 * and its provenance commit together or not at all.
 *
 * A column that could be re-pointed would let one job claim another's invoices —
 * which is exactly how a workshop would come to believe it had been paid for a
 * repair it had not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // restrictOnDelete: an invoice must never lose the job that explains
            // it, and a job with a posted bill against it is not deletable for
            // exactly that reason.
            $table->foreignId('workshop_job_id')->nullable()->after('against_transaction_id')
                ->constrained('workshop_jobs')->restrictOnDelete();

            // "What has this job been billed" — the read behind the job screen's
            // amount column and behind refusing to bill the same parts twice.
            $table->index(['tenant_id', 'workshop_job_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'workshop_job_id']);
            $table->dropForeign(['workshop_job_id']);
            $table->dropColumn('workshop_job_id');
        });
    }
};
