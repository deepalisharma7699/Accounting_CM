<?php

namespace App\Exceptions\Tenancy;

use App\Enums\TenantStatus;
use App\Exceptions\ApiException;

/**
 * The user's credentials are fine, but the workshop they belong to is
 * suspended or cancelled. Distinct from AccountInactiveException so support
 * can tell "this person was deactivated" from "this business was suspended"
 * without reading the logs.
 */
class TenantInactiveException extends ApiException
{
    public function __construct(TenantStatus $status)
    {
        parent::__construct(
            message: match ($status) {
                TenantStatus::Suspended => 'This workspace is suspended. Contact support to restore access.',
                TenantStatus::Cancelled => 'This workspace has been closed.',
                TenantStatus::Active => 'This workspace is unavailable.',
            },
            status: 403,
            errorCode: 'TENANT_INACTIVE',
            details: ['status' => $status->value],
        );
    }
}
