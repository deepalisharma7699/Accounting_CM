<?php

namespace App\Http\Requests\Transaction;

use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who a walk-in sale turns out to have been for.
 *
 * Shape only. Whether the party exists in this workshop, is active and holds the
 * customer role is the posting engine's check, the same as on any sale; whether
 * the sale may take a customer at all is the service's.
 */
class AssignCustomerRequest extends FormRequest
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
            'party_id' => ['required', 'integer', 'min:1'],
            // The reissued invoice is a new document, so a retry after a timeout
            // must land on the one the first attempt wrote — M17.
            ...$this->clientRefRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the customer this invoice is for.',
        ];
    }
}
