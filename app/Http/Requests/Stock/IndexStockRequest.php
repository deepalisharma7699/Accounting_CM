<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The stock screen's filters.
 *
 * `status` is the one worth noticing: it filters on a figure that is not a
 * column — the sum of a variant's movements — which is why the service pages in
 * PHP rather than the database. See
 * {@see \App\Services\Inventory\StockLedgerService::report()}.
 */
class IndexStockRequest extends FormRequest
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
            'item_id' => ['nullable', 'integer', 'min:1'],
            /*
            | Named variants, for a caller that already knows which rows it is
            | asking about — the bill form bringing the "on hand" figure beside
            | each of its lines back up to date after a sale moved the shelf.
            |
            | Capped at the page size, because the alternative to this filter was
            | one request per line and the alternative to the cap is a bill of
            | four hundred rows becoming a single unbounded report.
            */
            'variant_ids' => ['nullable', 'array', 'max:200'],
            'variant_ids.*' => ['integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            // Archived variants keep their stock, so this defaults to active-only
            // rather than enforcing it.
            'is_active' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['low', 'below_minimum', 'negative', 'out', 'in_stock'])],
            'sort' => ['nullable', Rule::in(['name', 'quantity', 'value', 'cost'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'item_id' => $this->filled('item_id') ? (int) $this->input('item_id') : null,
            'variant_ids' => $this->filled('variant_ids')
                ? array_map('intval', (array) $this->input('variant_ids'))
                : null,
            'category_id' => $this->filled('category_id') ? (int) $this->input('category_id') : null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            'status' => $this->input('status'),
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 50);
    }
}
