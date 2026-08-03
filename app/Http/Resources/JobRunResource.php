<?php

namespace App\Http\Resources;

use App\Models\JobRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One piece of background work, as a client sees it — M14.
 *
 * `is_settled` is sent as well as `status`, and it is what a polling client
 * stops on. A client keyed to a list of statuses of its own would keep polling
 * for ever the first time a state was added; asking the server "is this
 * finished" moves that decision to the side that knows.
 *
 * @mixin JobRun
 */
class JobRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_settled' => $this->status->isSettled(),

            'progress' => $this->progress,
            'processed' => $this->processed,
            'total' => $this->total,
            'message' => $this->message,

            // Sent beside the progress on purpose. Progress is a number a worker
            // last wrote, so a worker killed mid-run leaves it frozen; the
            // elapsed time is computed and is what distinguishes "working" from
            // "stuck".
            'elapsed_seconds' => $this->elapsedSeconds(),

            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // One line, for a person. The stack trace is `failed_jobs`' business.
            'error' => $this->errorMessage(),

            'result' => $this->result,
            'payload' => $this->payload,

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
