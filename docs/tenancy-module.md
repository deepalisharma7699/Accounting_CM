# Multi-Tenancy Module

Every workshop's books are isolated from every other workshop's. This module is
the boundary that makes that true, and Step 1 of the Phase 1 build sequence —
nothing else can be built until it exists, because every table from here on
carries a `tenant_id`.

## The decision that shapes everything here

The PRD specifies **PostgreSQL Row-Level Security**. This application runs on
**MySQL**, which has no equivalent. That was a deliberate choice, and it moves
isolation from being a property of the *database* to being a property of the
*code*:

| | PostgreSQL RLS | Here (MySQL) |
| --- | --- | --- |
| Enforced by | The database, on every query | `TenantScope` on every Eloquent query |
| A forgotten policy / trait | Rejected by the database | **Silent leak** |
| Raw SQL (`DB::select`) | Still enforced | **Not enforced** |
| Cost of getting it wrong | Low | A customer sees another workshop's numbers |

Three things follow from that, and they are the design of this module:

1. **Fail closed, loudly.** A query with no tenant established throws
   `MissingTenantContextException` rather than returning zero rows. In an
   accounting system a silent empty result is the worse failure: a day book or
   a trial balance renders as ₹0 and nobody notices.
2. **Every bypass names itself.** `TenantContext::runWithoutScope()` is the only
   way through the boundary. `grep -rn runWithoutScope app/` is the complete
   audit — currently four call sites, each commented with why.
3. **A test replaces the missing database guarantee.**
   `TenantIsolationInvariantTest` fails the build if any model with a
   `tenant_id` column lacks the trait. It scans `app/Models` at runtime, so
   tables added by later slices are covered without anyone remembering to
   extend it.

**Tenant-owned tables must always be reached through their Eloquent model.**
A raw `DB::table('journal_entries')` bypasses the scope entirely and is a bug.

## Layout

```
app/Support/Tenancy/TenantContext.php     Who "we" are, for this request
app/Models/Scopes/TenantScope.php         The global scope (the RLS stand-in)
app/Models/Concerns/BelongsToTenant.php   Trait: scope + stamp + immutability
app/Models/Tenant.php
app/Enums/TenantStatus.php
app/Exceptions/Tenancy/                   MissingTenantContext, CrossTenantWrite, TenantInactive
app/Repositories/*/…TenantRepository      Persistence behind an interface
app/Services/Tenancy/TenantService.php    Provisioning + administration
app/Http/Controllers/Api/V1/TenantController.php
app/Providers/TenancyServiceProvider.php  Singleton context + repository binding
config/tenancy.php                        All tunables
```

## Schema

| Table | Change |
| --- | --- |
| `tenants` | `name`, `slug` (unique), `gstin` (unique, nullable), `address`, `state_code`, `status`, soft deletes |
| `users` | `+ tenant_id` — **nullable**, FK `restrictOnDelete`, indexed with `status` |

`users.tenant_id` is nullable because a **platform super-admin** (the PRD's
third role) belongs to no workshop and owns no books. Every other user belongs
to exactly one tenant.

`restrictOnDelete`, not cascade: deleting a tenant that still has users fails
loudly rather than silently destroying accounts.

Tenant-owned tables added by later slices must declare `tenant_id` as **NOT
NULL** — a nullable one is a row belonging to nobody, invisible to every tenant
and missing from every report. `TenantIsolationInvariantTest` enforces this.

## How a tenant is established

Inside `JwtAuthenticate`, not in a middleware of its own.

Identity and tenancy are the same question in this application — *which
workshop's books am I looking at* is answered by *who am I* — and a separate
middleware could be left off a route, which on MySQL means an unfiltered query.
Doing it in the auth guard makes that impossible: every authenticated request
has a tenant, and every unauthenticated one has no access to tenant-owned data
at all.

```
verify access token
  -> load user  (cross-tenant: this lookup is what decides the tenant)
  -> reject inactive user
  -> reject inactive tenant
  -> TenantContext::setTenant($user->tenant_id)
```

The JWT carries no tenant claim. Tenancy is re-read from the database on every
request, for the same reason the role is: a 15-minute access token must not
outlive a suspension.

