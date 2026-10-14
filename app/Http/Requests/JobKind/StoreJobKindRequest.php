<?php

namespace App\Http\Requests\JobKind;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing a kind of thing the workshop takes in.
 *
 * Deliberately short. A kind is a name, a description and a place in the list —
 * everything that describes a thing for *sale* (HSN or SAC, a GST rate, a unit,
 * whether it is kept in stock) belongs to a category and means nothing about
 * something standing on a bench. See {@see \App\Models\JobKind}.
 *
 * Whether the name is already taken is the service's, not a `unique` rule here:
 * the refusal has a remedy attached to it, and a validator cannot say "there is
 * already a kind called that, and two would split the bench's own history".
 */
class StoreJobKindRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => (string) $this->string('name'),
            'description' => $this->input('description'),
            'display_order' => (int) $this->input('display_order', 0),
        ];
    }
}
