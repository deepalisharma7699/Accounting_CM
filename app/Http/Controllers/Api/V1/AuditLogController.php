<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\Audit\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Reading a workshop's trail — M13.
 *
 * Two routes and no more, and the shape is the point: there is no POST, no
 * PATCH and no DELETE anywhere in this module. Entries arrive through model
 * events on {@see \App\Models\Concerns\Auditable}, and the model itself refuses
 * an UPDATE and a DELETE — so there is no verb here that could put a claim on
 * the trail, or take one off it.
 *
 * Note also what is *not* here: a per-record history endpoint. One record's own
 * history is `GET /audit-logs?resource=party&resource_id=12`, which is the same
 * question at a different filter. A second URL for one answer is a second thing
 * to keep in step, and the second one always drifts — the same reasoning M12
 * used for not re-exposing the trial balance under `/reports`.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * GET /api/v1/audit-logs
     *
     * The trail, newest first.
     */
    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $page = $this->audit->paginate($request->filters(), $request->perPage());

        return ApiResponse::paginated($page, AuditLogResource::class);
    }

    /**
     * GET /api/v1/audit-logs/meta
     *
     * What may be filtered by — including the people who appear on the trail,
     * which is read from the trail itself rather than from the user list.
     * Somebody who has left still has a history, and a filter built from the
     * current users could not select them.
     */
    public function meta(): JsonResponse
    {
        $meta = $this->audit->meta();

        return ApiResponse::success([
            'resources' => $meta['resources'],
            'actions' => $meta['actions'],
            'actors' => $meta['actors'],
        ], null, 200, [
            // So a screen can tell "this workshop has no history yet" from
            // "nothing matches what you asked for". Both are an empty table and
            // they mean entirely different things.
            'total' => $meta['total'],
        ]);
    }
}
