# Deployment log

What is actually on the server, and when each schema step was run against it.

CLAUDE.md §4.7 exists because this repository cannot answer that question by
itself: the branch runs ahead of the server, a hotfix may have gone out of band,
and the migrations sitting in `database/migrations` were written before §4.6 and
may or may not have been applied. **Nothing here is inferred from git history or
from a local `migrations` table.** Every row was stated by the operator on the
date beside it, and is updated as things go out.

Newest first.

---

## 29 September 2026 — recipes (`item_components`)

**Stated by the operator on 29 September 2026, before the window opened:**

| Question | Answer |
| --- | --- |
| Commit live on the server before this deployment | `73d7fc3` |
| Committed migrations applied | All of them. `php artisan migrate` is current through `2026_09_19_100002_give_existing_workshops_their_own_roles`. |
| Backup | A fresh `mysqldump` taken immediately before the window. |

**What went out.** The working tree as it stood at `73d7fc3` plus the
uncommitted changes — recipes (`item_components`, `ItemComponentService`, the
posting and preview expansion), the `stockMovement()` → `stockMovements()`
plural and the five places that assumption was load-bearing, the searchable
`<select>`, and the catalogue and workspace markup that went with them.

**Schema step.** One, and it is the only one this deployment needs:

```
database/manual/sql/2026_09_29_001_item_components.sql
```

One `CREATE TABLE`. No column added to an existing table, no existing row
written, and no lock on anything the workshop is billing against — it is safe
during trading hours.

**Order.** Either way round. `ItemComponentService::isInstalled()` is the single
place that is decided: with the table absent every read answers "no recipe",
which is what was true of every product the day before, so every screen and
every posting behaves exactly as it did. Only a *write* refuses, out loud. That
is §4.6's "tolerate both shapes", and it means the deployment is not one failed
step away from a broken screen.

**Run on:** _<!-- operator: fill in the date and time the SQL was actually run -->_

**Verified by:** the `SELECT` at the foot of the file, which must return one row
reading `ok`.
