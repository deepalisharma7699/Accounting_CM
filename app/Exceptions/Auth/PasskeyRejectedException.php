<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;

/**
 * A passkey ceremony did not verify.
 *
 * One message for every cause — an unknown credential, a bad signature, the
 * wrong origin, a counter that went backwards — for the reason
 * {@see InvalidCredentialsException} gives: a response that distinguishes
 * "no such credential" from "signature failed" tells whoever is probing which
 * of the two to keep working on. The real cause is logged instead, where the
 * workshop can see it and an attacker cannot.
 */
class PasskeyRejectedException extends ApiException
{
    public function __construct(string $message = 'That passkey could not be verified. Try again.')
    {
        parent::__construct(
            message: $message,
            status: 401,
            errorCode: 'PASSKEY_REJECTED',
        );
    }
}
