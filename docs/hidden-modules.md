# The ten hidden modules

Status report on the modules that carry `'enabled' => false` in
[config/modules.php](../config/modules.php): what is behind each switch, which
part of its job an enabled card has already taken over, which part nothing else
can do, and what it costs a workshop that the card is off.

Keep this file current. When a module is converted and its flag flipped, delete
its section here rather than leaving a stale one — a status document that has to
be checked against the code is worse than none.

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
- **The old URL still resolves.** `/audit` redirects to `/dashboard#audit`, which
  opens nothing while the module is off. The redirect stays registered so that
  `route('audit.index')` does not 500 — a shrug, not a broken link.
- **Off is not free.** Every one of the ten holds at least one capability that
  nothing else can reach, and **five are unreachable in their entirety** — Jobs,
  Uploads, Workshops, Settings and Opening balances have no part of their job
  covered by any card that is on. The other five have had their *list* taken over
  by Sales, Purchase or Insights while their *write* has no home.

---

## The ten at a glance

| Card | Key | Grant | Has an enabled card taken over part of it? | What only it can still do |
|---|---|---|---|---|
| Bills | `bills` | `READ:TRANSACTIONS` | Yes — its whole list | Record an **expense** |
| Jobs | `jobs` | `READ:WORKSHOP_JOBS` | No | The entire bench: receive, inspect, estimate, bill a motor |
| Transactions | `journal` | `READ:TRANSACTIONS` | Partly — the list, and payment against one bill | A receipt or payment **not tied to a document**, and the **journal voucher** |
| Accounting | `accounts` | `READ:ACCOUNTS` | Partly — the entry list | **Create or edit a ledger account** |
| Ledger | `ledger` | `READ:LEDGER` | Partly — P&L, GST, day book, party ledgers | The **trial balance** and its reconciliation |
| Uploads | `uploads` | `READ:ATTACHMENTS` | No | Store and retrieve a photographed bill |
| Workshops | `tenants` | `READ:TENANTS` | No | Provision, suspend or reactivate a workshop |
| Settings | `workspace` | `READ:WORKSPACE` | No | Set GSTIN, address, financial year, timezone, go-live date |
| Opening balances | `opening` | `UPDATE:WORKSPACE` | No | Get an existing workshop's position into the books |
| History | `audit` | `READ:AUDIT` | Partly — per-record activity tabs | The trail **across** the workshop |

---

## Module by module

### Bills — `bills`

**What it is.** The §23 transaction list — Invoice · Customer · Date · Items ·
Total · Paid · Due · Status, with a payment-status filter — and the expense form
posting `POST /transactions/expense`.

**Already on a card.** Its list, entirely. Sales lists invoices and credit notes,
Purchase lists bills and debit notes, and Insights' **Day Book** tab lists every
posted document, including the expenses and journal vouchers neither of those
shows.

**Only here.** **Writing an expense.** `/transactions/expense` has exactly one
caller in the whole front end and it is
[pages/bills.js](../resources/js/pages/bills.js). Rent, electricity, a mechanic's
conveyance, tea for the counter — none of it can be recorded from a card that is
on.

**Why it matters.** An expense is what it costs to be open, as against what was
bought to sell. With no way to enter one, the P&L reports a margin against
overheads of nil and the cash position drifts from the tin.

**When converting.** Do not rebuild the list — that is the §5.1 mistake this
module invites. Bills becomes the expense module: an expense create form under
§2A.1 with past expenses behind "Show list". Note that its markup also holds the
only link to the counter at `/bills/new` (see *Gaps*, below).

### Jobs — `jobs`

**What it is.** M19's bench, and the largest domain in the product that nobody
can reach: the job list, the job card, the received → inspection → estimate →
in progress → ready → delivered pipeline, parts written onto a job, the estimate
and its approval, and **Generate bill**. See
[workshop-module.md](workshop-module.md).

**Already on a card.** Nothing. The counter at `/bills/new` can *bill* an
existing job through its Workshop bill tab, but cannot create one — and nothing
in the UI links to the counter either, so in practice the whole domain is dark.

**Only here.** All of it. Receiving a motor, recording the complaint and the
motor's details, moving status, adding parts, estimating, getting approval.

**Why it matters.** This is the workshop's actual trade. It is covered by 25
feature tests including the §34 walkthrough end to end, and no workshop can open
any of it.

