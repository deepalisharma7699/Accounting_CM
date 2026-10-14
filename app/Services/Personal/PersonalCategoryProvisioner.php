<?php

namespace App\Services\Personal;

use App\Models\PersonalExpenseCategory;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a workshop its opening personal categories.
 *
 * Runs at provisioning, beside the chart of accounts and the catalogue. Create-
 * only and idempotent by name, so a category the owner renamed or archived is
 * left exactly as it is. Does nothing where the schema step has not been run
 * yet — provisioning a workshop must never fail over an optional diary.
 */
class PersonalCategoryProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function seedFor(Tenant|int $tenant): int
    {
        if (! Schema::hasTable('personal_expense_categories')) {
            return 0;
        }

        return $this->context->runFor($tenant, function (): int {
            $existing = PersonalExpenseCategory::query()->pluck('name')->all();
            $created = 0;

            foreach (PersonalCategoryDefaults::names() as $index => $name) {
                if (in_array($name, $existing, true)) {
                    continue;
                }

                PersonalExpenseCategory::create([
                    'name' => $name,
                    'is_active' => true,
                    'display_order' => $index + 1,
                ]);

                $created++;
            }

            return $created;
        });
    }
}