## The three states of TenantContext

The distinction between the first and last rows is the whole safety mechanism,
and `isResolved()` is what separates the two `null`s.

| State | `current()` | Meaning | A scoped query |
| --- | --- | --- | --- |
| Unresolved | `null` | Nobody established a tenant — **a bug** | Throws `MissingTenantContextException` (500) |
| Resolved to a tenant | `int` | A workshop user | Filters to that tenant |
| Resolved to none | `null` | A platform super-admin | Throws `NoWorkspaceException` (403) |

Both null cases fail closed; they differ in *what they mean*. A queued job that
forgot to set the context is a bug and should look like one. A platform admin
opening a page of workshop data is not — their account is valid and their
request is well formed, there is simply no "my books" for them, so they get a
403 that says so.

**Authority is not membership.** A platform admin holds the `*`/`*` grant, so
every permission check passes and they reach the controller — and then have no
tenant. Permissions and tenancy are separate gates, and both have to be
satisfied. The front end mirrors this: `data-requires-workspace` hides
workshop-scoped navigation from a user whose `tenant_id` is null, independently
of `data-requires-permission`.

## What the trait does

```php
use App\Models\Concerns\BelongsToTenant;   // on every accounting model
```

| Operation | Behaviour |
| --- | --- |
| Read | Filtered to the current tenant, or refused |
| Create | `tenant_id` stamped from context, never from user input |
| Create with an explicit `tenant_id` | Refused unless it matches, or unscoped |
| Update of `tenant_id` | **Always refused** — write-once |

`tenant_id` is immutable because reassigning it would move money, stock or an
invoice from one workshop's books to another's, leaving both wrong with no
audit trail.

## Why `users` does not use the trait

It is the one exception, and it is deliberate: authentication has to find a
user *before* it knows which tenant they belong to, so a global scope would
have to be bypassed on every auth call.

Instead `EloquentUserRepository` splits its lookups into two families, and
mixing them up is how tenants leak:

| Method | Scope |
| --- | --- |
| `findById`, `paginate` | **Tenant-scoped.** Every administrative flow. |
| `findAuthenticatable`, `findByEmail`, `emailExists` | **Cross-tenant.** The auth path, and the platform-wide unique email index. |

One private `scoped()` method is the entire filter — one line to audit. It is
covered end-to-end by `TenantIsolationTest`, including the case app-layer
filtering usually misses: fetching another tenant's user *by a known id*, which
returns **404, not 403** (a 403 would confirm the id exists).

Emails are unique platform-wide. A person working at two workshops uses two
addresses — login has only an email to go on, so it must resolve to one user.

## Registration is workshop sign-up

`POST /api/v1/auth/register` provisions a **tenant and its owner together**.
There is no user without a workshop in this product, so both commit or neither
does — a tenant with no owner is unreachable, an owner with no tenant cannot
log in.

Joining an *existing* workshop is an invitation, issued by its owner through
`POST /v1/users` — never self-serve, or anyone could type their way into
someone else's books.

Set `TENANCY_ALLOW_PUBLIC_SIGNUP=false` for sales-led onboarding: registration
then returns 403 `SIGNUP_DISABLED`, and workshops are created only by a
platform super-admin via `POST /v1/tenants` (which accepts an optional `owner`
block to do both in one call).

## Roles

| Role | Scope | Grants |
| --- | --- | --- |
| `ADMIN` | Platform | `*`/`*`. Manages tenants; sees no workshop's books. |
| `OWNER` | One workshop | Full control of its people and books. **No** `TENANTS` grant. |
| `DATA_ENTRY` | One workshop | Captures transactions and reads the chart of accounts. **No** `LEDGER` grant: entering the day's events is a different authority from reading the workshop's whole financial position. |

Roles are **platform-global, not per-tenant**: Phase 1 needs only this fixed
list, and `WRITE:ROLES` is held by no tenant role, so a workshop cannot create
one for everybody. If per-tenant roles are ever needed, the change is a
`roles.tenant_id` column and the trait.

