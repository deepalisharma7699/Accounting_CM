<?php

namespace App\Http\Requests\Account;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
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
            // Four digits, banded by type. The band itself is checked in
            // ChartOfAccountService, which knows the ranges.
            'code' => ['required', 'string', 'digits:4'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.digits' => 'An account code is four digits, matching its type — see the account type bands.',
        ];
    }

    /**
     * @return array{code: string, name: string, description: string|null, type: string}
     */
    public function payload(): array
    {
        return [
            'code' => (string) $this->string('code'),
            'name' => trim((string) $this->string('name')),
            'description' => $this->filled('description') ? trim((string) $this->string('description')) : null,
            'type' => (string) $this->string('type'),
        ];
    }
}
