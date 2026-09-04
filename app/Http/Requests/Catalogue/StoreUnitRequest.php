<?php

namespace App\Http\Requests\Catalogue;

use App\Services\Inventory\UnitService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A unit, new or edited.
 *
 * `code` is accepted on create and ignored on update. It is what
 * `items.base_uom`, `transaction_lines.unit` and `workshop_job_parts.unit` all
 * store, and posted documents store it as a *copy* of what was true when they
 * were issued — so renaming 'kg' to 'metre' would silently reinterpret every
 * quantity ever recorded. See {@see UnitService}.
 */
class StoreUnitRequest extends FormRequest
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
            'label' => [$required, 'string', 'min:1', 'max:60'],

            // Optional: derived from the label when nobody gives one, so an
            // admin types "Bundle" and gets `bundle` without being asked about
            // codes.
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z0-9_ -]*$/'],

            'symbol' => ['nullable', 'string', 'max:12'],

            'kind' => ['nullable', Rule::in(['count', 'weight', 'length', 'volume', 'time', 'electrical', 'other'])],

            // How many decimal places a quantity in this unit is recorded to, and
            // therefore whether a fraction of one means anything at all. Capped
            // at 3 because `stock_movements.quantity` is DECIMAL(15,3) — a unit
            // promising four places would promise precision the database rounds
            // away.
            'decimals' => ['nullable', 'integer', 'between:0,3'],

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
            'label.required' => 'Give the unit a name — "Kilogram", "Box", "Metre".',
            'decimals.between' => 'A unit records between 0 and 3 decimal places. 0 means whole numbers only.',
            'code.regex' => 'A unit code starts with a letter and contains only letters, numbers, spaces, hyphens and underscores.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $keys = ['label', 'symbol', 'kind', 'decimals', 'display_order', 'is_active'];

        if ($this->isMethod('POST')) {
            $keys[] = 'code';
        }

        $payload = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $payload[$key] = match ($key) {
                'is_active' => $this->boolean($key),
                'decimals', 'display_order' => $this->input($key) === null ? null : (int) $this->input($key),
                default => $this->input($key),
            };
        }

        return $payload;
    }
}
