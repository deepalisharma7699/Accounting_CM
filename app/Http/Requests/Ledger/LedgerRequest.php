<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by the account ledger and the trial balance: a period, and nothing
 * else. Both are pure reads of `journal_entries`.
 */
class LedgerRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    public function period(): array
    {
        return [
            'from' => $this->input('from'),
            'to' => $this->input('to'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 50);
    }
}
