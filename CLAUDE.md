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

3.7 **Held is not the same as stale.** §3.6 and §2A.7 keep a module's rows for
the life of the tab, so a write in *another* module leaves them wrong. A screen
that holds a copy of something declares what it is a copy of — `refreshOn` on
`mountWorkspace`, or `onChange` from [data-bus.js](resources/js/data-bus.js) —
and refetches when it is next looked at. Never a refresh button, never a poll,
never a reload, and never a per-module notification: writes announce themselves
from `auth-client` so a new one cannot forget to.

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

4.5 **A migration that deletes business data does not live in
`database/migrations`.** Schema changes run by themselves, on every deployment
and on every fresh database, and that is the point of them. A one-time data
operation that empties tables must not inherit that: the case nobody plans for is
restoring a dump taken before it ran and then migrating — which is exactly what a
recovery is — and it would run a second time, at the moment least able to absorb
it. Put it in `database/manual/`, which `migrate` does not scan, give it a guard
that refuses unless it has been asked for explicitly, and document the invocation
beside it. `database/manual/README.md` is the worked example, and the go-live
cleanup that prompted the rule sat in the automatic path for two days.

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

**Holding a module alive is also the only way its rows go wrong.** A module's
data is a snapshot of whenever it was last looked at, and the module that
invalidates it is usually a different one — post a sale and Stock's shelf is a
sale out of date, with nothing on screen saying so. Before
[data-bus.js](resources/js/data-bus.js) the only cure was reloading the page,
which §3.2 rules out, and one stale figure survived even that: the bill form's
"4 PCS on hand" is captured when a line is picked and rides in the autosaved
draft, so a reload restored the same wrong number for up to a week.

Three decisions in it are load-bearing. **The announcement is made in
`auth-client`**, from one table of paths, because the failure being fixed is a
*missed* invalidation and a convention that every write site remembers to call
`announce()` fails in exactly that way — silently, one site at a time. **Nothing
in the bus fetches**: it marks screens stale, and the refetch waits for
`module:shown` or the next Show, or a write in one module would be loading
another module's data (§7.2). And the table is **generous on purpose** — a
receipt moves no stock but is listed under `transactions` all the same, because
a false positive costs one refetch and a false negative is a workshop selling
stock it does not have. The one thing it must never treat as a write is a
**read-only POST**: `transactions/preview` runs on a debounce as somebody types,
and announcing on it refetched every line's position every few keystrokes.

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

**A variant carries two levels, and the ladder is one list.** `reorder_level` is
when to order and `min_stock` is when to stop what you are doing and go and get
some — a shop orders at 20 and panics at 5. At or below the trigger is `is_low`;
**strictly** under the floor is `is_below_minimum`; both are the server's
verdicts and a row carries both, never one status a screen has to unpick. Worst
wins, in one order — `negative → out → below_minimum → low → in_stock` — decided
in that same file for a family (`positionStatus`) and for a single row
(`statusOfRow`). Never rank them inline at a caller: it had been done at two, and
both put `low` ahead of `out`, so a variant sitting at nought reported *Low
stock* underneath a family row that said *Out of stock* about the same shelf.

**Changing a position is one form, and opening stock is an input rather than a
column.** Nothing writes a quantity directly — not the Items screen, not Stock,
not the create form — and
[components/stock-adjust.js](resources/js/components/stock-adjust.js) with
[partials/stock-adjust.blade.php](resources/views/partials/stock-adjust.blade.php)
is the whole of asking a person to change one. Stock mounts it in `count` mode
and types the difference; Items mounts it in `variant` mode against one variant
and types **what is on the shelf**, and the component subtracts the position to
get the difference. Both post `POST /transactions/stock-adjustment` like any
other document. A host supplies a mode, what the books currently say, and what to
do afterwards; it must never fork the file.

