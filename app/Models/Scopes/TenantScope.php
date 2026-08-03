<?php

namespace App\Models\Scopes;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Exceptions\Tenancy\NoWorkspaceException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current tenant.
 *
 * This is the MySQL stand-in for PostgreSQL Row-Level Security. It is weaker
 * in one specific way — it only binds queries that go through Eloquent, so raw
 * DB::select() and query-builder calls bypass it entirely. Tenant-owned tables
 * must therefore always be reached through their model.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isUnscoped()) {
            return;
        }

        $tenantId = $context->current();

        if ($tenantId === null) {
            // Fail closed either way — see MissingTenantContextException for
            // why this throws rather than returning an empty result set — but
            // the two reasons for a null tenant are not the same thing:
            //
            //   resolved   — the auth guard ran and the caller belongs to no
            //                workshop. A platform super-admin. Their account is
            //                valid and the request is well formed; there is
            //                simply no "my books" for them. That is a 403 with
            //                an explanation, not a server error.
            //
            //   unresolved — nobody established tenancy at all. A queued job or
            //                a console command that forgot to set the context.
            //                That is a bug, and it should look like one.
            throw $context->isResolved()
                ? new NoWorkspaceException
                : MissingTenantContextException::for($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