A wildcard grant is *authority, not omniscience*. The tenant boundary is
orthogonal to permissions: a platform admin with `*`/`*` still cannot list a
workshop's users, because permissions and tenancy are separate gates. This is
asserted in `TenantIsolationTest`.

## Two surfaces, two permissions

The distinction is the point, and it is why there are two controllers rather
than one with a relaxed guard:

| | `TENANTS` | `WORKSPACE` |
| --- | --- | --- |
| Authority over | Every workshop on the platform | Exactly one — the caller's |
| Held by | `ADMIN` (platform) | `OWNER` (workshop) |
| Workshop identified by | An id in the URL | The tenant context |
| Can change `status` | Yes | **No** |

`/workspace` has **no `{id}`**. It resolves from the tenant context the auth
guard established, so there is nothing for a caller to tamper with and no way
to address another workshop whatever they send. That is structural, not a check
someone could forget to write.

A platform user reaching `/workspace` gets `403 NO_WORKSPACE` — a wildcard
grant is authority, not membership, and they have no workshop of their own.

## Workshop settings

Stamped at provisioning, never resolved from config at read time: a report must
not change retrospectively because someone edited a config file.

| Setting | Editable | Purpose |
| --- | --- | --- |
| `financial_year_start_month` | ✅ | Every period-based report. India: 4 (April) |
| `timezone` | ✅ | Transaction dates and the day book. Validated against the tz database |
| `books_start_date` | ✅ | Go-live. Nothing posts before it — that period's closing position arrives as opening balances |
| `currency` | ❌ | Display only. GST, HSN/SAC and the tax engine are India-specific, so anything but INR would format correctly and compute wrongly |

`Tenant::financialYearFor()` resolves the year containing a date, and
`Tenant::acceptsPostingOn()` enforces go-live. Both live on the model so the
April off-by-one — 10 February 2026 belongs to the year that *opened* on
1 April 2025 — is computed in exactly one place. `FinancialYearTest` pins it
down before any report depends on it.

### GSTIN and state code

A GSTIN's first two digits **are** the state code, so the two cannot legally
disagree. The state code is re-derived from the GSTIN on **create and update
alike** — without that, a workshop supplying its GSTIN after sign-up keeps
whatever default it was given, and every bill it raises picks the wrong side of
the intra/inter-state GST split.

## Endpoints

Added under `/api/v1`.

### The caller's own workshop

| Method | Path | Required permission |
| --- | --- | --- |
| GET | `/workspace` | `READ:WORKSPACE` |
| PATCH | `/workspace` | `UPDATE:WORKSPACE` |

### Platform administration

All require the `TENANTS` permission, which only `ADMIN` holds.

| Method | Path | Required permission |
| --- | --- | --- |
| GET | `/tenants` | `READ:TENANTS` |
| GET | `/tenants/{id}` | `READ:TENANTS` |
| POST | `/tenants` | `WRITE:TENANTS` + `WRITE:USERS` |
| PATCH | `/tenants/{id}` | `UPDATE:TENANTS` |
| PUT | `/tenants/{id}/status` | `UPDATE:TENANTS` |
| DELETE | `/tenants/{id}` | `DELETE:TENANTS` |

`POST /tenants` requires authority over users too, because it may create the
owner account in the same call.

### Error codes

| Status | Code | Cause |
| --- | --- | --- |
| 403 | `TENANT_INACTIVE` | The workshop is suspended or cancelled |
| 403 | `NO_WORKSPACE` | A platform user reached `/workspace`; they have no workshop |
| 403 | `TENANT_CROSS_WRITE` | A write aimed at another tenant |
| 403 | `TENANT_IMMUTABLE` | An attempt to move a row between tenants |
| 403 | `SIGNUP_DISABLED` | Public sign-up is off |
| 409 | `TENANT_GSTIN_TAKEN` | GSTIN already registered |
| 409 | `TENANT_IN_USE` | Deleting a workshop that still has users |
| 500 | `TENANT_CONTEXT_MISSING` | **A bug** — a scoped query with no tenant set |

## Screens

