<?php

namespace App\Http\Requests\WorkshopJob;

use App\Enums\WorkshopJobStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a job along the pipeline.
 *
 * Its own request and its own route, because a pipeline move is an event rather
 * than an edit: "the motor is ready" is a thing that happened, and it should not
 * be possible for it to arrive as a side effect of somebody fixing a typo in the
 * complaint.
 *
 * The rule here says the status is a real one. **Whether it is a legal move from
 * where the job is now is not checked here** — that lives on
 * {@see WorkshopJobStatus::canMoveTo()}, because it depends on the job, and a
 * form request that went and loaded the job to answer it would be doing the
 * service's work in a worse place.
 */
class UpdateJobStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(WorkshopJobStatus::class)],
            // What happened, in the fitter's words: "winding tested OK", "customer
            // refused the estimate". Optional, and worth having on the same
            // request as the move rather than as a separate edit — the reason is
            // never more available than at the moment somebody records the thing
            // it explains.
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Say what the job has moved to.',
        ];
    }

    public function status(): WorkshopJobStatus
    {
        return WorkshopJobStatus::from((string) $this->string('status'));
    }

    public function notes(): ?string
    {
        return $this->filled('notes') ? trim((string) $this->string('notes')) : null;
    }
}
