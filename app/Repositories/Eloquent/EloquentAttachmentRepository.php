<?php

namespace App\Repositories\Eloquent;

use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentAttachmentRepository implements AttachmentRepositoryInterface
{
    public function create(array $attributes): Attachment
    {
        return Attachment::create($attributes);
    }

    public function findById(int $id): ?Attachment
    {
        return Attachment::query()->with(['uploader:id,name', 'jobRun'])->find($id);
    }

    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return Attachment::query()
            ->when(filled($filters['kind'] ?? null), fn ($query) => $query->ofKind($filters['kind']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->withStatus($filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where(
                'original_name',
                'like',
                '%'.$filters['search'].'%',
            ))
            ->with(['uploader:id,name', 'jobRun'])
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function duplicatesOf(string $checksum, ?int $exceptId = null): Collection
    {
        return Attachment::query()
            ->matching($checksum, $exceptId)
            ->newestFirst()
            // Three is plenty. The notice says "you already have this"; a list of
            // every copy is a different screen, and it is the library.
            ->limit(3)
            ->get();
    }

    public function attachJobRun(Attachment $attachment, int $jobRunId): Attachment
    {
        $attachment->forceFill(['job_run_id' => $jobRunId])->save();

        return $attachment;
    }

    public function recordVerification(Attachment $attachment, string $status, ?array $meta = null): Attachment
    {
        $attachment->forceFill([
            'status' => $status,
            'meta' => $meta === null ? $attachment->meta : array_merge($attachment->meta ?? [], $meta),
        ])->save();

        return $attachment;
    }

    public function delete(Attachment $attachment): void
    {
        $attachment->delete();
    }
}
