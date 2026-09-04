# Implementation Plan — "Modified Flow" (workshop POS redesign)

Plan for `docs/modified flow.md`. Written after auditing the existing code, so it
says what already exists, what is missing, and what has to change — rather than
restating the brief.

---

## Part 0 — What the audit found

The backend is in far better shape than the brief assumes. M1–M14 of
`docs/implementation-roadmap.md` are complete: double-entry ledger, posting
templates, items + variants with per-type attribute schemas, a stock ledger with
weighted average cost, a GST-correct bill engine, drafts, reversal, parties,
audit log, attachments. **Most of sections 3, 6, 7, 8, 19, 20, 21, 29 and 33 of
the brief are already implemented in the backend.** The gap is concentrated in
four places: per-invoice money, workshop jobs, returns, and the entire front end.

### Already built — do not rebuild

| Brief section | Where it lives today |
| --- | --- |
| §3 unified billing (sale/purchase/combined) | `TransactionType::Sale` covers goods + labour on one document; `BillTemplate`, `SaleTemplate`, `PurchaseTemplate` |
| §6 variants | `items` / `item_variants`, `ItemType::attributeSchema()` — HP/phase/RPM per type |
| §7 units | `UnitOfMeasure`, `items.base_uom`, unit copied onto every `transaction_lines` row |
| §8 automatic inventory | `stock_movements` written by `PostingEngine` inside the same DB transaction; no `qty_on_hand` column anywhere |
| §9 inventory status | `StockLedgerService::report()` already returns `low`, `negative`, `out_of_stock` totals and filters |
| §11 drafts | `transactions.status = draft`, `draft_payload`, `draft_lines`, `draft_payments`; drafts write no journal entries and no movements |
| §13 payment modes + partial | `PaymentMode` (cash/bank/upi/cheque), `transaction_payments`, a bill may carry a part-payment |
| §14/§15 ledgers | `PartyLedgerService`, `GET /parties/{id}/ledger` |
| §20 cancellation | `PostingEngine::reverse()` — mirrors entries **and** stock movements at original value |
| §21 inventory ledger | `stock_movements` + `GET /stock/variants/{variant}` (stock card) |
| §29 atomicity | Every posting path is wrapped in `DB::transaction`, with CHECK constraints restating the rules at the database |
| §37 performance | Server-side `search`, pagination and indexes already exist on parties, items and stock |

### Genuinely missing — the work

| # | Gap | Brief |
| --- | --- | --- |
| G1 | No document number. `transactions` has an id and nothing else — there is no `INV-1001` | §14, §23 |
| G2 | No per-invoice paid/due. A receipt carries a `party_id` but is not linked to the bill it settles, so "INV-1012 — Partial" cannot be computed | §13, §14, §15, §23 |
| G3 | Negative stock is only warned about, never refused, and there is no setting | §10 |
| G4 | No returns. Only whole-document reversal exists; "customer returns 1 of 4 bearings" has no path | §8, §20, scenarios 5–6 |
| G5 | No workshop job, no motor details, no job status, no estimate → job → bill | §16, §17, §18 |
| G6 | No duplicate-submit protection of any kind | §28, scenario 8 |
| G7 | Dashboard is hard-coded placeholder arrays in `DashboardController` | §22, §24 |
| G8 | Billing UI: modal form that loads `?per_page=200` of every party and every item into `<select>`s (`bills.js:83`), no search, no stock/unit shown on the picker, no quick-add customer (quick-add item exists), no confirmation summary, no autosave, no keyboard flow | §2, §4, §5, §12, §25, §26 |
| G9 | Bills list has no Total/Paid/Due/Status columns — it cannot, until G2 | §23 |

---

## Part 1 — Decisions this plan makes

Stated up front because they shape everything below. Each follows the grain of
the existing architecture rather than cutting across it.

**D1 — Workshop jobs are a new module, not a repurposed draft.**
A job exists before any money does: a motor is received, inspected, worked on,
and only then billed. Modelling it as a draft sale would mean a document with a
customer and no items sitting in the books' draft queue for a fortnight. New
tables `jobs` and `job_parts`; the bill is generated *from* the job and carries
`job_id`.

