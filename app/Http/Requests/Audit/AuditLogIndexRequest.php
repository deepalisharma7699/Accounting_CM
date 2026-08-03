<?php

namespace App\Http\Requests\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtering the trail — M13.
 *
 * `resource` and `action` are validated against their enums rather than left
 * open, which is the opposite of what {@see \App\Http\Requests\Report\ReportPeriodRequest}
 * does with its period, and the difference is deliberate. A stale report preset
 * should fall back to something and draw, because a report that refuses teaches
 * people the reports are broken. An unknown *filter* on an audit trail is the
 * other case entirely: silently ignoring it would show a complete history to
 * somebody who believes they are looking at a filtered one, and they would draw
 * a conclusion from the difference.
 */
class AuditLogIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'resource' => ['nullable', Rule::in(AuditResource::values())],
            // Only meaningful alongside a resource — one record's own history.
            // The pairing is enforced in withValidator() below, so asking for
            // "everything about record 12" gets an explanation instead of a
            // silent sweep across the parties, items and accounts that happen to
            // share an id.
            'resource_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', Rule::in(AuditAction::values())],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resource.in' => 'That is not a kind of record this workshop keeps a history for.',
            'action.in' => 'That is not something that can happen to a record.',
            'from.date_format' => 'Give the start date as YYYY-MM-DD.',
            'to.date_format' => 'Give the end date as YYYY-MM-DD.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('resource_id') && ! $this->filled('resource')) {
                $validator->errors()->add(
                    'resource',
                    'Say which kind of record that id belongs to — ids are only unique within a kind.',
                );
            }
        });
    }

    /**
     * @return array{
     *     search: string|null,
     *     resource: string|null,
     *     resource_id: int|null,
     *     action: string|null,
     *     actor_id: int|null,
     *     from: string|null,
     *     to: string|null
     * }
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'resource' => $this->input('resource'),
            'resource_id' => $this->filled('resource_id') ? (int) $this->input('resource_id') : null,
            'action' => $this->input('action'),
            'actor_id' => $this->filled('actor_id') ? (int) $this->input('actor_id') : null,
            // Swapped rather than refused when they arrive the wrong way round,
            // the same courtesy the reports extend: a screen that will not draw
            // teaches people it is broken.
            'from' => $this->earlier(),
            'to' => $this->later(),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 50);
    }

    private function earlier(): ?string
    {
        return $this->swapped() ? $this->input('to') : $this->input('from');
    }

    private function later(): ?string
    {
        return $this->swapped() ? $this->input('from') : $this->input('to');
    }

    private function swapped(): bool
    {
        return $this->filled('from')
            && $this->filled('to')
            && $this->input('from') > $this->input('to');
    }
}
