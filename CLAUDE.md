# CLAUDE.md

Mandatory development rules for this project. They apply to every change —
features, refactors, bug fixes — unless the user explicitly says otherwise in
that request. When a rule here conflicts with existing code, the rule wins and
the code is what gets corrected.

This file is the source of truth for these rules. Keep it short. UX detail,
module specifications and business background belong in `docs/`, not here.

---

## 1. Architecture

1.1 The application is an SPA. No full-page navigation for normal operations,
no browser reload, no leaving the user's context to complete a workflow.

1.2 **No sidebar.** Do not add or reintroduce a left sidemenu. Global chrome
stays minimal: the topbar and the dashboard.

1.3 **One home page.** `/dashboard` is the single entry point and the primary
navigation. Modules appear on it as cards in a responsive grid. Create a card
only for a module that exists.

1.4 **Every card is operable from the home page.** Clicking a module card opens
that module inside the mounted dashboard shell. The user is not routed away.
All of the module's normal operations — list, search, filter, sort, add, edit,
view, delete, status changes — happen there.

The whole normal flow is:

```
Dashboard → module card → module workspace → action → save → keep working
```

1.5 Do not add a page route (`/items`, `/bills`, …) in order to operate a
module. Routes exist only for authentication, public pages, deep links, and
other requirements justified in the request.

## 2. Interaction depth — how a module is opened

Use exactly this hierarchy. It exists to satisfy §1.4 without producing nested
modals.

| Level | Surface | Used for |
|---|---|---|
| 0 | Dashboard card grid | Home |
| 1 | **Inline workspace** mounted in the shell | The whole module: list, filters, tabs, bulk work |
| 2 | **Drawer** (side slide-over) | One record: add, edit, view |
| 3 | Small modal | Confirm, quick-create, one short decision |

2.1 A module workspace is **level 1, never a modal.** A list with filters,
pagination and forms inside a dialog is a scroll trap.

2.2 Level 3 may open over level 2. Nothing opens over level 3. If a flow needs
modal → modal → modal, redesign it as an inline view or a step-based flow
inside the workspace.

2.3 The dashboard shell stays mounted the whole time. Opening a module swaps
the region below the chrome; it does not replace the document.

2.4 Sync the address bar with `history.pushState` when a module opens, so Back
and deep links work. That is not navigation — never let it trigger a load.

2.5 Load a module's code and data on open, never at startup. Reuse the lazy
`import()` registry in [app.js](resources/js/app.js).

## 2A. The module flow — the default for every module

This is the standard shape of a level-1 workspace. Build it into the shared
renderer so every module inherits it; never write a per-module variant.

```
card click → CREATE FORM
           → "Show list" beside the heading → form is replaced by the table
           → row → drawer (level 2) → confirm (level 3)
```

2A.1 A module opens on its **create form**. The list is not rendered and not
fetched until asked for.

2A.2 The form and the list are **alternatives, never siblings**. Exactly one is
in the DOM at a time.

2A.3 **One switch control**, at the right edge of the module heading, in the
same slot in both modes. It names its destination, never the act of hiding —
"Show list" on the form, "Create `<noun>`" on the list. Never "Hide": that
strands the user on a table with no visible route back.

2A.4 Put the record count on the Show control, so the form says how much is
behind it without switching.

2A.5 A card click **always lands on the form**, including for a module last
left on its list. Consistency outranks restoring the mode here.

2A.6 Everything else survives the round trip: the part-typed draft, the list's
search and filters, and the fetched rows live in a per-module state cache, not
in the markup. Returning to a module refetches nothing.

2A.7 The list is fetched on the **first** Show and held from then on. A module
only ever used to write never pays for a list.

2A.8 A successful create **stays on the form**, clears the fields for the next
entry and returns focus to the first one — a clerk writes several in a row.
Flag the new row so it is highlighted when the list is next shown.

2A.9 Escape unwinds one level per press: modal → drawer → list → module → home.

