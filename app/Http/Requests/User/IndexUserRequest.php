<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'sort' => ['nullable', 'string', Rule::in(['id', 'name', 'email', 'status', 'created_at', 'last_login_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * @return array{search: string|null, status: string|null, role_id: int|null, sort: string|null, direction: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'status' => $this->input('status'),
            'role_id' => $this->has('role_id') ? (int) $this->input('role_id') : null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 15);
    }
}
