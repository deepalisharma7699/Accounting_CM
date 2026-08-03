# Authentication & User Management Module

JWT authentication with dual tokens, dynamic RBAC, and administrative user
management for the Accounting CM Laravel 13 application.

> Multi-tenancy layers on top of this module and changes two things about it:
> `POST /auth/register` now provisions a workshop, and user lookups are split
> into tenant-scoped and cross-tenant families. See
> [tenancy-module.md](tenancy-module.md).

## Stack mapping

The brief was written against a Node/Prisma stack. Laravel equivalents used:

| Brief | Here |
| --- | --- |
| Prisma / TypeORM / Mongoose | **Eloquent ORM** + migrations (MySQL 8; schema is portable to PostgreSQL/SQLite) |
| Zod / Joi / class-validator | **Form Requests** (`app/Http/Requests`) with `Rule::enum`, `Password::defaults()` |
| `express-rate-limit` | **`RateLimiter` + `throttle:` middleware**, plus a persisted per-account lockout |
| `bcrypt` (12 rounds) | `BCRYPT_ROUNDS=12` via Laravel's `hashed` cast; switch to Argon2 with `HASH_DRIVER=argon2id` |
| Express middlewares | `auth.jwt` (authGuard) and `permission` (permissionGuard) aliases |

## Layout

```
routes/api.php                       Route table + guard wiring
app/Http/Controllers/Api/V1/         Thin controllers: validate in, resource out
app/Http/Requests/                   Input validation
app/Http/Resources/                  Output shaping
app/Http/Middleware/
    JwtAuthenticate.php              authGuard      -> alias auth.jwt
    EnsurePermission.php             permissionGuard-> alias permission
    ForceJsonResponse.php
app/Services/
    Auth/TokenService.php            Mint / verify / rotate / revoke tokens
    Auth/AuthService.php             register, login, refresh, logout use cases
    Auth/LoginThrottleService.php    Per-account lockout
    Rbac/AuthorizationService.php    The single "may this user…?" decision point
    Rbac/RoleService.php             Custom role CRUD + permission sync
    Rbac/PermissionService.php       Permission catalogue
    UserManagement/UserService.php   Admin user management
app/Repositories/Contracts|Eloquent  Persistence behind interfaces
app/Exceptions/                      Typed failures + ApiExceptionRenderer
app/Models/                          User, Role, Permission, RefreshToken
app/Enums/                           UserStatus, TokenType, PermissionAction/Resource
app/Support/ApiResponse.php          The one response envelope
database/migrations/2026_07_25_*     Schema
database/seeders/                    Permissions, ADMIN role, admin user
config/jwt.php, config/rbac.php      All tunables
```

Dependency direction is one-way: **Controller → Service → Repository → Model**.
Controllers never touch Eloquent; services never touch the request or response.

## Schema

| Table | Purpose |
| --- | --- |
| `users` | `+ tenant_id` (nullable — NULL is a platform super-admin), `status`, `custom_role_id`, `failed_login_attempts`, `locked_until`, `last_login_at/ip`, `deleted_at`. `password` **is** the hash column. |
| `roles` | `name`, `slug`, `description`, `is_system_role`, soft deletes |
| `permissions` | `action`, `resource`, `description`; unique on (action, resource) |
| `role_permission` | Junction, unique on (role_id, permission_id), cascade delete |
| `refresh_tokens` | `jti`, `token_hash` (SHA-256), `family_id`, `expires_at`, `revoked_at`, `revoked_reason`, `replaced_by_jti` — this is the blacklist |

## Endpoints

Base path `/api/v1`. Access tokens go in `Authorization: Bearer <token>`.

