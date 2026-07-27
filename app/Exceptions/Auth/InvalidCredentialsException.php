<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;

class InvalidCredentialsException extends ApiException
{
    /**
     * @param  int|null  $remainingAttempts  Surfaced so a UI can warn before the lock trips.
     */
    public function __construct(?int $remainingAttempts = null)
    {
        // Deliberately identical whether the email is unknown or the password
        // is wrong — anything else turns login into a user enumeration oracle.
        parent::__construct(
            message: 'The provided credentials are incorrect.',
            status: 401,
            errorCode: 'AUTH_INVALID_CREDENTIALS',
            details: $remainingAttempts === null ? [] : ['remaining_attempts' => max(0, $remainingAttempts)],
        );
    }
}
