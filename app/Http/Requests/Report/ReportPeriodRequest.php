<?php

namespace App\Http\Requests\Report;

use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Reporting\ReportPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The window a report covers, and how many rows of it to send.
 *
 * The period is resolved *here* rather than in each controller action, because
 * every report takes the same one and the workshop's financial year is what
 * turns "this year" into two dates — see {@see ReportPeriod}. A copy of that
 * arithmetic per report would be several copies of the April off-by-one.
 */
class ReportPeriodRequest extends FormRequest
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
            // Not `in:` — an unknown or stale preset falls back to everything
            // rather than refusing to draw. See ReportPeriod::resolve().
            'period' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'Give the start date as YYYY-MM-DD.',
            'to.date_format' => 'Give the end date as YYYY-MM-DD.',
        ];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::resolve(
            $this->input('period'),
            $this->input('from'),
            $this->input('to'),
            $this->workshop(),
        );
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 25);
    }

    /**
     * The caller's workshop, where they have one.
     *
     * Null for a platform admin, and {@see ReportPeriod} handles that by falling
     * back to all time — a financial year needs a workshop to have one, and
     * inventing April for somebody who owns no books would be inventing a fact.
     */
    private function workshop(): ?Tenant
    {
        $id = app(TenantContext::class)->current();

        return $id === null ? null : app(TenantRepositoryInterface::class)->findById($id);
    }
}
