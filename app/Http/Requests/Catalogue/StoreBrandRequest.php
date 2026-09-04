<?php

namespace App\Http\Requests\Catalogue;

use App\Services\Inventory\ItemBrandService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A brand, new or edited.
 *
 * One class for both, for the same reason {@see StoreCategoryRequest} is one: a
 * brand has no field that is required on create and forbidden on update, and
 * `sometimes` makes a PATCH that omits the name a no-op rather than a refusal.
 *
 * Shape only. Whether the name is taken and whether a brand products carry may be
 * removed are {@see ItemBrandService}'s business — the same rules have to hold
 * for the importer, which does not come through a form request.
 */
class StoreBrandRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'display_order' => ['nullable', 'integer', 'between:0,65535'],

            // The archive control — what a brand products already carry gets
            // instead of a delete.
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the brand a name — it is what the create form offers in its dropdown.',
        ];
    }

    /**
     * Only the keys that were actually sent, so absent means unchanged and an
     * explicit null clears.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'code', 'description', 'display_order', 'is_active'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $payload[$key] = match ($key) {
                'is_active' => $this->boolean($key),
                'display_order' => $this->input($key) === null ? null : (int) $this->input($key),
                default => $this->input($key),
            };
        }

        return $payload;
    }
}
