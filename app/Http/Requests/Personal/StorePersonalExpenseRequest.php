<?php

namespace App\Http\Requests\Personal;

use App\Enums\PersonalPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Writing or correcting one personal diary entry. One request for both: on a
 * PATCH every field is optional, and only what is sent changes.
 */
class StorePersonalExpenseRequest extends FormRequest
{
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'date' => [$required, 'date_format:Y-m-d'],
            'category_id' => [$required, 'integer', 'min:1'],
            'amount' => [$required, 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'paid_by' => [$required, Rule::enum(PersonalPaymentMethod::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'Choose what it was for.',
            'amount.required' => 'Enter how much was spent.',
            'amount.gt' => 'Enter an amount greater than zero.',
            'amount.decimal' => 'Enter the amount in rupees and paise — at most two decimals.',
            'paid_by.required' => 'Say how it was paid.',
            'notes.not_regex' => 'A note cannot contain < or > or hidden control characters.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['date', 'amount', 'paid_by'] as $key) {
            if ($this->has($key)) {
                $payload[$key] = (string) $this->input($key);
            }
        }

        if ($this->has('category_id')) {
            $payload['category_id'] = (int) $this->input('category_id');
        }

        if ($this->has('notes')) {
            $notes = trim((string) $this->input('notes'));
            $payload['notes'] = $notes === '' ? null : $notes;
        }

        return $payload;
    }
}
