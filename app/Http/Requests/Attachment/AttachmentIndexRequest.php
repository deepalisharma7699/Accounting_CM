<?php

namespace App\Http\Requests\Attachment;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtering the file library — M14.
 */
class AttachmentIndexRequest extends FormRequest
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
            'kind' => ['nullable', Rule::in(AttachmentKind::values())],
            'status' => ['nullable', Rule::in(AttachmentStatus::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{kind: string|null, status: string|null, search: string|null}
     */
    public function filters(): array
    {
        return [
            'kind' => $this->input('kind'),
            'status' => $this->input('status'),
            'search' => $this->input('search'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 25);
    }
}
