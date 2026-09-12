<?php

namespace App\Http\Requests\WorkshopJob;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Booking something in — the brief's §16.
 *
 * Shape only, as every store request in this application is. Whether the party
 * exists, holds the customer role and is not archived belongs to
 * {@see \App\Services\Workshop\JobService}, which every entry point passes
 * through — a check in one controller says nothing about the next one.
 *
 * Note how little is required: the customer, what is wrong, and when it arrived.
 * Everything describing the thing itself is optional on purpose — its kind
 * included. A pump is wheeled in at four in the afternoon by a driver who does
 * not know its brand, and a form that refused to book it in would be a form that
 * got a job card written on paper instead.
 *
 * There is no `hp` and no `phase` here any more, and there must not be again.
 * What a thing on the bench is described by is the question set its category
 * asks — `specs`, keyed by the same attributes a variant of that category
 * answers — because a cooler, a fan and a hand drill are none of them described
 * by a rating and a phase. Which keys are kept and why nothing is required is
 * {@see \App\Services\Workshop\JobService::normaliseSpecs()}.
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

            // The catalogue entry for this exact product, where the workshop
            // happens to sell one. Optional, and the free text below is not a
            // fallback for it — see the migration.
            'item_id' => ['nullable', 'integer', 'min:1'],

            // What kind of thing came in — an `item_categories` row. Whether it
            // exists, is active and can be a physical object belongs to the
            // service, which every entry point passes through.
            'category_id' => ['nullable', 'integer', 'min:1'],

            /*
            | What its kind asked about it, keyed by attribute — the same flat
            | bag `item_variants.attributes` holds.
            |
            | Strings throughout, whatever the attribute's declared data type,
            | and that is not laziness. "7.5", "1/2" and "0.5" are all things a
            | plate says, and parsing them into a decimal here would be deciding
            | that two of them are wrong about a motor the workshop did not
            | build. The catalogue holds its *own* values to their types,
            | because that is a product it chose to stock; this is a
            | competitor's forty-year-old unit.
            */
            'specs' => ['nullable', 'array', 'max:40'],
            'specs.*' => ['nullable', 'string', 'max:120'],

            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'serial_no' => ['nullable', 'string', 'max:60'],

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
            'party_id.required' => 'Say whose this is.',
            'complaint.required' => 'Say what the customer reported — it is what the job is for.',
            'promised_date.after_or_equal' => 'The promised date cannot be before it arrived.',
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
            'category_id' => $this->filled('category_id') ? (int) $this->input('category_id') : null,
            'specs' => $this->input('specs'),
            'brand' => $this->input('brand'),
            'model' => $this->input('model'),
            'serial_no' => $this->input('serial_no'),
            'complaint' => (string) $this->string('complaint'),
            // Today where none was given. Something that arrived is on the
            // bench now, and making somebody type the date they are standing in
            // is the kind of friction that ends in a paper job card.
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
