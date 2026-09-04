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
            'code' => self::freeCodeIn($type),
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
            'code' => self::freeCodeIn($type),
        ]);
    }

    /**
     * A code inside the type's band that no seeded account has already claimed.
     *
     * `fake()->unique()` only promises not to repeat itself — it knows nothing
     * about the fifteen-odd codes every workshop is provisioned with, so a
     * random expense code could land on COGS's 5000 and the insert would fail
     * on the per-tenant unique index. That was a one-in-a-thousand flake per
     * account long before anybody noticed it, and adding a system account makes
     * it marginally likelier rather than newly possible.
     */
    private static function freeCodeIn(AccountType $type): string
    {
        $taken = array_map(fn (SystemAccount $account) => $account->code(), SystemAccount::cases());

        do {
            $code = (string) fake()->unique()->numberBetween(...$type->codeRange());
        } while (in_array($code, $taken, true));

        return $code;
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
