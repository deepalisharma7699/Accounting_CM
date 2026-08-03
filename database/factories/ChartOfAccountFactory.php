<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(AccountType::cases());

        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            'code' => (string) fake()->unique()->numberBetween(...$type->codeRange()),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'type' => $type,
            'system_key' => null,
            'is_active' => true,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'code' => (string) fake()->unique()->numberBetween(...$type->codeRange()),
        ]);
    }

    /**
     * A seeded account, exactly as ChartOfAccountProvisioner would create it.
     */
    public function system(SystemAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $account->code(),
            'name' => $account->accountName(),
            'description' => $account->description(),
            'type' => $account->type(),
            'system_key' => $account,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
