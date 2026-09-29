<?php

namespace App\Services\Rbac;

use App\Enums\PermissionAction as Action;
use App\Enums\PermissionResource as Resource;

/**
 * The roles every workshop is given when it is provisioned.
 *
 * These are *blueprints*, not rows. A role belongs to one workshop — see the
 * `tenant_id` column — so this list is stamped out once per workshop rather
 * than seeded once for the platform, and each workshop is then free to retune
 * its own copy without touching anybody else's. That is the whole point of the
 * change: the platform's role list and a workshop's role list are different
 * lists, and deleting from one must never empty the other.
 *
 * The list lives here, in one place, because three callers need exactly the
 * same one: {@see RoleProvisioner} for a new workshop, the migration that
 * backfilled the workshops that already existed, and the tests that assert what
 * a fresh workshop can do. Written out three times they would drift, and a
 * workshop set up last year would quietly have different authority from one set
 * up this morning.
 *
 * ADMIN is deliberately absent. It is the platform's own superuser role, it
 * holds the wildcard grant, and no workshop ever gets one — see
 * {@see \Database\Seeders\RoleSeeder}.
 */
final class RoleDefaults
{
    /**
     * Blueprint for every default role, keyed by slug.
     *
     * @return array<string, array{name: string, description: string, grants: array<int, array{0: Action, 1: Resource}>}>
     */
    public static function all(): array
    {
        return [
            'OWNER' => [
                'name' => 'OWNER',
                'description' => 'Full control of this workshop: its people, its roles and its books.',
                'grants' => self::ownerGrants(),
            ],
            'MANAGER' => [
                'name' => 'MANAGER',
                'description' => 'Runs the floor: jobs, parties, catalogue, stock and staff. No user or role administration.',
                'grants' => self::managerGrants(),
            ],
            'ACCOUNTANT' => [
                'name' => 'ACCOUNTANT',
                'description' => 'Keeps the books: the chart, the transactions and the ledger. No staff or user administration.',
                'grants' => self::accountantGrants(),
            ],
            'DATA_ENTRY' => [
                'name' => 'DATA_ENTRY',
                'description' => 'Captures day-to-day transactions. No user or role administration.',
                'grants' => self::dataEntryGrants(),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /**
     * OWNER — the first user of a workshop, and the PRD's "Owner / Admin".
     *
     * Authority over the people inside their own workshop, and nothing at the
     * platform level: no TENANTS grant, so they never see another workshop.
     * They write ROLES too, but only ever their own workshop's — and what such
     * a role may contain is bounded by
     * {@see PermissionService::grantableFor()}.
     *
     * @return array<int, array{0: Action, 1: Resource}>
     */
    private static function ownerGrants(): array
    {
        return [
            // Their own workshop's details and settings — never anyone else's,
            // which is why this is WORKSPACE and not TENANTS.
            [Action::Read, Resource::Workspace],
            [Action::Update, Resource::Workspace],
            [Action::Read, Resource::Users],
            [Action::Write, Resource::Users],
            [Action::Update, Resource::Users],
            [Action::Delete, Resource::Users],
            [Action::Read, Resource::Roles],
            [Action::Write, Resource::Roles],
            [Action::Update, Resource::Roles],
            [Action::Delete, Resource::Roles],
            // The catalogue is read so the permission matrix can be drawn.
            [Action::Read, Resource::Permissions],
            [Action::Read, Resource::Accounts],
            [Action::Write, Resource::Accounts],
            [Action::Update, Resource::Accounts],
            // Who the workshop trades with. DELETE is held here and not by
            // DATA_ENTRY: removing a party is only ever possible before they
            // have been transacted with, so it is a tidying-up authority
            // rather than an operational one.
            [Action::Read, Resource::Parties],
            [Action::Write, Resource::Parties],
            [Action::Update, Resource::Parties],
            [Action::Delete, Resource::Parties],
            [Action::Read, Resource::Items],
            [Action::Write, Resource::Items],
            [Action::Update, Resource::Items],
            [Action::Delete, Resource::Items],
            // What is actually on the shelf, and what it is worth.
            [Action::Read, Resource::Stock],
            // The books: capturing transactions, and reading the whole
            // financial position they add up to.
            [Action::Read, Resource::Transactions],
            [Action::Write, Resource::Transactions],
            [Action::Update, Resource::Transactions],
            [Action::Delete, Resource::Transactions],
            [Action::Read, Resource::Ledger],
            // Who has been changing the chart, the parties, the catalogue and
            // the settings — the owner's authority and nobody else's, because
            // the trail records what a data-entry user did and reading it is
            // not part of doing it.
            [Action::Read, Resource::Audit],
            // Stored evidence. No UPDATE anywhere in the system — a file's
            // bytes never change — and DELETE is held here and not by
            // DATA_ENTRY, because removing evidence is not data entry.
            [Action::Read, Resource::Attachments],
            [Action::Write, Resource::Attachments],
            [Action::Delete, Resource::Attachments],
            [Action::Read, Resource::Jobs],
            [Action::Read, Resource::WorkshopJobs],
            [Action::Write, Resource::WorkshopJobs],
            [Action::Update, Resource::WorkshopJobs],
            [Action::Delete, Resource::WorkshopJobs],
            // The people who work for the workshop, and what they are paid.
            // Note that none of this posts anything: paying an advance and
            // running payroll additionally need WRITE:TRANSACTIONS, which this
            // role also holds, and holds separately.
            [Action::Read, Resource::Staff],
            [Action::Write, Resource::Staff],
            [Action::Update, Resource::Staff],
            [Action::Delete, Resource::Staff],
        ];
    }

    /**
     * MANAGER — the person who runs the floor while the owner is elsewhere.
     *
     * Everything operational: the counter, the bench, the shelf, the
     * catalogue, the people on the payroll and the money the day moves. The
     * line is drawn at *administration* — no USERS, no ROLES, no WORKSPACE
     * settings and no AUDIT. A manager runs the workshop; deciding who may
     * sign in to it, what each login is allowed to do, and reading the trail of
     * who changed what, stays with the owner.
     *
     * Holds STAFF, unlike DATA_ENTRY, because scheduling the fitters and
     * marking them present is exactly what this role is for. Holds LEDGER too:
     * somebody answerable for the month's numbers has to be able to read them.
     *
     * @return array<int, array{0: Action, 1: Resource}>
     */
    private static function managerGrants(): array
    {
        return [
            [Action::Read, Resource::Accounts],
            [Action::Read, Resource::Parties],
            [Action::Write, Resource::Parties],
            [Action::Update, Resource::Parties],
            [Action::Delete, Resource::Parties],
            [Action::Read, Resource::Items],
            [Action::Write, Resource::Items],
            [Action::Update, Resource::Items],
            [Action::Delete, Resource::Items],
            [Action::Read, Resource::Stock],
            [Action::Read, Resource::Transactions],
            [Action::Write, Resource::Transactions],
            [Action::Update, Resource::Transactions],
            [Action::Delete, Resource::Transactions],
            [Action::Read, Resource::Ledger],
            [Action::Read, Resource::Attachments],
            [Action::Write, Resource::Attachments],
            [Action::Delete, Resource::Attachments],
            [Action::Read, Resource::Jobs],
            [Action::Read, Resource::WorkshopJobs],
            [Action::Write, Resource::WorkshopJobs],
            [Action::Update, Resource::WorkshopJobs],
            [Action::Delete, Resource::WorkshopJobs],
            [Action::Read, Resource::Staff],
            [Action::Write, Resource::Staff],
            [Action::Update, Resource::Staff],
            [Action::Delete, Resource::Staff],
        ];
    }

    /**
     * ACCOUNTANT — the books, and only the books.
     *
     * Extends the chart of accounts, which DATA_ENTRY and MANAGER do not: it is
     * structural, and shaping it is this role's job. Reads the whole ledger and
     * every transaction, and posts and corrects them.
     *
     * No STAFF, and the exclusion is about privacy rather than authority: what
     * each person in the workshop earns is not something the books need in
     * order to balance, and the payroll reaches the ledger through
     * TRANSACTIONS, which this role does hold. No USERS, no ROLES, no
     * WORKSPACE, no AUDIT — the same administrative line MANAGER stops at.
     *
     * @return array<int, array{0: Action, 1: Resource}>
     */
    private static function accountantGrants(): array
    {
        return [
            [Action::Read, Resource::Accounts],
            [Action::Write, Resource::Accounts],
            [Action::Update, Resource::Accounts],
            [Action::Read, Resource::Parties],
            [Action::Write, Resource::Parties],
            [Action::Update, Resource::Parties],
            // Read-only on the catalogue and the shelf: an accountant values
            // stock, they do not decide what the workshop sells.
            [Action::Read, Resource::Items],
            [Action::Read, Resource::Stock],
            [Action::Read, Resource::Transactions],
            [Action::Write, Resource::Transactions],
            [Action::Update, Resource::Transactions],
            [Action::Delete, Resource::Transactions],
            [Action::Read, Resource::Ledger],
            [Action::Read, Resource::Attachments],
            [Action::Write, Resource::Attachments],
            [Action::Read, Resource::Jobs],
            // Read-only: the invoice is raised off a job somebody else booked.
            [Action::Read, Resource::WorkshopJobs],
        ];
    }

    /**
     * DATA_ENTRY — the PRD's "authorized data-entry user": captures
     * transactions, sees limited data, manages nobody.
     *
     * Read-only on the chart of accounts, because entering a transaction means
     * choosing accounts from it — the chart is structural and a clerk does not
     * extend it.
     *
     * Read *and write* on parties and items, though, because the customer
     * standing at the counter is new far more often than the chart needs a new
     * account, and a clerk who had to stop and fetch the owner to record a
     * walk-in would record the sale against the wrong party or not at all.
     * Editing and deleting an existing one stays higher up.
     *
     * Full authority over transactions — including discarding a draft, which
     * only ever throws away work that never reached the ledger — but **no**
     * LEDGER grant. Capturing the day's events and reading the workshop's whole
     * financial position are different things, and this role does the first.
     *
     * No STAFF, and deliberately: what each person in the workshop earns is not
     * something the person at the counter needs in order to do their job.
     *
     * @return array<int, array{0: Action, 1: Resource}>
     */
    private static function dataEntryGrants(): array
    {
        return [
            [Action::Read, Resource::Accounts],
            [Action::Read, Resource::Parties],
            [Action::Write, Resource::Parties],
            [Action::Read, Resource::Items],
            [Action::Write, Resource::Items],
            // Stock, but not the ledger. A clerk billing a bearing has to know
            // whether there is one, and a clerk who cannot see that guesses —
            // which is how stock goes negative in the first place.
            [Action::Read, Resource::Stock],
            [Action::Read, Resource::Transactions],
            [Action::Write, Resource::Transactions],
            [Action::Update, Resource::Transactions],
            [Action::Delete, Resource::Transactions],
            // The person holding the paper invoice is the person who
            // photographs it, so capture belongs here — but not deletion.
            [Action::Read, Resource::Attachments],
            [Action::Write, Resource::Attachments],
            // Somebody who uploads a file has to be able to see whether it went
            // through. A progress bar only the owner could watch would be a
            // progress bar nobody watches.
            [Action::Read, Resource::Jobs],
            // Booking a motor in, moving it along the bench and writing parts
            // onto it is precisely what the person at the counter does all day.
            // Only DELETE stays higher up.
            [Action::Read, Resource::WorkshopJobs],
            [Action::Write, Resource::WorkshopJobs],
            [Action::Update, Resource::WorkshopJobs],
        ];
    }
}
