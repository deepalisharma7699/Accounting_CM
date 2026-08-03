# M14 · Async Jobs & Object Storage

The ground M15 stands on: work that happens after the response, and files that
live somewhere other than the database.

**Status:** ✅ done
**Depends on:** M2 (tenancy), M13 (the trail records a deleted file)
**Required before:** M15
**Test:** `php artisan test --filter='Async'`

---

## Delivered

| Piece | Detail |
| --- | --- |
| `job_runs` | One row per piece of work, from dispatch to outcome |
| `TrackedJob` | Carries the tenant and the actor across the queue boundary |
| `JobProgress` | Throttled progress reporting, handed to the job body |
| `attachments` | A pointer to a stored object: what, how big, whose, verified |
| `FileStorageService` | The only thing that knows a disk exists |
| `ProcessAttachment` | Reads every upload back and confirms it landed |
| `documents` disk | Private, S3-compatible in production, local in development |
| `GET/POST/DELETE /attachments` | No UPDATE — a file's bytes never change |
| `GET /jobs`, `GET /jobs/{uuid}` | Read-only; the second is what a progress bar polls |
| `/uploads` | The Uploads screen, with live progress |
| `jobs:prune` | Scheduled; successes after a week, failures after ninety days |

---

## The part that actually matters: the queue boundary

A queued job runs with no request behind it. That is the point of a queue, and in
this application it is also a hazard, because two things everything else relies on
are established by the request and by nothing else.

### The tenant

`TenantContext` is what makes every query safe on MySQL — there is no Row-Level
Security to fall back on — and the JWT middleware is what sets it. A worker has no
middleware.

Worse, `TenantContext` is a **singleton**, and a worker is a long-lived process:
one PHP lifetime handling job after job, with the container carried across all of
them. A job that established a tenant and finished leaves it set, and the *next*
job starts life believing it belongs to the previous job's workshop. If it then
reads or writes anything tenant-owned before establishing its own tenant, it does
so in somebody else's books — and **nothing throws**, because the context is
populated and looks entirely legitimate.

Two things address it, and they are deliberately belt and braces:

1. **`TrackedJob`** captures the tenant at *dispatch*, while the request is still
   standing, carries it through the queue as a plain integer, and re-establishes
   it with `runFor()` around the job body. So a job reads like a controller: the
   tenant is current and nothing in the body has to think about it.

2. **`AsyncServiceProvider::isolateTenantPerJob()`** clears the context before
   every job and restores the caller's afterwards. This is the guard for
   everything that does *not* go through `TrackedJob` — a framework job, a
   package's job, a future job somebody writes without noticing the base class.
   Clearing to *unresolved* rather than to null is what makes it a guard:
   unresolved means "nobody has decided", and every tenant-owned query refuses
   rather than quietly returning nothing.

The listener **saves and restores** rather than simply clearing, and the reason is
worth stating because it was found by a failing test rather than by reasoning.
With the `sync` driver — and with `dispatchSync` on any driver — the job runs
*inside the request that dispatched it*, sharing its container and therefore its
context. A listener that cleared on the way out strips the tenant from the
controller that is still mid-way through its work, and everything after the
dispatch fails with "no tenant" for reasons nothing in the controller could
explain. It restores on the exception hook as well as the success hook, because
`JobProcessed` does not fire for a job that threw and a failed job must not take
the caller's context down with it.

### The actor

M13's recorder reads the authenticated user, and there is nobody authenticated
inside a worker. Left alone, every change a job made would appear on the trail as
"the system" — including the parties an import invents on somebody's behalf.

`TrackedJob` captures the dispatching user's id and re-establishes them through
`AuditRecorder::actingAs()`. The entry then names the person who uploaded the
file, and its `context.via` says `console`, which is true.

### Dispatching without a workshop fails at dispatch

`TenantContext::requireTenant()` throws if there is none. Background work here
always belongs to a workshop, so "no tenant" at dispatch is a platform
administrator queueing something with nowhere to go — and failing in the request,
in front of the person who asked, is enormously better than failing in a worker an
hour later.

---

## What "nothing blocks on upload" actually means

It does not mean the bytes travel later. They cannot: PHP has the temporary file
only for the length of the request, and deferring the move would mean storing it
somewhere else first, which is the same work in a worse place.

What the request does is the irreducible minimum — check the file, move it to
object storage, write one row — and then it **stops**. It does not read the object
back to confirm it landed, it does not open the image, and when M15 arrives it
will not call a model. All of that is queued, and the response carries a job
handle instead of an outcome.

The distinction is the one that matters on screen: an upload returns in the time
it takes to move a file, and the part that could take ten seconds or fail is
watched rather than waited on.

---

## Object storage

### The three rules in `FileStorageService`

