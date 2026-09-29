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