2A.10 Read-mostly modules (Reports, Stock, Payments) have no meaningful create
form. They open on their list instead. Confirm the treatment before building
one rather than forcing a form onto it.

## 3. Data and forms

3.1 All operations go through the `/api/v1` endpoints asynchronously — create,
read, update, delete, search, filter, paginate, status change. Never use a
traditional form POST when an API call is possible.

3.2 **Never** `window.location.reload()`, `location.reload()`, or any
equivalent refresh after an operation. The pattern is:

```
API → response → update UI state → continue
```

3.3 Every form handles: client-side validation where useful, a disabled and
labelled busy state, success feedback, field-level 422 errors, and API/network
failure. Do not redirect after a normal save. Use the existing plumbing in
[ui.js](resources/js/ui.js) — `setSubmitting`, `showFormErrors`,
`clearFormErrors`, `toast`, `confirmAction`.

3.4 Every async surface handles all of: loading, success, empty, validation
error, API error, network failure, permission denied. The user must never be
left unsure whether something is processing.

3.5 Destructive actions require confirmation through `confirmAction()`. Never
the browser's `confirm()`.

3.6 Preserve module state. A search, a filter, an open record, a closed drawer
must not reset the workspace or refetch what is already held.

## 4. Business logic

4.1 The existing business logic outranks any UI change. Never alter it to make
a UI improvement easier.

4.2 Before touching a feature, read the whole path: UI → page module → API →
controller → service → repository → model. Do not change one layer blind.

4.3 **Inventory is critical.** Sales, purchases, returns, adjustments and
workshop jobs must move stock through the existing rules. Never bypass,
duplicate or reimplement a stock calculation. Posting goes through the posting
engine and the stock ledger service; there is one source of truth for quantity
and movement.

4.4 One source of truth generally: inventory, sales, purchase, tax, discount
and payment calculations, validation rules and shared UI behaviour each live in
exactly one place.

## 5. Reuse

5.1 Search the project before creating any component, API, service, controller,
model, helper, form, modal or utility. If something equivalent exists, reuse or
refactor it. Do not add a second implementation.

5.2 Existing shared pieces to reuse rather than rewrite: `ui.js` primitives,
`components/` (party picker, item picker, payment rows, quick item, quick
party, badge), `permissions.js` gating, `auth-client.js` for every request.

Writing a counterparty is `components/quick-party.js` and its partial —
create *and* edit, quick shape *and* full record. It is the form the bill
counter opens from a picker's "+ Add" and the form the Customers and Vendors
screens open from their own list. Never a third copy: the two it replaced had
already drifted to different fields and different validation.

## 6. Security

6.1 Frontend validation is never sufficient. Enforce validation and
authorization on the backend, on every endpoint.

6.2 Never bypass the existing auth or permission rules. UI gating via
`data-requires-permission` is presentation only — the grant is checked server
side too.

6.3 Never expose SQL errors, stack traces, internal server detail or sensitive
data in a response.

## 7. Quality

7.1 Do not over-engineer. No new library, abstraction, route or API call
without a reason. Do not rewrite working code without a reason. Simplest
maintainable solution wins.

7.2 Performance: paginate, load on demand, debounce search, lazy-load module
code, keep queries efficient. Opening one module must not load another
module's data. Never fetch the whole dataset when a page will do.

7.3 Responsive on desktop, laptop, tablet and mobile — cards, workspaces,
tables, forms, drawers.

7.4 Consistent patterns across modules: buttons, cards, forms, inputs, tables,
drawers, modals, toasts, confirmations, loading and empty states. Do not invent
a new interaction pattern per module.

7.5 Fewer clicks. Before building a flow, ask whether the user can finish it in
fewer steps without losing context, and prefer that.

7.6 Before finishing: remove unused code and debug statements, follow existing
conventions, keep functions focused, leave no temporary implementation and no
TODO for work that was in scope.