The two directions are the reason it is one component. "Two fewer than the books
say" and "two on the shelf" are different figures that post different documents,
and the conversion between them is arithmetic on a quantity — done in integer
thousandths, because `12.3 - 4.1` is not `8.2` in a float and `decimal:0,3`
refuses what comes out. Two copies would be two places the sign, `post: true` and
the `client_ref` are decided, and the sign is the whole meaning of the document.

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
Sales and Jobs all mount it, and it is the only way a bill gets written. A host
supplies a direction, a draft key and what to do after a post; it must never fork
the file. The direction decides the endpoint, the party's role, and
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

**A rate can be quoted with the GST already in it, and the line remembers
which.** A counter prices both ways — "ten thousand plus tax" for a rewind,
"eleven eight" for a part with the figure on the box — so a bill line carries a
toggle beside its rate, prefilled from `items.price_includes_tax` and flippable
per line. `GstRate::baseWithin()` divides by one-and-the-rate in integer paise
and `GstBreakdown::within()` takes the tax as **what is left over**, never as a
second multiplication: two roundings do not reliably land back on the figure
somebody typed, and a customer handing over a hundred-rupee note for a
hundred-rupee price is the whole point of the mode. Add a reader, never a second
copy (§4.4).

This was never only a convenience. **Stock arrives at the taxable value**, and
that arrival recomputes the weighted average — so a supplier's MRP-inclusive
rate entered as exclusive carried the shelf inflated by the whole rate,
permanently, with every later margin wrong and nothing on any screen saying so.

Three parts of it are load-bearing. The **line keeps its own copy** in
`transaction_lines.price_includes_tax`, because after posting it is
unrecoverable: ₹100 plus tax and ₹118 inclusive are the same taxable value, the
same split and the same total, and only `unit_price` differs. `ReturnService`
pins it exactly as it pins the rate and the intra/inter-state shape, or a credit
note refunds tax that was never charged and the pair fails to net out on the
return that reports both. A line that **sends no flag takes the item's default**
rather than `false`, so a caller that predates the toggle is not silently charged
tax on top of a price that already had it. And **the customer's invoice prints
the rate before tax** whichever way it was typed — a tax invoice's rate column
sits beside a taxable value and has to be the same kind of figure, or the
recipient's evidence for an input tax credit is a row that does not multiply out.
There is deliberately no document-level switch and no category default: one bill
routinely carries a printed-price part and labour quoted before tax. See
[inclusive-pricing.md](docs/inclusive-pricing.md).

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

**There is exactly one sheet in the shell, and a screen that shows it borrows
it.** A posted sale or a posted workshop bill lands on `#invoice-preview` — the
customer's copy, level 2 over the emptied form, with Print and Share — and that
drawer renders no invoice markup:
[components/invoice-delivery.js](resources/js/components/invoice-delivery.js)
moves the one `[data-invoice-document]` node out of `#invoice-print` and hands it
back on Print, on close, and on `beforeprint` (plus the `matchMedia('print')`
change, which is what Safari has instead of those events). Never mount a second
copy of the partial in the shell. The print rule
keeps whichever child of `body` *contains* the document, so a second one under
`<main>` makes `<main>` worth keeping and every print after that carries the whole
application around the invoice — with nothing on screen saying so. Both hosts are
**direct children of `<body>`** for the same reason the sheet always was, and the
preview additionally because the shell caches a module's root detached: declared
inside a module it would take the document off the page when that module closed.
The next module to hand a customer a document borrows this drawer; it does not
build a second one.

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

**What is left — two modules, both of them built.** **Items**, **Stock**,
**Purchase**, **Sales**, **Vendors**, **Customers**, **Users**, **Roles**,
**Staff**, **Insights**, **Settings** (`workspace`), **Opening balances**
(`opening`), **Expenses** (`bills`), **Transactions** (`journal`), **Jobs**,
**Accounting** (`accounts`) and **History** (`audit`) have been converted and are
on. The other two — **Uploads** and **Workshops** (`tenants`) — are
`'enabled' => false`.

