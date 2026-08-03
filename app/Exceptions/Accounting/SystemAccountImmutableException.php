<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * An attempt to change something about a seeded account that the posting
 * engine relies on.
 *
 * A workshop may rename a system account and edit its description — calling
 * "UPI / Wallet" what they actually use is helpful. Everything else is refused:
 * its type, its code and its very existence are structural.
 */
class SystemAccountImmutableException extends ApiException
{
    public static function field(string $field): self
    {
        return new self(
            message: "The [{$field}] of a system account cannot be changed. Only its name and description are editable.",
            status: 403,
            errorCode: 'ACCOUNT_SYSTEM_IMMUTABLE',
            details: ['field' => $field],
        );
    }

    public static function archiving(): self
    {
        return new self(
            message: 'A system account cannot be archived — the posting engine depends on it.',
            status: 403,
            errorCode: 'ACCOUNT_SYSTEM_IMMUTABLE',
            details: ['field' => 'is_active'],
        );
    }
}