## 8. Done means verified

8.1 For every affected feature, verify create, read, update, delete,
validation, loading, error handling, success handling, search, filter,
responsive behaviour and permissions.

8.2 For anything that touches inventory, verify the actual stock impact — not
just that the request succeeded.

8.3 Report honestly. If a check was skipped or a test failed, say so.

## 9. Documentation

9.1 If a change alters the architecture, a workflow, business logic, the API
structure or a development convention: update this file when the rule should
bind future work, and update the relevant file in `docs/` otherwise.

## 10. Local development credentials

10.1 The local development database has one workshop owner, and these are its
credentials. **Do not change them.** Signing in to check a change is a normal
part of the work; resetting the password to do it is not, because the next
person to open the project would find a login that no longer works.

| Account | Email | Password | Scope |
|---|---|---|---|
| Workshop owner | `owner@demo.test` | `Password123!` | Tenant 1 — "Demo Workshop" |

10.2 These are for the local development database only. They are not seeded, not
used by the test suite, and must never be a valid credential anywhere a real
workshop's books are kept.

10.3 Do not create extra accounts to test with, and do not edit demo records as a
side effect of testing. If a check needs a record written, write one and remove
it afterwards; if a check needs a record changed, say so first.

---

## Current state vs. these rules

The shell now satisfies §1 and §2. There is one page — `/dashboard` — and no
sidebar. What is left to do is per module.

**The shell.** [shell.js](resources/js/shell.js) swaps `#view-module` beneath a
topbar that never unmounts, syncs the URL with `pushState`, and unwinds Escape
one level at a time. A module's markup arrives once from `/modules/{key}`, and
its root is then cached **detached** — so reopening it re-attaches the same node,
refetches nothing, and runs its `pages/*.js` `default()` exactly once.

**The registry.** [config/modules.php](config/modules.php) is the single source
of truth for which modules exist, what grant each needs, and which are switched
on. [Modules.php](app/Support/Modules.php) reads it, and is the whitelist the
fragment route checks — a module switched off has no card *and* no fragment.

**A detached surface is not in `document`.** §2A.2 keeps exactly one of the form
and the list attached, so `document.querySelector` finds nothing in the other —
which is precisely when a save wants to bring the list up to date. Hold each
surface's node at mount and scope its lookups to it (`$(sel, listRoot)`);
querying a node works detached, so the table is already current when it returns
to the screen. Reaching for `document` here throws on the first `.classList`,
aborts the refetch behind it, and leaves the list on its pre-creation rows.

**The flow.** [workspace.js](resources/js/workspace.js) is §2A built once, so
every module inherits it. `adoptForm()` moves a single form node between its
level-1 slot and an edit dialog; a module must never render the same fields
twice. A read-mostly module (§2A.10) mounts with `canCreate: false` and declares
**no `data-ws-form` at all** — the workspace then lands on the list and paints no
switch control. Stock is the worked example; Reports and Payments follow it.

**One shelf, one arithmetic.** Items and Stock both turn a variant's positions
into a family's, and
[components/stock-position.js](resources/js/components/stock-position.js) is where
that is decided — the roll-up, average cost as total value over total quantity,
worst-wins status, and the badge. Add a third reader, not a third copy (§4.4).

**The catalogue's vocabulary is data, not code.** There is no `ItemType` enum and
no `UnitOfMeasure` enum. What kinds of product exist, what each one records, whose
each thing is, and how any of it is counted are rows in `item_categories`,
`item_attributes`, `item_brands` and `units` — edited from the Items workspace,
published by `GET /api/v1/items/meta`, and drawn by one universal create form that
knows nothing about motors or bearings. **Never reintroduce a hard-coded product
type, attribute list, brand or unit, never put one back as free text on the
product, and never render any of those lists into a Blade template**: a typed
brand is a master list nobody maintains, and a copy in the markup goes stale the
moment an admin adds a category — the exact failures the module was rebuilt to
remove. See [catalogue-master.md](docs/catalogue-master.md).

