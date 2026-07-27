<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level middleware (auth.jwt + permission) owns authorization.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[\pL\pN][\pL\pN \-_]*$/u'],
            'description' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['sometimes', 'array', 'max:500'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The role name may only contain letters, numbers, spaces, hyphens and underscores.',
            'permission_ids.*.exists' => 'One or more of the selected permissions do not exist.',
        ];
    }

    /**
     * @return array{name: string, description: string|null, permission_ids: array<int, int>}
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'description' => $this->input('description'),
            'permission_ids' => array_map('intval', (array) $this->input('permission_ids', [])),
        ];
    }
}
