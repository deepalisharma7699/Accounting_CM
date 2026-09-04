<?php

namespace App\Http\Requests\WorkshopJob;

use App\Enums\WorkshopJobStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexJobRequest extends FormRequest
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
            // The four things somebody has when they walk up to the counter: the
            // ticket, the customer, the plate on the casing, and the complaint.
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(WorkshopJobStatus::class)],
            /*
            | "Still on our plate" — five statuses at once, and distinct from a
            | status filter rather than a shorthand for one. A worklist defaults
            | to this; a tab uses `status`. Both are honoured together and narrow
            | each other, so `?open=1&status=delivered` returns nothing rather
            | than quietly ignoring one of the two.
            */
            'open' => ['nullable', 'boolean'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Promised back by now and still on the bench. A toggle rather than a
            // date, because the answer is always "today" and asking a client to
            // compute it invites a browser clock to decide what is late.
            'overdue' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['received_date', 'promised_date', 'created_at', 'job_no'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->input('search'),
            'status' => $this->input('status'),
            // Absent rather than false when the toggle is off, so it narrows
            // nothing — `open=0` is "I did not ask", not "show me the finished
            // ones", which is what `status=delivered` is for.
            'open' => $this->boolean('open') ?: null,
            'party_id' => $this->filled('party_id') ? (int) $this->input('party_id') : null,
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            // Resolved here rather than in the repository, which has no business
            // knowing what day it is — the same split TransactionService makes
            // for the overdue-invoice cutoff.
            'overdue_on_or_before' => $this->boolean('overdue') ? now()->toDateString() : null,
            'sort' => $this->input('sort'),
            'direction' => $this->input('direction'),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