| Method | Path | Guard | Required permission |
| --- | --- | --- | --- |
| POST | `/auth/register` | `throttle:auth-register` | — (provisions a workshop + its owner) |
| POST | `/auth/login` | `throttle:auth-login` | — |
| POST | `/auth/refresh` | `throttle:auth-refresh` + refresh cookie | — |
| POST | `/auth/logout` | refresh cookie | — |
| POST | `/auth/logout-all` | `auth.jwt` | — |
| GET | `/auth/me` | `auth.jwt` | — |
| GET | `/permissions` | `auth.jwt` | `READ:PERMISSIONS` |
| — | `/workspace` | `auth.jwt` | `READ`/`UPDATE:WORKSPACE` — see [tenancy-module.md](tenancy-module.md) |
| — | `/tenants…` | `auth.jwt` | `*:TENANTS` — see [tenancy-module.md](tenancy-module.md) |
| — | `/accounts…` | `auth.jwt` | `*:ACCOUNTS` — see [accounting-module.md](accounting-module.md) |
| GET | `/roles` | `auth.jwt` | `READ:ROLES` |
| GET | `/roles/{id}` | `auth.jwt` | `READ:ROLES` |
| POST | `/roles` | `auth.jwt` | `WRITE:ROLES` |
| PATCH | `/roles/{id}` | `auth.jwt` | `UPDATE:ROLES` |
| DELETE | `/roles/{id}` | `auth.jwt` | `DELETE:ROLES` |
| PUT | `/roles/{id}/permissions` | `auth.jwt` | `UPDATE:ROLES` + `READ:PERMISSIONS` |
| GET | `/users` | `auth.jwt` | `READ:USERS` |
| GET | `/users/{id}` | `auth.jwt` | `READ:USERS` |
| POST | `/users` | `auth.jwt` | `WRITE:USERS` |
| PATCH | `/users/{id}` | `auth.jwt` | `UPDATE:USERS` |
| PUT | `/users/{id}/role` | `auth.jwt` | `UPDATE:USERS` + `READ:ROLES` |
| PUT | `/users/{id}/status` | `auth.jwt` | `UPDATE:USERS` |
| DELETE | `/users/{id}` | `auth.jwt` | `DELETE:USERS` |

### Response envelope

```jsonc
// success
{ "success": true, "message": "Signed in successfully.", "data": { … }, "meta": { … } }

// failure
{ "success": false, "error": { "code": "AUTH_INVALID_CREDENTIALS", "message": "…", "details": { … } } }
```

| Status | Error code(s) |
| --- | --- |
| 401 | `AUTH_TOKEN_MISSING`, `AUTH_TOKEN_INVALID`, `AUTH_TOKEN_EXPIRED`, `AUTH_TOKEN_REVOKED`, `AUTH_TOKEN_REUSED`, `AUTH_TOKEN_WRONG_TYPE`, `AUTH_INVALID_CREDENTIALS` |
| 403 | `AUTH_FORBIDDEN`, `AUTH_ACCOUNT_INACTIVE`, `RBAC_SYSTEM_ROLE_IMMUTABLE`, `TENANT_INACTIVE`, `TENANT_CROSS_WRITE`, `TENANT_IMMUTABLE`, `SIGNUP_DISABLED`, `NO_WORKSPACE`, `ACCOUNT_SYSTEM_IMMUTABLE` |
| 404 | `RESOURCE_NOT_FOUND`, `ENDPOINT_NOT_FOUND` |
| 405 | `METHOD_NOT_ALLOWED` |
| 409 | `AUTH_EMAIL_TAKEN`, `USER_EMAIL_TAKEN`, `USER_SELF_DELETE`, `RBAC_ROLE_EXISTS`, `RBAC_ROLE_IN_USE`, `TENANT_GSTIN_TAKEN`, `TENANT_IN_USE`, `ACCOUNT_CODE_OUT_OF_BAND`, `ACCOUNT_CODE_TAKEN`, `ACCOUNT_NAME_TAKEN` |
| 422 | `VALIDATION_FAILED` (field errors under `error.details.fields`) |
| 423 | `AUTH_ACCOUNT_LOCKED` (with `Retry-After`) — a locked account is not the same as bad credentials, so it gets its own status |
| 429 | `TOO_MANY_REQUESTS` |
| 500 | `INTERNAL_SERVER_ERROR`, `TENANT_CONTEXT_MISSING` (always a bug) |

**Any 5xx suppresses its message** outside `APP_DEBUG`, including a typed
`ApiException` that carries one. The stable error code is still returned, but
the text is written for a developer — `TENANT_CONTEXT_MISSING` names the model
class — and is logged rather than sent. Only the code is contractual for a 5xx.

## Tokens

* **Access token** — 15 min, HS256, claims `iss/aud/sub/jti/typ/iat/nbf/exp` plus
  `email` and `role` for convenience. Sent as `Authorization: Bearer`.
* **Refresh token** — 7 days, signed the same way but with `typ=refresh` and a
  `fam` (family) claim. Delivered **only** as an `HttpOnly; Secure; SameSite=Strict`
  cookie scoped to `/api/v1/auth`. Never appears in a response body.
* `typ` separation means a refresh token cannot be replayed as an access token.
* The `Key` object pins the algorithm, so `alg: none` and alg-confusion attacks
  are rejected before any signature check.
* Rotation: every refresh redeems the old token (`revoked_reason = rotated`) and
  issues a new one in the same family. **Reuse detection** — presenting an
  already-rotated token revokes the whole family and returns `AUTH_TOKEN_REUSED`.

### Surviving lost refreshes and multiple tabs

Strict rotation has two failure modes that log users out for no good reason.
Both are handled, at different layers:

