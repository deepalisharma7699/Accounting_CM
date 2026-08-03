<?php

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every read of the trail goes through here, and every one of them is an
 * Eloquent query — a raw `DB::table('audit_logs')` would bypass the tenant
 * scope, which on MySQL is the entire isolation boundary.
 */
class EloquentAuditLogRepository implements AuditLogRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return AuditLog::query()
            ->when(filled($filters['resource'] ?? null), fn ($query) => $query->forResource(
                $filters['resource'],
                $filters['resource_id'] ?? null,
            ))
            ->when(filled($filters['action'] ?? null), fn ($query) => $query->forAction($filters['action']))
            ->when(filled($filters['actor_id'] ?? null), fn ($query) => $query->forActor((int) $filters['actor_id']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->from($filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->upTo($filters['to']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';

                // The label and the actor's name, which are the two things
                // somebody actually remembers: "that supplier" and "who did it".
                // Both are the copies taken at the time, so a search still finds
                // an account under the name it had when it was archived.
                $query->where(function ($inner) use ($term) {
                    $inner->where('label', 'like', $term)
                        ->orWhere('actor_name', 'like', $term);
                });
            })
            ->with('actor:id,name,email')
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function actors(): Collection
    {
        return AuditLog::query()
            ->selectRaw('actor_id, MAX(actor_name) as actor_name, COUNT(*) as entries')
            ->groupBy('actor_id')
            // Busiest first: on a workshop with one owner and three clerks, the
            // useful order is who did the most, not who happens to sort first.
            ->orderByDesc('entries')
            ->get()
            ->map(fn (AuditLog $row) => [
                'id' => $row->actor_id === null ? null : (int) $row->actor_id,
                // Null actor_id and null name together mean an act with nobody
                // behind it — a console command or a seeder.
                'name' => $row->actor_name ?? 'The system',
                'entries' => (int) $row->entries,
            ]);
    }

    public function count(): int
    {
        return AuditLog::query()->count();
    }
}
