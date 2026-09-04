<?php

namespace App\Services\Inventory;

use App\Exceptions\Accounting\CatalogueMasterException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Unit;
use App\Repositories\Contracts\UnitRepositoryInterface;
use App\Support\Units\UnitRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Unit Master: how the shop counts what it holds.
 *
 * Three rules are enforced here rather than in a form request, for the reason the
 * catalogue's rules always have been — the importer and any future capture agent
 * write units without passing through a controller:
 *
 *   * **A code is fixed once set.** `items.base_uom`, `transaction_lines.unit`
 *     and `workshop_job_parts.unit` all store the code, and posted documents
 *     store it as a *copy* of what was true when they were issued. Renaming 'kg'
 *     to 'metre' would silently reinterpret every quantity ever recorded.
 *   * **A unit in use cannot be deleted.** It can be switched off, which takes it
 *     out of every picker and leaves it explaining the quantities behind it.
 *   * **The scale cannot narrow below what is already recorded.** 12.5 kg exists;
 *     making kilograms countable would round it to 13 on every screen without
 *     saying so.
 *
 * ## Why the registry is forgotten after every write
 *
 * {@see UnitRegistry} holds the workshop's units for the life of the request, and
 * the request that just renamed one usually goes on to serialise it. Without the
 * eviction the response would carry the old label, and the screen would show the
 * edit failing when it had succeeded.
 */