| Problem | Fix | Where |
| --- | --- | --- |
| Two tabs refresh at once; the second looks like a replay | Refreshes are serialised across tabs with the **Web Locks API**, so the waiting tab redeems the cookie the first one just stored | `resources/js/auth-client.js` |
| A refresh response never reaches the browser (tab closed mid-flight, dropped connection), leaving the client holding a spent token | A **grace window** forgives a token rotated within `JWT_REFRESH_GRACE` seconds (default 10) | `TokenService::withinRotationGrace()` |

The grace window is deliberately narrow. It only forgives a token whose
`revoked_reason` is `rotated`, and only inside the window. A token revoked by
logout, a password change, a status change or an earlier reuse is always
rejected, and replaying anything older than the window still burns the family.
Set `JWT_REFRESH_GRACE=0` to restore strict behaviour.
* Only the SHA-256 of each refresh token is stored, compared with `hash_equals`.

Clients must send credentialed requests (`fetch(..., { credentials: 'include' })`)
for `/auth/refresh` and `/auth/logout`.

## Authorization

A permission is an `(action, resource)` pair. Either side may be the wildcard
`*`. The seeded `ADMIN` system role holds exactly one grant — `*` / `*`.

```php
// routes/api.php
->middleware('permission:WRITE,POSTS')             // one action/resource pair
->middleware('permission:UPDATE:ROLES,READ:PERMISSIONS') // several, all required (AND)
->middleware(EnsurePermission::using('WRITE', 'POSTS'))  // type-safe helper
```

The guard reloads the user from the database on every request, so a suspension,
a role change or a revoked grant takes effect on the next request rather than
when the 15-minute token expires. Role grants are cached per role
(`config/rbac.php`) and flushed by `RoleService` on every mutation.

## Security notes

* Login responses are identical for an unknown email and a wrong password, and a
  dummy hash is computed on the unknown-email path to equalise timing.
* Two throttling layers: `throttle:auth-login` (per email+IP and per IP) and a
  persisted per-account lockout after `AUTH_LOGIN_MAX_ATTEMPTS` failures.
  A distributed attack still trips the account lock.
* Locked accounts are rejected **before** the password comparison.
* Changing a user's role or deactivating them revokes all their refresh tokens.
* System roles cannot be renamed, re-scoped or deleted through the API.
* A role still assigned to users cannot be deleted (409, not a silent unassign).
* `password` is `#[Hidden]` on the model and never referenced in any resource.

## Setup

```bash
php artisan migrate
php artisan db:seed        # permissions -> ADMIN role -> admin user
```

Set `JWT_SECRET` (falls back to `APP_KEY`, acceptable locally only):

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

`ADMIN_PASSWORD` is mandatory when seeding in production; locally a random one
is generated and printed once.

### Changing a password or display name

`AdminUserSeeder` never overwrites an existing user's password or name —
re-seeding with a different `ADMIN_PASSWORD` / `ADMIN_NAME` deliberately does
nothing, so a live admin cannot be hijacked by running the seeder. Use the CLI:

```bash
# password (prompts when --password and --name are both omitted)
php artisan user:password admin@example.com --unlock
php artisan user:password admin@example.com --password=... --unlock

# display name only — no password prompt, no sessions revoked
php artisan user:password admin@example.com --name="Harshita Sharma"

# both at once
php artisan user:password admin@example.com --name="..." --password=... --unlock
```

* `--name` sets the name used by the sidebar and the dashboard greeting (which
  takes the first word). Validated exactly as the API validates it: 2–120 chars.
* `--unlock` clears the failed-attempt counter and any active lockout.
* A **password** change revokes existing refresh tokens, since whoever knew the
  old password must not keep a live session; `--keep-sessions` skips that. A
  rename revokes nothing.
* Passwords below the application policy are refused unless you pass `--force`.
  Note that a sub-policy password can sign in but cannot later be changed
  through `PATCH /api/v1/users/{id}`, which enforces `Password::defaults()`.

## Tests

219 tests / 848 assertions covering registration, login, lockout, rate limiting,
token rotation, reuse detection, the permission guard (including wildcards),
role CRUD, user management, tenant isolation, workshop self-service, and the
chart of accounts.

```bash
php artisan test
```

The suite runs against **MySQL**, the same engine the application uses, so
collation, strict mode and index-length behaviour are exercised for real.
`phpunit.xml` overrides only `DB_CONNECTION` and `DB_DATABASE`; the host,
username and password come from `.env`, so no credentials are committed.

One-time setup:

```sql
CREATE DATABASE accounting_cm_test;
```

`RefreshDatabase` runs `migrate:fresh`, which drops every table in the target
database. `tests/TestCase.php` therefore refuses to run unless the database name
ends in `_test`/`_testing`, so a stray env var cannot wipe `accounting_cm`.
