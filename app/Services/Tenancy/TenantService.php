<?php

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Tenancy\NoWorkspaceException;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Accounting\ChartOfAccountProvisioner;
use App\Services\Inventory\CatalogueProvisioner;
use App\Services\Auth\TokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisioning and administration of tenants.
 *
 * Every method here runs above the isolation boundary — a tenant cannot be
 * created from inside itself — so the whole class is a platform super-admin
 * surface, guarded by the TENANTS permission in routes/api.php.
 */
class TenantService
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly TokenService $tokens,
        private readonly TenantContext $context,
        private readonly ChartOfAccountProvisioner $chartOfAccounts,
        private readonly CatalogueProvisioner $catalogue,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array{search?: string|null, status?: string|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->tenants->paginate($filters, $perPage);
    }

    public function find(int $id): Tenant
    {
        return $this->tenants->findById($id) ?? throw new ResourceNotFoundException('Tenant', $id);
    }

    public function userCount(Tenant $tenant): int
    {
        return $this->tenants->userCount($tenant);
    }

    /* ---------------------------------------------------------------------
     | Provisioning
     |-------------------------------------------------------------------- */

    /**
     * Create a workshop with no users yet.
     *
     * @param  array{name: string, gstin?: string|null, address?: string|null, state_code?: string|null, status?: string|null}  $data
     */
    public function provision(array $data): Tenant
    {
        return DB::transaction(fn () => $this->createTenant($data));
    }

    /**
     * Create a workshop and its first user, who becomes the owner.
     *
     * This is the whole of self-serve sign-up, and also what a platform
     * super-admin calls when onboarding a customer by hand. Both halves commit
     * together: a tenant with no owner is unreachable, and an owner with no
     * tenant cannot log in.
     *
     * @param  array{name: string, gstin?: string|null, address?: string|null, state_code?: string|null, status?: string|null}  $tenantData
     * @param  array{name: string, email: string, password: string}  $ownerData
     * @return array{tenant: Tenant, owner: User}
     */
    public function provisionWithOwner(array $tenantData, array $ownerData): array
    {
        $email = strtolower(trim($ownerData['email']));

        if ($this->users->emailExists($email)) {
            throw new ConflictException(
                'An account with this email address already exists.',
                'AUTH_EMAIL_TAKEN',
                ['field' => 'email'],
            );
        }

        $ownerRoleSlug = (string) config('tenancy.owner_role', 'OWNER');
        $ownerRole = $this->roles->findBySlug($ownerRoleSlug);

        if ($ownerRole === null) {
            // Loudly, and before anything is written. An owner with no role
            // can sign in and then do nothing at all, which presents as a
            // baffling support ticket rather than as the install error it is.
            // Same reasoning as AdminUserSeeder refusing to run without ADMIN.
            throw new RuntimeException(
                "The [{$ownerRoleSlug}] role is missing, so a workspace owner cannot be created. Run `php artisan db:seed`."
            );
        }

        return DB::transaction(function () use ($tenantData, $ownerData, $email, $ownerRole) {
            $tenant = $this->createTenant($tenantData);

            // Run the rest as the new tenant. Nothing below strictly needs it
            // yet, but this is the seam where per-tenant bootstrapping lands —
            // the seeded chart of accounts, above all.
            $owner = $this->context->runFor($tenant, fn () => $this->users->create([
                'tenant_id' => $tenant->getKey(),
                'name' => trim($ownerData['name']),
                'email' => $email,
                'password' => $ownerData['password'],
                'status' => UserStatus::Active,
                'custom_role_id' => $ownerRole->getKey(),
            ]));

            Log::info('tenancy.provisioned', [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
                'owner_id' => $owner->id,
            ]);

            return ['tenant' => $tenant, 'owner' => $owner];
        });
    }

    /* ---------------------------------------------------------------------
     | Administration
     |-------------------------------------------------------------------- */

    /**
     * Platform administration of any workshop. Accepts `status`, which is why
     * a workshop owner must never reach it — see {@see updateOwnWorkspace()}.
     *
     * @param  array{name?: string, gstin?: string|null, address?: string|null, state_code?: string|null, status?: string|null, financial_year_start_month?: int, timezone?: string, books_start_date?: string|null, payment_due_days?: int|null, allow_negative_stock?: bool, round_off_invoices?: bool}  $data
     */
    public function update(int $id, array $data): Tenant
    {
        $tenant = $this->find($id);

        $attributes = [];

        // The slug is not re-derived from a renamed workshop: it may already be
        // in URLs, logs and support tickets, and a silently moving identifier
        // is worse than a slightly stale one.
        if (array_key_exists('name', $data)) {
            $attributes['name'] = trim($data['name']);
        }

        foreach ([
            'address', 'state_code', 'financial_year_start_month', 'timezone', 'books_start_date',
            // M16 and M17. Neither changes a posted figure; both change what the
            // application refuses or reports, which is why both are on
            // Tenant::auditAttributes().
            'payment_due_days', 'allow_negative_stock', 'round_off_invoices',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('gstin', $data)) {
            $gstin = $this->normaliseGstin($data['gstin'], $tenant->id);
            $attributes['gstin'] = $gstin;

            // Re-derived here exactly as it is at provisioning: a GSTIN's first
            // two digits *are* the state code, so the two cannot legally
            // disagree. Without this, a workshop that supplies its GSTIN after
            // sign-up keeps whatever state code it was defaulted to, and every
            // bill it raises picks the wrong side of the intra/inter-state
            // split.
            if ($gstin !== null) {
                $attributes['state_code'] = substr($gstin, 0, 2);
            }
        }

        $suspending = false;

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $status = TenantStatus::from($data['status']);
            $attributes['status'] = $status;
            $suspending = ! $status->canOperate() && $tenant->isActive();
        }

        $tenant = DB::transaction(function () use ($tenant, $attributes, $suspending) {
            $updated = $this->tenants->update($tenant, $attributes);

            if ($suspending) {
                // The auth guard already refuses a suspended tenant on the next
                // request; killing the refresh tokens closes the ≤15-minute
                // window where an unexpired access token still works.
                $this->revokeTenantSessions($updated);
            }

            return $updated;
        });

        Log::info('tenancy.updated', ['tenant_id' => $tenant->id, 'suspended' => $suspending]);

        return $tenant;
    }

    public function changeStatus(int $id, TenantStatus $status): Tenant
    {
        return $this->update($id, ['status' => $status->value]);
    }

    /* ---------------------------------------------------------------------
     | Workshop self-service
     |-------------------------------------------------------------------- */

    /**
     * The caller's own workshop.
     *
     * Resolved from the tenant context, never from a URL id — there is no
     * parameter here to tamper with, so no way to address someone else's
     * workshop no matter what the client sends.
     */
    public function currentWorkspace(): Tenant
    {
        $tenantId = $this->context->current();

        if ($tenantId === null) {
            throw new NoWorkspaceException;
        }

        // Deliberately unscoped: Tenant is not a tenant-owned model — it is
        // the thing tenancy scopes *to* — so this is an ordinary lookup by the
        // id the auth guard already established.
        return $this->tenants->findById($tenantId)
            ?? throw new ResourceNotFoundException('Workspace', $tenantId);
    }

    /**
     * An owner editing their own workshop.
     *
     * `status` is stripped rather than merely absent from the request rules:
     * suspension is a platform decision, and a workshop must not be able to
     * un-suspend itself even if a future caller passes the field through.
     *
     * @param  array{name?: string, gstin?: string|null, address?: string|null, state_code?: string|null, financial_year_start_month?: int, timezone?: string, books_start_date?: string|null}  $data
     */
    public function updateOwnWorkspace(array $data): Tenant
    {
        $workspace = $this->currentWorkspace();

        unset($data['status'], $data['slug'], $data['currency']);

        return $this->update((int) $workspace->getKey(), $data);
    }

    /**
     * Soft delete. Refused while the workshop still has users — the FK is
     * restrictOnDelete, and an orphaned set of accounts is worse than an
     * explicit error.
     */
    public function delete(int $id): void
    {
        $tenant = $this->find($id);

        $users = $this->tenants->userCount($tenant);

        if ($users > 0) {
            throw new ConflictException(
                "This workspace still has {$users} user(s). Remove them before deleting it.",
                'TENANT_IN_USE',
                ['user_count' => $users],
            );
        }

        $this->tenants->delete($tenant);

        Log::info('tenancy.deleted', ['tenant_id' => $tenant->id]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * @param  array{name: string, gstin?: string|null, address?: string|null, state_code?: string|null, status?: string|null}  $data
     */
    private function createTenant(array $data): Tenant
    {
        $name = trim($data['name']);
        $gstin = $this->normaliseGstin($data['gstin'] ?? null);

        $tenant = $this->tenants->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'gstin' => $gstin,
            'address' => $data['address'] ?? null,
            // A GSTIN's first two digits are the state code, so prefer it over
            // anything supplied separately — they cannot legally disagree.
            'state_code' => $gstin !== null
                ? substr($gstin, 0, 2)
                : ($data['state_code'] ?? config('tenancy.defaults.state_code')),
            'status' => TenantStatus::from($data['status'] ?? TenantStatus::Active->value),

            // Settings are stamped at provisioning rather than left null and
            // resolved later: a report that has to fall back to a config value
            // for the financial year is a report that changes retrospectively
            // when someone edits the config.
            'financial_year_start_month' => (int) config('tenancy.defaults.financial_year_start_month', 4),
            'timezone' => (string) config('tenancy.defaults.timezone', 'Asia/Kolkata'),
            'currency' => (string) config('tenancy.defaults.currency', 'INR'),
            // Left null: go-live is decided during onboarding, not at sign-up.
            'books_start_date' => null,
        ]);

        // Every workshop gets its books the moment it exists. Inside the
        // caller's transaction, so a workshop can never exist without a chart
        // of accounts — the posting engine has no fallback if one is missing.
        $this->chartOfAccounts->seedFor($tenant);

        // And its catalogue vocabulary, for the same reason and in the same
        // breath: a workshop with no units and no categories cannot record a
        // product at all, and the create form would open on an empty dropdown.
        $this->catalogue->seedFor($tenant);

        return $tenant;
    }

    /**
     * "Sharma Electricals" -> sharma-electricals, then -2, -3 … on collision.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Tenant::slugFor($name);

        if ($base === '') {
            $base = 'workshop';
        }

        if (! $this->tenants->slugExists($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = "{$base}-{$suffix}";

            if (! $this->tenants->slugExists($candidate)) {
                return $candidate;
            }
        }

        // Fifty workshops sharing a name is unlikely enough that a random tail
        // is a better answer than counting forever.
        return $base.'-'.Str::lower(Str::random(6));
    }

    private function normaliseGstin(?string $gstin, ?int $exceptTenantId = null): ?string
    {
        if (! filled($gstin)) {
            return null;
        }

        $gstin = strtoupper(trim($gstin));

        if ($this->tenants->gstinExists($gstin, $exceptTenantId)) {
            throw new ConflictException(
                'A workspace with this GSTIN already exists.',
                'TENANT_GSTIN_TAKEN',
                ['field' => 'gstin'],
            );
        }

        return $gstin;
    }

    /**
     * End every session belonging to a tenant. Crosses the isolation boundary
     * on purpose: the caller is a platform super-admin acting on the tenant.
     */
    private function revokeTenantSessions(Tenant $tenant): void
    {
        $this->context->runWithoutScope(function () use ($tenant) {
            User::where('tenant_id', $tenant->getKey())
                ->get()
                ->each(fn (User $user) => $this->tokens->revokeAllForUser($user, 'tenant_suspended'));
        });
    }
}
