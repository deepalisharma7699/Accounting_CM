<?php

namespace App\Exceptions;

/**
 * The request is well formed but conflicts with current state — a duplicate
 * email that slipped past validation on a concurrent request, a duplicate role
 * name, and so on.
 */
class ConflictException extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, string $errorCode = 'CONFLICT', array $details = [])
    {
        parent::__construct(
            message: $message,
            status: 409,
            errorCode: $errorCode,
            details: $details,
        );
    }
}
