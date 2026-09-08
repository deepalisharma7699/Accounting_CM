# The two hidden modules

What is behind each `'enabled' => false` in
[config/modules.php](../config/modules.php): which part of its job an enabled
card has already taken over, which part nothing else can do, and what it costs a
workshop that the card is off.

**This file says what is unreachable. It does not say how to convert it** — that
is Part E of [implementation-roadmap.md](implementation-roadmap.md), which
schedules the rest as **C6–C7** and carries each one's shape, its *do not
rebuild* list and its test checklist. The **order**, the gates between the steps
and what the pre-flight sweep found are
[execution-plan.md](execution-plan.md). Read them before starting a conversion:
the roadmap for what to build, the execution plan for when, this file for what
the workshop is missing while you do not.

**This file is scheduled for deletion.** When C7 flips the last two flags there
will be no hidden module left to describe, and a status document that outlives
its subject is the kind that gets believed. P7 of the execution plan either
rewrites it as the record of what hiding cost or folds its two surviving notes —
the removed dashboard service, and what "off" meant — into the roadmap.

**C1 to C5 are done**, and their sections have been deleted from this file
rather than left to go stale. A workshop can now go from sign-up to a correct
opening trial balance without a developer; the three trading rules the API had
always accepted — `payment_due_days`, `allow_negative_stock`,
`round_off_invoices` — are on the settings form; **an expense can be recorded**,
so the P&L reports a margin against real overheads instead of nil; **money that
arrives without a bill has a home**, along with the journal voucher and the
allocation screen M16 had built and never given a caller; **the bench is
reachable** — a motor can be received, worked, estimated, approved and billed
from a card, which retired the last page shell in the product with it; and **the
books can be read and added to** — a workshop can create an expense head of its
own, and an accountant can be shown a trial balance that reconciles.

C5 is also the one step that **removed a key** rather than only flipping a flag.
Accounting and Ledger were two cards answering one question at two zoom levels,
so `ledger` is gone from the registry and its screen is the trial-balance view of
the Accounting card. `/ledger` still redirects, registered by hand in
`routes/web.php` because the loop there only declares one per module the registry
still names.

Keep this file current. **When a module's card goes on, delete its section here**
rather than leaving a stale one — a status document that has to be checked
against the code is worse than none.

---

## What "hidden" means, and what it does not

**None of these modules is unfinished.** Each has a complete backend, a complete
`pages/*.js`, a fragment view in `resources/views/modules/{key}.blade.php`, and
feature tests. Every one of them worked as a page before the shell migration.

They are off for exactly one reason: they still open on a list with a modal
create instead of the §2A flow (`card → create form → "Show list" → drawer →
confirm`). Turning one on is `'enabled' => true` **after** its screen is
re-flowed, and nothing else.

Three consequences worth stating plainly, because each is regularly misread:

- **The API is not hidden.** `/api/v1/attachments` answers whether or not the
  Uploads card exists, under its own grant, tenant-scoped as always. The switch
  governs the card and the fragment route. It is a UI reachability boundary and
  never a security one.
- **The old URL still resolves.** `/uploads` redirects to `/dashboard#uploads`,
  which opens nothing while the module is off. The redirect stays registered so
  that `route('uploads.index')` does not 500 — a shrug, not a broken link.
- **Off is not free.** Both of these are unreachable **in their entirety** —
  neither Uploads nor Workshops has any part of its job covered by a card that is
  on. There is no partial coverage left to reason about: History, which had its
  *list* taken over in one direction only, went on 7 September.

---

## The two at a glance

In conversion order. The pair that decided whether a workshop could start using
the product at all — C1 — the expense module that gave its P&L overheads — C2 —
Transactions, which gave money without a document somewhere to go — C3 — Jobs,
the trade itself — C4 — Accounting with Ledger merged into it — C5 — and
**History**, which went on ahead of C7 on 7 September because it needed no
re-flow to speak of, are done and gone from this table.

| Step | Card | Key | Grant | Covered elsewhere? | What only it can still do |
|---|---|---|---|---|---|
| **C6** | Uploads | `uploads` | `READ:ATTACHMENTS` | No | Store and retrieve a photographed bill |
| **C7** | Workshops | `tenants` | `READ:TENANTS` | No | Provision, suspend or reactivate a workshop |

