<?php

namespace App\Http\Requests\Item;

use App\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexItemRequest extends FormRequest
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
            'type' => ['nullable', Rule::enum(ItemType::class)],
            'is_active' => ['nullable', 'boolean'],
            'is_stock' => ['nullable', 'boolean'],
            // The review queue: everything an importer or the capture agent
            // invented and nobody has looked at yet.
            'is_draft' => ['nullable', 'boolean'],
            // Variants cost a second query for the page. Worth it on the items
            // screen, pure waste on a picker that only needs family names, so the
            // caller says which it is rather than the server guessing — the same
            // bargain as `with_position` on parties.
            'with_variants' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'code', 'created_at'])],
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
            'type' => $this->input('type'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'is_stock' => $this->has('is_stock') ? $this->boolean('is_stock') : null,
            'is_draft' => $this->has('is_draft') ? $this->boolean('is_draft') : null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function wantsVariants(): bool
    {
        return $this->boolean('with_variants');
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
