<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentUserRepository implements UserRepositoryInterface
{
    /**
     * Columns a client is allowed to sort by. Anything else falls back to the
     * default, which keeps user input out of the ORDER BY clause.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['id', 'name', 'email', 'status', 'created_at', 'last_login_at'];

    public function findById(int $id): ?User
    {
        return User::with('customRole')->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::with('customRole')->where('email', $email)->first();
    }

    public function emailExists(string $email, ?int $exceptUserId = null): bool
    {
        return User::withTrashed()
            ->where('email', $email)
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();
    }

    public function create(array $attributes): User
    {
        return User::create($attributes)->load('customRole');
    }

    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user->fresh(['customRole']);
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return User::query()
            ->with('customRole')
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';
                    $query->where('name', 'like', $term)->orWhere('email', 'like', $term);
                })
            )
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['role_id'] ?? null), fn ($query) => $query->where('custom_role_id', $filters['role_id']))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();
    }
}