Be clear about what that means, because it is the most misread fact in this
repository: **none of them is unfinished work.** Each has a complete backend, a
complete `pages/*.js`, a fragment view in `resources/views/modules/{key}.blade.php`
and feature tests. They are off for one reason only — they still open on a list
with a modal create instead of the §2A flow. **Their APIs answer normally**; it
is the card and the fragment route that are shut, so this is a reachability gap
in the UI and never a security boundary. What that costs a workshop today — no
photographed bill to keep, no way to provision a workshop, no audit trail — is
set out module by module in
[hidden-modules.md](docs/hidden-modules.md). **Read it before converting one**:
some of them have had part of their job taken over by a card that is already
on, and converting the whole of the old screen would rebuild what Sales,
Purchase and Insights already do (§5.1).

Coverage for a module that is off stays in `PagesRenderTest`, rendered with
`$this->view()` rather than fetched, because its fragment route answers 404 while
it is off. Once it is on, switch that coverage to `$this->get('/modules/{key}')`,
which asserts the route as well as the markup.

Convert one module at a time, then flip its `enabled` flag. Do not add a page
route, do not reintroduce a sidebar, and do not regress what already conforms.

**The card grid is settled, and the conversion is scheduled.** It is not a
staging post and there is no second navigation coming: every remaining module
lands on it. The order was **go-live first**, because with Settings and Opening
balances both off a real workshop could not start using this product at all, and
with Bills off its P&L has no overheads:

```
C1  Settings + Opening balances ✅ C5  Accounting + Ledger, merged  ✅
C2  Bills → expenses only       ✅ C6  Uploads                      ←
C3  Transactions                ✅ C7  Workshops   (History ✅)
C4  Jobs                        ✅ C8  One workshop-day test
```

Each step's shape, its *do not rebuild* list and its checklist are Part E of
[implementation-roadmap.md](docs/implementation-roadmap.md), which is now the one
plan for the product — `modified-flow-plan.md` is a historical record and
`hidden-modules.md` is the standing account of what is unreachable.

The **order** the rest of it runs in is
[execution-plan.md](docs/execution-plan.md): C6, then C7 — Workshops alone, now
that History has gone on — then C8 moved up to sit immediately after it, then
seven open points (P1–P7), of which **P2 is done** — the party
statement that no screen calls, the advance receipt, the parked-draft worklist,
five endpoints nothing reaches, and password reset with invitation and mail. **M15, the AI capture agent, is parked** as of 7 September 2026 and is
outside that plan; nothing in it waits on the agent. Four steps
also *finished* something rather than only re-flowing it: C1 shipped the three
workshop settings the API accepted and no screen offered, C3 shipped the screen
for allocating a receipt after it was taken, C4 shipped a job's edit, its
delete, and the half of its bill the endpoint had been throwing away, and C5
shipped the only way a workshop can add an account to its own chart.

**C3 is done, and it is the module that stopped parking work.** **Transactions**
— key `journal` — is Receipt, Payment and the journal voucher, three §2A
workspaces under one card on the **Staff** shape: one root each, mounted lazily
on the first click of a tab, each workspace registering Escape under its own key
while the module registers `journal`. It opens on Receipt, which is the one done
most. The four tabs of transaction list it used to carry are **gone rather than
moved** — Sales, Purchase, Expenses and the Day Book already draw all of them
(§5.1).

Receipt and Payment are **one implementation rendered twice**: one
[partials/settlement-section.blade.php](resources/views/partials/settlement-section.blade.php)
included with a `$direction`, and one factory in
[pages/journal.js](resources/js/pages/journal.js) called twice, each call closing
over its own state — `pages/counterparty.js`'s rule, for the reason that file
records. The direction decides the endpoint, the party's role and the wording and
nothing else, because the server does not either: `/transactions/receipt` and
`/transactions/payment` are two routes over one `StoreSettlementRequest`.

