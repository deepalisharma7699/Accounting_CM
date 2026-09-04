<?php

namespace App\Support\Units;

use App\Models\Unit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The workshop's units, loaded once and resolved by code.
 *
 * ## Why anything is cached at all
 *
 * `$item->base_uom` is read on every row of every catalogue listing, every bill
 * line as it is priced, and every stock movement as it is valued. Left to
 * Eloquent that is a query per read, for a table with twenty-odd rows in it that
 * changes about once a year. So the workshop's units are fetched whole on the
 * first ask and kept for the rest of the request.
 *
 * ## Why per request and not in the cache store
 *
 * Because the invalidation would be the interesting part, and it would be wrong
 * eventually. A request lives milliseconds; a cache entry lives until somebody
 * remembers to clear it, and a workshop that renamed a unit and still saw the old
 * symbol on its invoices would have no way to explain it. {@see forget()} exists
 * for the one caller that needs it — the service that just wrote a unit and is
 * about to render the result in the same request.
 *
 * ## Keyed by tenant
 *
 * Two workshops in one queue worker do not share a unit list. The key is the
 * tenant id, and an unscoped context (a seeder, platform provisioning) resolves
 * nothing rather than resolving somebody's.
 */
class UnitRegistry
{
    /**
     * Loaded units, keyed by tenant id and then by unit code.
     *
     * @var array<int, array<string, UnitDefinition>>
     */
    private array $byTenant = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * One unit, or an `unknown()` standing in for a code nobody recognises.
     *
     * Never throws and never returns null, and that is the point: this is called
     * from an Eloquent cast while hydrating a row, and a unit that has gone
     * missing must not be able to take a page down. See
     * {@see UnitDefinition::unknown()}.
     */
    public function get(?string $code): UnitDefinition
    {
        $code = trim((string) $code);

        if ($code === '') {
            return UnitDefinition::unknown('');
        }

        return $this->all()[$code] ?? UnitDefinition::unknown($code);
    }

    public function has(?string $code): bool
    {
        return $this->get($code)->isKnown;
    }

    /**
     * Every unit the current workshop has, keyed by code.
     *
     * Includes inactive ones. A unit switched off should vanish from the *picker*
     * — which is a filtered read in the service — and must go on explaining the
     * quantities already recorded against it, which is this.
     *
     * @return array<string, UnitDefinition>
     */
    public function all(): array
    {
        $tenantId = $this->context->current();

        if ($tenantId === null) {
            return [];
        }

        if (array_key_exists($tenantId, $this->byTenant)) {
            return $this->byTenant[$tenantId];
        }

        return $this->byTenant[$tenantId] = $this->load();
    }

    /**
     * @return array<string, UnitDefinition>
     */
    private function load(): array
    {
        try {
            return Unit::query()
                ->orderBy('display_order')
                ->orderBy('label')
                ->get()
                ->mapWithKeys(fn (Unit $unit) => [$unit->code => $unit->toDefinition()])
                ->all();
        } catch (Throwable $e) {
            // Reachable before the table exists — a migration running against a
            // fresh database will hydrate an Item on the way past. An empty list
            // degrades every unit to `unknown()`, which prints the code and
            // refuses nothing; throwing here would take the migration down.
            Log::warning('units.registry_unavailable', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Drop what is held for the current workshop, so the next read is fresh.
     *
     * Called by {@see \App\Services\Inventory\UnitService} after a write, because
     * the same request usually goes on to serialise the thing it just changed.
     */
    public function forget(?int $tenantId = null): void
    {
        $tenantId ??= $this->context->current();

        if ($tenantId === null) {
            $this->byTenant = [];

            return;
        }

        unset($this->byTenant[$tenantId]);
    }
}
