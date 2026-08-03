<?php

namespace App\Http\Requests\Account;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAccountRequest extends FormRequest
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
            'type' => ['nullable', Rule::enum(AccountType::class)],
            'is_active' => ['nullable', 'boolean'],
            'is_system' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(['code', 'name', 'type', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array{search: string|null, type: string|null, is_active: bool|null, is_system: bool|null, sort: string|null, direction: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'type' => $this->input('type'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'is_system' => $this->has('is_system') ? $this->boolean('is_system') : null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function perPage(): int
    {
        // A chart of accounts is small — fifteen seeded plus a handful of the
        // workshop's own — so the default page holds the whole thing.
        return (int) $this->input('per_page', 50);
    }
}
