<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountProvisioner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * A workshop is not valid without its chart of accounts — TenantService
     * seeds one inside the same transaction that creates the tenant — so the
     * factory produces one too. A test that built a tenant with no books would
     * be testing a state production can never reach.
     *
     * Note for anyone force-deleting a factory tenant: chart_of_accounts.
     * tenant_id is restrictOnDelete, so its accounts have to go first.
     */
    public function configure(): static
    {
        return $this->afterCreating(
            fn (Tenant $tenant) => app(ChartOfAccountProvisioner::class)->seedFor($tenant)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Electricals';

        return [
            'name' => $name,
            'slug' => Tenant::slugFor($name).'-'.Str::lower(Str::random(6)),
            'gstin' => null,
            'address' => fake()->address(),
            'state_code' => '27',
            'status' => TenantStatus::Active,
            'financial_year_start_month' => 4,
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'books_start_date' => null,
        ];
    }

    public function withBooksStartingOn(string $date): static
    {
        return $this->state(fn (array $attributes) => ['books_start_date' => $date]);
    }

    public function withFinancialYearStartingIn(int $month): static
    {
        return $this->state(fn (array $attributes) => ['financial_year_start_month' => $month]);
    }

    public function withStatus(TenantStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function suspended(): static
    {
        return $this->withStatus(TenantStatus::Suspended);
    }

    public function cancelled(): static
    {
        return $this->withStatus(TenantStatus::Cancelled);
    }

    public function withGstin(string $gstin): static
    {
        return $this->state(fn (array $attributes) => [
            'gstin' => strtoupper($gstin),
            'state_code' => substr($gstin, 0, 2),
        ]);
    }
}
