<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Housekeeping — M14
|--------------------------------------------------------------------------
|
| Three tables grow with use and are worth forgetting. They are the only ones:
| everything else here is either a fact about the books or M13's record of who
| changed one, and both are kept for ever — a trail with an expiry date answers
| "who changed this" with "we no longer know".
|
| Requires the scheduler to be running. On a server that is one cron entry:
|
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| Without it nothing below happens, and the failure is silent — which is why
| docs/async-module.md puts the scheduler in the deployment checklist rather
| than in a footnote.
|
*/

// Our own record of background work. Successes go after a week, failures are
// kept for ninety days — see config/attachments.php on why the two differ.
Schedule::command('jobs:prune')->dailyAt('03:10')->onOneServer();

// Laravel's own. `failed_jobs` holds the stack traces a developer needs and a
// workshop never sees; `job_runs` holds what the workshop sees. Both are
// pruned, on their own schedules, because they answer different questions.
Schedule::command('queue:prune-failed --hours=2160')->dailyAt('03:20')->onOneServer();
Schedule::command('queue:prune-batches --hours=720')->dailyAt('03:30')->onOneServer();
