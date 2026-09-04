<?php

namespace App\Http\Requests\Transaction;

use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use App\Services\Accounting\ReturnService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "The customer brought one of the four bearings back" — M18.
 *
 * Notice how little this carries. No prices, no tax, no accounts and no items:
 * a line number and a quantity, against a bill the URL names. Everything else is
 * read off the invoice being credited, by {@see ReturnService}, and that is the
 * point — a credit note has to give back what was actually charged, and a
 * payload that could state its own prices would be a payload that could give back
 * something else.
 *
 * It is also what makes the screen usable: somebody at a counter with goods in
 * their hand ticks lines and types quantities, and never re-enters a figure the
 * system already knows.
 *
 * Shape only. That the bill is returnable, that the line exists, and that this
 * much of it is still outstanding are all the service's business — every entry
 * point passes through it, and a rule enforced in one controller says nothing
 * about the others.
 */
class StoreReturnRequest extends FormRequest
{
    use CarriesClientRef;

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
            // Today where none is given. Dated when the goods came back rather
            // than when the invoice was written — see ReturnService.
            'date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],

            // A retry after a timeout must not become a second credit note —
            // M17, and it matters more here than anywhere: a duplicate return
            // puts stock back on the shelf twice.
            ...$this->clientRefRules(),

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_no' => ['required', 'integer', 'min:1'],
            // Three decimals, matching the quantity column: 2.5 kg of copper can
            // come back, and whether a fraction is meaningful for *this* item is
            // the unit's business, checked further down.
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Say which lines are being returned, and how many of each.',
            'lines.*.line_no.required' => 'Each returned line needs the line number it is on the bill.',
            'lines.*.quantity.gt' => 'A returned quantity has to be greater than zero.',
        ];
    }

    /**
     * @return array<int, array{line_no: int, quantity: string}>
     */
    public function lines(): array
    {
        return array_map(fn (array $line) => [
            'line_no' => (int) ($line['line_no'] ?? 0),
            'quantity' => (string) ($line['quantity'] ?? '0'),
        ], array_values((array) $this->input('lines', [])));
    }
}
