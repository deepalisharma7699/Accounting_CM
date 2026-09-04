<?php

namespace App\Repositories\Contracts;

use App\Models\Unit;
use Illuminate\Support\Collection;

interface UnitRepositoryInterface
{
    public function findById(int $id): ?Unit;

    public function findByCode(string $code): ?Unit;

    /**
     * @param  array{search?: string|null, kind?: string|null, is_active?: bool|null}  $filters
     * @return Collection<int, Unit>
     */
    public function all(array $filters = []): Collection;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    public function labelExists(string $label, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Unit;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Unit $unit, array $attributes): Unit;

    public function delete(Unit $unit): bool;

    /**
     * How many *products* are counted in this unit.
     *
     * Separate from {@see documentLineCountForCode()} because the two carry
     * different remedies: a product can be re-pointed at another unit, and a
     * posted bill line cannot be touched at all.
     */
    public function itemCountForCode(string $code): int;

    /**
     * How many posted document lines and job parts name this unit.
     */
    public function documentLineCountForCode(string $code): int;

    /**
     * How many category attributes print this unit beside their input.
     */
    public function attributeCountForCode(string $code): int;

    /**
     * The largest number of decimal places actually used by a quantity recorded
     * in this unit, across stock movements and document lines.
     *
     * The question behind "may this unit be narrowed to whole numbers?" — 12.5 kg
     * on the shelf means kilograms cannot become countable without rounding a
     * real figure on a real screen.
     *
     * @return array{scale: int, example: string|null}
     */
    public function widestRecordedScale(string $code): array;
}
