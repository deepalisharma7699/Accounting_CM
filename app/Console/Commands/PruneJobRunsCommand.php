<?php

namespace App\Console\Commands;

use App\Services\Jobs\JobRunService;
use Illuminate\Console\Command;

/**
 * Drop finished job runs past their retention — M14.
 *
 * `job_runs` is the one table in this application that is allowed to be
 * forgotten. Everything else here is either a fact about the books, which is
 * kept for ever, or a trail of who changed one, which is kept for ever for a
 * stronger reason — a history with an expiry date answers "who changed this"
 * with "we no longer know". A run row is neither: it describes a *process*, and
 * once the process is long finished the row records only that eleven thousand
 * uploads worked.
 *
 * Successes and failures are kept for very different lengths of time, and the
 * asymmetry is the point. A successful upload from three weeks ago is fully
 * answered by the attachment itself; a failure from three weeks ago is the only
 * record that the work was ever attempted at all. See `config/attachments.php`.
 *
 * Scheduled daily in `routes/console.php`, beside Laravel's own
 * `queue:prune-failed` and `queue:prune-batches`.
 */
class PruneJobRunsCommand extends Command
{
    protected $signature = 'jobs:prune';

    protected $description = 'Delete finished background-job records past their retention period';

    public function handle(JobRunService $runs): int
    {
        $retention = (array) config('attachments.retention', []);

        $deleted = $runs->prune();

        $this->components->info(sprintf(
            'Pruned %d finished job run%s (successes older than %d days, failures older than %d days).',
            $deleted,
            $deleted === 1 ? '' : 's',
            (int) ($retention['succeeded_days'] ?? 7),
            (int) ($retention['failed_days'] ?? 90),
        ));

        return self::SUCCESS;
    }
}
