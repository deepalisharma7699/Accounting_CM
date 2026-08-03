<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\AttachmentIndexRequest;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\JobRunResource;
use App\Models\Attachment;
use App\Services\Storage\AttachmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stored evidence — M14.
 *
 * There is no PATCH and no PUT here, and there never will be: a file's bytes do
 * not change, so correcting a bad photograph means taking another one. That is
 * also why {@see \App\Enums\PermissionResource::Attachments} has no UPDATE
 * grant — an authority over an operation that does not exist would be a lie in
 * the permission catalogue.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * GET /api/v1/attachments/meta
     *
     * What may be uploaded, and how big. Published rather than written into the
     * screen, so an upload control's `accept` attribute and its size warning
     * come from the same numbers the API enforces — a copy in the browser is
     * right until an operator raises a limit, and then it refuses files the
     * server would take.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'kinds' => AttachmentKind::catalogue(),
            'statuses' => array_map(
                fn (AttachmentStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                AttachmentStatus::cases(),
            ),
        ]);
    }

    /**
     * GET /api/v1/attachments
     */
    public function index(AttachmentIndexRequest $request): JsonResponse
    {
        $page = $this->attachments->paginate($request->filters(), $request->perPage());

        return ApiResponse::paginated($page, AttachmentResource::class);
    }

    /**
     * GET /api/v1/attachments/{attachment}
     *
     * The one place a signed URL is minted, and only for a single record — it is
     * a bearer credential for an object, so it is issued when somebody asks for
     * that object rather than sprayed across every row of a listing.
     */
    public function show(int $attachment): JsonResponse
    {
        $record = $this->attachments->find($attachment);

        return ApiResponse::success(
            new AttachmentResource($record, $this->attachments->temporaryUrl($record)),
        );
    }

    /**
     * POST /api/v1/attachments
     *
     * Returns as soon as the bytes are in object storage and the row is written.
     * Whether the object can be read back is a separate claim, and it is made by
     * the job whose handle comes back in `meta.job` — poll `GET /jobs/{id}`, or
     * re-read this attachment and watch `is_usable`.
     */
    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $result = $this->attachments->upload(
            $request->uploadedFile(),
            $request->kind(),
            $request->user(),
        );

        return ApiResponse::created(
            new AttachmentResource($result['attachment']),
            'File uploaded. It is being checked in the background.',
            [
                'job' => $result['job'] === null
                    ? null
                    : (new JobRunResource($result['job']))->resolve($request),
                // Said, never acted on: a workshop that photographs one invoice
                // twice has done something reasonable. The same treatment a
                // shared GSTIN gets in M5 and a duplicate specification in M7.
                'duplicates' => $result['duplicates']->map(fn (Attachment $existing) => [
                    'id' => $existing->id,
                    'name' => $existing->original_name,
                    'created_at' => $existing->created_at?->toIso8601String(),
                ])->all(),
            ],
        );
    }

    /**
     * GET /api/v1/attachments/{attachment}/download
     *
     * Streamed through the application, as an attachment rather than inline, so
     * nothing a workshop uploads can be rendered by a browser inside this
     * application's origin. Always available — the signed URL on `show` is an
     * optimisation for production storage, not the only way in.
     */
    public function download(int $attachment): StreamedResponse
    {
        return $this->attachments->stream($this->attachments->find($attachment));
    }

    /**
     * DELETE /api/v1/attachments/{attachment}
     *
     * The row goes, then the object. Recorded on M13's trail, which is the one
     * thing worth recording about a stored file: unlike an archived party, it
     * leaves nothing behind.
     */
    public function destroy(int $attachment): JsonResponse
    {
        $deleted = $this->attachments->delete($attachment);

        return ApiResponse::message("Deleted \"{$deleted->original_name}\".");
    }
}
