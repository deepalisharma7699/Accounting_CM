# Migrations that are never run automatically

`php artisan migrate` scans `database/migrations`. It does **not** scan this
directory, and that is the whole reason this directory exists.

What lives here is a migration that is correct, tested and wanted — and that
must only ever run when somebody has decided, that day, that it should. Nothing
in a deployment, a CI step or a recovery can reach it.

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
