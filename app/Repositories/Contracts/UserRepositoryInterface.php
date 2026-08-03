<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Two families of lookup live here, and mixing them up is how tenants leak:
 *
 *   findById / paginate / delete   — scoped to the current tenant. Everything
 *                                    an administrator does goes through these.
 *
 *   findAuthenticatable / findByEmail / emailExists
 *                                  — deliberately cross-tenant. The auth path
 *                                    must resolve a user *before* tenancy
 *                                    exists, and email is unique platform-wide.
 *
 * The second family is never acceptable in an administrative flow.
 */
interface UserRepositoryInterface
{
    /**
     * Find a user within the current tenant. Returns null for a user that
     * exists but belongs to someone else — indistinguishable, by design, from
     * one that does not exist at all.
     */
    public function findById(int $id): ?User;

    /**
     * Resolve a user for authentication, before a tenant is known.
     *
     * Cross-tenant on purpose: this is the call that *establishes* tenancy.
     */
    public function findAuthenticatable(int $id): ?User;

    /**
     * Cross-tenant: emails are unique platform-wide, and login has only an
     * email to go on.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Cross-tenant: enforces the platform-wide unique index on users.email.
     */
    public function emailExists(string $email, ?int $exceptUserId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User;

    public function delete(User $user): bool;

    /**
     * Scoped to the current tenant. A platform super-admin (no tenant) sees
     * only other platform users, not everybody's staff.
     *
     * @param  array{search?: string|null, status?: string|null, role_id?: int|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