**D2 — Parts are issued from stock when the bill posts, not when they are added
to the job.** One stock movement per part, written by the existing posting
engine. Adding a part to a job reserves nothing and moves nothing — it is a note
about what will be billed. This keeps the invariant that stock only moves through
a posted transaction, which is what the whole inventory module rests on.

**D3 — An estimate is a job field, not a transaction.** `jobs.estimate_lines`
(JSON, same shape as a bill's items) plus an approval timestamp. Converting to a
bill copies the lines into the bill form. An estimate that posted journal entries
would be claiming revenue nobody has agreed to.

**D4 — Per-invoice settlement gets its own table, `transaction_allocations`.**
`(settlement_transaction_id, bill_transaction_id, amount)`. Paid = `SUM(amount)`
over allocations plus the bill's own at-counter payments; due = total − paid.
Nothing is stored on the bill — same rule as party outstanding and stock on hand.

**D5 — Returns are new transaction types with their own template**,
`sales_return` and `purchase_return`, each carrying `against_transaction_id`.
They reuse `BillTemplate`'s GST arithmetic with the signs inverted, and value the
returned stock at the *original* movement's cost, exactly as `reverse()` does.
Whole-document cancel stays on `reverse()`.

**D6 — Negative stock is refused by default**, with a per-tenant
`allow_negative_stock` setting to permit it. This reverses the current documented
decision ("both post" — `docs/billing-module.md` §Warnings, not refusals), so it
is a deliberate change and the module doc must be updated with it.

**D7 — Duplicate protection is a client-generated `client_ref` UUID**, unique per
tenant on `transactions`. Simpler than an idempotency-key table and it survives a
retry after a timeout: the second POST returns the first transaction with 200
instead of creating a second invoice.

**D8 — The billing screen becomes a full page, not a modal.** `/bills` stays as
the list; `/bills/new` is a dedicated counter screen. A modal cannot host a
search-first item picker, a running total, a keyboard flow and a confirmation
step without becoming a scroll trap.

---

## Part 2 — Phases

Ordered so each phase leaves the app working, and so the UI phase builds on
finished endpoints rather than racing them.

### M16 · Money you can see per invoice
*Unblocks the bills list, both ledgers, and the dashboard. Backend only.*

- Migration: `transactions.doc_no` (string, nullable, unique per tenant) and a
  `document_sequences` table `(tenant_id, series, financial_year, next)`, taken
  under `SELECT … FOR UPDATE` inside the posting transaction. Assigned **at
  posting only** — a draft has no number, because a number that could be
  discarded is a gap somebody has to explain to an auditor.
- Migration: `transaction_allocations`, with a CHECK that `amount > 0` and a
  unique `(settlement_transaction_id, bill_transaction_id)`.
- `SettlementService`: allocate a receipt/payment across a party's open bills,
  oldest first by default, with an explicit per-bill split accepted on the
  request. Refuses over-allocation (§27: "payment greater than invoice amount").
- `BillService::settlementFor(Transaction)` → `{total, paid, due, status}` where
  status ∈ paid | partial | unpaid | overdue, derived, never stored.
- Extend `TransactionResource` and `GET /transactions` with `doc_no`, `paid`,
  `due`, `payment_status`; add a `payment_status` filter and an
  `outstanding=1` filter.
- `GET /parties/{party}/statement` — the §14/§15 ledger shape: totals plus a
  per-invoice list.
- Tests: allocation arithmetic, over-allocation refusal, oldest-first ordering,
  number sequence uniqueness under concurrency, no number on a draft.

### M17 · Stock discipline and duplicate protection
*Small, high-value, and blocks the "make it POS-like" claim.*

- Migration: `tenants.allow_negative_stock` (boolean, default false); expose it
  on `GET/PATCH /workspace` and on the Workspace screen.
- `StockLedgerService::assertCanIssue()` — refuses an issue that takes a variant
  below zero unless the setting is on. Message in the brief's words: *"Only 5 PCS
  available in stock."* Wired into `PostingEngine` before composition, so a
  refusal never half-writes.
- Migration: `transactions.client_ref` + unique `(tenant_id, client_ref)`.
  `TransactionService::create()` returns the existing transaction on a repeat
  rather than throwing.
- `POST /transactions/preview` — prices a bill payload without posting it: line
  totals, GST split, grand total, and any stock refusals. This is what the §12
  confirmation screen renders, and it means the confirmation shows the server's
  numbers rather than the browser's.
- Update `docs/billing-module.md` and `docs/inventory-module.md` with D6.
- Tests: scenarios 8 and 9 from §36; setting on and off; preview matches posted.

### M18 · Returns
- `TransactionType::SalesReturn` / `PurchaseReturn`, `movesStock()` true,
  `hasDocumentLines()` true, party roles mirrored from sale/purchase.
- Migration: `transactions.against_transaction_id`.
- `ReturnTemplate`: revenue and GST reversed on the returned quantities only;
  stock valued from the original bill's `stock_movements` via
  `transaction_line_id` (the join the schema was already built for).
- `POST /transactions/{transaction}/return` taking `{lines: [{line_no, quantity}]}`;
  refuses a quantity above what was billed, and above what remains after earlier
  returns.
- Tests: scenarios 5 and 6; partial return twice; over-return refused; a returned
  bill's paid/due recomputed correctly against M16.

### M19 · Workshop jobs — **shipped**
*The largest new surface, and the brief's §16–§18. See `docs/workshop-module.md`.*

- Migrations:
  - `workshop_jobs` — `tenant_id`, `job_no`, `party_id`, motor details
    (`item_id` nullable, `hp`, `brand`, `model`, `serial_no`, `phase`),
    `complaint`, `received_date`, `status`, `promised_date`, `delivered_at`,
    `notes`, `estimate_lines` JSON + `estimate_approved_at`.
  - `workshop_job_parts` — `workshop_job_id`, `item_id`, `variant_id`,
    `description`, `quantity`, `unit`, `unit_price`, `discount_amount`, and
    `transaction_line_id` nullable, set when billed.
  - `transactions.workshop_job_id` nullable, and write-once: it joined
    `opening_import_id` in `Transaction::STAMPABLE_ONCE_POSTED` rather than
    needing a new field on `PostingBatch`, so the posting engine was not touched.
- **Naming, corrected against the plan.** `jobs` is Laravel's queue table and
  `job_runs` is M14's; `/api/v1/jobs` is M14's polling endpoint and is in the
  wild. So the table is `workshop_jobs`, the model `WorkshopJob`, the permission
  `WORKSHOP_JOBS` and the API prefix `/workshop-jobs` — the same qualifier the
  plan had already accepted for `WorkshopJobStatus`, applied consistently. The
  *web* route stays `/jobs`, because nothing on that side routes the queue.
- `WorkshopJobStatus` enum: received → inspection → estimate → in_progress →
  ready → delivered, plus cancelled. Legal transitions declared on the enum, the
  way `TransactionStatus` already does it. Two deliberate exceptions to
  forward-only: cancel from anywhere unfinished, and ready → in_progress for a
  motor that failed its test run.
- `JobService`: create, advance status (refusing illegal jumps), add/remove
  parts, save/approve/apply the estimate, and `billPayloadFor(WorkshopJob)` → the
  exact payload `POST /transactions/sale` accepts, so billing a job reuses the
  bill engine whole and re-enters nothing. `bill()` wraps three writes in one
  database transaction — the sale, the job stamp, and each part's pointer at the
  line it became.
- Permissions: `WORKSHOP_JOBS` with all four actions, seeded and granted (OWNER
  all four, DATA_ENTRY the first three). `{job}/bill` additionally needs
  `WRITE:TRANSACTIONS`.
- Tests: `tests/Feature/Workshop/WorkshopJobTest.php` — 25 of them, including the
  §34 motor-repair walkthrough end to end; parts move stock exactly once, at
  billing; a cancelled job bills nothing (scenario 10); a job cannot be billed
  twice for the same part; M17's shortfall and M17's duplicate protection both
  reached through this path.

### M20 · The bill counter (front end) — **shipped**
*Where the brief's §2, §4, §5, §12, §25, §26, §27 are actually satisfied.*

- New page `/bills/new` (`resources/js/pages/bill-counter.js`), one screen:
  1. **Type** — Sale · Purchase · Workshop bill, three large buttons.
  2. **Party** — type-ahead against `GET /parties?search=` (debounced, server
     side — replaces the 200-row preload at `bills.js:83`), with **+ Add
     customer** opening a drawer that posts to `/parties` and selects the result.
     Same component for vendors.
  3. **Items** — one search box against `GET /stock?search=` so every result
     carries live quantity, unit, price and an IN STOCK / LOW / OUT badge.
     Enter selects, Tab moves to quantity, price is editable inline, a row is
     removed with one key. Services come from `/items?type=service`.
  4. **Totals** — discount and GST per line, running footer from
     `POST /transactions/preview`.
  5. **Payment** — mode chips, multiple rows, "paid in full" one-click.
  6. **Confirm & save** — the §12 summary, one primary button, disabled while in
     flight, `client_ref` generated once per bill and reused on retry.
- Autosave to `localStorage` on every change, restored on load with a "you have
  an unfinished bill" banner (§26). Cleared on a successful post.
- Extract the party type-ahead, the item picker, the payment rows and the totals
  footer into `resources/js/components/` — `journal.js`, `opening.js` and the
  job screen all want the same three.
- `/bills` list rewritten to the §23 columns: Invoice · Customer · Date · Items ·
  Total · Paid · Due · Status, with a payment-status filter.
- Keep `bills.js`'s quick-add-item flow — it is good and it already solves §5's
  "the item does not exist yet" case; move it into the shared picker.

**What shipped, and where it differs.** `resources/js/components/` now holds
`party-picker`, `item-picker`, `payment-rows`, `quick-item` and `badge`; the
quick-add markup moved to `resources/views/partials/quick-item-modal.blade.php`
so the counter, the bills list and the job card share one copy. The totals footer
is not a component — it is three lines of `bill-counter.js` rendering the
server's `POST /transactions/preview` response, and extracting a component that
only formats one payload would have been indirection for its own sake. The item
picker searches `/stock` **and** `/items?type=service` in parallel: services have
no shelf and would never appear in a stock report, and a picker that could not
offer labour is useless in a rewinding shop. The counter also grew a fourth
thing the plan did not anticipate — a **Workshop bill** tab, which loads a job
through `{job}/bill-preview` and posts through `{job}/bill` rather than
`/transactions/sale`, because only the job endpoint stamps the invoice and marks
the parts. Drafting is disabled while a job is loaded, for the same reason.

### M21 · Jobs screen, dashboard, consistency — **shipped**
- `/jobs` list (§23 columns: Job · Customer · Motor · Complaint · Status ·
  Amount · Date), a job detail screen with the status pipeline, parts, estimate
  and a **Generate bill** button that lands on the counter pre-filled.
- `GET /dashboard` — real figures replacing the placeholders in
  `DashboardController`: today's sales / purchases / service revenue, total
  outstanding, counts of customers/vendors/products, low and out-of-stock counts,
  pending and ready jobs. Built on existing services (`ReportService`,
  `StockLedgerService`, `PartyLedgerService`) plus M16's outstanding and M19's
  job counts.
- §24 quick actions wired to real destinations.
- §38 sweep: one status-badge helper, one currency formatter (`ui.js` has
  `formatMoney` already — use it everywhere), unit always rendered beside a
  quantity, one date format, plain-language validation messages mapped from API
  error codes (§27).

**What shipped.** `GET /api/v1/dashboard` (gated on `READ:LEDGER`) backed by
`DashboardService`; the web controller's five placeholder arrays are gone and
`dashboard.blade.php` is a shell like every other screen — which also closed a
quiet hole, since that shell is public and was rendering invented takings into
HTML anybody could fetch. `/jobs` is the list, the job card, the pipeline and
`Generate bill`, reusing the counter's own item picker so a part is chosen the
same way wherever it is chosen.

**What has since been undone, and why.** The dashboard endpoint is **gone** —
route, `Api\V1\DashboardController`, `DashboardService` and its test, along with
`WorkshopJobRepository::overdueOn()`, which existed only to feed it. The shell
migration made home the module card grid, and a card carries no figure; nothing
called the endpoint afterwards, and M23 (Insights) answers the same question over
a period the reader chooses. Two services answering "how is the business doing"
is the pair where one drifts (§4.4, §5.1). The placeholder arrays this bullet
removed are still gone and must stay gone: that shell is public. If the grid is
ever to carry a count, it comes from `/insights/*`.

`/jobs` is also **switched off** — the module is complete and tested, but it
still opens on a list with a modal create, so it waits for the §2A conversion
like the other nine. See [hidden-modules.md](hidden-modules.md).

The §38 sweep landed as `resources/js/components/badge.js`, and the drift it
found was real: a reversed transaction was rose on the accounts screen and
neutral on the journal, and a draft was two different ambers. The tone now comes
from the API — `PaymentStatus::tone()` and `WorkshopJobStatus::tone()` publish
the same four words — so the judgement about which states are alarming lives with
the enum that owns them. `stock.js`'s `trimQuantity` became the shared
`formatQuantity`, which is what puts the unit beside every quantity. The §27
error mapping is deliberately *not* a translation table in the browser: the
server's messages are already in plain language ("Only 5 PCS available in
stock."), and a second copy of the wording is a second thing to keep in step.

### M22 · Verification
- Feature tests for all ten §36 scenarios, as one `WorkshopFlowTest` that walks a
  real workshop day: purchase 10 bearings → sell 3 → job consuming 2 → cancel →
  return → partial payment → double submit → over-sell → combined bill.
- Invariant tests extended: stock value still equals the Inventory account after
  returns and job billing; every allocation sums to ≤ its bill's total.
- The §39 technical summary written up as `docs/workshop-flow.md`.

---

## Part 3 — Sequencing

M16 and M17 are independent and can run together; both are backend-only and both
unblock the UI. M18 needs M16 (a return changes what is due). M19 needs M17 (job
parts must be refusable when stock is short). M20 needs M16, M17 and M19's
payload builder. M21 needs everything.

Suggested order: **M16 + M17 → M18 → M19 → M20 → M21 → M22.**

**Status.** M16 through M21 are shipped, with two qualifications recorded
above: M21's dashboard half was deleted rather than kept, and M16 has no screen
for allocating a receipt after it was taken (`POST /transactions/{id}/allocate`
and `GET /transactions/{id}/open-bills` have no caller in the front end).

This phase plan's verification step is what remains. M22 here — the ten §36
scenarios as one `WorkshopFlowTest`, the extended invariant tests, and
`docs/workshop-flow.md` — is not the same M22 as the roadmap's Staff module;
this document's numbering ran ahead of the product's and the roadmap in
[implementation-roadmap.md](implementation-roadmap.md) is the one to trust.
Several of these scenarios are already covered where they landed rather than in
one place: 5 and 6 in `ReturnTest`, 8 and 9 in `StockDisciplineTest`, 10 and the
§34 walkthrough in `WorkshopJobTest`. The value of the outstanding work is the
*single* test that walks one workshop day through all of them together, which is
where an interaction between two modules would show up.

**What happened after this plan.** The whole front end was re-flowed to the SPA
shell and the §2A module workspace (CLAUDE.md §1–§2A), which is why several
screens this plan describes as shipped — `/jobs`, `/bills`, the accounting
screens — are built, tested and currently switched off. That is a UI conversion
backlog, not unfinished work from this plan; it is tracked in
[hidden-modules.md](hidden-modules.md).

## Part 4 — Risks

- **M16's numbering under concurrency** is the one piece that is subtly hard. It
  must be a locked row in the same DB transaction as the posting, not a `MAX()+1`.
- **D6 reverses a documented decision.** If a workshop routinely bills stock
  before entering the purchase, refusing will be experienced as the product
  breaking. The setting is the escape hatch; it should default to *off* for new
  tenants and be set *on* for any tenant with existing negative positions at
  migration time.
- **M19 is the only phase that adds a new domain.** Everything else extends
  something that exists. If time is short, M16 + M17 + M20 alone deliver most of
  the brief's felt improvement.
- **Scope not covered here:** M15 (AI capture) is untouched, and the brief's §35
  is satisfied by construction — every phase above is API-first with the UI last.
