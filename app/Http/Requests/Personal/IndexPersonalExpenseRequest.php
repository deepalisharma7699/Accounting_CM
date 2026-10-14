<?php

namespace App\Http\Requests\Personal;

use App\Enums\PersonalPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPersonalExpenseRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'paid_by' => ['nullable', Rule::enum(PersonalPaymentMethod::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'category_id' => $this->filled('category_id') ? (int) $this->input('category_id') : null,
            'paid_by' => $this->input('paid_by'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
