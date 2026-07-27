<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class SyncPermissionsRequest extends FormRequest
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
            // Required (not "sometimes"): this endpoint replaces the whole
            // set, so an empty array must be sent explicitly to clear it.
            'permission_ids' => ['present', 'array', 'max:500'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function permissionIds(): array
    {
        return array_map('intval', (array) $this->input('permission_ids', []));
    }
}