| Path | Who | What |
| --- | --- | --- |
| `/register` | Anyone | Sign-up: workshop + owner in one form. **404** when `TENANCY_ALLOW_PUBLIC_SIGNUP=false` — a visible page whose endpoint answers 403 is worse than no page |
| `/workspace` | `READ:WORKSPACE` + membership | The owner's own workshop: identity and book settings. Read-only without `UPDATE` |
| `/tenants` | `READ:TENANTS` | Platform administration of every workshop |

Sign-up lands the new owner on `/workspace?welcome=1`, which explains why the
GSTIN and financial year matter *before* anything is posted rather than after.
That is the whole of onboarding — a separate wizard would only be the same two
fields with more clicks.

`/tenants` fills its per-row user count from the single-tenant endpoint after
the list arrives. The list endpoint deliberately does not report counts, so
paging through a hundred workshops does not run a hundred count queries.

## Behaviour worth knowing

* **Suspending a workshop ends every session inside it.** The guard refuses the
  next request, and refresh tokens are revoked too — otherwise suspension would
  take up to seven days to fully bite.
* **Renaming a workshop does not change its slug.** The slug may already be in
  URLs, logs and support tickets; a silently moving identifier is worse than a
  slightly stale one.
* **A GSTIN's first two digits win over a separately supplied `state_code`** —
  they cannot legally disagree. GSTIN is validated for *shape*, not checksum: a
  mistyped one is caught by GST filing long before this matters, and rejecting
  a legitimate one at sign-up is the worse failure.
* **Colliding workshop names get `-2`, `-3` … slugs**, then a random tail past 50.
* **Provisioning fails loudly if the `OWNER` role is missing.** An owner with no
  role can sign in and then do nothing, which presents as a baffling support
  ticket rather than the install error it is. Run `php artisan db:seed`.

## Per-tenant bootstrapping

`TenantService::createTenant()` seeds the workshop's **chart of accounts**
inside the same transaction, so a tenant can never exist without books. See
[accounting-module.md](accounting-module.md). Anything else that must exist
from a workshop's first moment belongs there too.

## Tests

59 tests / 157 assertions, in five files:

| File | Proves |
| --- | --- |
| `TenantScopeTest` | The trait itself: reads, aggregates, creates, updates, deletes, nesting, fail-closed. Runs against a real table via `tests/Fixtures/TenantScopedFixture`, so the trait is proven **before** anything valuable depends on it. |
| `TenantIsolationTest` | The `users` boundary end-to-end over HTTP, including 404-not-403 and the platform-admin case. |
| `TenantIsolationInvariantTest` | The standing guard: no model may carry `tenant_id` without the trait, and no owned table may allow a null one. |
| `TenantManagementTest` | The platform API: provisioning, slugs, GSTIN, suspension, deletion, rollback. |
| `WorkspaceTest` | The owner's own-workshop API: same URL resolving to different workshops per caller, status/slug/currency refused, settings, GSTIN state-code derivation. |
| `tests/Unit/FinancialYearTest.php` | Financial-year and go-live arithmetic. No database. |

```bash
php artisan test --filter=Tenancy
```

Test helpers live in `tests/Concerns/InteractsWithTenancy.php` — use it
alongside `InteractsWithAuthModule`, which supplies `roleWith()`.

> **Gotcha for future test authors.** MySQL commits the open transaction
> implicitly on any DDL, so a `Schema::create()` inside a test defeats
> `RefreshDatabase`: everything written afterwards survives the rollback and
> leaks into later tests, *and* every following statement is committed and
> fsynced on its own — it cost about five seconds per test here.
>
> Put scaffolding tables in `tests/database/migrations/` instead. They are
> loaded only under `APP_ENV=testing` (see
> `AppServiceProvider::loadTestOnlyMigrations()`) and created by
> `migrate:fresh` before any transaction opens. Verify isolation with
> `vendor\bin\phpunit --order-by=random`.

## Setup

```bash
php artisan migrate
php artisan db:seed        # adds the OWNER and DATA_ENTRY roles
```

The seeded platform administrator has `tenant_id = NULL` — it exists above the
workshops, to provision and suspend them, and owns no books.
