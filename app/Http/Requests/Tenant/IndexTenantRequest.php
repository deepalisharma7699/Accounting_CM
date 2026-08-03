<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTenantRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
            'sort' => ['nullable', 'string', Rule::in(['id', 'name', 'slug', 'status', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * @return array{search: string|null, status: string|null, sort: string|null, direction: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'status' => $this->input('status'),
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 15);
    }
}
