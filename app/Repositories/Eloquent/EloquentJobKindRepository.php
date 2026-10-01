<?php

namespace App\Repositories\Eloquent;

use App\Models\JobKind;
use App\Models\JobKindAttribute;
use App\Models\WorkshopJob;
use App\Repositories\Contracts\JobKindRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentJobKindRepository implements JobKindRepositoryInterface
{
    public function all(array $filters = []): Collection
    {
        return JobKind::query()
            // Eager, always: every caller of this list goes on to resolve the
            // question set, and a lazy `fields` would be one query per kind on
            // a screen that draws all of them.
            ->with('fields')
            ->when(
                array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($query) => $query->where('is_active', (bool) $filters['is_active']),
            )
            ->when(
                ($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '',
                fn ($query) => $query->where('name', 'like', '%'.trim((string) $filters['search']).'%'),
            )
            ->ordered()
            ->get();
    }

    public function findById(int $id): ?JobKind
    {
        return JobKind::with('fields')->find($id);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return JobKind::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function create(array $attributes): JobKind
    {
        return JobKind::create($attributes);
    }

    public function update(JobKind $kind, array $attributes): JobKind
    {
        $kind->fill($attributes)->save();

        return $kind->refresh();
    }

    public function delete(JobKind $kind): bool
    {
        return (bool) $kind->delete();
    }

    public function jobCount(int $kindId): int
    {
        return WorkshopJob::where('job_kind_id', $kindId)->count();
    }

    public function jobCounts(array $kindIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $kindIds)));

        $counts = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $counts;
        }

        $rows = WorkshopJob::query()
            ->whereIn('job_kind_id', $ids)
            ->groupBy('job_kind_id')
            ->selectRaw('job_kind_id, COUNT(*) as jobs')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->job_kind_id] = (int) $row->jobs;
        }

        return $counts;
    }

    /* ---------------------------------------------------------------------
     | The fields under a kind
     |-------------------------------------------------------------------- */

    public function findField(JobKind $kind, int $id): ?JobKindAttribute
    {
        return JobKindAttribute::where('job_kind_id', $kind->id)->find($id);
    }

    public function fieldKeyExists(JobKind $kind, string $key, ?int $exceptId = null): bool
    {
        return JobKindAttribute::where('job_kind_id', $kind->id)
            ->where('key', $key)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function createField(JobKind $kind, array $attributes): JobKindAttribute
    {
        return $kind->fields()->create($attributes);
    }

    public function updateField(JobKindAttribute $field, array $attributes): JobKindAttribute
    {
        $field->fill($attributes)->save();

        return $field->refresh();
    }

    public function deleteField(JobKindAttribute $field): bool
    {
        return (bool) $field->delete();
    }

    /**
     * The answers live in a JSON bag rather than in rows, so both of the reads
     * below go through `JSON_EXTRACT` on `workshop_jobs.specs`.
     *
     * The key is interpolated into the path, which is safe for exactly one
     * reason and it is worth being explicit about it: a key is snake case
     * starting with a letter, capped at 40 characters, because
     * {@see \App\Support\Catalogue\AttributeFieldShape::key()} refuses anything
     * else on the way in rather than escaping it. Nothing else may be
     * interpolated here.
     */
    public function answeredCount(JobKindAttribute $field): int
    {
        return $this->answeredQuery($field)->count();
    }

    public function answersFor(JobKindAttribute $field): array
    {
        // Aliased and plucked by the alias. `pluck(DB::raw(...))` reads the
        // expression back as an array key, which it never is — the row comes
        // back keyed by whatever MySQL decided to call the column.
        return $this->answeredQuery($field)
            ->select(DB::raw($this->path($field).' AS answer'))
            ->distinct()
            ->pluck('answer')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /**
     * Jobs of this field's kind that recorded something under its key.
     *
     * Scoped to the kind as well as to the key, because two kinds may both ask
     * `hp` and a cooler's answers say nothing about whether a motor's field can
     * be removed.
     *
     * @return Builder<WorkshopJob>
     */
    private function answeredQuery(JobKindAttribute $field): Builder
    {
        return WorkshopJob::query()
            ->where('job_kind_id', $field->job_kind_id)
            ->whereRaw($this->path($field).' IS NOT NULL')
            ->whereRaw($this->path($field)." <> ''");
    }

    private function path(JobKindAttribute $field): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(specs, '$.\"{$field->key}\"'))";
    }
}
