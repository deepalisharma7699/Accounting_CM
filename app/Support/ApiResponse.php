<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Every JSON response from the API — success or failure — is built here, so
 * clients only ever have to implement one envelope.
 *
 * Success: { "success": true,  "message": ..., "data": ..., "meta": ... }
 * Failure: { "success": false, "error": { "code", "message", "details" } }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $payload['data'] = $data instanceof JsonResource || $data instanceof ResourceCollection
            ? $data->resolve(request())
            : $data;

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return self::success($data, $message, 201, $meta);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return self::success(null, $message, $status);
    }

    /**
     * Wrap a paginator, keeping the page metadata out of the data payload.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>|null  $resource
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        ?string $resource = null,
        ?string $message = null,
    ): JsonResponse {
        $items = $resource === null
            ? $paginator->items()
            : $resource::collection(collect($paginator->items()))->resolve(request());

        return self::success($items, $message, 200, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        int $status = 400,
        string $code = 'BAD_REQUEST',
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $status, $headers);
    }
}
