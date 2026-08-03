<?php

namespace App\Repositories\Eloquent;

use App\Enums\JobStatus;
use App\Models\JobRun;
use App\Repositories\Contracts\JobRunRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentJobRunRepository implements JobRunRepositoryInterface
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function create(array $attributes): JobRun
    {
        return JobRun::create($attributes);
    }

    public function update(JobRun $run, array $attributes): JobRun
    {
        $run->fill($attributes)->save();

        return $run->refresh();
    }

    public function findByUuid(string $uuid): ?JobRun
    {
        return JobRun::query()->where('uuid', $uuid)->first();
    }

    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return JobRun::query()
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->ofType($filters['type']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->withStatus($filters['status']))
            ->when((bool) ($filters['unsettled'] ?? false), fn ($query) => $query->unsettled())
            ->with('creator:id,name')
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function unsettledCount(): int
    {
        return JobRun::query()->unsettled()->count();
    }

    public function pruneSettled(string $succeededBefore, string $failedBefore): int
    {
        // The one deliberately cross-tenant operation in this module. Pruning is
        // an operator's act over the whole installation, run from the scheduler
        // where there is no tenant context to scope to — and a prune that only
        // ever reached one workshop would leave every other workshop's table
        // growing for ever, silently.
        return $this->tenancy->runWithoutScope(function () use ($succeededBefore, $failedBefore): int {
            return JobRun::query()
                ->where(function ($query) use ($succeededBefore, $failedBefore) {
                    $query->where(fn ($ok) => $ok
                        ->where('status', JobStatus::Succeeded->value)
                        ->where('finished_at', '<', $succeededBefore))
                        ->orWhere(fn ($bad) => $bad
                            ->where('status', JobStatus::Failed->value)
                            ->where('finished_at', '<', $failedBefore));
                })
                ->delete();
        });
    }
}
