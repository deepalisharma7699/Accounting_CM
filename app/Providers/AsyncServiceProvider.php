<?php

namespace App\Providers;

use App\Repositories\Contracts\AttachmentRepositoryInterface;
use App\Repositories\Contracts\JobRunRepositoryInterface;
use App\Repositories\Eloquent\EloquentAttachmentRepository;
use App\Repositories\Eloquent\EloquentJobRunRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Wires background work and object storage — M14.
 *
 * Two concerns in one provider because they are one module: the reason a file
 * upload needs a queue is that reading the object back is slow and may fail, and
 * the reason the queue needs a visible record is that a user who uploaded
 * something has to be able to see what became of it.
 */
class AsyncServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        JobRunRepositoryInterface::class => EloquentJobRunRepository::class,
        AttachmentRepositoryInterface::class => EloquentAttachmentRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }

    public function boot(): void
    {
        $this->isolateTenantPerJob();
    }

    /**
     * Give every job a clean tenant context, and give the caller theirs back
     * afterwards.
     *
     * The most important thing in this module, and the reason it is a *listener*
     * rather than a rule for job authors to follow.
     *
     * `TenantContext` is a singleton. Under PHP-FPM that is per-request and
     * harmless, but a queue worker is a long-lived process: one PHP lifetime
     * handling job after job, with the container — and therefore the context —
     * carried across all of them. A job that established a tenant and finished
     * leaves it set, and the *next* job starts life believing it belongs to the
     * previous job's workshop. If that job then reads or writes anything
     * tenant-owned before establishing its own tenant, it does so in somebody
     * else's books, and nothing throws, because the context is populated and
     * looks entirely legitimate.
     *
     * {@see \App\Jobs\TrackedJob} sets its own tenant, so anything going through
     * it is already safe. This is the guard for everything else: a framework
     * job, a package's job, a future job somebody writes without noticing the
     * base class. Clearing to *unresolved* rather than to null is what makes it
     * a guard at all — unresolved means "nobody has decided", and every
     * tenant-owned query refuses rather than quietly returning nothing.
     *
     * ## Why it saves and restores rather than simply clearing
     *
     * Because a job does not always run in a worker. With the `sync` driver — and
     * with `dispatchSync` on any driver — the job runs *inside the request that
     * dispatched it*, sharing that request's container and therefore its context.
     * A listener that cleared on the way out would strip the tenant from the
     * controller that is still mid-way through its work, and everything after
     * the dispatch would fail with "no tenant" for reasons nothing in the
     * controller could explain. Saving the caller's context and handing it back
     * keeps the isolation guarantee for a worker without breaking the
     * synchronous case.
     *
     * Restoring on the exception hook as well as the success hook is what makes
     * that a guarantee rather than a happy path: `JobProcessed` does not fire for
     * a job that threw, and a job that failed must not take the caller's context
     * down with it. A stack, not a variable, because a job dispatching another
     * one synchronously nests.
     */
    private function isolateTenantPerJob(): void
    {
        /** @var array<int, array{0: int|null, 1: bool}> $saved */
        $saved = [];

        $context = fn (): TenantContext => $this->app->make(TenantContext::class);

        Queue::before(function (JobProcessing $event) use (&$saved, $context): void {
            $tenancy = $context();

            $saved[] = [$tenancy->current(), $tenancy->isResolved()];

            $tenancy->forget();
        });

        $restore = function () use (&$saved, $context): void {
            if ($saved === []) {
                return;
            }

            [$tenantId, $resolved] = array_pop($saved);
            $tenancy = $context();

            // `forget()` and `setTenant(null)` are different states, and the
            // difference is the whole isolation model: the first is "nobody has
            // decided", which refuses; the second is "decided: no workshop",
            // which is a platform administrator and proceeds.
            $resolved ? $tenancy->setTenant($tenantId) : $tenancy->forget();
        };

        Queue::after(fn (JobProcessed $event) => $restore());
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => $restore());
    }
}
