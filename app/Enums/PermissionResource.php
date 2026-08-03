<?php

namespace App\Enums;

/**
 * Resources owned by this module. Other modules add their own constants /
 * seed rows; the permission guard accepts any string, so nothing here has to
 * change when a new resource appears elsewhere in the application.
 */
enum PermissionResource: string
{
    case Users = 'USERS';
    case Roles = 'ROLES';
    case Permissions = 'PERMISSIONS';

    /**
     * Workshops themselves. A platform-level resource: granting it means
     * authority over tenants, not authority within one.
     */
    case Tenants = 'TENANTS';

    /**
     * The caller's *own* workshop — its name, GSTIN, address and settings.
     *
     * Distinct from TENANTS, and the distinction is the point: TENANTS is
     * authority over every workshop on the platform, WORKSPACE is authority
     * over exactly one, resolved from the tenant context rather than from a
     * URL. An owner holds the second and never the first.
     */
    case Workspace = 'WORKSPACE';

    /**
     * The chart of accounts. Structural, not transactional — authority here
     * shapes the books, it does not post to them.
     */
    case Accounts = 'ACCOUNTS';

    /**
     * Customers and suppliers — the record of who the workshop trades with.
     *
     * Master data like ACCOUNTS, and gated separately from it because the two
     * are edited by different people: a data-entry user adds the customer
     * standing at the counter without being trusted to extend the chart of
     * accounts. Note that holding this grants the *list*, not the money —
     * reading what a party owes is LEDGER.
     */
    case Parties = 'PARTIES';

    /**
     * The catalogue — what the workshop sells, fits, consumes and charges for,
     * and the specific variants of each.
     *
     * Master data like ACCOUNTS and PARTIES, and gated with PARTIES rather than
     * with ACCOUNTS for the same reason: a data-entry user meets a part nobody
     * has recorded yet as often as they meet a new customer, and a clerk who had
     * to fetch the owner to add a bearing would end up billing it as something
     * else. Holding this grants the catalogue, not the stock position — M8's
     * quantities and costs are a separate read.
     */
    case Items = 'ITEMS';

    /**
     * Quantities on hand, weighted average cost and stock value — M8.
     *
     * Separate from ITEMS on purpose, and the split is the same one ACCOUNTS and
     * LEDGER already make: knowing that the workshop deals in 5 HP motors is
     * catalogue, knowing that four of them are on the shelf at ₹8,200 each is
     * position. A picker needs the first; a stock-take needs the second.
     *
     * Read-only, like LEDGER, and for the same structural reason: nothing writes
     * to `stock_movements` except the posting engine, so a WRITE, UPDATE or
     * DELETE grant would be a grant over something that cannot happen. Moving
     * stock is done by posting a transaction, which is TRANSACTIONS.
     */
    case Stock = 'STOCK';

    /**
     * Capturing business events: writing a transaction, saving it as a draft,
     * posting it, correcting it with a reversal.
     *
     * The day job of a workshop, and the one accounting grant a DATA_ENTRY user
     * holds. Note that it does not imply LEDGER: entering the day's takings is
     * a different authority from reading the workshop's whole financial
     * position.
     */
    case Transactions = 'TRANSACTIONS';

    /**
     * Reading the books as a whole — account ledgers and the trial balance.
     *
     * Read-only by nature: nothing writes to the ledger except the posting
     * engine, so there is no WRITE, UPDATE or DELETE to grant.
     */
    case Ledger = 'LEDGER';

    /**
     * The trail of who changed what — M13.
     *
     * Read-only, structurally, like LEDGER and STOCK: rows arrive through model
     * events and are refused an UPDATE and a DELETE on the model itself, so a
     * WRITE, UPDATE or DELETE grant would be authority over something that
     * cannot happen.
     *
     * Its own resource rather than part of WORKSPACE, and the split is the one
     * this enum keeps making: WORKSPACE is authority to *change* the workshop's
     * settings, AUDIT is authority to see who has been changing things. Only
     * OWNER holds it. A DATA_ENTRY user does not, deliberately — the trail
     * records what they did, and being able to read it is not part of doing it.
     *
     * Note the boundary. This covers the workshop's own master data: its chart,
     * its parties, its catalogue, its settings, its people. Roles and
     * permissions are platform-defined — one role belongs to every workshop at
     * once — so there is no workshop whose history they belong in, and they are
     * not on the trail. See {@see \App\Enums\AuditResource}.
     */
    case Audit = 'AUDIT';

    /**
     * Stored files: photographed invoices, recorded audio, uploaded documents —
     * M14, and the ground M15's capture agent is built on.
     *
     * READ, WRITE and DELETE, and **no UPDATE**, which is the whole shape of the
     * module in one line: a file's bytes never change. Correcting a bad
     * photograph means taking another one, so an UPDATE grant would be authority
     * over an operation that does not exist. DATA_ENTRY holds READ and WRITE —
     * the person holding the invoice is the person photographing it — and
     * deleting evidence stays with the owner.
     */
    case Attachments = 'ATTACHMENTS';

    /**
     * Background work: what has been queued, what is running, what failed — M14.
     *
     * Read-only for the same structural reason as LEDGER and STOCK. A job run is
     * created by the act that dispatched it — an upload, an import — never by a
     * POST to a jobs endpoint, so there is nothing here to write. DATA_ENTRY
     * holds it: somebody who uploads a file has to be able to see whether it
     * went through, and a progress bar only the owner could watch would be a
     * progress bar nobody watches.
     */
    case Jobs = 'JOBS';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