**When converting.** The strongest candidate to go first: complete, duplicating
nothing, and the biggest hole. Keep decision D2 on the screen where parts are
added — **a part written onto a job moves no stock; the invoice does** — because
it is obvious in a design document and baffling at a counter.

### Transactions — `journal`

**What it is.** Four tabs over the transaction list, the double-entry journal
voucher grid (`POST /transactions/journal`), and the receipt and payment forms
(`POST /transactions/receipt` and `/payment`) with a settlement split across a
party's open bills.

**Already on a card.** The list, by Sales, Purchase and the Day Book. And the
common case of settlement: Sales collects against the invoice its drawer is open
on, Purchase pays a bill the same way, and both send an explicit `allocations`
entry rather than relying on oldest-first.

**Only here.** Two things, and both are structural:

- **A receipt or payment not tied to one document** — a customer clearing three
  invoices with one cheque, or paying on account before anything is raised.
- **The manual journal voucher.** CLAUDE.md names it as the correction mechanism
  for everything else in the books; it is why the Insights overview is allowed to
  disagree with the P&L at all.

**Why it matters.** Without it, money that arrives without a bill has nowhere to
go, and the only correction available anywhere is reversing a whole document.

**When converting.** This is where §5.1 bites hardest — four tabs of a list that
three enabled cards already draw. What survives conversion is the settlement
forms and the voucher grid.

### Accounting — `accounts`

**What it is.** Three tabs behind one strip: **Ledger Accounts** (every account
and what it stands at), **Journal Entries** (the postings that put it there), and
**Chart of Accounts** (create and edit an account), plus a per-account CSV
statement and a ten-row activity window in the drawer.

**Already on a card.** The journal-entry list overlaps the Day Book.

**Only here.** **Creating or editing a ledger account.** `POST /accounts` and
`PATCH /accounts/{id}` have one caller and it is
[pages/accounts.js](../resources/js/pages/accounts.js).

**Why it matters.** A workshop that wants its own expense head — "Diesel",
"Workshop rent", "Rewinding wire scrap" — cannot create one. The seeded chart is
all it will ever have.

**When converting.** Convert it **together with Ledger**, as one module. They are
the same question at two zoom levels, and `accounts.js` already defers to it in
so many words: its drawer shows the last ten entries because "the full statement
is the Ledger screen's job". Two cards would both answer "what does this account
stand at".

### Ledger — `ledger`

**What it is.** The trial balance across a chosen period with a **reconciliation
banner** — the single most important number on the screen, because if the two
sides differ everything else on it is suspect — and one account's ledger with a
running balance, paginated.

**Already on a card.** Insights carries the P&L, the GST summary, the day book
and the parked drafts. Customers and Vendors carry a *party's* ledger and
statement.

**Only here.** **The trial balance.** No enabled card shows it, and no enabled
card checks that the books balance.

**Why it matters.** It is the one screen that proves double entry held, and the
first thing an accountant asks for.

**When converting.** Merge with Accounting, above. Do not build a second period
picker or a second trial-balance renderer.

### Uploads — `uploads`

**What it is.** M14 — the upload queue, which polls `/api/v1/jobs` for progress,
above the library of what the workshop has stored, with download and delete.
Keeping the two apart is the design: a file still travelling is not yet one of
their records.

**Already on a card.** Nothing. No enabled module attaches a file to anything.

**Only here.** All of it — and `/api/v1/jobs`, M14's polling endpoint, has no
other consumer either.

**Why it matters.** Photographing a supplier's bill at the counter is one of the
things a workshop most wants from software like this, and the stored file is the
evidence behind a posted document.

**When converting.** Not read-mostly: uploading **is** the create act, so it takes
the ordinary §2A.1 shape — the drop target as the form, the library behind "Show
list". Keep the queue visually separate from the library.

### Workshops — `tenants`

**What it is.** The platform surface: every workshop on the platform, its
provisioning, and suspend/reactivate. `'workspace' => false` — this is the one
module about other people's books.

**Already on a card.** Nothing.

**Only here.** All of it. A platform admin signs in holding every grant, owns no
books, and with this card off the only cards that answer for them are Users and
Roles.

**Why it matters.** Onboarding a workshop, and suspending one that has stopped
paying, is the platform's entire job.

### Settings — `workspace`

**What it is.** The workshop's own record: name, GSTIN, state code, address,
financial-year start month, timezone and `books_start_date`.

