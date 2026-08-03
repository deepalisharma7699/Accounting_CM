<?php

namespace App\Models\Concerns;

use App\Exceptions\Tenancy\CrossTenantWriteException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned. Every accounting table — parties, items,
 * stock movements, transactions, journal entries — uses this.
 *
 * It does three things, and all three matter:
 *
 *   Reads   are filtered to the current tenant, or refused (TenantScope).
 *   Creates have tenant_id stamped from the context, never from user input.
 *   Updates may not move a row between tenants; tenant_id is write-once.
 *
 * Note that `users` deliberately does *not* use this trait: the auth path has
 * to find a user before it knows which tenant they belong to, so users are
 * scoped explicitly in EloquentUserRepository instead. That is the one table
 * where app-layer filtering is unavoidable, and it is tested accordingly.
 *
 * @property int $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $assigned = $model->getAttribute('tenant_id');

            if ($assigned === null) {
                $model->setAttribute('tenant_id', $context->requireTenant($model::class));

                return;
            }

            // An explicit tenant_id is honoured only while unscoped (seeders,
            // platform provisioning). Inside a tenant it must match, so a
            // stray mass-assignment cannot plant a row in someone else's books.
            if (! $context->isUnscoped()
                && $context->hasTenant()
                && (int) $assigned !== $context->current()) {
                throw CrossTenantWriteException::assigning(
                    $model::class,
                    (int) $assigned,
                    (int) $context->current(),
                );
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWriteException::reassigning($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
