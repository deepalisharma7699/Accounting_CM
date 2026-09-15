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

    /**
     * Point this failure at one entry of a repeated group.
     *
     * A refusal is raised where the rule is — "a SKU that identifies two things
     * is worse than no SKU" — and that layer legitimately does not know it was
     * raised about the third block of a repeater. The caller does, and this is
     * where it says so: `sku` becomes `variants.2.sku`, which is the name the
     * client actually sent and therefore the box it can paint.
     *
     * Written into `fields` and not only into `field`, because only the map
     * reaches a form input — `auth-client.js` reads `details.fields`, and a
     * singular `details.field` has never got past the banner. A failure that
     * named no field at all is pinned to the entry itself, which is still the
     * difference between "one of these five is wrong" and "this one".
     *
     * Only for a caller that sent the repeated shape. Scoping a refusal onto
     * `variants.0.sku` for somebody who sent a flat `sku` would name a field
     * they never wrote.
     */
    public function underField(string $prefix): static
    {
        $fields = $this->details['fields'] ?? null;
        $field = $this->details['field'] ?? null;

        if (is_array($fields) && $fields !== []) {
            $scoped = [];

            foreach ($fields as $name => $messages) {
                $scoped[$prefix.'.'.$name] = $messages;
            }

            $this->details['fields'] = $scoped;
        } elseif (is_string($field)) {
            $this->details['fields'] = [$prefix.'.'.$field => [$this->getMessage()]];
        } else {
            $this->details['fields'] = [$prefix => [$this->getMessage()]];
        }

        if (is_string($field)) {
            $this->details['field'] = $prefix.'.'.$field;
        }

        return $this;
    }
}