**Which invoice a cheque was for is answered after the fact, in the drawer, and
nothing guesses it.** That closes M16: `GET /transactions/{id}/open-bills` and
`POST /transactions/{id}/allocate` had been built and tested with no caller
anywhere. The panel is drawn from **two lists merged**, and this is the part that
is wrong in a way that looks right — `due` on an open bill is net of every
allocation *including this settlement's own*, so a bill the receipt has already
paid off in full is not open any more and is missing from the picker unless the
allocations already on the settlement are merged in beside it. `openBills` now
carries them in its meta. A row's ceiling is what is still owing plus what this
settlement is holding against it, and an allocation **replaces** the whole set,
so what is sent is every row with an amount on it and not only the ones touched.

A receipt naming no bills is applied oldest first, which is right far more often
than not and is still a decision — so the form **states what it did** in a line
above the cleared fields, with a control that opens the drawer to change it.
§2A.8 clears the form the instant it posts, and a toast is gone before somebody
has read the amount.

**No settlement and no journal voucher can be parked.** Money that has moved is
a fact, not a work in progress — the judgement M22 already makes about a payroll
run — and a parked receipt is a cash box that disagrees with the books for as
long as nobody authorises it. A draft that predates the conversion is still
openable and can be posted or discarded from the drawer; this module creates
none, and neither does a workshop bill.

That is narrower than it was first written down. This paragraph claimed there was
no "save as draft" left anywhere in the product, and there is: the shared bill
document still offers it, so **Sales and Purchase can still park a bill**.
Insights' parked-draft worklist is therefore not yet a set that only shrinks.
Removing the control from those two is a product decision nobody has taken;
saying it had already happened was a documentation error.

One refusal is deliberate and worth knowing before somebody "fixes" it: a
settlement **cannot be left unallocated while the party has open bills**. An
empty `allocations` array means "oldest first" to the server, not "allocate
nothing", on the way in as well as afterwards — so the drawer refuses to send a
cleared grid rather than silently doing the opposite of what it looks like.

C3 also gave [components/party-picker.js](resources/js/components/party-picker.js)
two things it had never needed. Its ids are **unique per mount**, because this is
the first module with more than one picker attached at once — a tab swap hides a
section rather than detaching it, and fixed ids meant a `<label for>` pointing
into another section. And it takes **`role: null`** for the voucher's optional
counterparty, which paints no position line and offers no "+ Add": `outstanding`
has a receivable half and a payable half and nothing would say which to read, and
quick-party never asks which role a record is — the module it was opened from
decides, and a journal has not decided.

**C2 is done, and what it deleted matters more than what it built.** The Bills
module was the whole transaction list; it is now **Expenses** and nothing else,
because Sales lists invoices and credit notes, Purchase lists bills and debit
notes, and Insights' Day Book lists every posted document — a fourth copy would
have been three screens answering one question (§5.1). The key stays `bills`,
which is the module's address; only the label changed. Two smaller things it
settled: a **listing does not load ledger entries**, so the expense account is a
server-side `account_id` filter and never a column — reach for that pattern
before widening a repository's eager loads; and the expense form now sends a
**`client_ref`** minted per document and reused on every retry, which every write
form in this application should, or a request that times out after the server has
posted puts the same document in the books twice.

**C1 is done, and two of its decisions bind what comes next.** A module with a
**single record** — Settings is the only one so far — declares **only
`data-ws-list`** and mounts `canCreate: false`, so the workspace lands on it and
paints no switch control. Do not add a single-surface mode to
[workspace.js](resources/js/workspace.js) for the next one. And a module whose
**context belongs beside the create form** puts it there: Opening balances keeps
the owner's stake, the go-live date and the trial balance on the *form*, because
they are what somebody about to declare their whole financial history reads
before they commit and what they want to see the instant they have. That is also
why it subscribes to the bus with `onChange` rather than `refreshOn` — `refreshOn`
refreshes a list when the list is next shown, and tiles on the form would stay
wrong. `/workspace` announces `ledger` for the same reason: the financial year,
the timezone and `books_start_date` define the period every statement is measured
over, so saving that screen makes a held report wrong without a figure having
moved.

**C4 is done, and there is no page shell left.** **Jobs** is the bench — receive
a motor, move it along, write parts onto it, quote, get the quotation approved,
bill it — and it is the workshop's actual trade, which nobody could reach at all
until now. The create form takes a motor in, the bench is behind "Show list", and
a job opens in a drawer carrying the pipeline, the parts, the estimate and
Generate bill.

