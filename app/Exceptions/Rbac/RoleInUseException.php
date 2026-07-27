<?php

namespace App\Exceptions\Rbac;

use App\Exceptions\ApiException;

class RoleInUseException extends ApiException
{
    public function __construct(string $roleName, int $userCount)
    {
        parent::__construct(
            message: "The role [{$roleName}] is still assigned to {$userCount} user(s).",
            status: 409,
            errorCode: 'RBAC_ROLE_IN_USE',
            details: ['role' => $roleName, 'assigned_users' => $userCount],
        );
    }
}
