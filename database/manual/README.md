# Migrations that are never run automatically

`php artisan migrate` scans `database/migrations`. It does **not** scan this
directory, and that is the whole reason this directory exists.

What lives here is a migration that is correct, tested and wanted — and that
must only ever run when somebody has decided, that day, that it should. Nothing
in a deployment, a CI step or a recovery can reach it.

---

## `sql/` — schema steps, from §4.6 onward

From **29 September 2026** a schema change is not a migration. `php artisan
migrate` reads a directory and applies whatever it finds, so a file committed
weeks ago runs during an unrelated deployment, on a live workshop's books, with
nobody deciding anything that day. `sql/` is where a schema change waits for an
operator instead.

Each step is a pair:

| File | What it is |
| --- | --- |
| `<date>_<nnn>_<name>.sql` | The forward step. Opens with what it does, what it locks, how long it runs, and the `SELECT` that proves it worked. |
| `<date>_<nnn>_<name>.rollback.sql` | The undo, and what it costs. |

**Running one.** Take a dump first — every one of these is run against books
somebody is trading on.

```bash
mysqldump -u <user> -p <database> > before-<step>-$(date +%F).sql
mysql -u <user> -p <database> < database/manual/sql/<step>.sql
```

The last statement in each file is a verification `SELECT`. Read it. A step that
prints nothing did not do what it says.

**The test suite runs these files itself**, once, straight after `migrate:fresh`
— see `tests/Support/ManualSchema.php`. Not a copy of them: the same bytes, so
the shape under test cannot drift from the shape on the server. Do not write a
migration that declares the same table "just for tests"; that second declaration
is the drift, and it would be found the first time somebody corrected one of the
two.

### `2026_09_29_001_item_components.sql` — recipes

Creates `item_components`: what a made thing consumes. A rewind is sold as one
line at one price and takes copper, varnish and sleeve off the shelf, and before
this the catalogue had no way to say so — the wire was bought, never issued, and
the shelf, the Inventory account and every margin moved wrong together.

One new table. No column added to anything, no existing row written, nothing
locked on any table the workshop is billing against. It is safe to run during
trading hours.

**The code works before it is run and after** (§4.6). `ItemComponentService::isInstalled()`
is the single place that is decided: with the table absent every read answers
"no recipe", which is exactly what was true of every product yesterday, so every
screen and every posting behaves as it did. Only a *write* refuses, out loud,
saying this step has not been run — because silently discarding a recipe
somebody just typed is the one failure that would look like success.

### `2026_09_30_001_job_kinds.sql` — what the bench takes in

Creates `job_kinds` and `job_kind_attributes`, and adds a nullable
`workshop_jobs.job_kind_id`.

The intake form's "Kind" list was `item_categories` filtered on `holds_stock`.
That filter separates *kept on a shelf* from *made when it is sold*, which says
nothing about whether a customer wheels one through a door — so the bench offered
Part, Bulk material, Bearing, Capacitor and Wire, none of which anybody brings
in, and offered no cooler, fan, mixer or submersible at all, because a repair
shop does not stock the things it repairs.

Two new tables and one `ADD COLUMN`. Nothing existing is written and nothing is
dropped: `workshop_jobs.category_id` is **left in place** on purpose, because it
is what the fallback below writes. The `ADD COLUMN` is nullable with no default,
which MySQL 8 performs INSTANT — no rebuild, no row touched — and on the server
this was prepared for `workshop_jobs` was empty besides. Safe during trading
hours.

**The code works before it is run and after** (§4.6).
`JobKindService::isInstalled()` is the single place that is decided: with the
tables absent the bench falls back to the list it read before — the catalogue's
categories, wrong filter and all, because being wrong in exactly yesterday's way
is the point of a fallback. No screen breaks and nothing is silently discarded,
because a job's kind is optional either way.

**Then, once, after the SQL:**

```
php artisan workshop:seed-kinds
```

