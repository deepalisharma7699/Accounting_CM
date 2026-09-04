<?php

namespace App\Http\Requests\Staff;

use App\Services\Staff\DesignationService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or renaming a designation — M22.
 *
 * One request for both, because the fields are the same three and the
 * difference is which of them may be absent. Whether the name is taken and whether the row
 * may be archived are {@see DesignationService}'s business.
 */
class StoreDesignationRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'name' => [
                $creating ? 'required' : 'sometimes',
                'string', 'min:2', 'max:80', 'not_regex:/[\x00-\x1F\x7F<>]/u',
            ],

            // The archive control. Absent on a create, where a new designation
            // is always active — offering "add this, switched off" would be a
            // form asking a question nobody has.
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],

            /*
            | Whether the sale form asks for this trade by name — M22.
            |
            | Offered on a create as well as an edit, unlike `is_active`: a
            | workshop adding "Varnisher" because it has started varnishing very
            | often wants it on the invoice screen in the same breath, and making
            | that a second trip to a second control would mean the box stays
            | unticked and the work goes unattributed.
            |
            | Optional either way, and defaulted to false by the column — a trade
            | nobody said anything about does not appear on the counter's screen.
            */
            'track_on_sales' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge([
                'name' => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('name'))),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the designation a name — Fitter, Winder, Helper.',
            'name.not_regex' => 'A designation cannot contain < or > or hidden control characters.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim((string) $this->string('name'));
        }

        if ($this->has('is_active')) {
            $payload['is_active'] = $this->boolean('is_active');
        }

        if ($this->has('track_on_sales')) {
            $payload['track_on_sales'] = $this->boolean('track_on_sales');
        }

        return $payload;
    }
}