Two things it deliberately does not do, so nobody adds them casually: **unit
conversion** (a factor between a purchase document and the stock ledger corrupts
stock and the Inventory account together, silently, if it is ever wrong) and
**batch/expiry** (it touches `stock_movements`, which this change left alone).

**One document, two screens.**
[components/bill-document.js](resources/js/components/bill-document.js) and
[partials/bill-document.blade.php](resources/views/partials/bill-document.blade.php)
are the whole of writing a bill — lines, the server-priced total, the
confirmation, the payment split, the autosaved draft and the post. Purchase,
Sales and the counter at `/bills/new` all mount it, and Bills will make four. A
host supplies a direction, a draft key and what to do after a post; it must
never fork the file. The direction decides the endpoint, the party's role, and
whether a line's rate is prefilled — **never on a purchase**, because stock
arrives at the line's taxable value and that arrival is what recomputes the
weighted average. There is no average column to correct afterwards.

**Correcting a posted bill is one component over two modules.**
[components/bill-revision.js](resources/js/components/bill-revision.js) loads a
posted document back into its module's own create form and posts it to
`/revise`, which reverses the original and issues the replacement as one act. It
is also what Sales' **Repeat** uses, which loads the same lines as a new document
and references nothing. Purchase and Sales each mount it; a host supplies a
direction and a noun and closes its own drawer afterwards. The parts that go
wrong in a second copy are not the obvious ones — the banner surviving onto a
blank document, the correction handle being dropped from the autosaved draft, the
client reference regenerated per attempt instead of per correction, a correction
allowed to park as a draft — so it must not be forked either.

**A sale is corrected on stricter terms than a purchase**, and the posting engine
is where that is enforced, never the form. A purchase arrives at its own stated
cost; a sale issues at whatever the weighted average was on the day, and that
figure is on no document. `assertRevisionKeepsTheCostItSoldAt` compares the unit
cost per variant on the reversal against the replacement and refuses with
`REVISION_WOULD_RESTATE_COST` if it moved — a post-condition on what the stock
ledger did, not a second opinion about what it should have done (§4.3). It is the
one refusal with **no acknowledgement path**: negative stock is a state somebody
can accept and fix with a count, a restated cost of goods sold is not something
anybody can agree to. See
[purchase-module.md](docs/purchase-module.md).

**The invoice a customer sees is a second document, not a filtered first
one.** [components/invoice-document.js](resources/js/components/invoice-document.js)
and [partials/invoice-document.blade.php](resources/views/partials/invoice-document.blade.php)
are the whole of it, and both copies go through them: the workshop's print sheet,
mounted hidden as a **direct child of `<body>`** in the layout, and the customer's
page at `/i/{token}`. **A difference between the two copies of an invoice is a
dispute**, and one partial with one renderer is how they are kept identical
structurally rather than by remembering to change both. The print rule is
whichever child of `body` *contains* the document is kept and every other one is
hidden — never a list of the chrome to keep extending, and never the name of a
host either, which is what `body > *:not(#invoice-print)` was until it printed the
customer's page blank. The print block also redefines `--color-border` on the
sheet: the screen token is a hairline a printer drops, and the document came out
of the preview with no rule on it anywhere.

Its payload comes from `InvoiceDocumentService`, which builds the customer's
document from **its own list of fields**. `TransactionResource` carries the cost
of every line, the margin, `below_cost`, the ledger entries and the stock
movements; none of that may reach the person the workshop sells to, and the way
to be sure is that there is no branch in that file which could include it. Never
serve a customer-facing document out of the internal resource, and never add "hide
the cost" as a flag to one — the buying price is the workshop's negotiating
position, with its supplier and with this customer next time.

