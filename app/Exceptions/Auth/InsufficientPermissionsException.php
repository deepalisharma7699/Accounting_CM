<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;

class InsufficientPermissionsException extends ApiException
{
    /**
     * @param  array<int, string>  $required  "ACTION:RESOURCE" grants the route demanded.
     */
    public function __construct(array $required = [])
    {
        parent::__construct(
            message: 'You do not have permission to perform this action.',
            status: 403,
            errorCode: 'AUTH_FORBIDDEN',
            details: $required === [] ? [] : ['required_permissions' => array_values($required)],
        );
    }
}
