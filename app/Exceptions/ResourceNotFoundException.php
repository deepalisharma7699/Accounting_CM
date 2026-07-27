<?php

namespace App\Exceptions;

class ResourceNotFoundException extends ApiException
{
    public function __construct(string $resource = 'Resource', string|int|null $identifier = null)
    {
        parent::__construct(
            message: $identifier === null
                ? "{$resource} not found."
                : "{$resource} [{$identifier}] not found.",
            status: 404,
            errorCode: 'RESOURCE_NOT_FOUND',
            details: array_filter([
                'resource' => $resource,
                'identifier' => $identifier,
            ], fn ($value) => $value !== null),
        );
    }
}
