<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Job\JobRunIndexRequest;
use App\Http\Resources\JobRunResource;
use App\Services\Jobs\JobRunService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * What the queue is doing, for the workshop it is doing it for — M14.
 *
 * Read-only, structurally: a run row is created by the act that dispatched it —
 * an upload, an import — never by a request to this controller. That is why
 * {@see \App\Enums\PermissionResource::Jobs} has only a READ grant, the same
 * shape LEDGER and STOCK have and for the same reason.
 *
 * `show` is the endpoint a progress bar polls, which is what makes it the one
 * route in the application designed to be called every second or two. It is
 * deliberately a single indexed lookup by uuid with no joins, and the client is
 * told when to stop by `is_settled` rather than deciding for itself.
 */
class JobRunController extends Controller
{
    public function __construct(private readonly JobRunService $runs) {}

    /**
     * GET /api/v1/jobs
     */
    public function index(JobRunIndexRequest $request): JsonResponse
    {
        $page = $this->runs->paginate($request->filters(), $request->perPage());

        return ApiResponse::success(
            JobRunResource::collection(collect($page->items()))->resolve(request()),
            null,
            200,
            [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                    'has_more' => $page->hasMorePages(),
                ],
                // Over the whole workshop rather than over this page: it is what
                // a badge shows and what tells a client whether to keep polling
                // at all.
                'unsettled' => $this->runs->unsettledCount(),
                'statuses' => JobStatus::catalogue(),
            ],
        );
    }

    /**
     * GET /api/v1/jobs/{job}
     *
     * By uuid. A 404 here is an ordinary outcome rather than an error — a run
     * that has passed its retention has been pruned, and a client polling one
     * should treat that as "finished, and long ago".
     */
    public function show(string $job): JsonResponse
    {
        return ApiResponse::success(new JobRunResource($this->runs->find($job)));
    }
}