Sharing is a row in `invoice_shares`, never a column on `transactions`: a posted
transaction refuses writes, and a link is issued, revoked, and issued again. The
link has **no expiry** — a customer keeps an invoice — so revoking is its whole
lifetime, and re-sharing mints a different token. Shareability is re-asked on
every read, which is what makes a reversed invoice stop opening without anything
having to remember. Tenancy at `/i/{token}` is established **from** the token, the
one deliberate unscoped read on that path. See
[billing-module.md](docs/billing-module.md).

**Writing a counterparty is one form.**
[components/quick-party.js](resources/js/components/quick-party.js) does create
and edit, in a quick shape from a picker and a full one on the record screens. It
replaced two copies that had already drifted to different fields and different
validation (§5.1). It has **two frames and one node**: pass a `slot` and the form
is moved into a module's level-1 create surface with its inline footer, pass none
and it opens in the drawer. An edit is always the drawer — one record over a
list is what level 2 is for. Never write those fields out a second time.

**It never asks which role.** Customers and Vendors are separate modules, and
what a record gets is decided by the one it was written from — never by a field
on the form. The counterparty who is both is still *one* row in `parties` with
one combined ledger: saving a name that is already taken offers to mark the
existing record with this role as well, which is the only moment that question
means anything. An edit carries the roles the record already holds, untouched.
Do not put the checkboxes back, and do not let a second record be the answer.

**One position, one arithmetic, and the sign is the whole meaning.**
[components/party-position.js](resources/js/components/party-position.js) decides
what a counterparty's `outstanding` *means* — owing, in credit, or nil — for the
Customers and Vendors lists, their drawer tiles, and the party picker on every
bill form. A negative receivable is a customer who has **paid ahead**, not a
small debt: showing it in the amber that means "chase this" everywhere else sends
somebody after money the workshop is holding. `null` is a fourth state and not a
zero — it means nobody asked for the figure, and rendering it as "Nil" is the one
wrong answer here that reads as reassurance. Add a fourth reader, not a fourth
copy (§4.4).

**The bill form says what they already owe, at the pick.** The party picker
fetches the position on the pick — not with the search, which runs on every
debounced keystroke and would compute one for nine parties nobody chose — and
holds it per id for the life of that picker. It comes from `GET /parties/{id}`
under **`READ:PARTIES` alone**, deliberately: deciding whether to sell on credit
is part of writing the invoice, and the counter clerk who may raise one holds
PARTIES and TRANSACTIONS and no LEDGER. The *statement* and the *ledger* — every
entry, the running balance, which invoices are open — stay behind `READ:LEDGER`.
The line is between one figure and the entries behind it, never between the name
and the money. See [parties-module.md](docs/parties-module.md).

**Two modules over one implementation.**
[pages/counterparty.js](resources/js/pages/counterparty.js) is a *factory*:
Customers and Vendors each call it once and close over their own state. The shell
keeps both mounted, so anything module-level in there — state, a held DOM node, a
form context — belongs to whichever initialised last, and the two lists start
reading each other's rows. The same rule holds for any pair of modules built from
one file.

**Authority is not the same question as membership.** **Users** and **Roles**
are the administration pair, and neither is `workspace`. Users is tenant-scoped
at the repository, so an owner reads their own staff and a platform admin reads
the platform's — the card is right for both. Roles are defined for the whole
platform: `OWNER` holds `READ:ROLES` and nothing else, so that module opens on
its **list**, with `canCreate: false` and no switch control at all, and its edit
and delete controls stripped by the permission gates. That is not an oversight
to be fixed by widening the card — the grant lives in `RoleSeeder`, and a tenant
creating a role would be creating it for every workshop. `ADMIN` writes there.
System roles (`is_system_role`) are refused by the API for edit and delete, and
their controls are **disabled rather than hidden**, so the reason stays where
the question is asked.

