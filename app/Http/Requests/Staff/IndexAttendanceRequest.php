<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reading the attendance sheet — M22.
 *
 * Two shapes behind one endpoint, chosen by which parameter arrives:
 *
 *   `?date=2026-09-03`  the day sheet — everybody, with whatever mark they have.
 *   `?period=2026-09`   the month register — a row per person, a column per day.
 *
 * One route rather than two because they are one question asked at two zoom
 * levels, and because a client switching between them should not have to switch
 * endpoints. Neither parameter given means today's day sheet, which is what
 * somebody opening the screen wants nine times out of ten.
 */
class IndexAttendanceRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Give the date as YYYY-MM-DD.',
            'period.date_format' => 'Give the month as YYYY-MM.',
        ];
    }

    /**
     * The day being asked about.
     *
     * `day()` rather than `date()`, which is taken: `Illuminate\Http\Request`
     * already has a `date($key, $format, $tz)` that reads a field as a Carbon
     * instance, and overriding it with a different signature is a fatal error
     * rather than a subtle one. Worth the note because `date` is the obvious
     * name and somebody will reach for it again.
     */
    public function day(): string
    {
        return $this->filled('date') ? (string) $this->string('date') : now()->toDateString();
    }

    /** True when the caller asked for a month rather than a day. */
    public function wantsRegister(): bool
    {
        return $this->filled('period') && ! $this->filled('date');
    }

    public function period(): string
    {
        return $this->filled('period') ? (string) $this->string('period') : now()->format('Y-m');
    }
}