**Already on a card.** Nothing.

**Only here.** All of it. Sign-up takes an *optional* GSTIN, so **a workshop that
signed up without one can never add it**, and none of them can correct a name, an
address, a financial year, a timezone or the date their books begin.

**Why it matters.** The GSTIN prints on every invoice; `state_code` is what
decides CGST/SGST against IGST; `books_start_date` is what
`Tenant::acceptsPostingOn()` refuses against.

**It is also incomplete, and converting it means finishing it.**
`UpdateWorkspaceRequest` already accepts three settings the form never offers:

| Setting | What it governs |
|---|---|
| `allow_negative_stock` | D6's escape hatch — whether an issue that would take a variant below zero is refused |
| `round_off_invoices` | Whether an invoice total is rounded |
| `payment_due_days` | The terms Insights measures its ageing against; with none set, the buckets are measured from the invoice date and say so |

Three controls, not a re-flow. Re-flowing the seven fields that exist and leaving
these behind would make this the module that looks converted and is not.

### Opening balances — `opening`

**What it is.** M11 — paste or upload the position at go-live, **preview**, then
post. The post button stays disabled until the preview has run against the text
currently in the box, so an edit made after a preview cannot be committed on the
strength of the preview it invalidated.

**Already on a card.** Nothing.

**Only here.** All of it.

**Why it matters.** This is the module whose absence is worst in combination:
with Settings and Opening balances both off, **a real workshop cannot go live**.
Its existing debtors, creditors, stock and cash have no way in, so every report
starts from zero on the day the software is first opened, and the trial balance
is not the workshop's.

**When converting.** Keep the two-button discipline exactly as it is. It is not
UX politeness, it is the whole safety property of the module.

### History — `audit`

**What it is.** M13's trail — who changed what and when, filtered, with the
changed fields inline on the row that describes them. No detail modal, because an
entry *is* its detail.

**Already on a card.** Partly, and only in one direction: Items and the
counterparty modules each carry an **Activity** tab in their drawer, reading
`/audit-logs?resource=…&resource_id=…` for that one record.

**Only here.** The trail **across** the workshop — "what did this user do last
Tuesday", "who has been editing prices". Per-record activity answers the opposite
question and cannot be walked backwards into this one.

**Why it matters.** Beyond the obvious: `PATCH /transactions/{id}/staff` is the
one write in this application that edits a posted document, and the audit trail
is the whole of its safeguard. Right now nobody can read it.

---

## Gaps that belong to no module

Three things are missing that converting a module will not fix on its own.

**1 · The counter is unreachable.** `/bills/new` is linked from exactly one
place: `data-new-bill` in `resources/views/modules/bills.blade.php`, which is
itself off. The page answers if the URL is typed. It matters because the counter
is the only screen that can raise a **workshop bill** — a job's parts and labour
posted through `{job}/bill`, which is what stamps the invoice and marks the parts
as billed. Sales cannot do it; it posts `/transactions/sale`. This resolves
itself when Bills is converted and the counter is retired, per CLAUDE.md, but
until then the job → invoice path has no route through the UI.

**2 · An existing receipt cannot be allocated.**
`POST /transactions/{id}/allocate` and `GET /transactions/{id}/open-bills` have no
caller anywhere in the front end. Settling on the way in works — Sales and
Purchase send `allocations` with the receipt — but deciding *afterwards* which
invoices a cheque covered does not exist. Insights reports the consequence (an
unallocated receipt leaves an invoice open on the ageing while the party's
balance is already nil) and correctly refuses to guess, but nobody has a screen
on which to answer.

**3 · Three workshop settings have no control at all** — see Settings, above.

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

---

## Suggested order

1. **Jobs** — complete, duplicates nothing, and its absence is the biggest hole.
2. **Settings + Opening balances** — together they are the answer to "can a real
   workshop start using this". Settings must gain its three missing controls.
3. **Bills**, as the expense module and nothing else.
4. **Transactions**, keeping only the settlement forms and the voucher grid.
5. **Accounting + Ledger**, merged into one module with one period picker.
6. **Uploads**.
7. **Workshops** and **History**.

Convert one at a time and flip its flag, per CLAUDE.md. When a module goes on,
move its coverage in `PagesRenderTest` from `$this->view('modules.{key}')` to
`$this->get('/modules/{key}')`, which asserts the fragment route as well as the
markup.
