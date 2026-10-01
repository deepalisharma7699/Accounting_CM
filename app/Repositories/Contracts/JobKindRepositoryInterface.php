<?php

namespace App\Repositories\Contracts;

use App\Models\JobKind;
use App\Models\JobKindAttribute;
use Illuminate\Support\Collection;

/**
 * The bench's own question sets — see {@see JobKind} for why they are not the
 * catalogue's categories.
 */
interface JobKindRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, JobKind>
     */
    public function all(array $filters = []): Collection;

    public function findById(int $id): ?JobKind;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): JobKind;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(JobKind $kind, array $attributes): JobKind;

    public function delete(JobKind $kind): bool;

    public function jobCount(int $kindId): int;

    /**
     * How many jobs a kind has, for every id asked about. One grouped query
     * rather than a count per row — the list draws a badge on each.
     *
     * @param  array<int, int>  $kindIds
     * @return array<int, int>
     */
    public function jobCounts(array $kindIds): array;

    /* ---------------------------------------------------------------------
     | The fields under a kind
     |-------------------------------------------------------------------- */

    public function findField(JobKind $kind, int $id): ?JobKindAttribute;

    public function fieldKeyExists(JobKind $kind, string $key, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createField(JobKind $kind, array $attributes): JobKindAttribute;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateField(JobKindAttribute $field, array $attributes): JobKindAttribute;

    public function deleteField(JobKindAttribute $field): bool;

    /**
     * How many jobs have recorded an answer under this field's key.
     *
     * Read out of `workshop_jobs.specs`, which is where the answers are — there
     * is no row per answer to count.
     */
    public function answeredCount(JobKindAttribute $field): int;

    /**
     * Which of a field's choices jobs have already been filed under.
     *
     * @return array<int, string>
     */
    public function answersFor(JobKindAttribute $field): array;
}
