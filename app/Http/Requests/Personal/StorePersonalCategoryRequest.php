<?php

namespace App\Http\Requests\Personal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding, renaming, archiving or restoring a personal category.
 */
class StorePersonalCategoryRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80', 'not_regex:/[\x00-\x1F\x7F<>]/u'],
            // A new category is always active.
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('name')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the category a name.',
            'name.not_regex' => 'A category cannot contain < or > or hidden control characters.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = (string) $this->input('name');
        }

        if ($this->has('is_active')) {
            $payload['is_active'] = $this->boolean('is_active');
        }

        return $payload;
    }
}
