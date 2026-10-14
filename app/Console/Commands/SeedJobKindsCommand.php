<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Workshop\JobKindProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backfill the kinds of thing a workshop takes in.
 *
 * New workshops get theirs at provisioning time, so this exists for the
 * workshops that predate the Kind master — the ones whose intake form used to
 * read its list out of `item_categories`. Run it once, after
 * `database/manual/sql/2026_09_30_001_job_kinds.sql`.
 *
 * Create-only and idempotent, matched by name: a second run adds nothing, and a
 * kind the workshop has renamed or switched off is left exactly as it is. So
 * running it against every tenant is always safe.
 */
class SeedJobKindsCommand extends Command
{
    protected $signature = 'workshop:seed-kinds
                            {--tenant=* : Tenant id to seed; repeatable. Omit for every tenant.}';

    protected $description = 'Create any missing job kinds — what a workshop takes in for repair';

    public function handle(JobKindProvisioner $provisioner): int
    {
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->components->warn('No matching workshops found.');

            return self::SUCCESS;
        }

        $kinds = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $counts = $provisioner->seedFor($tenant);
            } catch (Throwable $e) {
                // One workshop's failure must not stop the rest — a kind whose
                // seeded name collides with one somebody has already typed is
                // the likely cause, and it is per-workshop by definition.
                $failed++;
                $this->components->error("[{$tenant->slug}] {$e->getMessage()}");

                continue;
            }

            $kinds += $counts['kinds'];

            if ($counts['kinds'] > 0) {
                $this->components->twoColumnDetail(
                    $tenant->slug,
                    "{$counts['kinds']} kind(s), {$counts['fields']} field(s)",
                );
            }
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Created %d kind(s) across %d workshop(s).',
            $kinds,
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
