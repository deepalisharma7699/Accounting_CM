<?php

namespace Database\Seeders;

use App\Enums\PermissionAction;
use App\Enums\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Rbac\AuthorizationService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedAdminRole();
        $this->seedOwnerRole();
        $this->seedDataEntryRole();
        $this->seedExampleCustomRole();
    }

    /**
     * ADMIN — the seeded system role. It holds the single `*` / `*` grant, so
     * it satisfies every permission check without enumerating them, and it is
     * flagged is_system_role so the API refuses to edit or delete it.
     */
    private function seedAdminRole(): void
    {
        $name = (string) config('rbac.system_roles.admin', 'ADMIN');
        $wildcard = (string) config('rbac.wildcard', '*');

        $role = Role::updateOrCreate(
            ['slug' => Role::slugFor($name)],
            [
                'name' => $name,
                'description' => 'Full system access. Cannot be modified or deleted.',
                'is_system_role' => true,
            ]
        );

        $fullAccess = Permission::firstOrCreate(
            ['action' => $wildcard, 'resource' => $wildcard],
            ['description' => 'Full access to every action on every resource.']
        );

        // syncWithoutDetaching, not sync: never strip grants an operator may
        // have deliberately attached to the admin role.
        $role->permissions()->syncWithoutDetaching([$fullAccess->id]);
    }

    /**
     * OWNER — the first user of a workshop, and the PRD's "Owner / Admin".
     *
     * Authority over the people inside their own tenant, and nothing at the
     * platform level: no TENANTS grant (they cannot see other workshops) and
     * no write on ROLES (roles are platform-defined, so a tenant creating one
     * would be creating it for everybody).
     *
     * Accounting grants — parties, items, transactions, reports — land here as
     * each slice is built.
     */
    private function seedOwnerRole(): void
    {
        $this->seedSystemRole(
            name: (string) config('tenancy.owner_role', 'OWNER'),
            description: 'Full control of a single workshop: its people and its books.',
            grants: [
                // Their own workshop's details and settings — never anyone
                // else's, which is why this is WORKSPACE and not TENANTS.
                [PermissionAction::Read, PermissionResource::Workspace],
                [PermissionAction::Update, PermissionResource::Workspace],
                [PermissionAction::Read, PermissionResource::Users],
                [PermissionAction::Write, PermissionResource::Users],
                [PermissionAction::Update, PermissionResource::Users],
                [PermissionAction::Delete, PermissionResource::Users],
                [PermissionAction::Read, PermissionResource::Roles],
                [PermissionAction::Read, PermissionResource::Accounts],
                [PermissionAction::Write, PermissionResource::Accounts],
                [PermissionAction::Update, PermissionResource::Accounts],
                // Who the workshop trades with. DELETE is held here and not by
                // DATA_ENTRY: removing a party is only ever possible before
                // they have been transacted with, so it is a tidying-up
                // authority rather than an operational one.
                [PermissionAction::Read, PermissionResource::Parties],
                [PermissionAction::Write, PermissionResource::Parties],
                [PermissionAction::Update, PermissionResource::Parties],
                [PermissionAction::Delete, PermissionResource::Parties],
                // The catalogue. DELETE is held here and not by DATA_ENTRY for
                // the same reason as parties: removing an item is only possible
                // before anything references it, so it is a tidying-up authority
                // rather than an operational one.
                [PermissionAction::Read, PermissionResource::Items],
                [PermissionAction::Write, PermissionResource::Items],
                [PermissionAction::Update, PermissionResource::Items],
                [PermissionAction::Delete, PermissionResource::Items],
                // What is actually on the shelf, and what it is worth.
                [PermissionAction::Read, PermissionResource::Stock],
                // The books: capturing transactions, and reading the whole
                // financial position they add up to.
                [PermissionAction::Read, PermissionResource::Transactions],
                [PermissionAction::Write, PermissionResource::Transactions],
                [PermissionAction::Update, PermissionResource::Transactions],
                [PermissionAction::Delete, PermissionResource::Transactions],
                [PermissionAction::Read, PermissionResource::Ledger],
                // M13. Who has been changing the chart, the parties, the
                // catalogue and the settings — the owner's authority and
                // nobody else's, because the trail records what a data-entry
                // user did and reading it is not part of doing it.
                [PermissionAction::Read, PermissionResource::Audit],
                // M14. Stored evidence: photographed invoices and recorded
                // audio. No UPDATE anywhere in the system — a file's bytes
                // never change — and DELETE is held here and not by
                // DATA_ENTRY, because removing evidence is not data entry.
                [PermissionAction::Read, PermissionResource::Attachments],
                [PermissionAction::Write, PermissionResource::Attachments],
                [PermissionAction::Delete, PermissionResource::Attachments],
                [PermissionAction::Read, PermissionResource::Jobs],
            ],
        );
    }

    /**
     * DATA_ENTRY — the PRD's "authorized data-entry user": captures
     * transactions, sees limited data, manages nobody.
     *
     * Read-only on the chart of accounts, because entering a transaction means
     * choosing accounts from it — the chart is structural and a clerk does not
     * extend it.
     *
     * Read *and write* on parties, though, because the customer standing at the
     * counter is new far more often than the chart needs a new account, and a
     * data-entry user who had to stop and fetch the owner to record a walk-in
     * would end up recording the sale against the wrong party or not at all.
     * Editing and deleting an existing party stays with the owner.
     *
     * Full authority over transactions — including discarding a draft, which
     * only ever throws away work that never reached the ledger — but **no**
     * LEDGER grant. Capturing the day's events and reading the workshop's whole
     * financial position are different things, and the PRD's data-entry user
     * does the first.
     */
    private function seedDataEntryRole(): void
    {
        $this->seedSystemRole(
            name: 'DATA_ENTRY',
            description: 'Captures day-to-day transactions. No user or role administration.',
            grants: [
                [PermissionAction::Read, PermissionResource::Accounts],
                [PermissionAction::Read, PermissionResource::Parties],
                [PermissionAction::Write, PermissionResource::Parties],
                // Read and write on the catalogue, for the same reason as
                // parties: a part nobody has recorded yet turns up as often as a
                // new customer, and a clerk who had to fetch the owner to add a
                // bearing would bill it as something else. Editing and removing
                // an existing item stays with the owner.
                [PermissionAction::Read, PermissionResource::Items],
                [PermissionAction::Write, PermissionResource::Items],
                // Stock, but not the ledger. The distinction holds: a clerk
                // billing a bearing has to know whether there is one, and a
                // clerk who cannot see that guesses — which is how stock goes
                // negative in the first place. They already type the cost on
                // every purchase they enter, so the value column tells them
                // nothing they did not put there themselves.
                [PermissionAction::Read, PermissionResource::Stock],
                [PermissionAction::Read, PermissionResource::Transactions],
                [PermissionAction::Write, PermissionResource::Transactions],
                [PermissionAction::Update, PermissionResource::Transactions],
                [PermissionAction::Delete, PermissionResource::Transactions],
                // M14. The person holding the paper invoice is the person who
                // photographs it, so capture belongs here — but not deletion:
                // removing evidence is not data entry, and it stays with the
                // owner. No UPDATE exists for anybody.
                [PermissionAction::Read, PermissionResource::Attachments],
                [PermissionAction::Write, PermissionResource::Attachments],
                // Somebody who uploads a file has to be able to see whether it
                // went through. A progress bar only the owner could watch would
                // be a progress bar nobody watches.
                [PermissionAction::Read, PermissionResource::Jobs],
                // Deliberately no READ:AUDIT. The trail records what this user
                // did; reading it is a different authority.
            ],
        );
    }

    /**
     * @param  array<int, array{0: PermissionAction, 1: PermissionResource}>  $grants
     */
    private function seedSystemRole(string $name, string $description, array $grants): void
    {
        $role = Role::updateOrCreate(
            ['slug' => Role::slugFor($name)],
            [
                'name' => $name,
                'description' => $description,
                'is_system_role' => true,
            ]
        );

        // syncWithoutDetaching, not sync: re-seeding must never strip a grant
        // an operator deliberately added.
        $role->permissions()->syncWithoutDetaching($this->permissionIds($grants));

        // Effective permissions are cached per role for an hour. Seeding writes
        // to the pivot directly rather than through RoleService, so without this
        // an operator re-seeding to pick up a new module's grants would find
        // them inert until the cache expired — which presents as a baffling
        // "the permission is in the database but I still get a 403".
        $this->authorization()->flushRoleCache($role);
    }

    private function authorization(): AuthorizationService
    {
        return app(AuthorizationService::class);
    }

    /**
     * @param  array<int, array{0: PermissionAction, 1: PermissionResource}>  $grants
     * @return array<int, int>
     */
    private function permissionIds(array $grants): array
    {
        return collect($grants)
            ->map(fn (array $pair) => Permission::where('action', $pair[0]->value)
                ->where('resource', $pair[1]->value)
                ->value('id'))
            ->filter()
            ->all();
    }

    /**
     * A worked example of a custom (non-system) role: it can see and create
     * users and read the role catalogue, but cannot delete anything.
     */
    private function seedExampleCustomRole(): void
    {
        $role = Role::updateOrCreate(
            ['slug' => Role::slugFor('User Manager')],
            [
                'name' => 'User Manager',
                'description' => 'Can view, create and update users; read-only on roles.',
                'is_system_role' => false,
            ]
        );

        $grants = [
            [PermissionAction::Read, PermissionResource::Users],
            [PermissionAction::Write, PermissionResource::Users],
            [PermissionAction::Update, PermissionResource::Users],
            [PermissionAction::Read, PermissionResource::Roles],
        ];

        $ids = collect($grants)
            ->map(fn (array $pair) => Permission::where('action', $pair[0]->value)
                ->where('resource', $pair[1]->value)
                ->value('id'))
            ->filter()
            ->all();

        $role->permissions()->sync($ids);
    }
}
