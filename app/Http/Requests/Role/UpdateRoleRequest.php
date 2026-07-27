<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:64', 'regex:/^[\pL\pN][\pL\pN \-_]*$/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permission_ids' => ['sometimes', 'array', 'max:500'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }

    /**
     * Only the keys the client actually sent, so an omitted field is left
     * untouched rather than being overwritten with null.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = [];

        if ($this->has('name')) {
            $data['name'] = trim((string) $this->string('name'));
        }

        if ($this->has('description')) {
            $data['description'] = $this->input('description');
        }

        if ($this->has('permission_ids')) {
            $data['permission_ids'] = array_map('intval', (array) $this->input('permission_ids', []));
        }

        return $data;
    }
}
