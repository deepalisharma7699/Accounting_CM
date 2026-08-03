<?php

namespace App\Exceptions\Tenancy;

use App\Exceptions\ApiException;
use App\Support\Tenancy\TenantContext;

/**
 * A tenant-owned model was queried or written with no tenant established.
 *
 * This is a bug, not a user error, so it is a 500. The alternative — quietly
 * returning no rows — is far worse in an accounting system: a day book or a
 * trial balance would render as empty rather than failing, and nobody would
 * notice until the numbers were already wrong.
 *
 * If a query legitimately spans tenants (the auth path, platform
 * administration, seeders), say so explicitly with
 * {@see TenantContext::runWithoutScope()} — which is
 * greppable, and therefore auditable.
 */
class MissingTenantContextException extends ApiException
{
    public static function for(string $model): self
    {
        return new self(
            message: "No tenant is set for the current request, so [{$model}] cannot be accessed.",
            status: 500,
            errorCode: 'TENANT_CONTEXT_MISSING',
            details: ['model' => $model],
        );
    }
}
