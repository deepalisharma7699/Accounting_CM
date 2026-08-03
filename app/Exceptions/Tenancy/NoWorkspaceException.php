<?php

namespace App\Exceptions\Tenancy;

use App\Exceptions\ApiException;

/**
 * A workspace endpoint was reached by someone who belongs to no workshop —
 * in practice a platform super-admin.
 *
 * Not a bug, so not a 500: the account is valid and the request is well formed,
 * there is simply no "my workshop" for a platform user. They administer
 * workshops through /tenants instead, and the message says so rather than
 * leaving them with an empty object to puzzle over.
 */
class NoWorkspaceException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            message: 'Your account is not part of a workshop. Platform administrators manage workshops through /tenants.',
            status: 403,
            errorCode: 'NO_WORKSPACE',
        );
    }
}