Create-only and idempotent, matched by name — it gives each workshop its eight
opening kinds and their question sets, and adds nothing on a second run. A
workshop provisioned after this deployment gets them at provisioning time and
does not need the command.

---


---

## `2026_09_30_002_clear_trading_documents.sql` — empty the shelf, keep the catalogue

**A data operation, not a schema step**, which is why it sits here beside
`clear_trading_data` rather than in `sql/`. Two reasons, and the second is the
sharper one. §4.5: a one-time data operation must not live anywhere a deployment,
a CI step or a recovery can reach it. And `sql/` is not merely a directory — the
test suite globs it and runs every non-rollback file in it against the test
database (`tests/Support/ManualSchema.php`), which is exactly right for a
`CREATE TABLE` and exactly wrong for a `DELETE`. It is written as SQL rather than
as a guarded PHP migration because §4.6 is now the stricter rule: the operator
runs it by hand, in a window they chose, against a database they have just backed
up.

It deletes every document that **moved stock** for **one workshop**, and every
settlement of one:

| Goes | Stays |
| --- | --- |
| sale · purchase · sales_return · purchase_return · opening · stock_adjustment · receipt · payment | expense · journal · payroll · staff_advance |
| their lines, ledger entries, payment splits, stock movements, allocations, staff attributions, share links and opening imports | items, variants, recipes, categories, attributes, brands, units |
| | parties, job cards, staff, users, roles, the chart of accounts, and the numbering series |

**Stock goes to nil as a consequence, not as a separate statement.** There is no
`qty_on_hand` column and no `avg_cost` column anywhere in this schema — a
position is `SUM(quantity)` over `stock_movements` — so deleting the movements
*is* setting the shelf to nothing. Nothing is recalculated and no cache is
cleared afterwards.

Three of its decisions are the ones somebody will want to change, and each is
wrong in a way that looks right.

**Receipts and payments are in the doomed list although neither moves stock.**
They settle the documents that do. Leave a receipt whose invoice has been deleted
and Sundry Debtors carries a credit for a customer with no invoice behind it,
which is a worse tangle than the one being cleared. They go together or not at
all.

**Expenses and manual journals are not**, because neither moves stock and both
are ordinary records a workshop wants to keep — but a manual journal *can* be
posted straight to Inventory, Sales or COGS, and one that was will be left
standing with nothing behind it. That is why the preview exists and why block 6
of it is a warning rather than a count: the file will not guess at those, and the
operator has to reverse them by hand first. The worked case is an Inventory
balance left standing over an empty shelf.

**Job cards survive and their parts are un-billed**, rather than the cards being
deleted with the invoices. `workshop_job_parts.transaction_line_id` is RESTRICT,
so it has to be nulled before the lines go either way; nulling it rather than
deleting the row is what lets a repair that was billed on a wrong invoice be
billed again on a right one. It also means a job part still holds its item down —
preview block 9 lists exactly which items that is, because the step does not
touch them.

**Numbering is left alone by default.** The optional block at the foot of the
file restarts INV, PUR, RCT, PAY, CN, DN, ADJ and OB at 1001, and it is commented
out on purpose: a GST invoice series has to be consecutive, and re-issuing a
number a customer already holds is worse than a gap. JOB, EXP, JV, ADV and SAL
are deliberately absent from even that block — those documents all survive.

### Running it

```bash
# 1. the only undo there is
mysqldump --single-transaction --routines --triggers -u <user> -p <db> > before-clear-$(date +%F).sql

# 2. read all nine blocks. Change @tenant at the top of each file first.
mysql -u <user> -p <db> --table < database/manual/2026_09_30_002_clear_trading_documents.preview.sql

# 3. with the application stopped
mysql -u <user> -p <db> --table < database/manual/2026_09_30_002_clear_trading_documents.sql
```