**One card, four workspaces, and the shared renderer used four times.**
**Staff** — M22 — is employees, attendance, payroll and advances: four things a
workshop does with the same nine people, and only ever one after another. Each
section is an ordinary §2A workspace, mounted from
[pages/staff.js](resources/js/pages/staff.js) by calling `mountWorkspace()` on
that section's own root, so all four inherit the form/list swap, the one switch
control and the count badge without a line of per-module flow code. Sections
mount **lazily**, on the first click of their tab — a workshop that only marks
attendance never pays for the payroll sheet. Each workspace registers Escape
under a key of its own and the module registers `staff`, because the shell asks
for the module key: without that the last-mounted section answers for all four,
and a press on the payroll list swaps the attendance sheet.

**An unmarked day is not a blank, and what it is worth depends on how somebody
is paid.** A monthly salary is owed unless something is recorded against it, so
silence is **paid**; a daily wage is earned by turning up, so silence is
**unpaid**. That decision lives in `SalaryBasis::unmarkedDayIsPaid()` and nowhere
else — in particular the attendance screens return an unmarked day as unmarked
rather than defaulting it to present, because filling the gap in the UI would be
making the decision a second time in the layer least likely to be looked at when
a payslip is queried. `PayrollCalculator` is the one place any of this becomes
money: halves counted in integers, divided exactly once at the end, against a
month that is its own denominator. Add a reader, never a second copy (§4.4).

**A payroll run is a fact, not a work in progress.** There is no draft, because a
parked sheet is figures derived from a register that keeps moving under it —
somebody would open a fortnight-old one and pay a month that three subsequent
absences had already made wrong. It is computed on demand, posted, and corrected
by **reversing**, which frees the month. So what the operator saw is not what is
posted: `PayrollService::post()` recomputes the sheet, and the only thing carried
over from the screen is the human decision — how much of each advance to recover.

A run settles in full: one voucher for the whole month, `Dr Salary Expense / Cr
Staff Advance / Cr Cash`. There is **no salary-payable liability**, deliberately
— half a payables ledger is worse than none, which is the judgement Purchase
already makes about landed cost. An advance is an **asset**, never an expense,
and what is out with somebody is derived from posted advances less posted
recoveries, so reversing either side moves the figure with nothing having to
remember. See [staff-module.md](docs/staff-module.md).

**STAFF is not USERS, and it is the one grant withheld for privacy.** Who may
sign in and who is on the payroll are different questions: most of a workshop's
fitters have never touched the software. `DATA_ENTRY` holds no staff grant at all
— not because a clerk cannot be trusted with the list, but because what each
person earns is not something the person on the till needs. Inside the module the
line falls where the money starts: posting payroll and paying an advance
additionally require `WRITE:TRANSACTIONS`, the same boundary Jobs draws between
recording a repair and billing it.

**Designations are data; the bases and the attendance states are code.** What the
people here are called differs in every workshop, so it is a master table edited
from the module and published by `GET /api/v1/staff/meta` — never written into a
Blade template, for the reason the catalogue learned. The two salary bases and
the six attendance states are enums because each one changes the arithmetic, and
that is the test a candidate seventh has to pass: "late" and "on site" are real
and change nothing about what is owed, so recording them would be putting a diary
in the payroll input.

**Attributing a sale to the people who did it is finished, both halves.**
`transaction_staff`, `staff_designations.track_on_sales`,
`WorkAttributionService`, `GET /staff/{employee}/work` and
`PATCH /transactions/{id}/staff` are the back half, and
`TransactionController` syncs attributions when a sale is posted or revised. The
front half is one picker per `track_on_sales` designation in the shared bill
document ([components/staff-attribution.js](resources/js/components/staff-attribution.js),
mounted by [components/bill-document.js](resources/js/components/bill-document.js))
and the "work done" block in the employee drawer
([pages/staff.js](resources/js/pages/staff.js)); `tests/Feature/Staff/WorkAttributionTest.php`
covers it. Do not build a second way to record the same fact, and do not reach
for `transactions.employee_id`, which is spoken for and means who an *advance*
went to. Attribution is also **not an input to pay**: a throughput figure that
quietly became a piece rate would be a second source of truth for wages. The
detail is in [work-attribution.md](docs/work-attribution.md).