**The bill is a level-1 pane on the create surface, not a state of the drawer.**
The shared document is a two-column form with a searched item picker, a line
table, a payment split and a sticky totals panel, and §2.1 calls exactly that
inside a dialog a scroll trap. So the form surface holds two panes — booking a
motor in, and billing one — with one shown at a time, which is §2A.2's judgement
applied one level down.

**The lines are sent only when the operator changed them, and this is the part
that is wrong in a way that looks right.** `POST {job}/bill` pairs each part with
the invoice line it became **by position**, and that only holds while the lines
are the ones `billPayloadFor()` produced — so `JobService::bill()` marks nothing
at all when the payload carries an `items` key, which the shared document always
builds. The counter did exactly that, which means **no bill ever raised in this
product had marked a part as billed**: the same bearings were billable again the
week after. `pages/jobs.js` fingerprints the lines when they load, as scaled
integers rather than parsed floats, and omits `items` while they are untouched.
Where a rate really was argued down the lines are sent, the invoice posts, and
the parts stay on the job card — the service's deliberate safe way to be wrong,
and the banner says so before anybody presses post.

The endpoint also had to learn the rest of the document. `BillJobRequest` named
six keys per line and nothing else, so a line's inclusive-of-tax flag, a
percentage line discount, a discount on the whole repair and **who did the work**
were all being dropped on the floor. The last is the one that matters: a rewind
is the canonical case for M22's attribution, and it was guaranteed to be lost on
exactly the document it belongs on. `WorkshopJobController::bill()` now syncs the
attribution inside the same transaction as the posting, as
`TransactionController::store()` does.

Two more endpoints had no caller anywhere and now do: `PATCH` and `DELETE` on a
job. Correcting the card is a *state* of the drawer with the create form adopted
into it (`adoptForm()`, so the fields exist once); the customer and the received
date are inline-only, because `UpdateJobRequest` accepts neither.

**And the counter is gone.** `/bills/new` was the last page shell in the
application, kept alive through C2 only because it was the one screen that could
raise a workshop bill. The route, `resources/views/bills/new.blade.php` and
`pages/bill-counter.js` are deleted, and the two links that pointed at it —
"Create sale" on Customers, "Create purchase bill" on Vendors — open the Sales
and Purchase cards in the mounted shell with `?party=`, which is a module swap
and not a document load (§1.1).

**And the Jobs card hands the customer their invoice, which is P2.**
`#invoice-preview` was Sales' drawer and about four hundred lines of
`pages/sales.js`; it is
[components/invoice-delivery.js](resources/js/components/invoice-delivery.js)
now — the delivery state, custody of the one sheet, print, the share link, the
WhatsApp message and revocation — and both modules mount it. A posted job bill
lands on the same preview a sale does, the line above the cleared form offers
**Print or share it**, and every invoice ever raised off a repair reopens from a
row on the job card. The share dialog moved with it, from the Sales fragment to
`partials/invoice-share-modal.blade.php` in the layout, because the shell caches
a module's root detached and a dialog inside Sales is not in the page while Jobs
is open. `#confirm-modal` is `z-index: 60` for the same reason it is level 3:
below the z-55 share dialog it was asking "Stop sharing this invoice?" from
underneath the panel that asked it.

**A job's status is about the motor; whether it has been billed is a second
signal.** `WorkshopJobStatus::Delivered` already says the two do not imply each
other — a regular customer's pump goes home on Friday against an invoice raised
at the end of the month — so billing is never folded into the pipeline.
[JobBillingState](app/Enums/JobBillingState.php) is the other half, derived from
the invoices that point at the job and the parts that point at their lines, never
stored: reversing a bill moves it with nothing having to remember. A row reads
*In progress · Invoiced*, and neither half is a lie. And **Generate bill is
disabled with the reason on it** rather than left out, which is what it was — a
job that had had nothing done to it, or one already billed in full, simply had no
button and nothing said why.

