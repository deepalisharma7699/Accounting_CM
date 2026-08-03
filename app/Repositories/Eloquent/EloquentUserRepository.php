<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\Tenancy\CrossTenantWriteException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentUserRepository implements UserRepositoryInterface
{
    /**
     * Columns a client is allowed to sort by. Anything else falls back to the
     * default, which keeps user input out of the ORDER BY clause.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['id', 'name', 'email', 'status', 'created_at', 'last_login_at'];

    public function __construct(private readonly TenantContext $context) {}

    /* ---------------------------------------------------------------------
     | Tenant-scoped: administrative flows
     |-------------------------------------------------------------------- */

    public function findById(int $id): ?User
    {
        return $this->scoped(User::with('customRole'))->find($id);
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $this->scoped(User::query())
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

    /* ---------------------------------------------------------------------
     | Cross-tenant: the authentication path and the unique-email index
     |-------------------------------------------------------------------- */

    public function findAuthenticatable(int $id): ?User
    {
        return User::with(['customRole', 'tenant'])->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::with(['customRole', 'tenant'])->where('email', $email)->first();
    }

    public function emailExists(string $email, ?int $exceptUserId = null): bool
    {
        return User::withTrashed()
            ->where('email', $email)
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();
    }

    /* ---------------------------------------------------------------------
     | Writes
     |-------------------------------------------------------------------- */

    public function create(array $attributes): User
    {
        // Stamp the tenant from context rather than trusting the caller. A
        // caller may still pass one explicitly — the tenant provisioner does,
        // because it runs before any context exists — but it must not
        // contradict an established context.
        if (! array_key_exists('tenant_id', $attributes)) {
            $attributes['tenant_id'] = $this->context->current();
        } else {
            $this->assertAssignable($attributes['tenant_id']);
        }

        return User::create($attributes)->load('customRole');
    }

    public function update(User $user, array $attributes): User
    {
        // tenant_id is write-once: moving a user between workshops would carry
        // their authorship of past transactions with them.
        unset($attributes['tenant_id']);

        $user->fill($attributes)->save();

        return $user->fresh(['customRole']);
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * Apply the current tenant to a query.
     *
     * `users` is the one table where isolation is enforced here rather than by
     * a global scope, because authentication has to read users before it knows
     * the tenant. Keeping the filter in a single private method means there is
     * exactly one line to audit.
     *
     * A platform super-admin has no tenant and is scoped to the other platform
     * users — not to everybody's.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function scoped(Builder $query): Builder
    {
        if ($this->context->isUnscoped()) {
            return $query;
        }

        if (! $this->context->isResolved()) {
            // Fail closed: an unresolved context means nobody established who
            // "we" are, which is a bug rather than a licence to list everyone.
            throw MissingTenantContextException::for(User::class);
        }

        $tenantId = $this->context->current();

        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }

    private function assertAssignable(?int $tenantId): void
    {
        if ($this->context->isUnscoped() || ! $this->context->isResolved()) {
            return;
        }

        if ($tenantId !== $this->context->current()) {
            throw CrossTenantWriteException::assigning(
                User::class,
                (int) $tenantId,
                (int) $this->context->current(),
            );
        }
    }
}
