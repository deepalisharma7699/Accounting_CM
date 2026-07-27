<?php

namespace App\Services\UserManagement;

use App\Enums\UserStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\TokenService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Administrative user management: listing, role assignment, status changes.
 *
 * Anything that reduces a user's access also revokes their refresh tokens, so
 * a deactivated or demoted user cannot keep riding an old session.
 */
class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly TokenService $tokens,
    ) {}

    /**
     * @param  array{search?: string|null, status?: string|null, role_id?: int|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->users->paginate($filters, $perPage);
    }

    public function find(int $id): User
    {
        return $this->users->findById($id) ?? throw new ResourceNotFoundException('User', $id);
    }

    /**
     * Create a user on someone's behalf (admin flow, not self-registration).
     *
     * @param  array{name: string, email: string, password: string, status?: string, custom_role_id?: int|null}  $data
     */
    public function create(array $data): User
    {
        $email = strtolower(trim($data['email']));

        if ($this->users->emailExists($email)) {
            throw new ConflictException(
                'An account with this email address already exists.',
                'USER_EMAIL_TAKEN',
                ['field' => 'email'],
            );
        }

        $roleId = $this->resolveRoleId($data['custom_role_id'] ?? null);

        $user = $this->users->create([
            'name' => $data['name'],
            'email' => $email,
            'password' => $data['password'],
            'status' => UserStatus::from($data['status'] ?? UserStatus::Active->value),
            'custom_role_id' => $roleId,
        ]);

        Log::info('users.created', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * @param  array{name?: string, email?: string, password?: string, status?: string, custom_role_id?: int|null}  $data
     */
    public function update(int $id, array $data): User
    {
        $user = $this->find($id);

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $email = strtolower(trim($data['email']));

            if ($email !== $user->email && $this->users->emailExists($email, $user->id)) {
                throw new ConflictException(
                    'An account with this email address already exists.',
                    'USER_EMAIL_TAKEN',
                    ['field' => 'email'],
                );
            }

            $attributes['email'] = $email;
        }

        if (array_key_exists('password', $data) && filled($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $revokeSessions = false;

        if (array_key_exists('custom_role_id', $data)) {
            $attributes['custom_role_id'] = $this->resolveRoleId($data['custom_role_id']);
            $revokeSessions = $attributes['custom_role_id'] !== $user->custom_role_id;
        }

        if (array_key_exists('status', $data)) {
            $status = UserStatus::from($data['status']);
            $attributes['status'] = $status;
            $revokeSessions = $revokeSessions || ! $status->canAuthenticate();
        }

        $user = DB::transaction(function () use ($user, $attributes, $revokeSessions) {
            $updated = $this->users->update($user, $attributes);

            if ($revokeSessions) {
                $this->tokens->revokeAllForUser($updated, 'privileges_changed');
            }

            return $updated;
        });

        Log::info('users.updated', ['user_id' => $user->id, 'sessions_revoked' => $revokeSessions]);

        return $user;
    }

    public function assignRole(int $userId, ?int $roleId): User
    {
        return $this->update($userId, ['custom_role_id' => $roleId]);
    }

    public function changeStatus(int $userId, UserStatus $status): User
    {
        return $this->update($userId, ['status' => $status->value]);
    }

    /**
     * Soft delete. The user's sessions die with them.
     */
    public function delete(int $id, ?User $actor = null): void
    {
        $user = $this->find($id);

        if ($actor !== null && $actor->is($user)) {
            throw new ConflictException(
                'You cannot delete your own account.',
                'USER_SELF_DELETE',
            );
        }

        DB::transaction(function () use ($user) {
            $this->tokens->revokeAllForUser($user, 'user_deleted');
            $this->users->delete($user);
        });

        Log::info('users.deleted', ['user_id' => $user->id, 'actor_id' => $actor?->id]);
    }

    private function resolveRoleId(?int $roleId): ?int
    {
        if ($roleId === null) {
            return null;
        }

        $role = $this->roles->findById($roleId) ?? throw new ResourceNotFoundException('Role', $roleId);

        return (int) $role->getKey();
    }
}
