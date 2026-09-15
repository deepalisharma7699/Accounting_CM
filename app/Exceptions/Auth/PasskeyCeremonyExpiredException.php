<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;

/**
 * The challenge this ceremony was answering is gone.
 *
 * Separate from {@see PasskeyRejectedException} because it is the one failure
 * that is routinely innocent — a prompt left open while somebody went to find
 * the customer's motor — and the only one whose fix is simply to press the
 * button again. Saying so is not an oracle: it reveals nothing about any
 * account, only that a challenge this server issued has since been spent or
 * timed out.
 */
class PasskeyCeremonyExpiredException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            message: 'That sign-in request has expired. Please try again.',
            status: 400,
            errorCode: 'PASSKEY_CEREMONY_EXPIRED',
        );
    }
}
