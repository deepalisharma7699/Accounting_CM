<?php

namespace App\Http\Requests\WorkshopJob;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Booking a motor in — the brief's §16.
 *
 * Shape only, as every store request in this application is. Whether the party
 * exists, holds the customer role and is not archived belongs to
 * {@see \App\Services\Workshop\JobService}, which every entry point passes
 * through — a check in one controller says nothing about the next one.
 *
 * Note how little is required: the customer, what is wrong, and when it arrived.
 * The rest of the motor is optional on purpose. A pump is wheeled in at four in
 * the afternoon by a driver who does not know its brand, and a form that refused
 * to book it in would be a form that got a job card written on paper instead.
 */
class StoreJobRequest extends FormRequest
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
            'party_id' => ['required', 'integer', 'min:1'],

            // The catalogue entry for this kind of motor, where the workshop
            // happens to have one. Optional, and the free text below is not a
            // fallback for it — see the migration.
            'item_id' => ['nullable', 'integer', 'min:1'],
            // A string rather than a number: "7.5", "1/2" and "0.5" are all
            // things a plate says, and parsing them into a decimal would be
            // deciding that one of them is wrong.
            'hp' => ['nullable', 'string', 'max:20'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'serial_no' => ['nullable', 'string', 'max:60'],
            'phase' => ['nullable', 'string', 'max:20'],

            // Required. A motor with no complaint is a motor nobody can say why
            // they have.
            'complaint' => ['required', 'string', 'max:1000'],

            'received_date' => ['nullable', 'date_format:Y-m-d'],
            // Refused before the motor arrived, which would put the job at the
            // top of an overdue list on the day it was written. Restated as a
            // CHECK constraint in the database, where nothing can bypass it.
            'promised_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:received_date'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'party_id.required' => 'Say whose motor this is.',
            'complaint.required' => 'Say what the customer reported — it is what the job is for.',
            'promised_date.after_or_equal' => 'The promised date cannot be before the motor arrived.',
            'received_date.date_format' => 'Give the date it arrived as YYYY-MM-DD.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'party_id' => (int) $this->input('party_id'),
            'item_id' => $this->filled('item_id') ? (int) $this->input('item_id') : null,
            'hp' => $this->input('hp'),
            'brand' => $this->input('brand'),
            'model' => $this->input('model'),
            'serial_no' => $this->input('serial_no'),
            'phase' => $this->input('phase'),
            'complaint' => (string) $this->string('complaint'),
            // Today where none was given. A motor that arrived is on the bench
            // now, and making somebody type the date they are standing in is the
            // kind of friction that ends in a paper job card.
            'received_date' => $this->filled('received_date')
                ? (string) $this->string('received_date')
                : now()->toDateString(),
            'promised_date' => $this->filled('promised_date')
                ? (string) $this->string('promised_date')
                : null,
            'notes' => $this->input('notes'),
        ];
    }
}
