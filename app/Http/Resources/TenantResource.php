<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Tenancy\TenantService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * Whether to include the tenant's user count. Off by default so listing
     * 100 tenants does not run 100 count queries.
     */
    protected bool $withUserCount = false;

    public function withUserCount(bool $value = true): static
    {
        $this->withUserCount = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'gstin' => $this->gstin,
            'address' => $this->address,
            'state_code' => $this->state_code,
            'status' => $this->status->value,

            'settings' => [
                'financial_year_start_month' => $this->financial_year_start_month,
                'timezone' => $this->timezone,
                'currency' => $this->currency,
                'books_start_date' => $this->books_start_date?->toDateString(),
                // How long a bill may go unsettled before it is reported overdue
                // — M16. Null means the workshop does not track it, and nothing
                // is ever overdue.
                'payment_due_days' => $this->payment_due_days,
                // Whether a bill may take stock the shelf does not hold — M17.
                // Off by default; the refusal message points here.
                'allow_negative_stock' => (bool) $this->allow_negative_stock,
                'round_off_invoices' => (bool) $this->round_off_invoices,
            ],

            // The financial year the workshop is currently in, resolved server
            // side so no client has to re-implement the off-by-one that an
            // April start creates.
            'current_financial_year' => $this->currentFinancialYear(),

            'user_count' => $this->when(
                $this->withUserCount,
                fn () => app(TenantService::class)->userCount($this->resource)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{start: string, end: string}
     */
    private function currentFinancialYear(): array
    {
        [$start, $end] = $this->resource->financialYearFor();

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }
}
