<?php

namespace App\Exceptions\Audit;

use App\Exceptions\ApiException;

/**
 * An attempt to update or delete an audit log entry.
 *
 * There is no legitimate reason for either, ever, and the reason is close to
 * tautological: a record of what happened that can itself be changed is not a
 * record of what happened. The value of this table is entirely in its being
 * append-only, so the guard belongs on the model rather than in a service that
 * some future caller might route around.
 *
 * A 500, not a 4xx: no route reaches this code and no payload can provoke it, so
 * if it is raised the fault is in the application and it should look like one.
 * The same reasoning as {@see \App\Exceptions\Accounting\LedgerImmutableException}.
 */
class AuditImmutableException extends ApiException
{
    public static function updating(?int $id): self
    {
        return new self(
            message: "Audit entry [{$id}] cannot be modified. The audit log is append-only — ".
                'a record of what happened that can be edited is not a record of anything.',
            status: 500,
            errorCode: 'AUDIT_IMMUTABLE',
        );
    }

    public static function deleting(?int $id): self
    {
        return new self(
            message: "Audit entry [{$id}] cannot be deleted. The audit log is append-only.",
            status: 500,
            errorCode: 'AUDIT_IMMUTABLE',
        );
    }
}