Both files must run **in one session each** — they build a `TEMPORARY` table,
which does not survive a reconnect, so they cannot be pasted in block by block.
The set of doomed documents is held in that table as **ids**, not type names:
matching a `VARCHAR` column against a session variable compares two collations
and errors (1267), and it is also the only way every block can be guaranteed to
be talking about the same set.

**Locks and runtime.** Row locks only — no `ALTER`, no DDL on any real table, no
rewrite. Everything is inside one `START TRANSACTION`/`COMMIT` with foreign keys
left **enabled**, so a reference this file did not expect fails loudly and
commits nothing rather than orphaning a row quietly. Run it with the application
stopped: every row it touches is locked until it commits, and a posting attempt
during the window will block or deadlock.

**It is scoped to one workshop and fails safe.** Every statement filters on
`@tenant`; if it is unset or wrong, `WHERE tenant_id = @tenant` matches nothing
and the file deletes nothing at all. A server with more than one workshop cannot
have the other one's books touched by accident. Re-running it is a clean no-op.

**What holds it correct.** It was exercised against a scratch database carrying
the real schema and a fixture of two workshops — a posted opening, purchase,
sale, credit note against that sale's line, allocated receipt and payment, stock
adjustment, a reversed sale pair, a draft purchase, a live share link, a staff
attribution, a job card billed onto a sale line, and surviving expense, journal,
payroll and advance vouchers. Asserted after: no stock movements left, every
variant at nil, the ledger still balancing at 0.00, no orphans in lines, entries
or movements, the second workshop byte-for-byte untouched, the payroll voucher
and its split intact, job parts un-billed rather than deleted, and items
deletable once their job parts were removed.

---

## `2026_09_05_100003_clear_trading_data_keeping_staff_and_users.php`

**Empties the trading side of the books for a go-live, and leaves the people
alone.**

| Goes | Stays |
| --- | --- |
| Every counterparty, every product, every position on the shelf | Users, roles, permissions, passkeys, tenants |
| Every document that moved either — sales, purchases, credit and debit notes, receipts, payments, expenses, manual journals, stock adjustments, opening balances, job cards | The chart of accounts, and the catalogue's vocabulary: categories, attributes, brands, units |
| The ledger entries behind all of them | The whole of staff — employees, designations, attendance, payroll runs and advances, **including their money** |
| Invoice and purchase numbering, back to 1001 | The ADV and SAL series, which the surviving vouchers already carry |

It leaves the books in an honest but lopsided state, deliberately: Cash and
Salary Expense carry wages with no sales behind them, so the trial balance opens
with the staff side already moved. That is what "the books started empty except
for what we have already paid people" actually looks like.

### Why it is not in the migration path

It was in `database/migrations` until **7 September 2026**. It was moved because
of the one case nobody plans for:

> Restore a database dump taken before it ran, then run migrations — which is
> exactly what a recovery is — and it empties the books a second time, at the
> moment least able to absorb it.

The same applies to any installation that has been trading for a while and then
catches up on migrations, and to a fresh clone pointed at a live database. None
of those is careless; all of them are ordinary. So the file was taken out of the
path that runs by itself.

### Running it, when it is genuinely wanted

**1 · Take a database dump.** There is no `down()`, and there is not one that
pretends to be. Nothing here can be undone.

```
mysqldump -u <user> -p <database> > before-clearing-<date>.sql
```

**2 · Ask for it explicitly.** The file carries a second lock and refuses to do
anything without it:

```bash
# bash
ALLOW_CLEAR_TRADING_DATA=yes php artisan migrate --path=database/manual
```

```powershell
# PowerShell
$env:ALLOW_CLEAR_TRADING_DATA='yes'; php artisan migrate --path=database/manual
Remove-Item Env:\ALLOW_CLEAR_TRADING_DATA
```

The variable is read from the process environment rather than through Laravel's
`env()` helper, so a cached config cannot make a deliberate run silently refuse.

