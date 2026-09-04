<?php

namespace App\Http\Requests\WorkshopJob;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The quotation — §18.
 *
 * Same line shape as a part and as a bill line, which is the whole point:
 * approving an estimate and turning it into parts is a copy, and the only thing
 * that can differ between what was quoted and what was billed is something
 * somebody deliberately changed.
 *
 * Replacing, not merging. An estimate is one document — "here is what this will
 * cost" — and a PATCH that added lines to it would produce a quotation nobody
 * ever saw as a whole. The service clears the approval when this lands, because a
 * customer who agreed to a figure has not agreed to a different one.
 */
class SaveEstimateRequest extends FormRequest
{
    /** Matches DECIMAL(15, 2). */
    private const MAX_AMOUNT = '9999999999999.99';

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
            // An empty array is allowed and clears the estimate — "we quoted, and
            // now we are not quoting" is a real thing that happens, and forcing a
            // workshop to leave a stale quotation on a job is worse.
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.item_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.present' => 'Send the estimate lines, even if the list is empty.',
            'lines.*.quantity.gt' => 'Each quoted line needs a quantity greater than zero.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lines(): array
    {
        return array_map(fn (array $line) => [
            'item_id' => ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null),
            'variant_id' => ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null),
            'quantity' => $line['quantity'] ?? 0,
            'unit_price' => ($line['unit_price'] ?? null) === '' ? null : ($line['unit_price'] ?? null),
            'discount' => $line['discount'] ?? 0,
            'memo' => $line['memo'] ?? null,
        ], array_values((array) $this->input('lines', [])));
    }

    public function notes(): ?string
    {
        return $this->filled('notes') ? trim((string) $this->string('notes')) : null;
    }
}
