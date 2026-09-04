<?php

namespace App\Http\Requests\Transaction;

use App\Services\Staff\WorkAttributionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting who did the work on a sale — M22.
 *
 * The one edit this application allows against a document that is already in the
 * books, and it is allowed because it moves no figure: no ledger entry, no stock
 * movement, no total, nothing on the customer's copy. See the
 * `transaction_staff` migration for why write-once would be the wrong rule and
 * why "reverse it and reissue" is not a way out on a sale.
 *
 * `staff` is **required**, and the whole set is sent every time. A PATCH that
 * accepted one trade at a time could never express "the winder box is now
 * empty", and a mis-picked name that could only be replaced and never removed is
 * half a correction. Whether each trade is one this workshop asks about and
 * whether the person is on its staff list belong to
 * {@see WorkAttributionService}.
 */
class UpdateTransactionStaffRequest extends FormRequest
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
            // `present` rather than `required`: an empty array is a legitimate
            // instruction — it clears every name off the document — and
            // `required` would reject exactly that.
            'staff' => ['present', 'array', 'max:10'],
            'staff.*.designation_id' => ['required', 'integer', 'min:1'],
            'staff.*.employee_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'staff.present' => 'Send the whole set of trades, including the ones left empty.',
        ];
    }

    /**
     * @return array<int, array{designation_id: mixed, employee_id: mixed}>
     */
    public function payload(): array
    {
        return array_map(fn (array $pair) => [
            'designation_id' => $pair['designation_id'] ?? null,
            'employee_id' => ($pair['employee_id'] ?? null) === '' ? null : ($pair['employee_id'] ?? null),
        ], array_values((array) $this->input('staff', [])));
    }
}
