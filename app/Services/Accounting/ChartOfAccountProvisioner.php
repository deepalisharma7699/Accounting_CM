<?php

namespace App\Services\Accounting;

use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gives a workshop its opening chart of accounts.
 *
 * Called once when a tenant is provisioned, and available afterwards as a
 * backfill (`php artisan accounts:seed`) for tenants that predate a newly
 * added SystemAccount case.
 */
class ChartOfAccountProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Create any seeded account this workshop does not already have.
     *
     * Idempotent, and deliberately create-only: an account that already exists
     * is left completely alone. A workshop that renamed "UPI / Wallet" to
     * "PhonePe" must not have that undone by a later backfill, and the engine
     * does not care — it resolves on system_key, never on the name.
     *
     * @return int The number of accounts created.
     */
    public function seedFor(Tenant|int $tenant): int
    {
        return $this->context->runFor($tenant, function (): int {
            $missing = $this->missing();

            if ($missing === []) {
                return 0;
            }

            // Not on the audit trail, and one of only two places in the
            // application that says so — see AuditRecorder::silently().
            //
            // These fifteen rows are not fifteen decisions. They are one act,
            // and it is already recorded: the workshop's own creation, which is
            // what caused them. Logging each account individually would put
            // fifteen entries nobody chose at the top of every new workshop's
            // history, and a log whose first page is machine noise is a log
            // people stop opening. A backfill run later is the same act, for the
            // same reason — `accounts:seed` adds what a workshop was short of,
            // it does not extend anybody's chart on their behalf.
            $this->audit->silently(fn () => DB::transaction(function () use ($missing): void {
                foreach ($missing as $account) {
                    ChartOfAccount::create([
                        'code' => $account->code(),
                        'name' => $account->accountName(),
                        'description' => $account->description(),
                        'type' => $account->type(),
                        'system_key' => $account,
                        'is_active' => true,
                    ]);
                }
            }));

            return count($missing);
        });
    }

    /**
     * Which seeded accounts a workshop is short of, without creating them.
     * Lets `accounts:seed --dry-run` report honestly rather than seeding and
     * rolling back.
     *
     * @return array<int, SystemAccount>
     */
    public function missingFor(Tenant|int $tenant): array
    {
        return $this->context->runFor($tenant, fn () => $this->missing());
    }

    /**
     * @return array<int, SystemAccount>
     */
    private function missing(): array
    {
        $existing = ChartOfAccount::system()->pluck('system_key')->all();

        return array_values(array_filter(
            SystemAccount::cases(),
            fn (SystemAccount $account) => ! in_array($account, $existing, true),
        ));
    }
}
