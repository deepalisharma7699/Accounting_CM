<?php

namespace App\Http\Requests\Party;

use App\Enums\PartyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPartyRequest extends FormRequest
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
            'role' => ['nullable', Rule::enum(PartyRole::class)],
            'is_active' => ['nullable', 'boolean'],
            'has_gstin' => ['nullable', 'boolean'],
            // Outstanding figures cost a second query over the ledger. Worth it
            // on the parties screen and pure waste on a picker, so the caller
            // says which it is rather than the server guessing.
            'with_position' => ['nullable', 'boolean'],
            // When the workshop last dealt with each of them. A second query
            // over the transactions, and opt-in for the same reason: the
            // customer and vendor screens show the date in a column, and
            // nothing else has any use for it.
            'with_activity' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array{search: string|null, role: string|null, is_active: bool|null, has_gstin: bool|null, sort: string|null, direction: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'role' => $this->input('role'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'has_gstin' => $this->has('has_gstin') ? $this->boolean('has_gstin') : null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function wantsPosition(): bool
    {
        return $this->boolean('with_position');
    }

    public function wantsActivity(): bool
    {
        return $this->boolean('with_activity');
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