---

## Module by module

### Uploads — `uploads` · C6

**What it is.** M14 — the upload queue, which polls `/api/v1/jobs` for progress,
above the library of what the workshop has stored, with download and delete.
Keeping the two apart is the design: a file still travelling is not yet one of
their records.

**Already on a card.** Nothing. No enabled module attaches a file to anything.

**Only here.** All of it — and `/api/v1/jobs`, M14's polling endpoint, has no
other consumer either.

**Why it matters.** Photographing a supplier's bill at the counter is one of the
things a workshop most wants from software like this, and the stored file is the
evidence behind a posted document. It is also the surface M15's image capture
starts from.

### Workshops — `tenants` · C7

**What it is.** The platform surface: every workshop on the platform, its
provisioning, and suspend/reactivate. `'workspace' => false` — this is the one
module about other people's books.

**Already on a card.** Nothing.

**Only here.** All of it. A platform admin signs in holding every grant, owns no
books, and with this card off the only cards that answer for them are Users and
Roles.

**Why it matters.** Onboarding a workshop, and suspending one that has stopped
paying, is the platform's entire job.

## Gaps that belong to no module

Three things were missing that converting a module would not fix on its own.
Each was scheduled inside the step closest to it, and **all three are now
closed**.

**1 · The counter, and the workshop bill behind it — closed by C4, and done.**
`/bills/new` was the last page shell in the product, and it survived C2 for one
reason: it was the only screen that could raise a **workshop bill** — a job's
parts and labour posted through `{job}/bill`, which is what stamps the invoice
and marks the parts as billed. Sales cannot do it; it posts
`/transactions/sale`. C4 moved that path onto the Jobs card, where the invoice
is raised from the job it came off, and the route, the view and
`pages/bill-counter.js` went with it. The two links that pointed at the counter —
"Create sale" on Customers and "Create purchase bill" on Vendors — now open the
Sales and Purchase cards in the mounted shell with `?party=`, which is a module
swap rather than a document load (§1.1).

**2 · An existing receipt could not be allocated — closed by C3, and done.**
`POST /transactions/{id}/allocate` and `GET /transactions/{id}/open-bills` had no
caller anywhere in the front end. Settling on the way in always worked — Sales
and Purchase send `allocations` with the receipt — but deciding *afterwards*
which invoices a cheque covered did not exist. It is now the drawer of a posted
receipt or payment on the Transactions card: the party's open bills, oldest
first, an amount against each, and the unallocated remainder stated plainly.
Insights still reports an unallocated receipt as a worklist and still refuses to
guess; there is now somewhere to answer.

**3 · Three workshop settings had no control at all — closed by C1, and done.**
`UpdateWorkspaceRequest` had always accepted `payment_due_days`,
`allow_negative_stock` and `round_off_invoices`, and no screen offered any of
them. All three are now on the settings form, each saying beside the control what
it changes — the terms the ageing is measured against, whether an issue below
zero is refused, and whether a bill is taken to the nearest rupee. The behaviour
each governs is covered where it happens: `StockDisciplineTest`, `RoundOffTest`
and `InsightTest`.

---

## What was removed

`GET /api/v1/dashboard`, `Api\V1\DashboardController`, `DashboardService` (448
lines) and `DashboardTest`, together with `WorkshopJobRepository::overdueOn()`,
which nothing else called.

M21 built them for a home screen with figures on it. Home is now the module card
grid, CLAUDE.md holds it figure-free, and `PagesRenderTest` asserts as much —
that shell is public, so a workshop's takings may never be rendered into it.
Nothing had called the endpoint since. A second service answering "how is the
business doing" beside Insights is the one that drifts (§4.4, §5.1).

**It is not recoverable.** Those three files had never been committed, so
deleting them removed them for good — this note is the record of what they were.
`DashboardService` assembled today's sales, purchases and service revenue, total
outstanding, counts of customers, vendors and products, low and out-of-stock
counts, and pending and ready job counts, from `ReportService`,
`StockLedgerService`, `PartyLedgerService` and `BillService`. Every one of those
figures is still answerable, and `InsightService` already answers most of them
over a period. If the card grid is ever to carry a count, it comes from
`/insights/*` — do not rebuild a second service to produce it.