**Reading the books is one card, at two zoom levels.** **Insights** — M23 — is
the overview, sales, purchase, stock, money owed and people panels *and* M12's
four statements: the day book, the P&L, the GST summary and the parked drafts.
There is no separate Reports card, and the merge is §5.1 rather than tidiness —
two cards would both have answered "how is the business doing", and an owner
looking for sales-by-month would have had to guess which of them had it. The
statements were **not** rewritten: those tabs still fetch `GET /reports/*`.

Nothing in it is stored, and nothing may be. It is the module most likely to be
handed a nightly rollup for speed and the one where a stale figure would do the
most damage — a workshop whose insights disagreed with its own P&L would stop
trusting both. If it becomes slow the answer is an index.

**It is also the only place that answers "how is the business doing".** There
was a second one — `GET /api/v1/dashboard`, backed by a 448-line
`DashboardService` built for M21's home screen — and it was deleted rather than
left dormant when home became the card grid: nothing called it, and a second
service answering the same question is the one that drifts (§4.4, §5.1). **A
card carries no figure.** If the grid is ever to show one, it comes from
`/insights/*`; do not reintroduce a dashboard endpoint, and do not render a
figure into `dashboard.blade.php`, which is a *public* shell — that is what
`test_the_dashboard_bakes_in_no_figures_of_its_own` holds shut.

**It sums the document lines where the P&L sums the ledger**, because the ledger
has one Sales account and cannot say which item earned the margin or who bought
it. The two agree whenever every rupee of income arrived through a bill, and they
cannot when somebody posts a manual journal straight to Sales — which M4 allows,
because it is the correction mechanism for everything else. The overview states
that difference and **never repairs it**, even when it is nil.

Four things in it are wrong in ways that look right, and each is load-bearing. A
**reversal pair drops out on both halves** — `status = posted` removes the
document that was reversed, `reverses_id is null` removes the reversal that
cancelled it — and stock *value* is the deliberate exception, counting every
movement because that is how they cancel. **Labour is out of the margin
percentage and in the revenue**, because an hour has no cost of goods and
counting it would flatter the figure everywhere. An **ageing measured against
terms nobody agreed to is not an ageing**, so a workshop with no
`payment_due_days` gets buckets measured from the invoice date and told so. And
the **ageing counts open documents where a party's balance counts the ledger**, so
an unallocated receipt leaves an invoice open while the customer's balance is
already nil — reported as a worklist, never netted away, because nothing may guess
which invoice a cheque was for.

**The People tab is the one gated for privacy.** It needs `READ:STAFF` as well as
`READ:LEDGER`, and a caller holding only the second gets an overview with no wage
tile *at all* — absent, not blanked, because a tile reading "—" tells somebody
there is a number there. Cost and attributed work sit side by side and are never
divided into one another: a ratio would look like a productivity score, and
attribution must never become an input to pay.

There is **no charting library**, and columns are HTML rather than SVG because an
SVG `viewBox` scales its text and renders microscopic labels on a phone. See
[insights-module.md](docs/insights-module.md).

**What is left — ten modules, all of them built.** **Items**, **Stock**,
**Purchase**, **Sales**, **Vendors**, **Customers**, **Users**, **Roles**,
**Staff** and **Insights** have been converted and are on. The other ten —
**Bills**, **Jobs**, **Transactions** (`journal`), **Accounting** (`accounts`),
**Ledger**, **Uploads**, **Workshops** (`tenants`), **Settings** (`workspace`),
**Opening balances** and **History** (`audit`) — are `'enabled' => false`.

