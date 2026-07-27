<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
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
            // `present` + nullable: sending null is how a role is unassigned.
            'role_id' => ['present', 'nullable', 'integer', 'exists:roles,id'],
        ];
    }

    public function roleId(): ?int
    {
        return $this->filled('role_id') ? (int) $this->input('role_id') : null;
    }
}