class UnitService
{
    public function __construct(
        private readonly UnitRepositoryInterface $units,
        private readonly UnitRegistry $registry,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array{search?: string|null, kind?: string|null, is_active?: bool|null}  $filters
     * @return Collection<int, Unit>
     */
    public function all(array $filters = []): Collection
    {
        return $this->units->all($filters);
    }

    public function find(int $id): Unit
    {
        return $this->units->findById($id)
            ?? throw new ResourceNotFoundException('Unit', $id);
    }

    /**
     * The units a picker should offer — active only, in the workshop's order.
     *
     * @return Collection<int, Unit>
     */
    public function selectable(): Collection
    {
        return $this->units->all(['is_active' => true]);
    }

    /**
     * What each unit is used by, for the master screen's "in use" column.
     *
     * Fetched on demand rather than with every listing: it is four counting
     * queries per unit and the only screen that needs it is the one where
     * somebody is deciding what to remove.
     *
     * @return array{items: int, documents: int, attributes: int, total: int}
     */
    public function usageFor(Unit $unit): array
    {
        $items = $this->units->itemCountForCode($unit->code);
        $documents = $this->units->documentLineCountForCode($unit->code);
        $attributes = $this->units->attributeCountForCode($unit->code);

        return [
            'items' => $items,
            'documents' => $documents,
            'attributes' => $attributes,
            'total' => $items + $documents + $attributes,
        ];
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{code?: string|null, label: string, symbol?: string|null, kind?: string|null, decimals?: int|string|null, display_order?: int|null}  $data
     */
    public function create(array $data): Unit
    {
        $label = trim($data['label']);
        $code = $this->normaliseCode($data['code'] ?? null, $label);
        $symbol = $this->trimmed($data['symbol'] ?? null) ?? $code;

        $this->assertCodeAvailable($code);
        $this->assertLabelAvailable($label);

        $unit = $this->units->create([
            'code' => $code,
            'label' => $label,
            'symbol' => $symbol,
            'kind' => $this->normaliseKind($data['kind'] ?? null),
            'decimals' => $this->normaliseDecimals($data['decimals'] ?? null),
            // Never true from a request. The flag marks the rows the system was
            // set up with, and a workshop must not be able to make one of its own
            // undeletable by accident.
            'is_system' => false,
            'is_active' => true,
            'display_order' => (int) ($data['display_order'] ?? 0),
        ]);

        $this->registry->forget();

        Log::info('units.created', ['unit_id' => $unit->id, 'code' => $unit->code]);

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): Unit
    {
        $unit = $this->find($id);
        $attributes = [];

        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);

            if ($label !== $unit->label) {
                $this->assertLabelAvailable($label, $unit->id);
                $attributes['label'] = $label;
            }
        }

        if (array_key_exists('symbol', $data)) {
            $attributes['symbol'] = $this->trimmed($data['symbol']) ?? $unit->code;
        }

        if (array_key_exists('kind', $data)) {
            $attributes['kind'] = $this->normaliseKind($data['kind']);
        }

        if (array_key_exists('decimals', $data)) {
            $decimals = $this->normaliseDecimals($data['decimals']);

            if ($decimals < (int) $unit->decimals) {
                $this->assertScaleWideEnough($unit, $decimals);
            }

            $attributes['decimals'] = $decimals;
        }

        if (array_key_exists('display_order', $data)) {
            $attributes['display_order'] = (int) $data['display_order'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        // `code` is absent by design. It is what every quantity ever recorded
        // points at, and changing it would reinterpret all of them at once.

        if ($attributes === []) {
            return $unit;
        }

        $unit = $this->units->update($unit, $attributes);

        $this->registry->forget();

        Log::info('units.updated', ['unit_id' => $unit->id, 'fields' => array_keys($attributes)]);

        return $unit;
    }

    /**
     * Remove a unit nothing points at.
     *
     * Anything else is refused and told to switch it off instead. The distinction
     * matters: a workshop that stops selling by the metre wants Metre out of its
     * pickers, and it must not thereby lose the word that explains the 4.5 on a
     * bill it issued last year.
     */
    public function delete(int $id): void
    {
        $unit = $this->find($id);

        if ($unit->is_system) {
            throw CatalogueMasterException::unitProtected((int) $unit->id, $unit->label);
        }

        $usage = $this->usageFor($unit);

        if ($usage['documents'] > 0) {
            throw CatalogueMasterException::unitInUse(
                (int) $unit->id,
                $unit->label,
                $usage['documents'],
                $usage['documents'] === 1 ? 'posted document line' : 'posted document lines',
            );
        }

        if ($usage['items'] > 0) {
            throw CatalogueMasterException::unitInUse(
                (int) $unit->id,
                $unit->label,
                $usage['items'],
                $usage['items'] === 1 ? 'product' : 'products',
            );
        }

        if ($usage['attributes'] > 0) {
            throw CatalogueMasterException::unitInUse(
                (int) $unit->id,
                $unit->label,
                $usage['attributes'],
                $usage['attributes'] === 1 ? 'category field' : 'category fields',
            );
        }

        $this->units->delete($unit);

        $this->registry->forget();

        Log::info('units.deleted', ['unit_id' => $id]);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * Refuse a scale that would make an already-recorded quantity unrepresentable.
     */
    private function assertScaleWideEnough(Unit $unit, int $decimals): void
    {
        $recorded = $this->units->widestRecordedScale($unit->code);

        if ($recorded['scale'] <= $decimals) {
            return;
        }

        throw CatalogueMasterException::unitScaleTooNarrow(
            $unit->label,
            sprintf('%s %s', $recorded['example'] ?? '0', $unit->symbol),
        );
    }

    /**
     * A code, derived from the label where nobody supplied one.
     *
     * Snake case and lower, because it goes into a column that is compared as a
     * string in three tables and read back as a form field name. "Cubic Metre"
     * becomes `cubic_metre`, which is ugly and unambiguous — and the *symbol* is
     * what anybody actually sees.
     */
    private function normaliseCode(?string $code, string $label): string
    {
        $source = $this->trimmed($code) ?? $label;

        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $source) ?? '');
        $slug = trim($slug, '_');

        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            // A code has to start with a letter: it is interpolated into a JSON
            // path in one place and used as an object key in the browser in
            // several, and a leading digit is legal in neither.
            $slug = 'u_'.$slug;
        }

        return substr($slug, 0, 20);
    }

    private function normaliseKind(?string $kind): string
    {
        $kind = strtolower(trim((string) $kind));

        $known = ['count', 'weight', 'length', 'volume', 'time', 'electrical', 'other'];

        return in_array($kind, $known, true) ? $kind : 'other';
    }

    /**
     * The scale, clamped to what the ledger can actually hold.
     *
     * `stock_movements.quantity` is DECIMAL(15,3), so a unit promising four
     * places would be promising something the database rounds away silently.
     * Clamped rather than refused: the intent — "this is a measured thing" — is
     * clear, and three places satisfies it.
     */
    private function normaliseDecimals(int|string|null $decimals): int
    {
        return max(0, min(3, (int) $decimals));
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertCodeAvailable(string $code, ?int $exceptId = null): void
    {
        if ($this->units->codeExists($code, $exceptId)) {
            throw CatalogueMasterException::unitCodeTaken($code);
        }
    }

    private function assertLabelAvailable(string $label, ?int $exceptId = null): void
    {
        if ($this->units->labelExists($label, $exceptId)) {
            throw CatalogueMasterException::unitLabelTaken($label);
        }
    }
}
