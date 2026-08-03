<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill the seeded chart of accounts.
 *
 * New workshops get theirs at provisioning time, so this exists for one
 * situation: a SystemAccount case was added after some tenants already
 * existed. It is create-only and idempotent, so running it against every
 * tenant is always safe.
 */
class SeedChartOfAccountsCommand extends Command
{
    protected $signature = 'accounts:seed
                            {--tenant=* : Tenant id to seed; repeatable. Omit for every tenant.}
                            {--dry-run : Report what is missing without creating anything}';

    protected $description = 'Create any missing system accounts in a workshop\'s chart of accounts';

    public function handle(ChartOfAccountProvisioner $provisioner): int
    {
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->components->warn('No matching workshops found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $count = $dryRun
                    ? count($provisioner->missingFor($tenant))
                    : $provisioner->seedFor($tenant);
            } catch (Throwable $e) {
                // One workshop's failure — a custom account already using a
                // system account's name or code — must not stop the rest.
                $failed++;
                $this->components->error("[{$tenant->slug}] {$e->getMessage()}");

                continue;
            }

            $created += $count;

            if ($count > 0) {
                $verb = $dryRun ? 'missing' : 'created';
                $this->components->twoColumnDetail($tenant->slug, "{$count} {$verb}");
            }
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s %d account(s) across %d workshop(s).',
            $dryRun ? 'Would create' : 'Created',
            $created,
            $tenants->count(),
        ));

        if ($failed > 0) {
            $this->components->error("{$failed} workshop(s) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        $ids = array_filter((array) $this->option('tenant'));

        return Tenant::query()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->orderBy('id')
            ->get();
    }
}
