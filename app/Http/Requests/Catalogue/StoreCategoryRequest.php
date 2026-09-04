<?php

namespace App\Http\Requests\Catalogue;

use App\Services\Inventory\ItemCategoryService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A category, new or edited.
 *
 * One class for both, because a category has no field that is required on create
 * and forbidden on update — `name` is the only thing it cannot do without, and
 * `sometimes` makes a PATCH that omits it a no-op rather than a refusal.
 *
 * Shape only. Whether the name is taken, whether the parent would make a cycle,
 * and whether stock may be turned off while products still hold some are all
 * {@see ItemCategoryService}'s business — the same rules have to hold for the
 * template importer, which does not come through a form request.
 */
class StoreCategoryRequest extends FormRequest
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

            // Null is a legitimate value — it means "top level" — so this is
            // `present`-friendly rather than `filled`. That the parent exists and
            // would not make a cycle is the service's business.
            'parent_id' => ['nullable', 'integer', 'min:1'],

            'holds_stock' => ['nullable', 'boolean'],
            'uses_sac_code' => ['nullable', 'boolean'],

            // A unit code, not an id: the units table is keyed by code
            // everywhere else, and the service drops anything it cannot resolve
            // rather than refusing the whole category over a default.
            'default_unit_code' => ['nullable', 'string', 'max:20'],
            'default_hsn_sac' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],

            // Nullable and meaningfully so: null means "this category has no
            // opinion, ask", and 0 means "zero rated".
            'default_gst_rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],

            'display_order' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the category a name — it is what the create form offers in its dropdown.',
            'default_hsn_sac.regex' => 'An HSN or SAC code is 4 to 8 digits.',
            'default_gst_rate.between' => 'A GST rate is a percentage between 0 and 100 — 18, not 0.18.',
        ];
    }

    /**
     * Only the keys that were actually sent.
     *
     * The service distinguishes "absent" from "sent as null" — clearing a
     * default is a real edit and must not look like not mentioning it — so the
     * payload is built by intersection rather than by reading every key.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $keys = [
            'name', 'code', 'description', 'parent_id', 'holds_stock', 'uses_sac_code',
            'default_unit_code', 'default_hsn_sac', 'default_gst_rate', 'display_order', 'is_active',
        ];

        $payload = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $payload[$key] = match ($key) {
                'holds_stock', 'uses_sac_code', 'is_active' => $this->boolean($key),
                'parent_id', 'display_order' => $this->input($key) === null ? null : (int) $this->input($key),
                default => $this->input($key),
            };
        }

        return $payload;
    }
}
