<?php

namespace Tests\Feature\Async;

use App\Enums\AuditResource;
use App\Enums\JobStatus;
use App\Jobs\JobProgress;
use App\Jobs\TrackedJob;
use App\Models\AuditLog;
use App\Models\JobRun;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Jobs\JobRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The queue boundary — M14.
 *
 * These are the tests that matter in this module. A job runs with no request
 * behind it, so the two things every other module relies on are gone unless
 * something carries them across: the tenant, without which MySQL has no
 * isolation at all, and the actor, without which M13's trail says "the system"
 * for everything a worker ever does.
 */
class JobRunTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([['*', '*']]);
    }

    /* ---------------------------------------------------------------------
     | Carrying the request's context across the queue
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_job_runs_as_the_tenant_that_dispatched_it(): void
    {
        [$other] = $this->tenantWithUser([['*', '*']], 'Other Role');

        $job = $this->actingForTenant($this->tenant, fn () => new RecordsATenantJob);

        // Dispatched from one workshop, and then run while the context claims a
        // completely different one — which is exactly what a long-lived worker
        // does between two jobs. The tenant has to come from what was captured
        // at dispatch, never from what happens to be current.
        $this->actingForTenant($other, fn () => dispatch($job));

        $run = $this->runFor($job);

        $this->assertSame(JobStatus::Succeeded, $run->status);
        $this->assertSame($this->tenant->id, $run->result['saw_tenant']);
        $this->assertSame($this->tenant->id, $run->tenant_id);
    }

    #[Test]
    public function a_job_writes_into_the_dispatching_workshops_books(): void
    {
        [$other] = $this->tenantWithUser([['*', '*']], 'Other Role');

        $job = $this->actingForTenant($this->tenant, fn () => new CreatesAPartyJob('Queued Supplier'));

        $this->actingForTenant($other, fn () => dispatch($job));

        $this->assertSame(
            'Queued Supplier',
            $this->actingForTenant($this->tenant, fn () => Party::query()->latest('id')->value('name')),
        );

        // And nothing landed next door.
        $this->assertSame(
            0,
            $this->actingForTenant($other, fn () => Party::query()->where('name', 'Queued Supplier')->count()),
        );
    }

    #[Test]
    public function a_job_attributes_its_changes_to_whoever_dispatched_it(): void
    {
        $this->actingAs($this->owner);

        $job = $this->actingForTenant($this->tenant, fn () => new CreatesAPartyJob('Attributed Supplier'));

        // The worker has nobody authenticated, which is the whole problem: left
        // alone, every change a job made would read as "the system".
        auth()->forgetUser();

        $this->actingForTenant($this->tenant, fn () => dispatch($job));

        $entry = $this->actingForTenant($this->tenant, fn () => AuditLog::query()
            ->forResource(AuditResource::Party)
            ->newestFirst()
            ->firstOrFail());

        $this->assertSame('Attributed Supplier', $entry->label);
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame($this->owner->name, $entry->actor_name);
        // Recorded as having come from outside a request, which is true.
        $this->assertSame('console', $entry->context['via']);
    }

    #[Test]
    public function dispatching_without_a_workshop_fails_at_dispatch_rather_than_in_a_worker(): void
    {
        // A platform administrator holds every grant and owns no books, so there
        // is nowhere for background work to belong. Failing here, in the request,
        // in front of the person who asked, beats failing on a worker an hour
        // later with nobody watching.
        $this->assertThrows(
            fn () => app(TenantContext::class)->runFor(null, fn () => new RecordsATenantJob),
            \App\Exceptions\Tenancy\MissingTenantContextException::class,
        );
    }

    /* ---------------------------------------------------------------------
     | The run row
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_run_row_exists_before_the_worker_touches_it(): void
    {
        $job = $this->actingForTenant($this->tenant, fn () => new RecordsATenantJob);

        // Created at dispatch, not at pick-up, so there is never a window in
        // which somebody has been told their work is queued and the application
        // can say nothing about it.
        $run = $this->runFor($job);

        $this->assertSame(JobStatus::Queued, $run->status);
        $this->assertSame('test.records_tenant', $run->type);
        $this->assertSame($this->owner->id === $run->created_by ? $this->owner->id : null, $run->created_by);
        $this->assertSame(0, $run->progress);
        $this->assertNull($run->started_at);
    }

    #[Test]
    public function progress_is_reported_and_settles_at_a_hundred(): void
    {
        $job = $this->actingForTenant($this->tenant, fn () => new CountsToTenJob);

        $this->actingForTenant($this->tenant, fn () => dispatch($job));

        $run = $this->runFor($job);

        $this->assertSame(JobStatus::Succeeded, $run->status);
        // Only markSucceeded() writes 100. While a job is running the reported
        // figure is capped at 99, because a bar reading 100% beside a spinner is
        // the commonest way a progress display loses somebody's trust.
        $this->assertSame(100, $run->progress);
        $this->assertSame(10, $run->processed);
        $this->assertSame(10, $run->total);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertTrue($run->isSettled());
    }

    #[Test]
    public function a_failing_job_records_why_in_terms_a_workshop_can_read(): void
    {
        $job = $this->actingForTenant($this->tenant, fn () => new AlwaysFailsJob);

        try {
            $this->actingForTenant($this->tenant, fn () => dispatch($job));
        } catch (RuntimeException) {
            // Re-thrown on purpose: swallowing it would make a job that failed
            // look like a job that was never tried, and the queue would never
            // retry it.
        }

        $run = $this->runFor($job);

        $this->assertSame(JobStatus::Failed, $run->status);
        $this->assertTrue($run->hasFailed());
        $this->assertSame('The invoice could not be read.', $run->errorMessage());
        $this->assertSame(RuntimeException::class, $run->error['exception']);
        // One line, for a person. The stack trace is `failed_jobs`' business.
        $this->assertArrayNotHasKey('trace', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    #[Test]
    public function one_workshop_cannot_see_anothers_background_work(): void
    {
        $mine = $this->actingForTenant($this->tenant, fn () => new RecordsATenantJob);

        [$other, $stranger] = $this->tenantWithUser([['READ', 'JOBS']], 'Stranger Role');

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/jobs/'.$mine->runUuid)
            ->assertNotFound();

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($other);
    }

    #[Test]
    public function pruning_keeps_failures_far_longer_than_successes(): void
    {
        $succeeded = $this->actingForTenant($this->tenant, fn () => new RecordsATenantJob);
        $failed = $this->actingForTenant($this->tenant, fn () => new AlwaysFailsJob);

        $this->actingForTenant($this->tenant, fn () => dispatch($succeeded));

        try {
            $this->actingForTenant($this->tenant, fn () => dispatch($failed));
        } catch (RuntimeException) {
            // Expected.
        }

        // Both finished a fortnight ago. A successful upload from two weeks back
        // is fully answered by the file itself; a failure is the only record the
        // work was ever attempted.
        //
        // Both timestamps are moved, not just the second: the table's own CHECK
        // constraint refuses a row that finished before it started, which is the
        // constraint doing its job on a test taking a shortcut.
        $this->actingForTenant($this->tenant, function () use ($succeeded, $failed) {
            foreach ([$succeeded->runUuid, $failed->runUuid] as $uuid) {
                JobRun::query()->where('uuid', $uuid)->update([
                    'started_at' => now()->subDays(14)->subMinute(),
                    'finished_at' => now()->subDays(14),
                ]);
            }
        });

        $this->assertSame(1, app(JobRunService::class)->prune());

        $this->actingForTenant($this->tenant, function () use ($succeeded, $failed) {
            $this->assertNull(JobRun::query()->where('uuid', $succeeded->runUuid)->first());
            $this->assertNotNull(JobRun::query()->where('uuid', $failed->runUuid)->first());
        });
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    private function runFor(TrackedJob $job): JobRun
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => JobRun::query()->where('uuid', $job->runUuid)->firstOrFail(),
        );
    }
}

/* -------------------------------------------------------------------------
 | Jobs written for these tests
 |
 | Real jobs rather than mocks, because what is under test *is* the base class:
 | a mock of TrackedJob would prove that the mock carries a tenant.
 | ---------------------------------------------------------------------- */

