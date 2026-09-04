<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What one person got through, over a window — M22.
 *
 * The same `from`/`to` pair every other staff listing takes, rather than the
 * `period=YYYY-MM` the attendance register uses. Attendance genuinely is a
 * calendar month — the register is drawn one month at a time and payroll runs
 * against exactly that — whereas "how has Ramesh been doing" is asked over a
 * fortnight, a quarter and a year at least as often as over a month.
 *
 * Both are optional, and both being absent means the whole history. That is the
 * right default for a drawer that opens on somebody's record: "forty-one jobs,
 * ever" is a fact, where an empty month would read as though they had done
 * nothing.
 */
class IndexWorkRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the period cannot be before its start.',
        ];
    }

    public function from(): ?string
    {
        return $this->filled('from') ? (string) $this->string('from') : null;
    }

    public function to(): ?string
    {
        return $this->filled('to') ? (string) $this->string('to') : null;
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
