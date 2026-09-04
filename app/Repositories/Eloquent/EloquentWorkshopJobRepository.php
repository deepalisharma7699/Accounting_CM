<?php

namespace App\Repositories\Eloquent;

use App\Enums\TransactionStatus;
use App\Enums\WorkshopJobStatus;
use App\Models\Transaction;
use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use App\Repositories\Contracts\WorkshopJobRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentWorkshopJobRepository implements WorkshopJobRepositoryInterface
{
    /**
     * Columns a client may sort by, so nothing user-supplied reaches ORDER BY.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['received_date', 'promised_date', 'created_at', 'job_no'];

    public function findById(int $id): ?WorkshopJob
    {
        return WorkshopJob::find($id);
    }

    public function findWithDetail(int $id): ?WorkshopJob
    {
        return WorkshopJob::query()
            ->with([
                'party:id,name,roles,phone,gstin,state_code,is_active',
                'item:id,name,category_id,base_uom',
                'parts.item:id,name,category_id,base_uom,gst_rate',
                'parts.variant:id,item_id,sku,label,attributes',
                'bills:id,workshop_job_id,doc_no,type,status,date,total',
                'creator:id,name',
            ])
            ->find($id);
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'received_date';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return WorkshopJob::query()
            ->with(['party:id,name,roles,phone', 'item:id,name'])
            // The parts are counted rather than loaded: a list shows "4 parts"
            // and a detail screen shows the parts, and loading them for a page of
            // twenty-five jobs to render a number is the read that makes a
            // worklist slow.
            ->withCount('parts')
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    // The four things somebody actually has when they walk up to
                    // the counter: the ticket, the customer, the plate on the
                    // casing, and what they said was wrong with it.
                    $query->where('job_no', 'like', $term)
                        ->orWhere('serial_no', 'like', $term)
                        ->orWhere('brand', 'like', $term)
                        ->orWhere('model', 'like', $term)
                        ->orWhere('complaint', 'like', $term)
                        ->orWhereHas('party', fn ($party) => $party->where('name', 'like', $term)
                            ->orWhere('phone', 'like', $term));
                })
            )
            ->when(
                WorkshopJobStatus::tryFrom((string) ($filters['status'] ?? '')) !== null,
                fn ($query) => $query->withStatus(WorkshopJobStatus::from((string) $filters['status']))
            )
            // Distinct from a status filter, and both are wanted: "open" spans
            // five statuses and is what a worklist defaults to, while a status
            // filter is what a tab uses.
            ->when(($filters['open'] ?? null) === true, fn ($query) => $query->open())
            ->when(
                filled($filters['party_id'] ?? null),
                fn ($query) => $query->where('party_id', (int) $filters['party_id'])
            )
            ->when(
                filled($filters['from'] ?? null),
                fn ($query) => $query->whereDate('received_date', '>=', $filters['from'])
            )
            ->when(
                filled($filters['to'] ?? null),
                fn ($query) => $query->whereDate('received_date', '<=', $filters['to'])
            )
            // Promised before today and still on the bench.
            ->when(
                filled($filters['overdue_on_or_before'] ?? null),
                fn ($query) => $query->open()
                    ->whereNotNull('promised_date')
                    ->whereDate('promised_date', '<=', $filters['overdue_on_or_before'])
            )
            ->orderBy($sort, $direction)
            // A stable tiebreaker: several motors arrive on one day, and without
            // this a page boundary can repeat or skip one.
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $attributes): WorkshopJob
    {
        return WorkshopJob::create($attributes);
    }

    public function update(WorkshopJob $job, array $attributes): WorkshopJob
    {
        $job->fill($attributes)->save();

        return $job->refresh();
    }

    public function delete(WorkshopJob $job): bool
    {
        return (bool) $job->delete();
    }

    public function addPart(WorkshopJob $job, array $attributes): WorkshopJobPart
    {
        // Stamped from the parent rather than from the context, so a part can
        // never end up on a different workshop's job from the job that owns it —
        // the rule every child table here follows.
        $attributes['tenant_id'] = $job->tenant_id;

        return $job->parts()->create($attributes);
    }

    public function findPart(WorkshopJob $job, int $partId): ?WorkshopJobPart
    {
        return $job->parts()->whereKey($partId)->first();
    }

    public function deletePart(WorkshopJobPart $part): bool
    {
        return (bool) $part->delete();
    }

    public function markPartBilled(WorkshopJobPart $part, int $transactionLineId): void
    {
        $part->forceFill(['transaction_line_id' => $transactionLineId])->save();
    }

    public function billCount(int $jobId): int
    {
        return Transaction::query()
            ->where('workshop_job_id', $jobId)
            // Drafts count. A parked bill against this job is still a document
            // pointing at it, and deleting the job underneath one would leave a
            // draft nobody could post.
            ->whereIn('status', [
                TransactionStatus::Draft->value,
                TransactionStatus::Posted->value,
                TransactionStatus::Reversed->value,
            ])
            ->count();
    }

    public function countsByStatus(): array
    {
        $counts = array_fill_keys(WorkshopJobStatus::values(), 0);

        WorkshopJob::query()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $status = $row->status instanceof WorkshopJobStatus
                    ? $row->status->value
                    : (string) $row->status;

                $counts[$status] = (int) $row->total;
            });

        return $counts;
    }
}
