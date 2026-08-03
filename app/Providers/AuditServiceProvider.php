<?php

namespace App\Providers;

use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the audit trail — M13.
 *
 * Registered late in `bootstrap/providers.php` and depended on by nothing:
 * the recorder is reached through model events on
 * {@see \App\Models\Concerns\Auditable}, so no service in the application holds
 * a reference to it. That is the point of the module — a cross-cutting concern
 * that the code it records knows nothing about cannot be forgotten by it.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLogRepositoryInterface::class, EloquentAuditLogRepository::class);

        // A singleton, and it must be: the recorder carries the suppression
        // depth and the acting user for the life of the request. A fresh
        // instance per resolution would mean a `silently()` scope opened by the
        // chart provisioner was invisible to the model event that fired inside
        // it — which is the exact case it exists to handle.
        $this->app->singleton(AuditRecorder::class);
    }
}
