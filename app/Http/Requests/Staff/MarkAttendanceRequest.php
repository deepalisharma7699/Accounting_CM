<?php

namespace App\Http\Requests\Staff;

use App\Enums\AttendanceStatus;
use App\Services\Staff\AttendanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving a day's attendance — M22.
 *
 * The **whole sheet** arrives at once rather than a mark at a time, because that
 * is how it is filled in: somebody opens the day, runs down the list, and saves.
 * A request per person would mean a half-saved day whenever the connection
 * dropped, on a screen that is used standing up next to a bench.
 *
 * `status` may be null, and that is a value rather than an omission: it **clears**
 * the mark and puts the day back to unmarked. Without it a mis-tap could only be
 * replaced, never undone — and unmarked is a state with its own meaning, not a
 * blank. See {@see \App\Enums\SalaryBasis::unmarkedDayIsPaid()}.
 *
 * Whether each `employee_id` belongs to this workshop is
 * {@see AttendanceService::mark()}'s business, and load-bearing there: the write
 * is an upsert that goes round the tenant scope.
 */
class MarkAttendanceRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],

            // A cap rather than an unbounded array: a workshop has staff in the
            // tens, and a sheet of five hundred is a client that has gone wrong.
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.employee_id' => ['required', 'integer', 'min:1'],
            'rows.*.status' => ['present', 'nullable', Rule::enum(AttendanceStatus::class)],
            'rows.*.notes' => ['nullable', 'string', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Say which day this sheet is for.',
            'date.date_format' => 'Give the date as YYYY-MM-DD.',
            'rows.required' => 'There is nobody on this sheet to mark.',
            'rows.*.status.present' => 'Every row needs a status, or null to clear the mark.',
        ];
    }

    /**
     * The day this sheet is for.
     *
     * `day()` rather than `date()`, which is taken: `Illuminate\Http\Request`
     * already has a `date($key, $format, $tz)`, and overriding it with a
     * different signature is a fatal error rather than a subtle one.
     */
    public function day(): string
    {
        return (string) $this->string('date');
    }

    /**
     * @return array<int, array{employee_id: int, status: string|null, notes: string|null}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row) => [
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'status' => ($row['status'] ?? null) === null || $row['status'] === ''
                ? null
                : (string) $row['status'],
            'notes' => isset($row['notes']) && trim((string) $row['notes']) !== ''
                ? trim((string) $row['notes'])
                : null,
        ], array_values((array) $this->input('rows', [])));
    }
}
