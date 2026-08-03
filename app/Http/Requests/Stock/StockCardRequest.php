<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The period a stock card covers. Nothing else — which variant it is about comes
 * from the URL.
 */
class StockCardRequest extends FormRequest
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
    public function filters(): array
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
