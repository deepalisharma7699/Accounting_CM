<?php

namespace App\Exceptions\Rbac;

use App\Exceptions\ApiException;

/**
 * A workshop role was given a permission it may not hold.
 *
 * Two different reasons share this refusal, and both are about escalation: a
 * workshop role may never carry a platform-level grant (authority over other
 * workshops), and whoever writes it may never hand out a permission they do not
 * hold themselves.
 *
 * @see \App\Services\Rbac\PermissionService::grantableFor()
 */
class RoleGrantNotAllowedException extends ApiException
{
    /**
     * @param  array<int, string>  $grants  the offending "ACTION:RESOURCE" pairs
     */
    public function __construct(array $grants)
    {
        parent::__construct(
            message: 'A workshop role cannot carry these permissions: '.implode(', ', $grants).'.',
            status: 422,
            errorCode: 'RBAC_GRANT_NOT_ALLOWED',
            details: ['permissions' => $grants],
        );
    }
}