**History went on ahead of C7, and it is the read-mostly rule at its strictest.**
**History** — key `audit` — is one filtered list and nothing else: no create, no
drawer, no detail modal, because an entry *is* its detail. It mounts with
`canCreate: false` and declares only `data-ws-list`, so the workspace lands on the
table and paints no switch control — and here that is not a permission decision
somebody could widen later, because there is no POST, PATCH or DELETE anywhere in
its API group and there cannot be. Entries arrive through model events and the
model refuses an UPDATE and a DELETE.

Two things the conversion changed, and the first is wrong in a way that looks
right. The filter option lists are **rebuilt rather than appended to**: `refreshOn`
brings the module back for another `loadMeta()` whenever something it is a copy of
was written, and appending would offer every kind, action and person a second
time — then a third. The current choice is put back afterwards, or a refresh would
silently widen the filter somebody is reading through. And `refreshOn` names
`items`, `parties`, `staff`, `ledger` and `transactions`, but **not `stock`**: a
stock movement is a posted document's consequence, and a posted document has no
entry on this trail at all.

**C5 is done, and it is the only step that removed a key.** **Accounting** —
key `accounts` — now carries what **Ledger** used to. They were one question at
two zoom levels, and the old code said so itself: this drawer showed ten entries
because "the full statement is the Ledger screen's job". Two cards would both
have answered "what does this account stand at", with two period pickers and two
trial-balance renderers between them (§5.1). So `ledger` is **gone from
[config/modules.php](config/modules.php)** — do not put it back — and its
redirect is registered by hand in [web.php](routes/web.php), because the loop
there declares one per module the registry still names.

It opens on the **account form**, which is the only way in the whole product to
add an account to a chart: `POST /accounts` has exactly one caller and it is
[pages/accounts.js](resources/js/pages/accounts.js). Behind "Show list" are
**two views over one period picker** — the chart, grouped by type, and the trial
balance with its reconciliation *stated* rather than left to be inferred from two
columns. A row on either opens the same drawer, and the edit is a **state** of
that drawer with the create form adopted into it, as Jobs does.

Three of its decisions are load-bearing, and each is wrong in a way that looks
right. **The search box and the archived select narrow the chart and are hidden
on the trial balance**: a trial balance's totals come from the server over every
account with movement, archived ones included, so filtering its rows in the
browser stops the columns adding up to the figures beneath them. **The chart is
fetched whole, with `is_active` deliberately unsent** — an archived account still
owns its code, and asking only for the active ones let the form's next-free-code
suggestion offer a number the server then refused, a 422 on a field the screen
had filled in itself. And **the second grant removes rather than blanks**: the
card is `READ:ACCOUNTS`, every figure on it is `READ:LEDGER`, and without the
second the balance column, the period picker and the whole trial-balance view are
taken out of the DOM — a column of dashes is a claim about the books, not about
the reader.

What it **deleted** matters as much: the Journal Entries tab, its drawer and its
`READ:TRANSACTIONS` gating are gone rather than moved, because every row of that
list is on Insights' Day Book. The per-row action menu went with it — a row opens
the drawer and the drawer holds the actions, which is what every other converted
module does (§7.4). See [accounting-module.md](docs/accounting-module.md) and
[ledger-module.md](docs/ledger-module.md).

**Signing in is a passkey first and a password second, and the asymmetry is
deliberate.** A passkey — fingerprint, face or screen lock — is checked by
[PasskeyService.php](app/Services/Auth/PasskeyService.php), which does the two
WebAuthn ceremonies and nothing else. Everything about *starting a session*
stays in `AuthService::startSession()`, which both ways in call: a second way in
that skipped it would leave a suspended employee, or a workshop whose account
was closed, still posting entries with the key they enrolled while they worked
there. Add a third way in by calling it, never by copying it (§4.4).

