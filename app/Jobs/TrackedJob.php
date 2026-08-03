<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Jobs\JobRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The base every queued job in this application extends — M14.
 *
 * ## The problem it exists to solve
 *
 * A queued job runs with no request behind it. That is the whole point of a
 * queue, and in this application it is also a hazard, because two things this
 * codebase relies on are established by the request and by nothing else:
 *
 *   - **The tenant.** `TenantContext` is what makes every query safe on MySQL,
 *     and the JWT middleware is what sets it. A worker has no middleware, so a
 *     job that did nothing about this would run with the context unresolved —
 *     and under a persistent worker it is worse than unresolved, because the
 *     context is a singleton that outlives a job and the *previous* job's tenant
 *     would still be sitting in it. That is a workshop writing into another
 *     workshop's books, and it would not throw.
 *
 *   - **The actor.** M13's audit recorder reads the authenticated user, and
 *     there is nobody authenticated inside a worker. Left alone, every change a
 *     job made would appear on the trail as "the system", including the parties
 *     an import invented on somebody's behalf.
 *
 * Both are captured at *dispatch*, while the request is still standing, carried
 * through the queue as plain integers, and re-established around
 * {@see run()}. So a job body is written exactly like a controller: the tenant
 * is current, the actor is known, and nothing in it has to think about either.
 *
 * ## Why the tenant is required rather than optional
 *
 * {@see TenantContext::requireTenant()} throws if there is none. Background work
 * in this product always belongs to a workshop, so "no tenant" at dispatch is a
 * platform administrator queueing something that has nowhere to go — and failing
 * at dispatch, in the request, in front of the person who asked, is enormously
 * better than failing in a worker an hour later.
 */
abstract class TrackedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Three attempts. Enough to ride out the failure this module actually sees —
     * an object storage endpoint that is briefly unreachable — and few enough
     * that a job failing for a real reason reaches somebody quickly.
     */
    public int $tries = 3;

    /**
     * Ten seconds, then a minute. A transient network fault clears in the first
     * gap; anything still failing after the second was never transient.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    /**
     * Captured at dispatch, carried as scalars. Not a `Tenant` model, and not a
     * `User`: `SerializesModels` would re-resolve them on the worker, which for
     * the tenant means a query against a scope that has not been established
     * yet — the exact ordering problem this class exists to avoid.
     */
    public int $tenantId;

    public ?int $actorId = null;

    public string $runUuid;

    /**
     * @param  array<string, mixed>  $payload  The ids this job was handed, for the
     *        run row. Never the data itself — see the `job_runs` migration.
     */
    public function __construct(array $payload = [])
    {
        $this->tenantId = app(TenantContext::class)->requireTenant(static::jobType());
        $this->actorId = Auth::hasUser() ? (int) Auth::id() : null;

        // Created here, in the request, so the caller can be handed a handle to
        // poll before the work has started. A run row created by the worker
        // instead would leave a window in which somebody has been told their
        // upload is being processed and the application can say nothing about it.
        $this->runUuid = app(JobRunService::class)
            ->open(static::jobType(), $this->tenantId, $this->actorId, $payload)
            ->uuid;
    }

    /**
     * A stable key for this kind of work — `attachment.process`, never the class
     * name. It is stored in `job_runs.type` and filtered on, so it is a promise
     * to the database rather than a detail of the code. Same reasoning as
     * {@see \App\Enums\AuditResource}.
     */
    abstract public static function jobType(): string;

    /**
     * The work.
     *
     * Runs with the tenant established and the actor attributed, so it reads like
     * any other service call. Anything it returns is stored on the run row as
     * the result — keep it to counts and ids.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function run(JobProgress $progress): ?array;

    final public function handle(
        TenantContext $tenancy,
        JobRunService $runs,
        AuditRecorder $audit,
    ): void {
        // runFor(), not setTenant(): it restores whatever was there afterwards,
        // even if the job throws. Under a persistent worker the context is a
        // singleton that outlives a single job, so leaving it set would hand the
        // next job this tenant.
        $tenancy->runFor($this->tenantId, function () use ($runs, $audit): void {
            $run = $runs->findOrNull($this->runUuid);

            if ($run === null) {
                // The dispatching transaction rolled back: the run row went with
                // it, and so did whatever the job was going to act on. Doing the
                // work anyway would be acting on a decision that was reversed.
                // Refused rather than skipped quietly, so it lands in
                // `failed_jobs` where somebody will see it.
                throw new RuntimeException(sprintf(
                    'Job run [%s] no longer exists, so [%s] has nothing to do. '.
                    'This normally means the transaction that dispatched it was rolled back.',
                    $this->runUuid,
                    static::jobType(),
                ));
            }

            $audit->actingAs($this->actor(), function () use ($runs, $run): void {
                $runs->markRunning($run);

                try {
                    $result = $this->run(new JobProgress($runs, $run));

                    $runs->markSucceeded($run->refresh(), $result);
                } catch (Throwable $exception) {
                    // Recorded, then re-thrown. Recording is what a screen reads;
                    // re-throwing is what lets the queue retry and, on the last
                    // attempt, record the whole trace in `failed_jobs`. Swallowing
                    // it here would make a job that failed three times look like a
                    // job that was never tried.
                    $runs->markFailed($run->refresh(), $exception);

                    throw $exception;
                }
            });
        });
    }

    /**
     * Every attempt used up.
     *
     * Laravel calls this outside {@see handle()}, so the tenant has to be
     * re-established again — and it is the one path where a second failure must
     * not escape: this is already the failure handler, and throwing from it
     * would replace a workshop's readable explanation with a stack trace nobody
     * asked for.
     */
    final public function failed(?Throwable $exception): void
    {
        try {
            app(TenantContext::class)->runFor($this->tenantId, function () use ($exception): void {
                $runs = app(JobRunService::class);
                $run = $runs->findOrNull($this->runUuid);

                // Ordinarily already marked by handle(); this covers the ways a
                // job dies without reaching that catch — a timeout, a worker
                // killed mid-run, a MaxAttemptsExceeded.
                if ($run !== null && ! $run->hasFailed()) {
                    $runs->markFailed($run, $exception ?? 'The job stopped without reporting why.');
                }
            });
        } catch (Throwable $secondary) {
            Log::error('jobs.failed_handler_failed', [
                'type' => static::jobType(),
                'run' => $this->runUuid,
                'error' => $secondary->getMessage(),
            ]);
        }
    }

    /**
     * The run row, for a job that wants to read its own payload back.
     */
    protected function jobRun(): ?JobRun
    {
        return app(JobRunService::class)->findOrNull($this->runUuid);
    }

    /**
     * Whoever dispatched this, resolved on the worker.
     *
     * Read without the tenant scope by nature — `users` carries no global scope,
     * for the reason {@see \App\Models\Concerns\BelongsToTenant} explains — and
     * it is safe here because the id was captured from an authenticated request
     * rather than supplied by one.
     */
    private function actor(): ?User
    {
        return $this->actorId === null ? null : User::query()->find($this->actorId);
    }
}
