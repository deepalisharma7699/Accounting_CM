<?php

namespace App\Exceptions\Tenancy;

use App\Exceptions\ApiException;

/**
 * An attempt to write a row into a tenant other than the current one, or to
 * move an existing row between tenants.
 *
 * `tenant_id` is write-once. Reassigning it would silently transfer money,
 * stock or an invoice from one workshop's books to another's, leaving both
 * sets of accounts wrong with no audit trail — so it is refused outright.
 */
class CrossTenantWriteException extends ApiException
{
    public static function assigning(string $model, int $attempted, int $current): self
    {
        return new self(
            message: "Cannot write [{$model}] into tenant [{$attempted}] from tenant [{$current}].",
            status: 403,
            errorCode: 'TENANT_CROSS_WRITE',
            details: ['model' => $model, 'attempted_tenant_id' => $attempted, 'current_tenant_id' => $current],
        );
    }

    public static function reassigning(string $model): self
    {
        return new self(
            message: "The tenant of an existing [{$model}] record cannot be changed.",
            status: 403,
            errorCode: 'TENANT_IMMUTABLE',
            details: ['model' => $model],
        );
    }
}
