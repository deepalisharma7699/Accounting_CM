<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Base class for every failure this module raises on purpose.
 *
 * Carrying the HTTP status and a stable machine-readable code on the
 * exception itself means the handler never has to guess: anything that is not
 * an ApiException (or a framework exception it knows about) is a bug and is
 * reported as a 500.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $details  Safe-to-expose context for the client.
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        protected int $status = 400,
        protected string $errorCode = 'BAD_REQUEST',
        protected array $details = [],
        protected array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
