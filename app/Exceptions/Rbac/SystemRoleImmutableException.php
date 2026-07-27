<?php

namespace App\Exceptions\Rbac;

use App\Exceptions\ApiException;

class SystemRoleImmutableException extends ApiException
{
    public function __construct(string $roleName, string $operation)
    {
        parent::__construct(
            message: "The system role [{$roleName}] cannot be {$operation}.",
            status: 403,
            errorCode: 'RBAC_SYSTEM_ROLE_IMMUTABLE',
            details: ['role' => $roleName, 'operation' => $operation],
        );
    }
}
