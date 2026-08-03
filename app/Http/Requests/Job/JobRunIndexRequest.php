<?php

namespace App\Http\Requests\Job;

use App\Enums\JobStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtering the list of background work — M14.
 */
class JobRunIndexRequest extends FormRequest
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
            // Not validated against a list of job types. The set grows with
            // every module, and an enum of them would have to be edited in step
            // with the jobs — a filter that quietly matches nothing is a small
            // cost against a registry that goes stale.
            'type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(JobStatus::values())],
            // "Anything still going" — the query behind a badge, and the one a
            // polling client makes most often. A flag rather than two statuses,
            // so a client is not maintaining its own idea of which states mean
            // "not finished".
            'unsettled' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{type: string|null, status: string|null, unsettled: bool}
     */
    public function filters(): array
    {
        return [
            'type' => $this->input('type'),
            'status' => $this->input('status'),
            'unsettled' => $this->boolean('unsettled'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 25);
    }
}
