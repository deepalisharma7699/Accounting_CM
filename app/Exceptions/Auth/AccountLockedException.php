<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;

class AccountLockedException extends ApiException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            message: 'Account temporarily locked after too many failed sign-in attempts.',
            status: 423,
            errorCode: 'AUTH_ACCOUNT_LOCKED',
            details: ['retry_after' => $retryAfterSeconds],
            headers: ['Retry-After' => (string) $retryAfterSeconds],
        );
    }
}
