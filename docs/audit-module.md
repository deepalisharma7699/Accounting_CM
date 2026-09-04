# M13 · Audit Log

Who changed what, when — on the records underneath the figures.

**Status:** ✅ done — **card switched off**, awaiting §2A conversion
**Depends on:** M2 (tenancy), and everything that owns master data
**Test:** `php artisan test --filter='Audit'`

> Items and the counterparty modules each read `/audit-logs` for **one record**
> in their drawer's Activity tab, so per-record history is reachable. The trail
> *across* the workshop — "what did this user do last Tuesday" — is not, and that
> is the question this module exists to answer. It is also the whole safeguard on
> `PATCH /transactions/{id}/staff`, the one write that edits a posted document.
> See [hidden-modules.md](hidden-modules.md).

---

## What this module is actually for

The roadmap asks for "who changed what, when, on every financial record", and the
first job of this module was to work out that most of that already existed.

M4 to M12 made it exist. A posted transaction cannot be edited or deleted at all;
`journal_entries` and `stock_movements` refuse an UPDATE on the model itself;
`created_by` and `posted_at` sit on every transaction; a correction is a
reversing entry that leaves both the mistake and the correction on the record.
**"Who changed this figure" has no answer because nothing changes a figure.**
Recording "journal entry 4,102 was created" here would be a second copy of a fact
the first copy already proves, and two copies of one truth is the arrangement
this codebase refuses everywhere else.

What has no trail at all is the *master data underneath the figures*. Every one
of these is mutable by design, changes silently, and changes what the books mean
without changing a single posted number:

| Change | What it silently does |
| --- | --- |
| A party's GSTIN is edited | Moves which columns of a GST return their invoices land in |
| A supplier is archived | Removes them from every picker; a payment against them starts being refused |
| A party's `roles` loses "vendor" | Same, and the refusal is baffling without the trail |
| An item's GST rate is changed | Does not restate one existing bill — which is correct, and exactly why a reader comparing two quarters needs to know |
| `financial_year_start_month` moves | Re-cuts every period report ever run, retrospectively |
| `books_start_date` moves | Changes which back-dated transactions are accepted |
| A user's role is changed | Changes what they can reach, and leaves no other mark anywhere |
| An attachment is deleted | Destroys evidence, leaving nothing behind |

That is the module. It is smaller than "audit everything" and it is the part that
was missing.

---

## Delivered

| Piece | Detail |
| --- | --- |
| `audit_logs` | Immutable, tenant-scoped, append-only |
| `Auditable` trait | Model events, so no service can forget to record |
| `AuditRecorder` | The one writer; actor resolution, redaction, suppression |
| `AuditAction` | created / updated / archived / restored / deleted |
| `AuditResource` | workspace / account / party / item / variant / user / attachment |
| `GET /audit-logs` | Filter by kind, record, action, person, date; search |
| `GET /audit-logs/meta` | The filters, published — including who appears on the trail |
| `/audit` | The History screen |
| `READ:AUDIT` | Held by OWNER, and deliberately not by DATA_ENTRY |

---

## The design decisions

### The trail follows the write, not the service

`Auditable` hangs on Eloquent's `created`, `updated` and `deleted` events rather
than being called from `PartyService::update()`.

The alternative is a rule every future service has to remember, and it is correct
exactly until somebody adds a second write path — an importer, a console command,
a repository method used from one other place. Then the trail has a hole that
nothing announces, and the hole is found by somebody looking for a change that
was never recorded, which is the one moment the log had a job to do.

Following the write means M11's CSV importer was audited without a line being
added to it: it creates parties through the same Eloquent path the API does. This
is the same reasoning `BelongsToTenant` uses for `tenant_id`, and it is why both
are traits rather than helpers.

### The attribute list is declared, never inferred

`auditAttributes()` is **abstract** on the trait, so a model cannot use it
without saying what may be recorded.

Defaulting to `$fillable` was the obvious shortcut and is a deny-list wearing an
allow-list's clothes: correct until somebody adds a fillable column, and the
column it eventually gets wrong is a password hash or a token sitting in a table
an owner is allowed to read. An explicit list cannot fail that way — a new column
is absent from the trail until somebody decides it belongs, and an absence is a
gap rather than a leak.

`User::auditAttributes()` is `['name', 'email', 'status', 'custom_role_id']`.
The lockout counters and `remember_token` are excluded for a second reason as
well: they move on their own, without anybody deciding anything, and a log of
things nobody did is how a log stops being read.

### A failure here fails the write

Not caught, not logged-and-swallowed, not queued. The audit row is written inside
whatever database transaction the caller opened, so a change that rolls back
takes its trail entry with it — and a trail entry that cannot be written takes
the change with it.

The instinct is the other way round: a broken trail should not stop somebody
editing a phone number. But a trail with silent holes is worse than no trail,
because it is *believed*. The whole value of the table is that a gap in it means
nothing happened, and the moment a failure can leave a change unrecorded, every
absence becomes ambiguous — surfacing during the one conversation where somebody
needs an answer. A loud failure is a bug that gets fixed on the day it appears.

### The actor's name is copied onto the row

`actor_id` is `nullOnDelete`, because a user can be deleted and the trail must
survive them — a history that empties itself when somebody leaves is not a
history. So `actor_name` holds the name at the moment of the act.

