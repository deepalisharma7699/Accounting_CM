<?php

namespace App\Http\Requests\Staff;

use App\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdvanceRequest extends FormRequest
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
            'employee_id' => ['nullable', 'integer', 'min:1'],
            // Posted or reversed. A reversed advance stays on the list rather
            // than disappearing — it is money that went out and came back, and a
            // list that hid it would be a list somebody could not reconcile
            // against a cash box.
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array{employee_id: int|null, status: string|null, from: string|null, to: string|null}
     */
    public function filters(): array
    {
        return [
            'employee_id' => $this->filled('employee_id') ? (int) $this->input('employee_id') : null,
            'status' => $this->input('status'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