1. **The media type is sniffed, never believed.** `Content-Type` on a multipart
   part is whatever the client chose to write there. The type comes from the
   bytes, and the stored extension is derived from *that* — so a file called
   `invoice.jpg.php` is stored as `.jpg` or refused, and is never written under
   the name it arrived with. The original name is kept on the row for display and
   downloads, and never touches a path.

2. **The key carries the tenant:**
   `tenants/{id}/{kind}/{year}/{month}/{ulid}.{ext}`. Every read is already
   filtered by the tenant scope, but storage is the one place a bug would not be
   caught by it — an object key is a string, and a string assembled wrongly
   reaches whatever it names. With the tenant in the prefix a bucket policy can
   enforce the boundary independently of this code, a mis-scoped read is visible
   in a listing, and a workshop's files can be exported or destroyed by prefix
   when they leave.

3. **Nothing is trusted until it has been read back.** A write to object storage
   can return cleanly and leave nothing readable — a wrong bucket, a policy
   covering writes and not reads, a region that has not caught up. Every one is
   silent at upload time and fatal three weeks later, when somebody opens the
   purchase to check what they were charged. `ProcessAttachment` fetches the
   object, checks its length and its SHA-256, and only that promotes a row from
   `pending` to `ready`.

### Reads are never public

The `documents` disk is private and states so explicitly rather than relying on a
driver default. An invoice carries a customer's name, address and GSTIN; a bucket
that serves objects to anybody holding the URL is one leaked link away from
publishing them.

`GET /attachments/{id}` mints a signed URL that expires in ten minutes where the
driver can make one, and `GET /attachments/{id}/download` always streams through
the application as a fallback. The signed URL is an optimisation to keep a
workshop's photographs off the application server's bandwidth, not the only way
in.

Downloads are sent as `attachment`, never `inline`, so nothing a workshop uploads
can be rendered by a browser inside this application's origin. Neither HTML nor
SVG is on any kind's allow-list today — but a `Content-Disposition` that depends
on an allow-list staying correct is a guard that will be wrong once.

**The object key is never sent to a client.** No `path`, no `disk`, no
`checksum` on `AttachmentResource`. A key is how the application fetches an
object, and handing one to a browser turns a private bucket into one whose only
protection is that the caller happened to be logged in.

### A duplicate is reported, not refused

Uploading the same photograph twice is worth *saying* and not worth refusing. A
workshop that photographs one invoice for the purchase and again for the payment
has done something reasonable; a second copy costs a few kilobytes, and quietly
handing back the first row would create a file that two things point at and either
may delete. The same treatment as a shared GSTIN in M5 and a duplicate
specification in M7.

### There is no `transaction_id`

Nothing attaches a file to a transaction yet. M15 will add the column and its
foreign key together — exactly as M4 deferred `transactions.party_id` to M5
rather than leaving it nullable and unconstrained for a module. A reserved empty
column is an invitation, and what it invites is a row that points at nothing while
the schema claims otherwise.

---

## Progress is the one stored number this codebase allows

Every other figure here is a sum over rows, because a stored aggregate drifts from
its source. There is no source here: a job's progress is a fact about a process
running in another machine's memory, and the only thing that knows it is the job.
Storing it is not a shortcut around a derivation — it is the derivation.

That also makes it the one figure allowed to be stale, and it is deliberately not
trusted for anything. **`status` decides whether work is finished**; progress is a
courtesy. A worker killed mid-run leaves a row reading 47% for ever, which is why
the API sends `elapsed_seconds` beside it — computed, so it cannot freeze — and
why the screens show them together.

Reported progress is capped at 99 while a job is running; only `markSucceeded()`
writes 100. A bar reading 100% next to a spinner is the commonest way a progress
display loses somebody's trust.

`JobProgress` throttles: a step is persisted only when the whole percentage moves,
which is at most a hundred writes over a job of any size. The obvious
implementation — a row update per item — turns a 400-line import into 400 extra
round trips, punishing a job for reporting diligently, and nobody can perceive 400
steps in four seconds anyway.

---

## Running it in production

### The worker

Nothing queued ever happens without one, **and the failure is silent** — uploads
sit at `pending` for ever and nobody is told why. Locally, `composer dev` starts
one alongside the server.

Supervisor:

```ini
[program:accounting-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/accounting/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
directory=/var/www/accounting
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/accounting-worker.log
stopwaitsecs=3600
```

systemd:

```ini
[Unit]
Description=Accounting queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/accounting
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

`--max-time=3600` restarts the worker hourly. A long-lived PHP process
accumulates memory and, more to the point here, holds a container across jobs —
the thing `isolateTenantPerJob()` exists to make safe. Recycling it is cheap
insurance on top.

**After every deploy, run `php artisan queue:restart`.** Workers hold the old code
in memory until they are told to stop.

### The scheduler

One cron entry:

```
* * * * * cd /var/www/accounting && php artisan schedule:run >> /dev/null 2>&1
```

Without it, `jobs:prune`, `queue:prune-failed` and `queue:prune-batches` never
run, and the failure is silent. See `routes/console.php`.

### Retention

| Table | Kept | Why |
| --- | --- | --- |
| `job_runs` (succeeded) | 7 days | A successful upload is fully answered by the file itself |
| `job_runs` (failed) | 90 days | The only record the work was ever attempted |
| `failed_jobs` | 90 days | Stack traces, for a developer |
| `job_batches` | 30 days | Unused in Phase 1 |
| `attachments` | For ever | Evidence |
| `audit_logs` | For ever | A trail with an expiry answers "who changed this" with "we no longer know" |

### Storage

Set `ATTACHMENTS_DRIVER=s3` and point `AWS_*` at any S3-compatible endpoint —
AWS, Cloudflare R2, Backblaze B2, MinIO. **The bucket must be private.** Set
`AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT=true` for anything that is not
AWS.

`attachments.disk` is stored per row rather than read from config at use time, so
an operator moving from local storage to S3 does not find that yesterday's
uploads have become unreadable because a config key changed underneath them.

Note that PHP's own `upload_max_filesize` and `post_max_size` still apply and are
usually the lower limit — raise them alongside `ATTACHMENTS_MAX_*_BYTES`.

### A note on HEIC

`image/heic` is on the invoice-image allow-list, but `finfo` on many systems
cannot identify it and reports `application/octet-stream`, which is refused. That
is the safe direction to fail in, and iPhones will normally send JPEG through a
browser file picker regardless. If a workshop reports it, the fix is a system
`libmagic` that knows the format — not a relaxed allow-list.

---

## Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| The run row is created at dispatch, not at pick-up | Otherwise there is a window in which somebody has been told their work is queued and the application can say nothing about it |
| A job is addressed by uuid, never by id | It is what a client polls with, and an incrementing integer in a URL invites a caller to try the one next to it. The tenant scope would refuse them, but a system whose safety rests on a check being present is weaker than one where guessing is pointless |
| `is_settled` comes from the server | A client keyed to its own list of statuses polls for ever the first time a state is added |
| A missing run row fails the job | It means the dispatching transaction rolled back, so the work was never committed either. Doing it anyway would be acting on a decision that was reversed |
| A failed *verification* does not fail the job | The job did its work correctly and found a bad file. Throwing would send it round the retry loop to reach the same conclusion, and the failure belongs on the attachment where the uploader will look — not in `failed_jobs` |
| Failure is recorded **and** re-thrown | Recording is what a screen reads; re-throwing is what lets the queue retry and record the trace. Swallowing it would make a job that failed three times look like one that was never tried |
| No `cancelled` status | Nothing in Phase 1 can be cancelled, and a cancel button racing the worker would sometimes report cancelling something already finished. A state the application cannot honestly reach should not be in the enum |
| No UPDATE grant for attachments, anywhere | A file's bytes never change; correcting a bad photograph means taking another one. A grant over an operation that does not exist is a lie in the permission catalogue |
| DATA_ENTRY may upload but not delete | The person holding the paper invoice is the person who photographs it. Removing evidence is not data entry |
| Polling, not websockets | A broadcast driver plus a held-open connection plus a second thing to supervise, to shorten a wait that is usually under five seconds. The backoff already handles M15's slower jobs |
| Size limits are per kind | A phone photograph is a couple of megabytes and a minute of speech is a fraction of that. One limit high enough for the largest lets somebody push 20 MB in as "audio" |
| The upload control's `accept` comes from the API | A copy in the browser is right until an operator raises a limit, and then it refuses files the server would take |
| The queue is `database` by default | No extra infrastructure, and right up to a few thousand jobs a day. Move to `redis` beyond that — nothing in the application changes |

---

## Test checklist

- [x] Queue worker configured and supervised — units above, plus `composer dev` locally
- [x] Object storage for invoice images and raw audio, private and verified
- [x] Progress reported to the UI; nothing blocks on upload
- [x] A job runs as the tenant that dispatched it, even when the context says otherwise
- [x] A job's writes land in the dispatching workshop's books and nowhere else
- [x] A job's changes are attributed to whoever dispatched it, not to "the system"
- [x] Dispatching with no workshop fails at dispatch, not in a worker
- [x] The object key carries the tenant and never the uploaded filename
- [x] A type outside the allow-list is refused and stores nothing
- [x] A file over its kind's ceiling is refused with the limit named
- [x] The declared kind is checked against the bytes
- [x] A duplicate is reported and both copies are kept
- [x] Deleting removes the object and lands on M13's trail
- [x] One workshop's files and jobs are invisible to another
- [x] There is no route and no grant that could edit a stored file
- [x] Pruning keeps failures far longer than successes