Everywhere else this schema refuses a denormalised copy as a stored aggregate
that will drift. This one is different in kind: it is not a copy of a *current*
fact that could disagree with its source, it is a copy of a *past* one — the name
that person went by when they did it. If they marry and change it, the old rows
are still correct. `label` works the same way, so an account renamed from "Petty
Cash" to "Cash in Hand" leaves a trail that reads as it read then.

### Archived and restored are their own actions

An update that flipped `is_active` is filed as `archived` or `restored` rather
than as `updated`.

Archiving is the closest thing this product has to a deletion — an account or
party that has been transacted with is switched off rather than removed, because
its entries would otherwise lose the name that explains them. "Who took our
biggest supplier off the list" is a question people actually ask, and filed under
`updated` it would be one row among forty field edits.

### A creation carries no snapshot; a deletion does

The asymmetry is deliberate. A creation needs none because *the record is the
snapshot*: it still exists, and every edit since is on the trail, so the original
state can be reconstructed by walking backwards. A deletion is the opposite —
nothing survives it — so the values are copied onto the row as they stood, or the
trail would say a party was deleted and be unable to say which one.

### The entry belongs to the tenant that was changed

`tenant_id` is the workshop the change was made *to*, never the actor's own. A
platform administrator editing a workshop's financial year writes into that
workshop's history, because that is where somebody will go looking for it.

The write is wrapped in `TenantContext::runFor()` for the length of one insert,
so `BelongsToTenant`'s cross-tenant guard is *satisfied* rather than worked
around — that guard exists so a stray mass-assignment cannot plant a row in
another workshop's books, and it should keep meaning exactly what it says.

### The chart provisioner is suppressed

`AuditRecorder::silently()` has exactly one caller: `ChartOfAccountProvisioner`.
Those fifteen seeded accounts are not fifteen decisions — they are one act, and
it is already on the trail as the workshop's own creation. Recording them
individually would put fifteen entries nobody chose at the top of every new
workshop's history, which is how a log stops being read.

Anything else is a bug. `grep -rn 'audit->silently' app/` is the audit —
deliberately the same shape as `runWithoutScope`, and for the same reason: a hole
in a guarantee should have to name itself.

### A save that changed nothing writes nothing

"Somebody pressed save" is not history, and a trail full of empty edits is a
trail nobody reads.

### An unknown filter is refused, not ignored

The opposite of what `ReportPeriodRequest` does with a stale period preset, and
the difference is deliberate. A report that refuses to draw teaches people the
reports are broken, so an unknown preset falls back to everything. An unknown
*filter on an audit trail* is the other case entirely: silently ignoring it would
show a complete history to somebody who believes they are looking at a filtered
one, and they would draw a conclusion from the difference.

---

## Two name collisions worth knowing about

Both were found by tests, and both fail silently rather than loudly — which is
why they are documented rather than just fixed.

**`audit_logs.changed_fields`, not `changes`.** `Model::$changes` is Eloquent's
own protected property holding what the last save modified. A column of that name
reads correctly from *outside* the class and returns the framework's empty
internal array from *inside* it, so any accessor on the model gets nothing and
reports no changes at all. The API still calls the field `changes`, because that
is the right word for a client.

**`AuditLogResource` reads `$entry`, not `$this`.** `JsonResource::$resource` is
the framework's property holding the wrapped model, and `audit_logs` has a
`resource` column too, so `$this->resource->value` reaches `Model::__call` and
explodes. Renaming the column was the alternative and is worse — `resource` is
the right word, and it appears in the query string, the enum and the index.

---

## What is deliberately not covered

| Not audited | Why |
| --- | --- |
| Transactions, journal entries, stock movements | Already immutable, with `created_by` and `posted_at` on the transaction. An entry here would restate what the ledger already proves |
| Roles and permissions | Platform-defined: one role belongs to every workshop at once, so there is no workshop whose history it belongs in |
| A platform administrator's own user record | They are a member of no workshop, so an edit to their name has no workshop's history to belong in. `AuditRecorder::tenantFor()` returns null and the entry is dropped |
| An attachment's `status` | It moves from `pending` to `ready`, but nobody decides it — a queued job writes it. The same exclusion the lockout counters get |
| Reads | Nobody has asked for it, and a row per page view would bury every row that matters. Worth revisiting if a customer's compliance regime demands it |

The audit log is **never pruned**, unlike M14's `job_runs`. A trail with an expiry
date answers "who changed this" with "we no longer know".

---

## Test checklist

- [x] Who changed what, when, on every record that can change silently
- [x] Immutable — an entry cannot be edited or deleted, guarded on the model
- [x] Tenant-scoped, and one workshop's trail is invisible to another
- [x] Queryable from the back-office, by kind, record, action, person and date
- [x] A password hash can never reach the trail, structurally
- [x] A rolled-back edit leaves no entry claiming it happened
- [x] Posting a transaction writes nothing — asserted, not assumed
- [x] The trail survives the deletion of the person who made it
- [x] Provisioning does not put fifteen accounts nobody chose at the top of a new workshop's history
- [x] `TenantIsolationInvariantTest` green with `audit_logs` in the schema
