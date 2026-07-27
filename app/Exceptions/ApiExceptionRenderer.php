<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any exception into the standard API error envelope.
 *
 * Registered from bootstrap/app.php. Every failure the client can see passes
 * through here, so status codes and error codes stay consistent no matter
 * which layer threw.
 */
class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        // Non-API traffic keeps Laravel's default (HTML) rendering.
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => ApiResponse::error(
                $e->getMessage(),
                $e->status(),
                $e->errorCode(),
                $e->details(),
                $e->headers(),
            ),

            // 422 — field-level validation failures.
            $e instanceof ValidationException => ApiResponse::error(
                'The given data was invalid.',
                422,
                'VALIDATION_FAILED',
                ['fields' => $e->errors()],
            ),

            // 429 — rate limiter tripped.
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'Too many requests. Please slow down and try again shortly.',
                429,
                'TOO_MANY_REQUESTS',
                array_filter(['retry_after' => $e->getHeaders()['Retry-After'] ?? null]),
                array_map('strval', $e->getHeaders()),
            ),

            // 401 — framework-level auth failure (e.g. a session guard route).
            $e instanceof AuthenticationException => ApiResponse::error(
                'Authentication is required to access this resource.',
                401,
                'AUTH_UNAUTHENTICATED',
            ),

            // 403 — gate/policy denial.
            $e instanceof AuthorizationException => ApiResponse::error(
                'You do not have permission to perform this action.',
                403,
                'AUTH_FORBIDDEN',
            ),

            // 404 — implicit model binding or an unmatched route.
            $e instanceof ModelNotFoundException => ApiResponse::error(
                'The requested resource was not found.',
                404,
                'RESOURCE_NOT_FOUND',
                ['resource' => class_basename($e->getModel())],
            ),

            $e instanceof NotFoundHttpException => ApiResponse::error(
                'The requested endpoint was not found.',
                404,
                'ENDPOINT_NOT_FOUND',
            ),

            // 405 — right path, wrong verb.
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'The HTTP method is not supported for this endpoint.',
                405,
                'METHOD_NOT_ALLOWED',
            ),

            // Any other HTTP exception keeps its status but gains the envelope.
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                $e->getStatusCode(),
                'HTTP_ERROR',
                [],
                array_map('strval', $e->getHeaders()),
            ),

            default => $this->renderUnexpected($e),
        };
    }

    /**
     * 500 — an actual bug. The message is logged in full but never returned
     * to the client outside local/testing, since stack traces and driver
     * errors leak schema and file paths.
     */
    private function renderUnexpected(Throwable $e): JsonResponse
    {
        Log::error('api.unhandled_exception', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $debug = (bool) config('app.debug');

        return ApiResponse::error(
            $debug ? $e->getMessage() : 'An unexpected error occurred. Please try again later.',
            500,
            'INTERNAL_SERVER_ERROR',
            $debug ? [
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
            ] : [],
        );
    }
}