**3 · If it has already run on this database**, Laravel has its name in the
`migrations` table and will skip it — the ordinary once-only rule, and a useful
extra brake. Re-running it is a third deliberate act: delete that row first.

```sql
DELETE FROM migrations WHERE migration = '2026_09_05_100003_clear_trading_data_keeping_staff_and_users';
```

### What holds it correct

`tests/Feature/Console/ClearTradingDataMigrationTest.php`, which runs in the
ordinary suite. It builds a workshop's whole trading history through the
endpoints the modules actually call — a purchase onto the shelf, a sale off it
with a fitter's name on it, a credit note against that sale's line, an allocated
receipt, a shared link, a reversed journal, a job card with parts, an opening
import — runs the migration over it, and asserts both halves: nothing trading
survives, and everything about the people does, down to the ledger entries that
paid them. It also asserts that the guard refuses **before** deleting anything,
rather than by unwinding a transaction afterwards.

Moving this file back into `database/migrations` would put the recovery hazard
back. The guard would still refuse, but it is the second lock, not the first.

---

## `2026_09_19_100003_move_workshop_users_onto_their_own_workshops_roles.php`

**Finishes the move to per-workshop roles: takes each workshop user off the
shared platform role they still hold and puts them on their own workshop's.**

Roles used to be platform-wide. One `OWNER` row and one `DATA_ENTRY` row were
shared by every workshop *and* listed on the platform administrator's own Roles
card, so deleting a role from that card emptied it out of every workshop at
once. They are per workshop now — `App\Services\Rbac\RoleDefaults` holds the
blueprints and `RoleProvisioner` stamps them out when a workshop is created.

The automatic migration `2026_09_19_100002` gave every workshop that already
existed its own `OWNER`, `MANAGER`, `ACCOUNTANT` and `DATA_ENTRY`. It stopped
there on purpose. This file does the rest:

| Does | Never does |
| --- | --- |
| Moves a workshop user from a platform `OWNER`/`DATA_ENTRY` to their own workshop's role of the same slug | Touches `ADMIN`, or any platform user |
| Soft-deletes the platform `OWNER`/`DATA_ENTRY` rows **once nobody holds them** | Deletes a role somebody is still on |
| Leaves a user alone, and logs them, when their workshop has no role of that slug | Strips anybody of their grants |

### Why it is not in the migration path

Two reasons, and the second is the sharper one.

§4.5, the same hazard the file above records: restore a dump taken before it
ran, then migrate — which is what a recovery is — and it runs a second time.

§10.3: **no migration may change the role of the two local development
accounts**, and `owner@choudharymotors.test` is precisely the record this moves.
A file in `database/migrations` doing this would break that rule on every
developer's machine, by design rather than by accident.

### Running it

**1 · Take a database dump.** There is no `down()`. Which user held which role
beforehand is recorded nowhere this could read back.

```
mysqldump -u <user> -p <database> > before-role-repoint-<date>.sql
```

**2 · Ask for it explicitly.**

```bash
# bash
ALLOW_ROLE_REPOINT=yes php artisan migrate --path=database/manual
```

```powershell
# PowerShell
$env:ALLOW_ROLE_REPOINT='yes'; php artisan migrate --path=database/manual
Remove-Item Env:\ALLOW_ROLE_REPOINT
```

The variable is read from the process environment rather than through `env()`,
so a cached config cannot make a deliberate run silently refuse.

**3 · Check the log.** It writes one `rbac.roles_repointed` entry with how many
users moved, how many platform roles were retired, and every user it left alone
because their workshop had no role of that slug.

Running it twice is harmless: a user already on a workshop role is not a
candidate, and a retired platform role is not found.

### Until it has been run

Nothing is broken. A workshop user still holding a platform role keeps every
grant they had — authorization loads `customRole` directly and is not scoped.
What they will notice is that their own role is not in their workshop's role
list, because it is not one of that workshop's roles.