Three things in it are load-bearing and each is wrong in a way that looks right.
The **origin** is the phishing resistance — the browser signs the origin it is
actually on, and `config('webauthn.origins')` is what refuses a look-alike
domain — so that list is exact and never a pattern. The assertion is verified
with a **null user handle**, which is the strict reading and not the lazy one:
null means "nobody was identified before this began", so the library *requires*
the device's own handle and compares it, where passing one would be comparing
the stored record with itself and calling it a check. And `recordFor()`
**re-applies the live `sign_count`** onto the deserialized credential, because
the serialized blob's counter is frozen at zero from enrolment — without it the
clone check compares every assertion against zero, passes all of them, and
nothing anywhere looks broken.

**A long session is something a passkey earns.** `refresh_tokens.trusted` buys
90 days instead of 7, and only a passkey sets it: it is bound to one device,
re-verified at every use and revocable per device, where a password is a secret
that can be watched or written inside a cupboard door. So there is **no "keep me
signed in" box** — a box lets somebody trade the whole safety margin for one
fewer tap on the screen least likely to be read carefully. Rotation **inherits**
the flag rather than re-deciding it, or every trusted session would quietly
revert at its first refresh and sign people out a week later with nothing to say
why.

Enrolment is behind `auth.jwt` and signing in is not, and that split is the
design: a device registered from the sign-in screen would be a way in that
anybody who reached that screen could grant themselves. Every refusal answers
`PASSKEY_REJECTED` with one message for every cause, for the reason the password
path answers one way for an unknown email and a wrong password. The password is
**not** being removed — it is the first sign-in on a new device and the recovery
when every passkey is gone, and there is deliberately no second recovery path,
because every one of those is a way in for somebody else too. See
[passkeys.md](docs/passkeys.md).

**The public site is not the application, and §1 does not reach it.** `/`, `/hi`
and `/services/{slug}` are the shop's own site — outside the sign-in, outside the
module shell, its own stylesheet and its own page module. Those routes are the
"public pages" §1.5 allows, and they are ordinary server-rendered pages on
purpose: a marketing page that needs JavaScript to show its content is a
marketing page a search engine cannot read.

Two things in it are decisions rather than markup, and both are enforced by
`tests/Feature/Site`. **A figure nobody has confirmed is not printed** —
`config/shop.php` carries a `verified` flag beside each one and
`Site::stats()` drops the unverified along with its label, because the page this
replaced invented "32+ years" and three testimonials, and a workshop caught
inventing its own numbers has nothing left to be believed about. The same gate
withholds the e-mail address, the makes on the counter and the reviews section.
And **the two languages are one site**: facts live in `config/shop.php`, words in
`lang/{en,hi}/site.php` key for key, and a key present in one and missing from
the other fails the build rather than rendering `site.faq.title` into a heading.

The language is in the **path** — `/` and `/hi` — never a cookie, so each has a
real URL to be shared on WhatsApp and indexed under. Templates read
`App\Support\Site` and never `config()` or `__()` directly. See
[public-site.md](docs/public-site.md).

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

**A row in it is a trade, so anything counted off it counts documents, not
rows.** Somebody who fitted a motor and wound it is two rows on one invoice, and
the throughput figures counted the rows — which made a two-trade person read as
twice as productive as somebody doing identical work and added the same invoice
into their value twice. De-duplicate the rows, never the aggregate: a
`sum(distinct total)` collapses two invoices that come to the same amount, which
on a counter charging ₹500 for a service happens daily. And an invoice naming a
fitter *and* a winder is whole in **both** their rows, so a per-person column is
read across and never summed — the workshop's own total belongs beside it, which
is what `/insights/people`'s `work` block is for.

**Sales deliberately has no quotation, no delivery challan, no recurring invoice
and no e-invoice.** A quotation and a challan each want their own numbering and
their own lifecycle, and a challan moves goods without billing them — a second
writer to `stock_movements`, which is the objection CLAUDE.md already records
against goods-received notes.

**Purchase deliberately has no purchase order, no goods-received note and no
landed cost.** Each touches when stock moves or what it is valued at, and half
of one is worse than none. See [purchase-module.md](docs/purchase-module.md).
