<?php

namespace App\Exceptions\Auth;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;

class AccountInactiveException extends ApiException
{
    public function __construct(UserStatus $status)
    {
        parent::__construct(
            message: 'This account is not active. Please contact an administrator.',
            status: 403,
            errorCode: 'AUTH_ACCOUNT_INACTIVE',
            details: ['status' => $status->value],
        );
    }
}
