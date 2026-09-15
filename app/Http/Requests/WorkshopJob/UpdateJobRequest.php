<?php

namespace App\Http\Requests\WorkshopJob;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting the details of a job already on the bench — what came in, what its
 * kind asked about it, the complaint and the promise.
 *
 * `sometimes` throughout, so a PATCH that only moves the promised date leaves
 * everything else exactly as it was — and so an explicit `null` genuinely clears
 * a field, which a `?? $existing` chain in the service could not express.
 *
 * Two things are deliberately absent.
 *
 * **The customer.** An invoice may already have been raised against them, and
 * re-pointing the job would leave the bill explaining a repair for somebody
 * else. Booking a motor in against the wrong customer is corrected by cancelling
 * and re-booking, which is cheap while nothing has been billed and honest once
 * something has.
 *
 * **The status.** It has a verb of its own — `PUT {job}/status` — because a
 * pipeline move is an event rather than an edit, and because a status arriving
 * on the same request as a typo correction would let a mis-click deliver a motor
 * that is still on the bench.
 *
 * One pair is not independent. `category_id` decides which keys `specs` may
 * carry, so sending the kind without the answers clears them — the service says
 * why, and the form always sends both together.
 */
class UpdateJobRequest extends FormRequest
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
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'specs' => ['sometimes', 'nullable', 'array', 'max:40'],
            'specs.*' => ['nullable', 'string', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:60'],
            'model' => ['sometimes', 'nullable', 'string', 'max:60'],
            'serial_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'complaint' => ['sometimes', 'required', 'string', 'max:1000'],
            'promised_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'complaint.required' => 'A job needs a complaint. Clear it and there is nothing saying why the motor is here.',
        ];
    }

    /**
     * Only the keys that were actually sent, so absent means unchanged and an
     * explicit null means cleared.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $fields = [
            'item_id', 'category_id', 'specs',
            'brand', 'model', 'serial_no',
            'complaint', 'promised_date', 'notes',
        ];

        $payload = [];

        foreach ($fields as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        return $payload;
    }
}
