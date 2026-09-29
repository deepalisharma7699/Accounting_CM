<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountProvisioner;
use App\Services\Inventory\CatalogueProvisioner;
use App\Services\Rbac\RoleProvisioner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * A workshop is not valid without its chart of accounts, its catalogue
     * vocabulary *or* its roles — TenantService seeds all three inside the same
     * transaction that creates the tenant — so the factory produces all three
     * too. A test that built a tenant with no books, with no units and no
     * categories, or with nobody it could make a second user into, would be
     * testing a state production can never reach.
     *
     * The catalogue matters as much as the chart now that the item types and
     * units are rows: without it there is no category to file a product under,
     * and the importer would refuse every line of a valid file. The roles matter
     * for the same kind of reason: they belong to the workshop, so a workshop
     * with none has no role to give anybody. Their *grants* depend on the
     * permission catalogue being seeded — a test that cares should call
     * seedRoleCatalogue() first, or the roles come out empty, which is harmless
     * and idempotent either way.
     *
     * Note for anyone force-deleting a factory tenant: chart_of_accounts,
     * units and item_categories are all restrictOnDelete, so they have to go
     * first.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            app(ChartOfAccountProvisioner::class)->seedFor($tenant);
            app(CatalogueProvisioner::class)->seedFor($tenant);
            app(RoleProvisioner::class)->seedFor($tenant);
        });
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