class RecordsATenantJob extends TrackedJob
{
    public static function jobType(): string
    {
        return 'test.records_tenant';
    }

    protected function run(JobProgress $progress): array
    {
        return ['saw_tenant' => app(TenantContext::class)->current()];
    }
}

class CreatesAPartyJob extends TrackedJob
{
    public function __construct(public readonly string $name)
    {
        parent::__construct(['name' => $name]);
    }

    public static function jobType(): string
    {
        return 'test.creates_party';
    }

    protected function run(JobProgress $progress): array
    {
        // No tenant_id passed: it is stamped from the context the base class
        // re-established, exactly as it would be inside a controller.
        $party = Party::create(['name' => $this->name, 'roles' => ['vendor'], 'is_active' => true]);

        return ['party_id' => $party->id];
    }
}

class CountsToTenJob extends TrackedJob
{
    public static function jobType(): string
    {
        return 'test.counts_to_ten';
    }

    protected function run(JobProgress $progress): array
    {
        $progress->total(10);

        for ($i = 0; $i < 10; $i++) {
            $progress->step();
        }

        return ['counted' => 10];
    }
}

class AlwaysFailsJob extends TrackedJob
{
    public int $tries = 1;

    public static function jobType(): string
    {
        return 'test.always_fails';
    }

    protected function run(JobProgress $progress): array
    {
        throw new RuntimeException('The invoice could not be read.');
    }
}
