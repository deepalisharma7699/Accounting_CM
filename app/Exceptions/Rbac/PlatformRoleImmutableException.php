<?php

namespace App\Exceptions\Rbac;

use App\Exceptions\ApiException;

/**
 * A workshop tried to change a role that belongs to the platform.
 *
 * Platform roles belong to the platform's own panel, so one workshop rewriting one
 * would be rewriting it for all of them. A workshop that needs something
 * different creates a role of its own.
 */
class PlatformRoleImmutableException extends ApiException
{
    public function __construct(string $roleName, string $operation)
    {
        parent::__construct(
            message: "[{$roleName}] is a platform role and cannot be {$operation} from a workshop. Create a role of your own instead.",
            status: 403,
            errorCode: 'RBAC_PLATFORM_ROLE_IMMUTABLE',
            details: ['role' => $roleName, 'operation' => $operation],
        );
    }
}
