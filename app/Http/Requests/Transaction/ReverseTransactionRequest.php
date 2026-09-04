<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class ReverseTransactionRequest extends FormRequest
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
            // Defaults to today rather than to the original's date: a
            // correction belongs in the period it was decided in, unless the
            // person correcting it says otherwise — which is what an accountant
            // closing a month needs.
            'date' => ['nullable', 'date_format:Y-m-d'],

            // Why, in the words of whoever noticed. It becomes the reversing
            // transaction's notes, so the record explains itself later.
            'reason' => ['nullable', 'string', 'max:500'],

            // "I know this takes the shelf below zero and I want it anyway."
            //
            // Absent on the first attempt, always: reversing a purchase whose
            // stock has already gone out is refused first and only goes through
            // when somebody says so — see
            // {@see \App\Services\Accounting\PostingEngine::assertReversalKeepsStockWhole()}.
            // A client that sent this by default would be turning the refusal
            // back off, which is why nothing sets it but the second attempt.
            'acknowledge_negative_stock' => ['sometimes', 'boolean'],
        ];
    }
}
