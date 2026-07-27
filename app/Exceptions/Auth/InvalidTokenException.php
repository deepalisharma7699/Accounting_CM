<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;
use Throwable;

class InvalidTokenException extends ApiException
{
    public function __construct(
        string $message = 'The token is invalid.',
        string $errorCode = 'AUTH_TOKEN_INVALID',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            status: 401,
            errorCode: $errorCode,
            headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
            previous: $previous,
        );
    }

    public static function missing(): self
    {
        return new self('Authentication token was not provided.', 'AUTH_TOKEN_MISSING');
    }

    public static function expired(): self
    {
        return new self('The token has expired.', 'AUTH_TOKEN_EXPIRED');
    }

    public static function revoked(): self
    {
        return new self('The token has been revoked.', 'AUTH_TOKEN_REVOKED');
    }

    /**
     * A refresh token that was already rotated has been presented again: the
     * cookie almost certainly leaked, so the whole family was just revoked.
     */
    public static function reused(): self
    {
        return new self(
            'This session was ended for security reasons. Please sign in again.',
            'AUTH_TOKEN_REUSED',
        );
    }

    public static function wrongType(): self
    {
        return new self('The token is not valid for this operation.', 'AUTH_TOKEN_WRONG_TYPE');
    }
}
