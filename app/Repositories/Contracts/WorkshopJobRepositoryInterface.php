<?php

namespace App\Repositories\Contracts;

use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WorkshopJobRepositoryInterface
{
    public function findById(int $id): ?WorkshopJob;

    /**
     * A job with everything a detail screen renders: the customer, the motor,
     * the parts and the bills raised off it.
     */
    public function findWithDetail(int $id): ?WorkshopJob;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkshopJob>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): WorkshopJob;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(WorkshopJob $job, array $attributes): WorkshopJob;

    public function delete(WorkshopJob $job): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addPart(WorkshopJob $job, array $attributes): WorkshopJobPart;

    public function findPart(WorkshopJob $job, int $partId): ?WorkshopJobPart;

    public function deletePart(WorkshopJobPart $part): bool;

    /**
     * Point a part at the invoice line it became — the write that makes a job
     * unbillable twice over the same bearing.
     */
    public function markPartBilled(WorkshopJobPart $part, int $transactionLineId): void;

    /**
     * How many bills have been raised off this job. What refusing a delete is
     * measured against.
     */
    public function billCount(int $jobId): int;

    /**
     * How many jobs sit in each status, for a whole workshop.
     *
     * One grouped query rather than a count request per tab — the same shape as
     * `transactions/counts`, and what the dashboard's job tiles read.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array;
}