Be clear about what that means, because it is the most misread fact in this
repository: **none of them is unfinished work.** Each has a complete backend, a
complete `pages/*.js`, a fragment view in `resources/views/modules/{key}.blade.php`
and feature tests. They are off for one reason only — they still open on a list
with a modal create instead of the §2A flow. **Their APIs answer normally**; it
is the card and the fragment route that are shut, so this is a reachability gap
in the UI and never a security boundary. What that costs a workshop today — no
expense entry, no standalone receipt or payment, no job card, no opening
balances, no audit trail, no workshop settings — is set out module by module in
[hidden-modules.md](docs/hidden-modules.md). **Read it before converting one**:
several of them have had part of their job taken over by a card that is already
on, and converting the whole of the old screen would rebuild what Sales,
Purchase and Insights already do (§5.1).

Coverage for a module that is off stays in `PagesRenderTest`, rendered with
`$this->view()` rather than fetched, because its fragment route answers 404 while
it is off. Once it is on, switch that coverage to `$this->get('/modules/{key}')`,
which asserts the route as well as the markup.

Convert one module at a time, then flip its `enabled` flag. Do not add a page
route, do not reintroduce a sidebar, and do not regress what already conforms.
The counter at `/bills/new` is the one remaining page shell; it now drives the
shared document engine, and it goes away when Bills is converted.

**Sales is Purchase mirrored, and the asymmetry is the whole of it.** A purchase
arrives at a cost it states; a sale issues at a weighted average that is on no
document. That one difference is why a sale line's rate is prefilled and a
purchase line's is not, why an invoice's correction is checked against the stock
ledger and a purchase's is not, and why the drawer's margin panel exists on this
side only — and may never reach the customer's copy. Everything else is the same
component. See [sales-module.md](docs/sales-module.md), and read
[purchase-module.md](docs/purchase-module.md) first: what is written down twice
will be changed once.

**Who did the work is a row, and the trades are data.** A sale can name the
people who did the job — "Ramesh fitted it, Sunil wound it". There is **no
`fitter_id` and no `winder_id`**: what a sale asks about is the designations the
workshop ticked in the Designation Master, so a shop that starts varnishing gets
a third picker without a deployment. Never put a trade name in a column, in a
Blade template or in a JavaScript file — it is the same failure the catalogue's
vocabulary rule already records, and
[components/staff-attribution.js](resources/js/components/staff-attribution.js)
is the one renderer for both surfaces that ask.

Three parts of it are load-bearing and each is wrong in a way that looks right.
The roster reaches the sale form through **`GET /transactions/meta`**, carrying
names and ids only — the counter clerk who raises invoices holds no `STAFF`
grant, deliberately, because that grant guards what people are paid; fetching the
pickers from `/staff` would 403 the form for its main user or push wages onto
everybody who writes a bill. An emptied picker is sent as **`employee_id: null`**
rather than omitted, because a correction has to be able to *remove* a name. And
`PATCH /transactions/{id}/staff` is **the one write in this application that
edits a posted document** — permitted because it moves no figure, and necessary
because correcting a sale by reversing and reissuing it is refused outright once
the weighted average has moved (`REVISION_WOULD_RESTATE_COST`). Write-once here
would leave a name that is known to be wrong, for ever. Every change is audited,
and that is the whole safeguard. See
[work-attribution.md](docs/work-attribution.md).

It deliberately records **no line grain, no hours and no piece rate**: the moment
a share of the bill lands in that table it is an input to somebody's pay, and pay
is computed from a rate and an attendance sheet in one place.

**Sales deliberately has no quotation, no delivery challan, no recurring invoice
and no e-invoice.** A quotation and a challan each want their own numbering and
their own lifecycle, and a challan moves goods without billing them — a second
writer to `stock_movements`, which is the objection CLAUDE.md already records
against goods-received notes.

**Purchase deliberately has no purchase order, no goods-received note and no
landed cost.** Each touches when stock moves or what it is valued at, and half
of one is worse than none. See [purchase-module.md](docs/purchase-module.md).
