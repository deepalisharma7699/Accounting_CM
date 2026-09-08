# Implementation Roadmap

The one plan for this product: what is built, what is switched off, what is being
converted now, and what has not been started. It absorbs the two plans that ran
before it. [modified-flow-plan.md](modified-flow-plan.md) is kept as the
historical record of M16–M23's decisions (D1–D8) and is no longer a status
document; [hidden-modules.md](hidden-modules.md) is the per-module brief for the
conversion Part E schedules, and is the file to read before converting one.

**How to use this document:** every module and every conversion step has a *Test
checklist* — the things that must be true before it is considered done. Work one
at a time, tick its checklist, then move on. Nothing later depends on a module
whose checklist is incomplete.

**The order the rest of it is being built in is
[execution-plan.md](execution-plan.md)** — the nine steps that finish this
product with the AI agent excepted, the gate after each, what the pre-flight
sweep across every endpoint turned up, and the two things it needs from the
owner. This file stays the specification for *what* each step is; that one is the
sequence, and where the two disagreed about order, it won.

---

## The shape of the work

The product was built in three passes, and the third is why half of it is
currently invisible.

1. **M1–M15 — the accounting product.** Planned in this document and built in
   strict dependency order. M1–M14 are complete and tested. M15, the AI capture
   agent the PRD called the heart of the product, was never started — deliberately
   last, because an agent posting into an incorrect ledger only produces wrong
   entries faster.
2. **M16–M23 — the workshop redesign.** Per-invoice money, stock discipline,
   returns, the bench, the bill counter, staff and payroll, insights. All built.
3. **The card shell.** CLAUDE.md §1–§2A: one page, no sidebar, a dashboard of
   module cards, and a standard module flow — *card → create form → "Show list" →
   drawer → confirm* — built once in [workspace.js](../resources/js/workspace.js)
   so no module writes its own. Sixteen modules have been converted to it. The
   other three still open on a list with a modal create, and were switched off
   rather than left on in a second shape.

**That switch-off is the current backlog, and Part E is the plan for clearing
it.** The card grid is settled: it is not a staging post and there is no second
navigation coming. Every remaining module lands on it, then its flag is flipped.

---

## Status legend

| | Meaning |
| --- | --- |
| ✅ | Built and tested, and its card is on the dashboard |
| ⬛ | Built and tested, card switched off — waiting for its Part E conversion. The code behind it still runs wherever it is used: M4's posting engine is under every enabled module, and only the *screen* is unreachable |
| 🟡 | Partly done — see the module's gap list |
| ⬜ | Not started |

**⬛ is not unfinished work, and it is not a security boundary.** Each of those
three has a complete backend, a complete `pages/*.js`, a fragment view and
feature tests, and every API behind them answers normally under its own grant. What is
shut is the card and the fragment route. What that costs a workshop, module by
module, is in [hidden-modules.md](hidden-modules.md).

## Module map

| # | Module | Layer | Built | Card | Reference |
| --- | --- | --- | --- | --- | --- |
| **M1** | Authentication & RBAC | Base | ✅ | Users · Roles | [auth-module.md](auth-module.md), [passkeys.md](passkeys.md) |
| **M2** | Tenancy | Base | ✅ | ⬛ Workshops · **Settings** | [tenancy-module.md](tenancy-module.md) |
| **M3** | Chart of Accounts | Base | ✅ | **Accounting** | [accounting-module.md](accounting-module.md) |
| **M4** | Ledger & Posting Engine | Accounting core | ✅ | Transactions · **Accounting** | [ledger-module.md](ledger-module.md) |
| **M5** | Parties | Accounting core | ✅ | Customers · Vendors | [parties-module.md](parties-module.md) |
| **M6** | Payments & Receipts | Accounting core | ✅ | Transactions | [payments-module.md](payments-module.md) |
| **M7** | Item Master & Variants | Inventory | ✅ | Items | [items-module.md](items-module.md), [catalogue-master.md](catalogue-master.md) |
| **M8** | Inventory & WAC | Inventory | ✅ | Stock | [inventory-module.md](inventory-module.md) |
| **M9** | Bill Engine | Billing | ✅ | Sales · Purchase | [billing-module.md](billing-module.md), [sales-module.md](sales-module.md), [purchase-module.md](purchase-module.md) |
| **M10** | Misc Expense | Billing | ✅ | **Expenses** | [billing-module.md](billing-module.md) |
| **M11** | Opening Balances | Onboarding | ✅ | **Opening balances** | [opening-balances-module.md](opening-balances-module.md) |
| **M12** | Reports & Worklists | Back-office | ✅ | Insights (4 tabs) | [reports-module.md](reports-module.md) |
| **M13** | Audit Log | Cross-cutting | ✅ | **History** | [audit-module.md](audit-module.md) |
| **M14** | Async Jobs & Object Storage | Cross-cutting | ✅ | ⬛ Uploads | [async-module.md](async-module.md) |
| **M15** | AI Capture Agent | AI | ⬜ | — | Part F, below |
| **M16** | Per-invoice money | Billing | ✅ | Sales · Purchase · Transactions | [modified-flow-plan.md](modified-flow-plan.md) |
| **M17** | Stock discipline & duplicate protection | Inventory | ✅ | Sales · Purchase · Settings | [modified-flow-plan.md](modified-flow-plan.md) |
| **M18** | Returns | Billing | ✅ | Sales · Purchase | [sales-module.md](sales-module.md) |
| **M19** | Workshop jobs | Workshop | ✅ | Jobs | [workshop-module.md](workshop-module.md) |
| **M20** | Bill counter & customer invoice | Billing | ✅ | Sales · Purchase | [billing-module.md](billing-module.md) |
| **M21** | Jobs screen, dashboard, sweep | Cross-cutting | ✅ | Jobs | [modified-flow-plan.md](modified-flow-plan.md) |
| **M22** | Staff, attendance, payroll, advances | Workshop | ✅ | Staff | [staff-module.md](staff-module.md), [work-attribution.md](work-attribution.md) |
| **M23** | Insights | Back-office | ✅ | Insights | [insights-module.md](insights-module.md) |

Dependency order is strictly top to bottom for M1–M14. **M4 is the single most
important module in the product** — everything above it is plumbing and
everything below it is built on its correctness.

### The rows that were qualified, and the one gap left

- **M17 was 🟡 and is now ✅.** Refusal, `client_ref` duplicate protection and
  the server-priced preview were always live; its escape hatch
  `allow_negative_stock` was validated and persisted by the API and offered by no
  screen. **C1 put it on the settings form**, beside the two other rules the
  request accepted and nothing offered.
- **M16 was 🟡 and is now ✅.** Numbering, allocation, per-invoice paid/due and
  the ageing always worked and were tested. What was missing was a screen:
  `POST /transactions/{id}/allocate` and `GET /transactions/{id}/open-bills` had
  no caller in the front end, so a receipt could not be allocated *after* it was
  taken. **C3 put it in the drawer of a posted receipt or payment** — the party's
  open bills, oldest first, an amount against each and the unallocated remainder
  stated plainly.
- **M21 is ✅ and partly deleted.** The jobs screen is on a card since C4. The
  dashboard half was removed — home is the card grid and carries no
  figures, so `GET /api/v1/dashboard` and `DashboardService` were deleted rather
  than left dormant beside M23, which answers the same question. The §38
  consistency sweep shipped and stands.
- **M1 has open gaps** that are not about the card: no password reset, no user
  invitation, no mail configured, no 2FA. They are listed in Part G.

### The numbering, stated once

Two schemes ran in parallel and one of them has been retired. An earlier version
of this document reused **M16–M20** for Tally sync, regional languages, SaaS
billing and the smart speaker; those labels are gone, because M16–M23 mean the
workshop redesign above and nothing else. Unscheduled Phase 2+ work carries no
number until it is scheduled (Part G). The conversion steps are numbered **C1–C8**
so they never collide with a module number, and the open points carried out of
the audit are **P1–P7**, assigned in [execution-plan.md](execution-plan.md).
Nothing else in this project uses a P.

---

# Part A — Base modules

These must be complete before any accounting work starts.

---

## M1 · Authentication & RBAC ✅

Reference: [auth-module.md](auth-module.md)

**Delivers:** JWT access/refresh tokens with rotation and reuse detection,
per-account lockout, rate limiting, dynamic role/permission model, user
administration, login and admin UI.

**Test:** `php artisan test --filter='Auth|Rbac|UserManagement'`

### Remaining gaps

| Gap | Why it matters | Priority |
| --- | --- | --- |
| No password reset / forgot-password flow | A locked-out owner has to call support | Medium |
| No user invitation flow — `POST /users` sets a password directly | An owner must invent and transmit a password for each fitter | Medium |
| No email sending configured | Blocks both of the above | Medium |
| No 2FA | Financial data; worth it eventually | Low |

The first three are **P6** in [execution-plan.md](execution-plan.md), scheduled
because a recovery path that ends in a telephone call to the developer is the
gap a paying workshop notices first. 2FA stays unscheduled: passkeys already
carry a second factor's worth of assurance on every device that has one.

---

## M2 · Tenancy ✅

Reference: [tenancy-module.md](tenancy-module.md)

**Done:** tenants table, tenant context, `BelongsToTenant` trait and global
scope, isolation invariant test, tenant provisioning, platform-admin tenant
API and screen, suspend/reactivate, workshop sign-up page, workshop
self-service, workshop settings.

**Test:** `php artisan test --filter='Tenancy|PagesRender'`

### M2.1 · Workshop self-service ✅

`GET` / `PATCH /api/v1/workspace`, guarded by the new `WORKSPACE` permission
which `OWNER` holds and `TENANTS` does not imply. No `{id}` in the URL — the
workshop resolves from the tenant context, so there is nothing to tamper with.

**Test checklist**

- [x] An owner can read their own workshop
- [x] An owner can set a GSTIN after sign-up, and the state code is re-derived
- [x] An owner cannot change their own `status`, `slug` or `currency`
- [x] A `DATA_ENTRY` user gets 403
- [x] A platform admin (no tenant) gets `403 NO_WORKSPACE`, not an empty response
- [x] The same URL resolves to a different workshop for each caller
- [x] A suspended workshop cannot edit itself
- [x] A PATCH never blanks a field it did not mention

### M2.2 · Workshop settings ✅

| Setting | Editable | Default |
| --- | --- | --- |
| `financial_year_start_month` | ✅ | 4 (April) |
| `timezone` | ✅ | Asia/Kolkata |
| `books_start_date` | ✅ | null until onboarding |
| `currency` | ❌ India-specific tax engine | INR |

`Tenant::financialYearFor()` and `Tenant::acceptsPostingOn()` are on the model
so the April off-by-one is computed in one place. **M4 must call
`acceptsPostingOn()` before posting** — the column and the rule exist, the
enforcement point does not yet.

**Test checklist**

- [x] Defaults are stamped at provisioning; no workshop has null settings
- [x] Changing the financial year start changes the reported period
- [x] An invalid month or timezone is rejected at the API
- [x] `acceptsPostingOn()` refuses a date before go-live (unit tested)
- [x] A back-dated *transaction* is refused — done in M4, `BOOKS_CLOSED`

### M2.3 · Tenant administration UI ✅

`/tenants`, following the existing `users.js` / `roles.js` pattern.

- [x] List, search by name/handle/GSTIN, filter by status, paginate
- [x] Create workshop, with the optional owner block (all-or-nothing)
- [x] Edit; the handle is shown but fixed, and GSTIN fills the state code
- [x] Suspend / reactivate, with confirmation explaining the effect
- [x] Delete, refused with a clear reason while the workshop still has users
- [x] Per-row user count, fetched after the list so paging stays cheap
- [x] Nav entry visible **only** to holders of `READ:TENANTS` — now the module card

### M2.4 · Sign-up & onboarding UI ✅

- [x] `/register` — workshop name, optional GSTIN, owner name, email, password
- [x] Route returns **404** when `TENANCY_ALLOW_PUBLIC_SIGNUP=false`, and the
      login page drops its sign-up link
- [x] Sign-up signs the owner straight in and lands them on `/workspace?welcome=1`
- [x] `/workspace` — identity plus the book settings, with a welcome prompt
      explaining why the GSTIN and financial year matter before anything is posted
- [x] Read-only for a user holding `READ:WORKSPACE` without `UPDATE`

### M2.5 · Definition of done for M2 ✅

- [x] An owner can complete sign-up and configure their workshop **without a developer**
- [x] A platform admin can provision, suspend and delete workshops from the UI
- [x] `php artisan test --filter=Tenancy` green
- [x] `TenantIsolationInvariantTest` still green after every new table

---

## M3 · Chart of Accounts ✅

Reference: [accounting-module.md](accounting-module.md)

**Done:** 15 seeded accounts per tenant, `SystemAccount` enum, `AccountType`
with normal balances and code bands, full API, backfill command, and the
`/accounts` screen.

**Test:** `php artisan test --filter='Accounting|AccountType|PagesRender'`

### M3.1 · Chart of accounts UI ✅

- [x] `/accounts` page — grouped by type, showing code, name, normal balance
- [x] Add a custom account, with the code band shown inline from `GET /accounts/types`
- [x] Edit name and description; system accounts show a locked code and archive
- [x] Archive / restore a custom account
- [x] Filter by type, by active/archived, and by search
- [x] Nav entry gated on `READ:ACCOUNTS` — now the module card

### M3.2 · Definition of done for M3 ✅

- [x] An owner can view and extend their chart without a developer
- [x] Trying to break an immutability rule shows a clear message, not a raw error

---

# Part B — Phase 1 accounting core

---

## M4 · Ledger & Posting Engine ✅ — **the critical module**

Reference: [ledger-module.md](ledger-module.md)

**Depends on:** M2, M3

Built with more test coverage than anything else in the product. Every module
below it inherits its correctness, and a bug here is invisible for months and
then catastrophic.

**Test:** `php artisan test --filter='Money|PostingEngine|Ledger|TransactionApi'`

### Delivers

| Piece | Detail | |
| --- | --- | --- |
| `transactions` | The business event: type, date, total, notes, source, status, created_by | ✅ |
| `journal_entries` | The accounting lines: account, debit, credit | ✅ |
| Posting templates | One per transaction type, declaring which accounts move | ✅ |
| The posting engine | Fills amounts → validates balance → commits atomically | ✅ |
| Ledger view | A filtered query on `journal_entries` per account | ✅ |
| Trial balance | Total debits vs total credits across all accounts | ✅ |
| Manual journal entry | A raw double-entry screen, for corrections | ✅ |

`party_id` was the one deferred column: it landed with M5, together with its
foreign key, rather than sitting nullable and unconstrained until then.

### The invariants — each has its own test

- [x] **Debits equal credits, or nothing posts.** No partial write, ever
- [x] A transaction with fewer than two lines is refused
- [x] Money is `DECIMAL`, never float — `Money` holds integer paise, and
      `0.1 + 0.2` is asserted not to break the balance check
- [x] Journals commit in **one** database transaction (stock and payments extend
      the same wrapper rather than adding a write path)
- [x] A posted transaction is immutable; corrections are reversing entries —
      guarded on the model, not only in the service
- [x] Every entry is tenant-scoped (`BelongsToTenant`)
- [x] The trial balance of an untouched workshop is 0 = 0
- [x] After any sequence of postings, total debits still equal total credits

### Test checklist

- [x] Post a manual journal → appears in both account ledgers
- [x] Unbalanced journal → rejected with a clear error, nothing written
- [x] Concurrent postings do not corrupt the trial balance — each posting is
      internally balanced and atomic, so any interleaving is balanced; asserted
      over interleaved runs across two workshops
- [x] Ledger balance for an account equals the sum of its entries (no stored
      balance anywhere)
- [x] A `draft` transaction posts nothing until authorised — drafts are not in
      `journal_entries` at all
- [x] The database's own CHECK constraints refuse a malformed line
- [x] A back-dated transaction is refused (`BOOKS_CLOSED`, carried from M2.2)

### Done when

- [x] Trial balance reconciles after every test scenario —
      `assertBooksBalance()` in `tests/Concerns/InteractsWithLedger.php`
- [x] A raw `DB::table('journal_entries')` appears nowhere in the codebase
      (one deliberate use in a test, to prove the CHECK constraints hold)

---

## M5 · Parties ✅

Reference: [parties-module.md](parties-module.md)

**Depends on:** M4

| Deliver | Detail | |
| --- | --- | --- |
| `parties` | Multi-value roles: one party may be both customer and vendor | ✅ |
| `transactions.party_id` | M4's deferred column, with its foreign key | ✅ |
| Party ledger | Derived from journal entries — **never** a stored balance | ✅ |
| Outstanding | Receivable / payable position, computed on read | ✅ |
| API + UI | List, search, create, edit, archive, statement view | ✅ |

**Test:** `php artisan test --filter='Party|PagesRender'`

**Test checklist**

- [x] A party can be both customer and vendor and shows one combined ledger
- [x] Outstanding recomputes correctly after every posting — no drift. Drift is
      impossible by construction: a party ledger and its control account are the
      same rows summed two ways, asserted by
      `every_party_position_sums_to_its_control_account`
- [x] Deleting a party with entries is refused (`PARTY_IN_USE`) — including one
      named only by a draft, which would otherwise be left unpostable
- [x] GSTIN validated for shape; duplicates flagged but allowed (branches exist)
- [x] Party list is tenant-scoped, and a transaction cannot name another
      workshop's party (`PARTY_UNKNOWN`)

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| The statement reads **both** control accounts regardless of the party's roles | Otherwise dropping the "vendor" tag would empty half their ledger while the money stayed in the control account |
| A reversal carries the original's party, and may name an archived one | Archiving means "no new business", not "this error is permanent" |
| Overpayment leaves a credit balance rather than being refused | The money is in the bank and it is theirs; forcing it onto the payable side would claim a supplier relationship that does not exist |
| `receivable`, `payable` and `net` are all reported, never just the net | The two sides are settled separately and on different terms |
| One "Parties" nav entry, not the design's Customers/Vendors pair | Two lists push people into two records for one counterparty, splitting one balance in half |
| `DATA_ENTRY` holds `WRITE:PARTIES` but not `READ:LEDGER` | A walk-in customer must be capturable at the counter; what they owe is a different authority |

---

## M6 · Payments & Receipts ✅

Reference: [payments-module.md](payments-module.md)

**Depends on:** M4, M5

The simplest real transactions — pure money movement, no GST, no stock. They
prove the engine end to end at minimum complexity, which is why they come
before billing.

M5 left nothing to plumb, and the prediction held exactly: **M6 added no
reporting code at all.** A payment reduces `payable` and a receipt reduces
`receivable` because both were already sums over `journal_entries`.

**Test:** `php artisan test --filter='Settlement|Party|PostingEngine|PagesRender'`

| Deliver | Detail | |
| --- | --- | --- |
| Vendor payment | `Dr Sundry Creditors / Cr Cash-Bank-UPI` (template D) | ✅ |
| Customer receipt | `Dr Cash-Bank-UPI / Cr Sundry Debtors` (template E) | ✅ |
| `transaction_payments` | Split across cash / bank / UPI / cheque in one transaction | ✅ |
| Payment mode → account | Each mode maps to its own asset account | ✅ |
| `transactions.draft_payments` | A draft's intended split, held off the settlement table | ✅ |

**Test checklist**

- [x] A receipt reduces the customer's outstanding by exactly the amount
- [x] A split payment (₹2,000 cash + ₹3,000 UPI) posts three balanced lines —
      one control line for the whole amount, one per mode
- [x] Cash, Bank and UPI ledgers each move independently
- [x] **A payment never touches GST** — structurally: neither GST account is
      reachable from `SettlementTemplate`
- [x] Overpayment **leaves a credit balance**, in both directions — M5's
      decision, applied. A supplier paid too much shows a negative payable,
      which is an advance and a real thing

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| A cheque settles through **Bank**, not a Cheques-in-Hand account | That account is only correct alongside a clearing workflow, and Phase 1 has none. Without one every cheque would sit there for ever and the bank balance would be permanently short. One day early beats permanently wrong |
| A cheque must carry its number; nothing else must | A cheque you cannot identify cannot be matched, chased or stopped — and the moment you need it is the moment it has gone wrong |
| Two lines on one account are never merged | A cheque and a transfer both land on Bank; they are two movements, and the voucher has to be able to say what the workshop actually did |
| `party_id` is **required**, and the role must match | Debiting Sundry Creditors *is* the claim "we owed this business money". The one place a role gates a write — roles still never filter a read |
| The role check is skipped on a reversal | A role removed after the fact cannot strand a known error permanently in the books. Same reasoning as the archived-party exemption |
| Two POST routes, one PATCH | On a POST the payloads have nothing in common; on a PATCH every field is optional by nature, so each shape is still fully validated when present |
| `lines` sent for a settlement draft is **refused**, not ignored | The template does not read them, so the caller would be told their edit saved while nothing changed. Silently discarding an edit to a financial document is worse than refusing it |
| Settlement rows join the engine's existing `DB::transaction` | The pattern M8's stock movements extend: everything a business event implies commits together or not at all |

---

## M7 · Item Master & Variants ✅

Reference: [items-module.md](items-module.md)

> **Superseded in part — read this before the section below.** The vocabulary
> described here as two enums is now **data**. There is no `ItemType` and no
> `UnitOfMeasure`: what kinds of product exist, what each records, and how any of
> it is counted are rows in `item_categories`, `item_attributes`, `item_brands`
> and `units`, edited from the Items workspace and published by
> `GET /api/v1/items/meta`. The four categories and seven units the enums held
> were migrated as seeded rows and are marked `is_system`. Everything else in this
> section — two levels, per-category attribute schemas validated in the service,
> attributes stored in schema order, `canHoldStock()` against `is_stock` — still
> holds, and the reasons in *Decisions worth carrying forward* are why the tables
> were shaped the way they were. See [catalogue-master.md](catalogue-master.md),
> and CLAUDE.md on why a hard-coded type, brand or unit may never come back.

**Depends on:** M2

No stock movement yet — catalogue only. The one module in Part B with **no posting
template**: it touches the ledger nowhere, which is why it could land beside M6
rather than after it.

**Test:** `php artisan test --filter='Item|PagesRender'`

| Deliver | Detail | |
| --- | --- | --- |
| `items` | type (motor/part/bulk_material/service), HSN/SAC, GST rate, base UOM, `is_stock` | ✅ |
| `item_variants` | Flexible `attributes` JSON, SKU, label, sell price, markup, reorder level | ✅ |
| Draft items | Auto-created items flagged for review, surfaced as a banner | ✅ |
| `UnitOfMeasure` | Counted / measured / time, with `isFractional()` for M8 and M9 | ✅ |

**Test checklist**

- [x] A motor (HP/phase/RPM), a bearing (size) and copper wire (gauge) all
      coexist — each in the unit its trade uses, defaulted from the type
- [x] Attribute shape is validated per item type, in the service rather than a
      form request, so M11's importer and M15's agent are bound by it too
- [x] A service item cannot hold stock — the flag is **overruled**, not merely
      defaulted, and stays false through an edit
- [x] Draft items are visible in a review queue, and are usable while in it

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| Two levels: family and variant | One HSN code and one GST rate cover forty motor ratings. Repeated forty times, two eventually disagree — and the wrong one puts a wrong figure on a government return |
| The attribute schema lives in `ItemType`, validated in the service | A motor whose HP was never captured is unidentifiable by anybody afterwards. That is permanent, so the rule must bind the importer and the capture agent, not just a form |
| A fixed value set only where one exists | Phase is 1 or 3. Frame size is open, and pinning it to a list would make the product wrong about the next frame |
| Optional attributes are never demanded | Refusing a bearing because nobody typed its material pushes people into not recording the bearing |
| Attributes stored in schema order | The derived label reads the way a specification is recited, and two equivalent variants compare equal as stored JSON |
| `canHoldStock()` is capability; `is_stock` is the choice within it | A service never can. A part bought to order legitimately is not stocked. Two different statements, so two different things |
| `type` and `base_uom` are not editable, ever | Reclassifying reinterprets every quantity recorded; "each" → "kilogram" turns 40 pieces into 40 kilograms in every report ever run |
| No quantity or cost column anywhere | M8 derives both from `stock_movements`. A reserved empty column is an invitation |
| `markup_percent` is not a margin | Cost is M8's weighted average *at the moment of sale*. A margin stored here would be stale the next time stock arrived |
| A variant cascades with its item; the item itself cannot be deleted once referenced | "5 HP / 1440" is uninterpretable without its family, so the protection belongs on the family — and an item with variants is still refused, because losing them silently is losing somebody's work |
| A duplicate specification is reported, not refused | Two brands at one rating is real. The same treatment as a shared GSTIN in M5 |
| `is_draft` is a flag, not a table | A draft item is a real item that stock may already be posted against. It drives a worklist, never a filter on the books |
| One nav entry labelled "Items", not "Inventory" | There are no quantities behind it until M8, and an entry promising stock that shows none is worse than one promising less |

---

## M8 · Inventory & WAC ✅

Reference: [inventory-module.md](inventory-module.md)

**Depends on:** M4, M7

M6 and M7 left the ground prepared, and the prediction held: **M8 added no second
write path.** The engine's `DB::transaction` already carried the header, the
journal entries and M6's settlement rows, and stock movements joined that wrapper.
M7's reserved-nothing catalogue needed no migration either — `scopeStocked()` was
the sweep, `UnitOfMeasure::isFractional()` was what a quantity is validated
against, and `suggestedPriceFrom()` was already waiting for a cost.

Stock is counted per **variant**, never per item.

**Test:** `php artisan test --filter='Stock|Item|PagesRender'`

| Deliver | Detail | |
| --- | --- | --- |
| `stock_movements` | IN / OUT / ADJUST / OPENING — simultaneously the stock ledger and the audit trail | ✅ |
| Weighted average cost | Value ÷ quantity, both sums; recomputed by arithmetic nobody performs | ✅ |
| Stock views | Quantity on hand, average cost, low-stock and negative flags, stock card | ✅ |
| Stock adjustment | Template G — the first type to write two kinds of record in one transaction | ✅ |
| `Quantity` | Integer thousandths, the companion to `Money` — a quantity is multiplied by a cost | ✅ |

The table is `stock_movements` (plural), matching every other table in the
schema; the roadmap's original `stock_movement` was shorthand.

**The invariants**

- [x] `qty_on_hand` and `avg_cost` change **only** through a movement — asserted
      structurally: no such column exists on `items` or `item_variants`
- [x] Stock-OUT values at current average cost and does **not** change it
- [x] WAC formula verified: 10kg @ ₹700 then 10kg @ ₹800 → ₹750/kg
- [x] Stock value in the Inventory ledger equals Σ(qty × cost) across variants —
      `assertStockAgreesWithInventoryAccount()`, and enforced at the engine
- [x] Negative stock is **warned**, not blocked — decided, documented and tested

### The decision the roadmap asked for

**Negative stock is allowed, surfaced, and never silently free.** Blocking sounds
safer and is not: refusing Tuesday's sale because Friday's invoice has not been
entered does not produce the bearing, it produces a workshop that stops recording
sales. The shortfall is valued at the last rate actually paid — never at zero,
which would report a 100% margin on an ordinary sale.

---

## M9 · Bill Engine (Sales & Purchases) ✅

Reference: [billing-module.md](billing-module.md)

**Depends on:** M4, M5, M6, M7, M8

The big one, and it turned out to be mostly composition. Nothing here is new
machinery except the tax arithmetic and the place-of-supply rule.

**Test:** `php artisan test --filter='Bill|Expense|Stock'`

| Deliver | Detail | |
| --- | --- | --- |
| `transaction_lines` | Stock lines and service lines on one bill | ✅ |
| GST | From the item's HSN rate; intra vs inter-state from two state codes | ✅ |
| COGS | From the weighted average at the moment of posting, under a lock | ✅ |
| Margin | Per line, from the movement rather than a stored copy | ✅ |
| Payment terms | Full / partial / credit, on the bill itself | ✅ |

**Test checklist**

- [x] Sale posts template A exactly: revenue, GST output, receivable, COGS, stock OUT
- [x] Purchase posts template C: inventory, GST input, payable, stock IN, WAC recomputed
- [x] A rewinding job mixing labour + copper + bearing posts template B correctly
- [x] A **labour-only** bill posts with zero stock movement
- [x] Intra-state splits CGST/SGST; inter-state uses IGST
- [x] Selling below cost raises a warning and still posts
- [x] Trial balance still reconciles after a hundred mixed bills — and so does the
      shelf against the Inventory account

Templates A and B are **one class**: a counter sale and a rewinding job are the
same document with a different mix of lines, and writing the tax arithmetic twice
would mean two places for the same rounding to drift — one of which ends up on a
government return.

---

## M10 · Misc Expense ✅

Reference: [billing-module.md](billing-module.md)

**Depends on:** M4. Trivial once the engine exists — template F, and built last
because by then the engine had nothing left to discover.

- [x] Expense with and without claimable GST input
- [x] Paid from any payment mode, and split across several
- [x] Booked to any of the workshop's own expense accounts, and refused on
      anything that is not one

An expense is deliberately **not** a purchase: a purchase is bought to sell or to
fit, an expense is what it costs to be open, and keeping them apart is the whole
reason a P&L can separate gross margin from overheads.

---

## M11 · Opening Balances ✅

Reference: [opening-balances-module.md](opening-balances-module.md)

**Depends on:** M4, M5, M8

A running workshop cannot start at zero. Template H — the only template whose
other side is always equity, because an opening balance is not a transaction
*with* anybody.

**Test:** `php artisan test --filter='Opening'`

| Deliver | Detail | |
| --- | --- | --- |
| Opening stock | `Dr Inventory / Cr Opening Balance Equity` — not a purchase, no GST | ✅ |
| Opening payables | `Dr OBE / Cr Sundry Creditors`, one document per party | ✅ |
| Opening receivables | `Dr Sundry Debtors / Cr OBE` | ✅ |
| Opening account balances | Cash, bank, a loan — the row people forget | ✅ |
| CSV import | Fuzzy matching, new items and parties flagged as drafts | ✅ |
| Post-import check | Trial balance and the owner's stake, **before** committing | ✅ |

CSV only, not Excel. Reading `.xlsx` means parsing ZIP and XML from an untrusted
upload, and a workshop's go-live file is the one piece of user-supplied data this
product parses at all — the smallest parser that does the job is the right one to
point at it.

**Test checklist**

- [x] Import produces a reconciling trial balance — and the shelf agrees with the
      Inventory account
- [x] A deliberate mismatch surfaces as an OBE residual, not a silent error
- [x] Re-importing the same file does not double the balances
- [x] Fuzzy matching does not create duplicate variants

### The residual, stated honestly

The checklist asks for OBE to "absorb any residual". It always does — every
opening line is posted against it, so **the books reconcile whatever is
imported**, and there is no difference that failed to balance.

What OBE ends up holding is the *owner's stake at go-live*: assets declared less
liabilities declared. That is the residual, it is a real figure, and the only way
it comes out wrong is if something was left out. Which is exactly why the preview
leads on it — a workshop that forgot its ₹40,000 of cash sees a stake ₹40,000
short of what they know it to be, before they agree to anything.

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| The per-target guard, not the fingerprint, is what stops a double import | A fingerprint is defeated by any edit; "this variant already has opening stock" is a fact about the ledger, and cannot be got round |
| A row whose target is already declared is **skipped**, not refused | Re-running an interrupted file is reasonable. Calling it broken sends people off to "fix" a file that was fine — which is how a workshop ends up with two go-live positions |
| Refused whole, never in part | A half-imported go-live can only be unpicked by reconciling the lot by hand, which is the work the import existed to avoid |
| Preview and commit are one code path, resolved inside the write | A preview that can disagree with the commit is worse than no preview |
| An item's `type` is demanded and never guessed | It fixes the unit permanently (M7), so a wrong guess cannot be corrected — only archived |
| The variant format is the *inverse of the printed label* | The importer reads what the screens already show, so a file this product exported round-trips and a hand-written one only has to follow what is on screen |
| Accounts are never invented; items and parties are | A wrong account puts entries on the wrong financial statement for ever. A wrong item is a draft in M7's review queue |
| `UPDATE:WORKSPACE` as well as `WRITE:TRANSACTIONS` | Declaring the workshop's net worth is a setup act beside `books_start_date`, not the day job. A data-entry user holds only the second |
| `opening_import_id` is write-once, guarded on the model | A receipt that could be re-pointed would claim postings it never made |

---

## M12 · Reports & Worklists ✅

Reference: [reports-module.md](reports-module.md)

**Depends on:** everything above. All derived from `journal_entries`,
`stock_movements` and `transaction_lines` — no report has its own stored numbers.

**Test:** `php artisan test --filter='Report|Ledger|Stock'`

- [x] Transaction list with filters, search and source provenance — M4, extended
      by M11's `import` source
- [x] Drill-down: transaction → lines → journal entries — M4, extended by M9
- [x] Day book — new
- [x] Trial balance — M4
- [x] Stock summary and per-item movement history — M8
- [x] GST output / input summary — new
- [x] P&L snapshot — new
- [x] Parked-draft worklist with stale flags — new

Half of the checklist was already true, and nothing already built was re-exposed
under `/reports`: a second URL for one answer is a second thing to keep in step,
and the second one always drifts.

**Test checklist**

- [x] Every report reconciles against the trial balance
- [x] Reports respect the financial year from M2.2 — an April workshop and a
      January workshop report different windows from the same day
- [x] A report for a workshop with no data shows zero — **not** an error, and never another workshop's numbers

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| The day book is its own query, not the transaction list with a filter | Opposite sort order — a day book runs forwards — and it loads every line where a list loads a count. One query bent to do both would make a listing page load the whole ledger |
| The P&L is assembled from the chart, not from a list of account names | A fixed list silently omits every account a workshop added, and the omission is invisible |
| COGS is the only account named | Gross margin is the one figure that needs cost of sales separated from overheads. An 8% margin is a pricing problem; a 40% margin that still loses money is a rent problem, and adding them says neither |
| GST is read from `transaction_lines`, not the ledger | Phase 1 has one GST account per direction, so the journal knows the tax but not the rate or the CGST/SGST/IGST split — and a return is filed rate by rate |
| The GST reconciliation is reported, never repaired | A difference means a manual journal touched a tax account, which M4 deliberately allows. It has to be visible before a return is filed |
| Stale is a warning, not an expiry | The engine already re-prices a draft when it posts, so staleness costs attention rather than correctness. Auto-deleting would destroy work; silence would lose the sale |
| The worklist ignores the period | A draft is outstanding work, not an event. The three-month-old one is the point |
| The period is a preset resolved server-side, in the workshop's timezone | "This financial year" depends on a setting the client must not hold a copy of — it would be right until somebody changed it |
| Bad dates are swapped and unknown presets fall back to everything | A report that refuses to draw teaches people the reports are broken |

---

# Part C — Cross-cutting

Build alongside, not after.

## M13 · Audit Log ✅

Reference: [audit-module.md](audit-module.md)

**Depends on:** M2, and everything that owns master data

- [x] Who changed what, when, on every record that can change silently
- [x] Immutable, tenant-scoped, queryable from the back-office

**Test:** `php artisan test --filter='Audit'`

The prediction held exactly. M4 to M12 had already made most of it unnecessary: a
posted transaction cannot be edited or deleted at all, journal entries and stock
movements are refused an UPDATE by the model, and `created_by` plus `posted_at`
are on every transaction — so "who changed this figure" has no answer because
nothing changes a figure. `posting_a_transaction_writes_nothing_to_the_trail` is
that stated as a test rather than as an intention.

What was genuinely missing is the *master data* trail, and it is the part that
matters: who archived a supplier, who edited a GSTIN, who moved the financial
year start. None of those changes a posted number, and every one of them changes
what the books mean — retrospectively, and with no other mark anywhere.

| Deliver | Detail | |
| --- | --- | --- |
| `audit_logs` | Immutable, tenant-scoped, append-only | ✅ |
| `Auditable` trait | Model events, so no service can forget to record | ✅ |
| `AuditRecorder` | The one writer: actor, redaction, suppression | ✅ |
| API + UI | Filter by kind, record, action, person, date; the History screen | ✅ |
| `READ:AUDIT` | OWNER holds it; DATA_ENTRY deliberately does not | ✅ |

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| The trail hangs on model events, not on service calls | A rule services must remember is correct until somebody adds a second write path, and then the trail has a hole nothing announces. M11's importer was audited without a line being added to it |
| `auditAttributes()` is **abstract** | Defaulting to `$fillable` is a deny-list wearing an allow-list's clothes: correct until somebody adds a fillable column, and the one it gets wrong is a password hash |
| A failure to record fails the write | A trail with silent holes is worse than no trail, because it is *believed*. A gap has to mean nothing happened, or every absence becomes ambiguous |
| The actor's name is copied onto the row | A history that empties itself when somebody leaves is not a history. Unlike every other denormalisation this schema refuses, it is a copy of a *past* fact and cannot drift |
| Archived and restored are their own actions | Archiving is this product's deletion. Filed under `updated` it would be one row among forty field edits, and it is the one people come looking for |
| A creation carries no snapshot; a deletion does | The record *is* the snapshot while it exists. Nothing survives a deletion |
| The entry belongs to the tenant that was changed | A platform admin editing a workshop's settings writes into that workshop's history, because that is where somebody will look |
| Provisioning is suppressed | Fifteen seeded accounts are one act, already on the trail as the workshop's creation. A log whose first page is machine noise is a log people stop opening |
| An unknown filter is refused, not ignored | The opposite of M12's stale period preset, deliberately: ignoring it shows a complete history to somebody who believes it is filtered, and they draw a conclusion from the difference |

## M14 · Async Jobs & Object Storage ✅

Reference: [async-module.md](async-module.md)

**Required before M15.**

- [x] Queue worker configured and supervised
- [x] Object storage for invoice images and raw audio
- [x] Progress reported to the UI; nothing blocks on upload

**Test:** `php artisan test --filter='Async'`

| Deliver | Detail | |
| --- | --- | --- |
| `job_runs` | One row per piece of work, from dispatch to outcome | ✅ |
| `TrackedJob` | Carries the tenant and the actor across the queue boundary | ✅ |
| `attachments` | A pointer to a stored object — verified, never trusted on the write | ✅ |
| `documents` disk | Private; S3-compatible in production, local in development | ✅ |
| `ProcessAttachment` | The first real job, and the shape every M15 job will take | ✅ |
| API + UI | Upload, library, live progress; `jobs:prune` on the scheduler | ✅ |

### The thing this module is really about

Not the queue — the **boundary**. A job runs with no request behind it, and two
things everything else here relies on are established by the request and by
nothing else: the tenant, without which MySQL has no isolation at all, and the
actor, without which M13's trail says "the system" for everything a worker does.

`TenantContext` is a singleton and a worker is a long-lived process, so a job
that finished leaves its tenant set and the *next* job inherits it — writing into
another workshop's books without throwing, because the context is populated and
looks legitimate. `TrackedJob` captures both at dispatch and re-establishes them;
`AsyncServiceProvider` clears and restores the context around every job as the
guard for anything that does not use the base class.

### Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| The context is saved and restored, not merely cleared | Under `sync` and `dispatchSync` the job runs inside the dispatching request. Clearing on the way out strips the tenant from a controller still mid-work — found by a failing test, not by reasoning |
| Dispatching with no workshop fails at dispatch | In the request, in front of the person who asked, rather than in a worker an hour later |
| Nothing is `ready` until it has been read back | A write to object storage can return cleanly and leave nothing readable. Every such failure is silent at upload and fatal three weeks later |
| The media type is sniffed, never believed | `Content-Type` is whatever the client wrote. The stored extension comes from the bytes, so `invoice.jpg.php` is stored as `.jpg` or refused |
| The object key carries the tenant | Storage is the one place a bug is not caught by the tenant scope — a key is a string, and a string assembled wrongly reaches whatever it names |
| The key is never sent to a client | Handing one to a browser turns a private bucket into one whose only protection is that the caller was logged in |
| No `transaction_id` yet | M15 adds the column with its foreign key, exactly as M5 did for `party_id`. A reserved empty column is an invitation |
| A duplicate upload is reported, not refused | The same treatment as a shared GSTIN in M5. Quietly returning the first row creates a file two things point at and either may delete |
| No UPDATE grant for attachments, anywhere | A file's bytes never change. A grant over an operation that does not exist is a lie in the permission catalogue |
| Progress is stored, and deliberately not trusted | The one figure here that is a fact about a process rather than a sum over rows. `status` decides whether work is finished; `elapsed_seconds` is what tells "working" from "stuck" |
| Polling, not websockets | A broadcast driver, a held-open connection and a second thing to supervise, to shorten a wait usually under five seconds |

---

# Part D — The workshop redesign (M16–M23)

Planned separately, after an audit of the code, in
[modified-flow-plan.md](modified-flow-plan.md) — which holds the eight decisions
(D1–D8) these modules were built on and is worth reading before changing any of
them. Summarised here so that one document answers "what exists".

| # | Module | Delivered | Where it lives now |
| --- | --- | --- | --- |
| **M16** | Per-invoice money | Document numbers assigned at posting under a locked sequence row, `transaction_allocations`, derived paid / due / status, the party statement, the ageing | Sales and Purchase drawers; Insights' credit panel; the allocation screen on Transactions since C3 |
| **M17** | Stock discipline | `assertCanIssue()` refusing an issue that takes a variant below zero, `client_ref` duplicate protection, `POST /transactions/preview` so a confirmation shows the *server's* figures | Every bill form; `allow_negative_stock` on the settings form since C1 |
| **M18** | Returns | `sales_return` / `purchase_return` with `against_transaction_id`, GST reversed on returned quantities only, stock valued from the original movement | Sales and Purchase |
| **M19** | Workshop jobs | `workshop_jobs`, `workshop_job_parts`, the received → delivered pipeline, estimates and approval, `billPayloadFor()` so billing a job re-enters nothing. 25 feature tests | Jobs, since C4 |
| **M20** | Bill counter & customer invoice | The search-first counter, the shared bill document, autosave, the confirmation step, the customer's invoice and its revocable share link | Sales · Purchase · Jobs (the counter page itself retired at C4) |
| **M21** | Jobs screen, dashboard, sweep | The jobs list and job card; one badge helper, one money formatter, one date format, the unit beside every quantity | Jobs, since C4. Dashboard half deleted |
| **M22** | Staff | Employees, attendance, payroll, advances — four §2A workspaces on one card — plus work attribution on a sale | Staff |
| **M23** | Insights | Six analysis panels plus M12's four statements, over one period picker | Insights |

**What the plan asked for and did not get: its verification phase.** The single
`WorkshopFlowTest` that walks one workshop day through all ten of the brief's
scenarios — purchase, sell, job, cancel, return, part-pay, double-submit,
over-sell, combined bill — was never written, and neither was `docs/workshop-flow.md`.
The scenarios are individually covered where they landed (5 and 6 in `ReturnTest`,
8 and 9 in `StockDisciplineTest`, 10 and the walkthrough in `WorkshopJobTest`).
The value of the missing test is the *interaction* between modules, which is
exactly what per-module coverage cannot see. It is scheduled as **C8**.

---

# Part E — The card conversion (C1–C8)

**This is the current work.** Three modules are still built, tested and switched
off because they open on a list with a modal create. Each step below re-flows one
of them (or one pair) to CLAUDE.md §2A and flips its flag. Convert one at a time;
the shell stays shippable after every step.

Order was **go-live first**: the pair that lets a real workshop start using the
product at all came before the module that is largest. After that the order is
what it costs a workshop to be without each one — overheads missing from the
P&L, then money that arrives without a bill, then the bench.

**C1 to C5 are done.** A workshop can go from sign-up to a correct opening trial
balance without a developer, its P&L reports a margin against real overheads
rather than nil, money that arrives without a bill has a home — along with the
one correction mechanism the books have for everything else — the bench is on a
card, which retired the last page shell in the product with it, and the books
can be read and added to: an expense head of the workshop's own, and a trial
balance that reconciles. **C6 is next.**

**C8 has moved, and this document is the one that was wrong.** It was scheduled
last. It now runs immediately after C7, because the property it tests — that
every module is reachable and they still agree with one another — becomes true at
C7 and at no later point. Written there it guards the open points that follow it
in [execution-plan.md](execution-plan.md); written last it guards nothing and is
the step most likely to be dropped when the end is in sight. The step itself is
unchanged.

| Step | Card(s) | Key(s) | Opens on | Also finishes |
| --- | --- | --- | --- | --- |
| ~~**C1**~~ ✅ | Settings · Opening balances | `workspace` `opening` | Record form · Import form | M17's missing controls |
| ~~**C2**~~ ✅ | Bills → expenses | `bills` | Expense form | — |
| ~~**C3**~~ ✅ | Transactions | `journal` | Three sections, each on its form | M16's allocation screen |
| ~~**C4**~~ ✅ | Jobs | `jobs` | Receive-a-motor form | Retired `/bills/new`; M19's edit, delete and attribution |
| ~~**C5**~~ ✅ | Accounting + Ledger | `accounts` (`ledger` retired) | Account form | Trial balance on a card |
| **C6** | Uploads | `uploads` | Drop target | — |
| **C7** | Workshops | `tenants` | Provision form | — |
| **C8** | — | — | — | The workshop-day test |

## What every step does

Six obligations, identical in every step, so the per-step sections below only
state what is particular to that module.

1. **Mount the shared flow.** `mountWorkspace()` on the module's own root, with
   `data-ws-form` and `data-ws-list` in
   `resources/views/modules/{key}.blade.php`. No module writes its own
   form/list swap, switch control or count badge. A module with several
   sections mounts one workspace per section on that section's root — the Staff
   pattern — and each registers Escape under a key of its own.
2. **Retire the modal create.** A record opens in a **drawer** (level 2);
   confirmations are small modals (level 3); nothing opens over level 3. A list
   with filters and a form inside a dialog is the shape being removed, not
   moved.
3. **Reuse before writing.** `ui.js` primitives, `components/` (bill-document,
   bill-revision, quick-party, party-picker, item-picker, payment-rows,
   quick-item, badge, party-position, stock-position, invoice-document),
   `permissions.js` gating, `auth-client.js` for every request. The per-step
   *Do not rebuild* list is not advice; it is the §5.1 rule applied to the
   screen in hand.
4. **Declare what the rows are a copy of.** `refreshOn` on `mountWorkspace`, or
   `onChange` from [data-bus.js](../resources/js/data-bus.js). Add the module's
   write paths to the bus's `WRITES` table only if a *second* surface can write
   the same fact — a row nothing listens to is worse than none.
5. **Gate the controls.** `data-requires-permission` for presentation; the grant
   is checked server-side on every endpoint regardless. Where a grant is missing,
   the control is **absent**, not blanked — except where a rule refuses an
   operation the user could otherwise perform (a system role, a system account),
   which is **disabled with its reason**, so the answer stays where the question
   is asked.
6. **Move the coverage, then flip the flag.** In `PagesRenderTest`, change that
   module from `$this->view('modules.{key}')` to `$this->get('/modules/{key}')`,
   which asserts the fragment route as well as the markup. Then
   `'enabled' => true` in [config/modules.php](../config/modules.php), and
   **delete that module's section from [hidden-modules.md](hidden-modules.md)** —
   a status document that has to be checked against the code is worse than none.

---

## C1 · Settings + Opening balances — the go-live pair ✅

**Done.** Both cards are on, `PagesRenderTest` fetches both fragments, and the three
settings the API had always accepted are on the form. What follows is the
record of what was built and why, not a plan.

**Why first.** With both cards off, **a real workshop cannot start using this
product.** A workshop that signed up without a GSTIN can never add one; nobody
can correct a name, an address, a financial year or a timezone; `books_start_date`
— what `Tenant::acceptsPostingOn()` refuses against — cannot be set; and existing
debtors, creditors, stock and cash have no way in, so every report starts from
zero on the day the software is first opened and the trial balance is not the
workshop's. Converted as one step because either alone leaves go-live blocked.

**Settings — one record, so one surface.** Declare **only** `data-ws-list` in the
fragment, holding the settings form, and mount with `canCreate: false`. The
workspace then lands straight on it and paints no switch control, which is
exactly right for a module with one record and nothing to create. Do not add a
single-surface mode to `workspace.js` for this.

**Opening balances — §2A.1.** The paste-or-upload box is the create surface; past
imports go behind "Show list". **Keep the two-button discipline exactly as it
is**: the post button stays disabled until the preview has run against the text
currently in the box, so an edit made after a preview cannot be committed on the
strength of the preview it invalidated. That is not UX politeness, it is the
whole safety property of the module.

**Settings is also incomplete, and converting it means finishing it.**
`UpdateWorkspaceRequest` already accepts three settings the form has never
offered, and each changes what the books do:

| Setting | What it governs | Say this on the form |
| --- | --- | --- |
| `allow_negative_stock` | Whether an issue that would take a variant below zero is refused | Off is the default; on is for a workshop that routinely bills before entering the purchase |
| `round_off_invoices` | Whether an invoice total is rounded, through `RoundOff` | It changes the total a customer pays |
| `payment_due_days` | The terms Insights measures its ageing against | With none set, the buckets are measured from the invoice date and the panel says so |

Re-flowing the seven fields that exist and leaving these behind would make this
the module that looks converted and is not. Shipping them closes **M17's 🟡**.

**Do not rebuild.** The sign-up flow, `GET`/`PATCH /workspace`,
`OpeningBalanceService`, `OpeningCsvParser`, or the preview-and-commit single
code path — a preview that can disagree with the commit is worse than no preview.

**Bus.** Add `/workspace` → `['ledger']`: the financial year and the timezone
define the period every statement and every Insights panel is measured over, so a
change to them makes a held report wrong.

### Test checklist

- [ ] An owner sets a GSTIN after sign-up and the state code is re-derived
- [ ] An owner cannot change their own `status`, `slug` or `currency`
- [ ] A `READ:WORKSPACE`-only user gets the form read-only, with no save control
- [ ] A platform admin (no tenant) is not offered the card at all — the
      `workspace => true` gate, not just the permission
- [ ] `allow_negative_stock` off refuses an issue below zero in the server's own
      wording; on, the same issue posts and is flagged
- [ ] `round_off_invoices` changes a posted total, and the rounding lands in the
      Round Off account
- [ ] Setting `payment_due_days` moves Insights' ageing off the invoice date, and
      the panel stops saying it has no terms to measure against
- [ ] Preview → edit the text → post is refused until re-previewed
- [ ] Re-running an interrupted import skips already-declared targets rather
      than doubling the balances
- [ ] After an import the trial balance reconciles and the shelf agrees with the
      Inventory account
- [ ] `PagesRenderTest` fetches `/modules/workspace` and `/modules/opening`

**Done when** a workshop can go from sign-up to a correct opening trial balance
without a developer, and both flags are on. ✅

**What it actually looks like.** Settings declares only `data-ws-list` and mounts
with `canCreate: false`, so it lands on its one record and paints no switch
control — and `workspace.js` grew no single-surface mode, as required. Opening
balances declares both surfaces: the declaration is the create surface and every
import ever run sits behind "Show list", with the count on the Show control. The
*position* — the owner's stake, the go-live date and the trial balance — travels
with the **form**, because it is what somebody about to declare their whole
financial history needs in front of them, and it is what they want to see the
instant they commit. `GET /opening-balances` answers with the position and the
receipts together, so the list costs no second request. The two-button discipline
is unchanged.

---

## C2 · Bills → the expense module ✅

**Done.** The card says **Expenses**, `PagesRenderTest` fetches the fragment, and
the transaction list that used to be here is gone rather than moved. What follows
is the record of what was built and why, not a plan.

**Why here.** An expense is what it costs to be open, as against what was bought
to sell, and keeping them apart is the whole reason a P&L can separate gross
margin from overheads. `POST /transactions/expense` has exactly one caller in the
front end and it is this module — so today rent, power, a mechanic's conveyance
and tea for the counter cannot be recorded at all, the P&L reports a margin
against nil overheads, and the cash position drifts from the tin.

**Shape.** §2A.1 — the expense form is the create surface, past expenses behind
"Show list". This module becomes the expense module and nothing else.

**Do not rebuild the transaction list.** This is the §5.1 mistake the module
invites: Sales lists invoices and credit notes, Purchase lists bills and debit
notes, and Insights' Day Book lists every posted document — including the
expenses and journal vouchers neither of those shows. The list behind "Show list"
here is **expenses only**.

**Did not delete the counter.** `resources/views/modules/bills.blade.php` held
the only link to `/bills/new`, and that counter was still the only screen that
could raise a **workshop bill** — a job's parts and labour posted through
`{job}/bill`, which is what stamps the invoice and marks the parts as billed.
Sales cannot do it; it posts `/transactions/sale`. The link was carried across to
the converted module and **C4 retired both together**.

**Reuse.** `payment-rows` for the split, `badge` for the status, `formatMoney`
and `formatQuantity`, `confirmAction` for a reversal.

### Test checklist

- [ ] An expense with claimable GST input, and one without, both post template F
- [ ] Paid from one mode, and split across several
- [ ] Booked only to one of the workshop's own expense accounts; anything else
      is refused with a clear reason
- [ ] The list holds expenses only — a sale posted in Sales does not appear in it
- [ ] A successful create stays on the form, clears it, returns focus to the
      first field, and flags the new row for the next Show (§2A.8)
- [ ] The P&L separates gross margin from overheads on real data
- [x] `/bills/new` is still reachable from this module (until C4 retired it)

**Done when** a workshop's overheads are in the books and the P&L is true. ✅

**What it actually looks like.** The form is the create surface and lands the
module; the list behind "Show list" is `types[]=expense` and nothing else, with
the count on the Show control. A posted expense stays on the form, clears
everything except the **date** — somebody entering a stack of receipts is
entering several from one day — and flags the new row for the next Show.

Two things came out of the conversion that were not in the plan. The **expense
account is a server-side filter rather than a column**: which head a cost was
booked to lives in the ledger entries, and the transaction listing deliberately
does not load them, so the column is the note ("March electricity") and the
account narrows the list through `account_id`, which the index request has always
accepted. And the form now sends a **`client_ref`**, minted per document and
reused on every retry of it — `StoreExpenseRequest` has always accepted one and
this form never sent it, so a request that timed out after the server had posted
would have put the electricity bill in the books twice.

The drawer is read-only plus **Reverse**. There is no correct-and-repost: that is
`revise`, and it is for bills, whose replacement has to be re-priced and re-taxed
against a shelf that has moved since.

---

## C3 · Transactions — settlement and the journal voucher ✅

**Done.** The card is on, `PagesRenderTest` fetches the fragment, and M16's
allocation screen has a caller for the first time. What follows is the record of
what was built and why, not a plan.

**Why here.** Two structural holes, and both are about money that does not
arrive on a document. A customer clearing three invoices with one cheque, or
paying on account before anything is raised, had nowhere to go. And the **manual
journal voucher** is named in CLAUDE.md as the correction mechanism for
everything else in the books — it is why the Insights overview is allowed to
disagree with the P&L at all. Without it the only correction available anywhere
was reversing a whole document.

**Shape — one card, three sections.** Receipt, Payment and Journal voucher are
three write acts on one card, so this follows the **Staff** pattern rather than
inventing one: each section is an ordinary §2A workspace mounted on its own root,
lazily on the first click of its tab, each registering Escape under a key of its
own while the module registers `journal`. It does **not** open by asking which of
the three — it opens on Receipt, which is the one done most.

**Also finished: the allocation screen — this closes M16's 🟡.**
`GET /transactions/{id}/open-bills` and `POST /transactions/{id}/allocate` had no
caller anywhere. Allocation now lives in the drawer of a posted receipt or
payment: the party's open bills, oldest first, an amount against each, and the
unallocated remainder stated plainly. **Nothing guesses which invoice a cheque
was for** — the operator decides, which is precisely why Insights reports an
unallocated receipt as a worklist and refuses to net it away.

**Do not rebuild.** The four tabs of transaction list — Sales, Purchase, Expenses
and the Day Book already draw them. And settlement on the way *in* was not
re-implemented: Sales collects against the invoice its drawer is open on,
Purchase pays a bill the same way, and both already send an explicit
`allocations` entry rather than relying on oldest-first.

**Reuse.** `party-picker` (which fetches the party's position on the pick),
`party-position` for what that position *means*, `payment-rows`, `badge`.

### Test checklist

- [x] A receipt on account, naming no document, posts and leaves a credit balance
- [x] One receipt allocated across three invoices moves each invoice's due;
      over-allocation is refused
- [x] An unallocated receipt appears on Insights' credit panel, and allocating it
      takes it off the worklist without any figure being guessed
- [x] A journal voucher with fewer than two lines, or one that does not balance,
      is refused and writes nothing
- [x] A journal voucher straight to Sales makes the Insights overview disagree
      with the P&L, and the overview states the difference rather than repairing it
- [x] A cheque carries its number; nothing else is demanded
- [x] Escape unwinds one section at a time, and a press on one section's list
      does not swap another section's surface

**Done when** money that arrives without a bill has a home, and the books have
their correction mechanism back. ✅

**What it actually looks like.** Three tabs over three §2A workspaces, mounted
lazily; the module fetches the payment modes on open and nothing else, and the
chart of accounts only when somebody clicks Journal voucher. Receipt and Payment
are **one implementation twice**: one
`partials/settlement-section.blade.php` included with a `$direction`, and one
`settlementSection()` factory called twice, each call closing over its own state
— `pages/counterparty.js`'s pattern, for the reason that file records. The
direction decides the endpoint, the party's role and the wording and nothing
else, because the server does not either: two routes over one
`StoreSettlementRequest`.

Four things came out of the conversion that were not in the plan.

**The allocation picker is drawn from two lists, not one, and this is the part
that is wrong in a way that looks right.** `open-bills` returns what is still
*open*, and `due` there is net of every allocation **including this settlement's
own** — so a bill the receipt has already paid off in full is not open any more
and never appears in it. A panel built from that alone silently drops exactly the
rows somebody re-pointing a cheque wants to change. So `openBills` now carries
the settlement's current allocations in its meta (`allocationMeta()`, the same
shape `POST /allocate` already answered with), the screen merges the two, and a
row's ceiling is what is still owing **plus** whatever this settlement is holding
against it. `AllocationTest` covers both halves; neither had any coverage before.

**A settlement says where its money went, on the form, after it has gone.** A
receipt naming no bills is applied oldest first, which is right far more often
than not — but it is a decision, and §2A.8 clears the form the moment it posts.
So the outcome is stated in a line above the cleared form, naming the documents
it was applied to and what is left on account, with **Change what it settles**
opening the drawer. Not a toast: a toast is gone before somebody has finished
reading the amount.

**No settlement and no journal voucher can be parked.** Money that has moved is
a fact, not a work in progress — the judgement M22 already makes about a payroll
run — and a parked receipt is a cash box that disagrees with the books for as
long as nobody authorises it. A draft that predates the conversion is still
openable from a list and can be posted or discarded from the drawer; this module
creates none.

> **Corrected after C4.** This paragraph first read "there is no save as draft
> any more, anywhere", and that was wrong: the shared bill document still offers
> **Save as a draft**, so Sales and Purchase can still park one. What C3 settled
> is the *settlement* screens. The Jobs bill pane hides the control — a workshop
> bill has no draft path that could stamp the job onto the document — but
> Insights' parked-draft worklist is not yet a set that only shrinks, and saying
> so was a documentation error rather than a change anybody made to the code.

**`party-picker` grew two things it needed and did not have.** Its ids are now
unique per mount, because this is the first module to have more than one on the
page at once — three, all attached, since a tab swap hides a section rather than
detaching it, and fixed ids meant one `<label for>` pointing at another section's
box. And it accepts `role: null` for the voucher's optional counterparty: a
journal does not decide whether its counterparty is a customer or a supplier, so
that picker offers no "what do they owe" line (`outstanding` has two halves and
nothing would say which to read) and no "+ Add" (quick-party never asks which
role a record is — the module it was opened from decides, and this one has not).

One limitation is worth writing down rather than discovering. **A settlement
cannot be left deliberately unallocated while the party has open bills.** An
empty `allocations` array means "oldest first" to the server and not "allocate
nothing" — `AllocateSettlementRequest` says so in as many words, and that is true
on the way in as well as afterwards. The drawer therefore refuses to send a
cleared grid rather than silently doing the opposite of what it looks like, and
says so. Expressing "this is an advance, do not apply it" would need the request
to distinguish an absent key from an empty array, which is a change to a rule
two endpoints share and is not C3's to make.

---

## C4 · Jobs — the bench ✅

**Done.** The card is on, `PagesRenderTest` fetches the fragment, and the last
page shell in the application is gone. What follows is the record of what was
built and why, not a plan.

**Why here.** The largest domain in the product that nobody could reach, and the
workshop's actual trade: receiving a motor, recording the complaint and the
motor's details, moving status, adding parts, estimating, getting approval and
billing. It was complete, it duplicated nothing, and it was covered by 25 feature
tests including the whole walkthrough end to end.

**Shape.** §2A.1 — "Receive a motor" is the create form (party, motor details,
complaint, promised date); the job list is behind "Show list"; a job opens in a
**drawer** carrying the status pipeline, the parts, the estimate and Generate
bill; confirmations are level 3.

**D2 stays on the screen where parts are added: a part written onto a job moves
no stock — the invoice does.** It is obvious in a design document and baffling at
a counter, which is why it is said where the part is added.

**Generate bill** mounts `components/bill-document.js` with a job direction that
posts `{job}/bill`, **never** `/transactions/sale` — only the job endpoint stamps
`workshop_job_id` on the invoice and marks each part with the line it became.
Drafting is off while a job is loaded, for the same reason.

**Then the counter was retired.** `Route::view('/bills/new')`,
`resources/views/bills/new.blade.php` and `resources/js/pages/bill-counter.js`
are deleted, `data-new-bill` is gone from the Expenses module, and
`route('bills.create')` no longer exists. That closes the last page shell in the
application and the third of the loose ends.

**Did not rebuild.** `JobService`, `billPayloadFor()`, the status enum's legal
transitions, or a second item picker: the job card uses the same one the bill
document uses.

### Test checklist

- [x] received → inspection → estimate → in_progress → ready → delivered, with
      illegal jumps refused and the two deliberate exceptions allowed: cancel
      from anywhere unfinished, and ready → in_progress for a failed test run
- [x] Parts added to a job move no stock; billing moves each exactly once
- [x] A cancelled job bills nothing
- [x] An estimate is approved before it can be applied, and applying it copies
      the lines onto the bill
- [x] Generate bill posts `{job}/bill`, stamps the invoice, and marks each part
      with its `transaction_line_id`
- [x] The same job cannot be billed twice for the same part
- [x] M17's shortfall refusal and `client_ref` duplicate protection both hold
      when reached through this path
- [x] `route('bills.create')` has no callers left, and nothing 404s or 500s

**Done when** a motor can be received, worked, estimated, approved and billed
without leaving the dashboard. ✅

---

**What it actually looks like.** Three surfaces and one document. The create form
takes a motor in; "Show list" swaps in the bench with the status tabs, the search
and the overdue filter; a row opens the job card at level 2. Booking one in stays
on the form and clears it (§2A.8), states the job number in a line above it, and
flags the row for the next Show.

Five things came out of the conversion that were not in the plan, and four of
them are the same kind of thing: an endpoint that had been finished for months
and was reachable by nobody, or reachable in a way that silently threw half the
request away.

**The bill is a level-1 pane, not a state of the drawer.** The roadmap said the
drawer carries Generate bill and left where the document goes unsaid. It goes on
the *form* surface: `partials/bill-document.blade.php` is a two-column form with
a searched item picker, a line table, a payment split and a sticky totals panel,
and §2.1 calls exactly that inside a dialog a scroll trap. So the create surface
holds **two panes** — booking a motor in, and billing one — with one shown at a
time, which is §2A.2's judgement applied one level down. Generate bill closes the
drawer, swaps the pane and says at the top of it which job is being billed.

**The lines are sent only when they were changed, and this is the part that is
wrong in a way that looks right.** `POST {job}/bill` pairs each part with the
invoice line it became **by position**, and that pairing only holds while the
lines are the ones `billPayloadFor()` produced — so `JobService::bill()` marks
nothing at all when the payload carries an `items` key. The shared document
always builds one. The counter always sent it, which means the counter had
**never once** marked a part as billed: the same bearings were billable again the
following week, and the only thing standing between a workshop and a double
issue was that nobody could reach the screen.

So the document's lines are fingerprinted the moment they are loaded — as scaled
integers, never parsed as floats — and `items` is **omitted** when nothing about
them changed, which is the ordinary case. Where a rate really was argued down at
the counter the lines are sent, the invoice posts, and the parts stay on the job
card: the service's deliberate "safe way to be wrong", and the banner says so
before anybody presses post. Neither branch had any test coverage before.

**The job endpoint now accepts the whole document.** `BillJobRequest` named six
keys per line and nothing else, so mounting the shared document against it would
have dropped, silently: a line's inclusive-of-tax flag, a line discount given as
a percentage, a discount on the whole repair, and **who did the work**. The last
of those is the one that matters — a rewind is the canonical case for M22's
attribution, "Ramesh fitted it, Sunil wound it", and the one document where it
was guaranteed to be lost. All four are accepted and passed through now, and
`WorkshopJobController::bill()` syncs the attribution inside the same database
transaction as the posting, exactly as `TransactionController::store()` does.

**A job card can be corrected, and a job can be deleted.** `PATCH
/workshop-jobs/{job}` and `DELETE /workshop-jobs/{job}` had no caller anywhere —
so a serial number typed wrong stayed wrong for ever and a motor booked in
against the wrong customer stayed on the bench. Both are in the drawer now.
Correcting is a *state* of that drawer with the create form adopted into it
(`adoptForm()`, so the fields exist once); the customer and the received date are
inline-only, because `UpdateJobRequest` accepts neither.

**The bus row was too narrow.** `/workshop-jobs` announced `transactions` and
`stock` — right for a part being issued, and wrong for `{job}/bill`, which posts
a sale. It announces `parties` and `ledger` as well now, or a workshop bill left
a held Customers list and a held statement a repair out of date.

Two limitations are deliberate and worth knowing before somebody "fixes" them.
The job card's part picker offers **no "create a new item"**: the one quick-add
dialog in this module belongs to the bill document, which is a level-1 surface
and is therefore detached exactly while the drawer is open over the list — a
second copy would be two nodes with one id. The picker says where a missing part
comes from instead. And the intake form does not offer **`item_id`**, the
catalogue row for a motor the workshop deals in: the request accepts it, nothing
in the application reads it, and a field written by a form and read by nothing is
a field that goes quietly wrong.

The customer's copy of the invoice is **not** offered here yet. `#invoice-preview`
— the one sheet, with Print and Share — is Sales' drawer and about three hundred
lines of `pages/sales.js`; borrowing it, which CLAUDE.md requires of the next
module that hands a customer a document, means extracting that into a component,
and that is a refactor of the highest-traffic module in the product rather than a
part of this step. A job bill states its invoice number and total above the
cleared form, with a link back to the job card; printing it is Sales' screen
until the extraction is done.

---

## C5 · Accounting + Ledger — one card ✅

**Done.** The card is on, `PagesRenderTest` fetches its fragment, and the
`ledger` key is gone from the registry. What follows is the record of what was
built and why, not a plan.

**Merged, and the merge was the point.** They were the same question at two zoom
levels, and `accounts.js` had already deferred to the Ledger screen in so many
words: its drawer showed the last ten entries because "the full statement is the
Ledger screen's job". Two cards would both have answered "what does this account
stand at", and would have needed two period pickers and two trial-balance
renderers — and the second copy of each is the one that drifts. The key stays
`accounts`, labelled **Accounting**; `ledger` is gone, and its redirect is
registered **by hand** in `routes/web.php`, because the loop there declares one
redirect per module the registry still names.

**Shape.** §2A.1 — creating an expense head is a real create act, so the account
form is the create surface, and until this step no workshop could perform it:
`POST /accounts` has exactly one caller in the whole front end and it is this
module. The seeded fifteen were all a workshop would ever get.

The list surface carries **two views over one period picker**: the chart of
accounts, grouped by type with what each one stands at, and the **trial balance
with its reconciliation stated** — the single most important figure on the
screen, because if the two sides differ everything else on it is suspect. Both
are level 1. The account drawer (level 2) holds the running statement, the CSV of
the whole of it, and the edit — which is a *state* of that drawer with the create
form adopted into it (`adoptForm()`), so the fields exist once.

**Two grants, one card.** The chart is `READ:ACCOUNTS`; every figure on the
screen is `READ:LEDGER`. A holder of the first without the second gets the chart
with **no figures at all** — absent, not blanked, the same judgement Insights'
People tab makes. Three things carry the mark and are removed together: the
balance column's period picker, the trial-balance panel, and the switch that
would reach it. With no figures anywhere there is one view, and a switch to a
view that would be blank is a switch to nothing.

**Two decisions in it are wrong in ways that look right.**

**The search box and the archived select narrow the chart and are hidden on the
trial balance.** A trial balance's totals come from the server, over every
account with movement in the period — archived ones included, because an archived
account still holds whatever was posted to it. Narrow its rows in the browser and
the columns stop adding up to the figures beneath them, with nothing on screen
saying so. The period is the one control both views share, because it changes
what every figure *means* rather than which of them are shown.

**The chart is fetched whole, `is_active` deliberately unused.** An archived
account still owns its code. Ask the index endpoint for the active ones and the
form's next-free-code suggestion cannot see the rest, so it offers a number the
server then refuses — a 422 on a field the screen had filled in itself. A chart
of accounts is bounded and small, so one request holds all of it and the archived
select narrows what is drawn. The form fetches it too, on the first type chosen,
because a code suggestion needs it: whichever of the form and the list asks first
pays for it, and the other reuses it.

**One control was broken and is now fixed.** The statement CSV asked for
`per_page=1000` against an endpoint that caps it at 200, so it had answered 422
and produced no file at all. It walks the pages now, and says so if an account
has more entries than the guard allows.

**What was deleted rather than moved.** The **Journal Entries** tab, its drawer
and its `READ:TRANSACTIONS` gating. Every row of it is on Insights' Day Book,
which lists every posted document including the journals Sales and Purchase do
not show — a fourth copy would have been screens answering one question (§5.1).
The per-row action menu went with it: a row opens the drawer, and the drawer
holds the actions, which is what every other converted module does (§7.4).

**Do not rebuild.** The day book, the P&L or the GST summary (Insights has all
three), and a party's ledger and statement (Customers and Vendors have them).

### Test checklist

- [x] A custom account is created with its code band shown from
      `GET /accounts/types`; a system account's code and type are locked, and its
      archive control is disabled with its reason beside it rather than hidden
- [x] Archive and restore a custom account; there is no delete and no route for
      one, because an account that has been posted to must keep its name
- [x] Trial balance totals are equal, and the banner states the reconciliation
      rather than leaving it to be worked out
- [x] A `READ:ACCOUNTS` holder without `READ:LEDGER` sees the chart and no
      balances anywhere on the screen
- [x] The period picker is shared by both views and survives the round trip to
      the drawer and back (§3.6)
- [x] The `ledger` key is gone from `config/modules.php`, `/ledger` still
      redirects, and `/modules/ledger` answers 404

**Done when** an accountant can be shown a trial balance that reconciles, from a
card. They can.

---

## C6 · Uploads

**Shape.** §2A.1, and the create act is the upload itself: the drop target is the
form, the library is behind "Show list". Keep the queue visually separate from
the library — a file still travelling is not yet one of the workshop's records,
and that separation is the design rather than a layout accident.

**Bus.** Do **not** add an `attachments` resource yet. Nothing else in the
application attaches a file, so the module's own reload after its own write is
sufficient, and a bus row nothing listens to is the kind of thing §7.1 refuses.
Add it the day a bill drawer can attach evidence.

**Do not rebuild.** `AttachmentService`, the sniffed media type, the
read-back-before-ready rule, or `watchJob()` in `job-progress.js` — the progress
poller already exists and this module is its only consumer.

### Test checklist

- [ ] An upload reports progress from `/api/v1/jobs` and reaches `ready`
- [ ] Nothing is `ready` until it has been read back from storage
- [ ] The media type is sniffed from the bytes, never taken from `Content-Type`
- [ ] The object key never reaches the browser
- [ ] Download and delete work, and a duplicate upload is reported rather than
      refused or silently merged
- [ ] A file that fails processing says so, and can be retried or removed

**Done when** a supplier's bill can be photographed at the counter and found
again.

---

## C7 · Workshops

**History went on ahead, on 7 September 2026.** It was the read-mostly half of
this step and needed no re-flow to speak of — one list, no create, no modal — so
it took `mountWorkspace(..., { canCreate: false })`, a `data-ws-list` wrapper and
the flag, and its card is on. Its five checklist items below are ticked. What is
left here is Workshops alone.

**Why it was worth taking out of order.** The trail is the whole safeguard on
`PATCH /transactions/{id}/staff` — the one write in this application that edits a
posted document — and until the card went on, that safeguard existed and nobody
could read it.

**Workshops (`tenants`)** — §2A.1: provisioning a workshop is the create form,
with the optional owner block all-or-nothing; the platform list is behind Show;
suspend, reactivate and delete are drawer actions whose confirmations explain the
effect. `workspace => false`: this is the one module about other people's books,
and a platform admin holding every grant owns none of their own.

**History (`audit`) — done.** §2A.10 read-mostly: it opens on its list,
`canCreate: false`, and paints no switch control. Filter by kind, record, action,
person and date, with the changed fields inline on the row that describes them.
**No detail modal** — an entry *is* its detail. An unknown filter is refused, not
ignored: ignoring it shows a complete history to somebody who believes it is
filtered, and they draw a conclusion from the difference. Two things the
conversion added: the filter option lists are **rebuilt rather than appended to**,
because meta is fetched again whenever the trail goes stale and appending would
offer every option twice; and the current choice is put back afterwards, so a
refresh cannot silently widen the filter somebody is reading through.

### Test checklist

- [ ] A workshop is provisioned with its owner in one act, or neither is written
- [ ] Delete is refused with a clear reason while a workshop still has users
- [ ] The per-row user count is fetched after the list, so paging stays cheap
- [ ] A platform admin editing a workshop's settings writes into **that
      workshop's** history
- [x] Archived and restored appear as their own actions, not as field edits
- [x] `PATCH /transactions/{id}/staff` — the one write in this application that
      edits a posted document — is on the trail, which is the whole of its
      safeguard
- [x] The trail opens on its list, declares no `data-ws-form`, and its coverage
      in `PagesRenderTest` is fetched from `/modules/audit` rather than rendered
- [ ] Neither card is offered to a caller without its grant

**Done when** the platform can onboard and suspend a workshop. The second half —
somebody can ask "who changed this, and when" across a whole workshop — is
**done**.

---

## C8 · Verification — one workshop day

The step the workshop redesign planned and never ran. Everything else in Part E
converts a screen; this one proves the modules still agree with one another once
they are all reachable.

- [ ] One `WorkshopFlowTest` walking a single day: purchase 10 bearings → sell 3
      → a job consuming 2 → cancel one → return one → part-pay the invoice →
      submit twice → over-sell → one combined goods-and-labour bill
- [ ] The invariants asserted throughout rather than at the end: the trial
      balance reconciles, stock value equals the Inventory account, and every
      allocation sums to no more than its bill's total
- [ ] Every module with `'enabled' => true` answers on `/modules/{key}` and has
      an entry in the lazy `import()` registry — so a flag flipped without its
      fragment cannot ship
- [ ] `docs/workshop-flow.md` written: the technical summary that plan owed
- [ ] `php artisan test --order-by=random` green

**Done when** all twenty cards can be exercised in one sitting without a figure
on one screen disagreeing with the same figure on another.
---

# Part F — The AI capture agent (M15)

**M15 is parked.** On **7 September 2026** the owner took it out of scope: the
product is to be finished end to end without it, which is what
[execution-plan.md](execution-plan.md) schedules. This part stays exactly as
written, because none of its reasoning has changed and nothing else in the
product waits on it — restarting it is a decision rather than a schedule slot.
Everything below describes the agent as it was planned.

**Scheduled after Part E, and the reason is the one that put it last originally,
sharpened by what has happened since.** The agent's whole job is to fill a draft
that a person then posts, and seven of the surfaces it would post into — the
expense, the receipt, the payment, the journal voucher, the job card, the opening
balances and the upload library it reads a photograph from — are behind a
switched-off card today. Building it first would mean building against screens
nobody can open, and discovering at conversion time that the flow it assumed is
not the flow the module got.

Two things have changed since it was planned, and both make it cheaper. **C6
gives it a screen** for the photograph it starts from. And
`POST /transactions/preview` (M17) already prices a payload without posting it,
which is most of what M15.5's preview card has to render.

## M15 · AI Capture Agent ⬜

**Depends on:** M4–M12 complete and correct, plus M14.

M14 left the ground prepared. A capture is a `TrackedJob` — the tenant and the
actor cross the queue boundary already, `JobProgress` reports back, and
`watchJob()` in `resources/js/job-progress.js` is what a preview card polls. A
photograph or a recording is an `Attachment`, verified before anything reads it.
The one column deliberately still missing is `attachments.transaction_id`, which
M15 adds with its foreign key — the same discipline M5 applied to `party_id`.

Built last, deliberately: *if the ledger and inventory are not correct under
manual entry, the agent only produces wrong entries faster.*

| Sub-module | Delivers |
| --- | --- |
| M15.1 Draft infrastructure | Draft / parked / posted states, one active draft, park queue |
| M15.2 Text capture | Typed input → LLM extract → resolve → preview → commit |
| M15.3 Resolution | Deterministic fuzzy match to catalogue and parties — **no LLM** |
| M15.4 Clarify | Templated questions for missing or low-confidence slots |
| M15.5 Preview card | Lines, cost/sell/margin, GST, totals, payment split, warnings |
| M15.6 Voice | Push-to-talk, per-word confidence, raw audio stored, fallback to text |
| M15.7 Image / OCR | Invoice photo → editable draft, never auto-posted |

**Test checklist**

- [ ] The LLM never writes to the ledger — it only fills a draft
- [ ] Nothing commits without explicit user authorisation
- [ ] Low-confidence numbers always surface for visual confirmation
- [ ] Garbled audio falls back to text instead of guessing
- [ ] A stale parked draft is re-validated and re-priced before it posts
- [ ] A hallucinated item name resolves to "unknown", never to the wrong SKU
- [ ] Cost per transaction stays within the ₹0.50–1.50 target

---

# Part G — Not scheduled

Work that is real, wanted, and deliberately not in a phase above. Nothing here
has a number: numbers are assigned when the work is scheduled, which is what
stopped the old M16–M20 labels from meaning two things at once.

## Carried from M1, and not about the card

**Three of these four are now scheduled** as P6 in
[execution-plan.md](execution-plan.md) — reset, invitation and the mail transport
that blocks both. Only 2FA stays here.

| Gap | Why it matters | Priority |
| --- | --- | --- |
| No password reset / forgot-password flow | A locked-out owner has to call support | Medium |
| No user invitation flow — `POST /users` sets a password directly | An owner must invent and transmit a password for each fitter | Medium |
| No email sending configured | Blocks both of the above | Medium |
| No 2FA for password sign-in | Financial data. Partly answered by passkeys, which are a second factor's worth of assurance on the devices that have one | Low |

## From the PRD, still unbuilt

| Item | State | Note |
| --- | --- | --- |
| **Tally sync** — XML export, local gateway, cloud connector, sync log | Nothing built | The journals are already in Tally-compatible double-entry form, which is the whole of the preparation done. Budget real time for the connector: Tally is finicky about XML |
| **A mobile capture app** | Nothing built | The web application is responsive on phone, tablet, laptop and desktop (§7.3). A native app is only worth it for the capture agent's microphone and camera, so it belongs with M15 or after it |
| **Regional languages in the application** | Only the public site is bilingual | `lang/en` and `lang/hi` hold `site.php` and nothing else. The application has no translation layer, and adding one is a sweep across every screen — schedule it as its own phase, never as a side effect of another |
| **Multi-tenant SaaS billing** | Nothing built | Tenancy, suspension and the platform admin surface exist; what a workshop is charged does not |
| **Smart-speaker interface** | Nothing built | PRD phase 4, and correctly last: voice-only confirmation of money amounts is the risk the PRD itself names |
| **Batch / lot tracking and expiry** | Nothing built, and deliberately | It touches `stock_movements`, which the catalogue rebuild left alone. Half of it is worse than none |
| **Unit conversion** between a purchase document and the stock ledger | Nothing built, and deliberately | A factor that is ever wrong corrupts stock and the Inventory account together, silently |

## Deliberately refused

Recorded so nobody schedules them by accident. Each has its reasoning written
where the module lives.

- **Sales:** no quotation, no delivery challan, no recurring invoice, no
  e-invoice. A quotation and a challan each want their own numbering and
  lifecycle, and a challan moves goods without billing them — a second writer to
  `stock_movements`.
- **Purchase:** no purchase order, no goods-received note, no landed cost. Each
  touches *when* stock moves or *what it is valued at*.
- **Staff:** no piece rate, and attribution is never an input to pay. A
  throughput figure that quietly became a wage is a second source of truth for
  what somebody is owed.
- **Payroll:** no salary-payable liability and no draft run. Half a payables
  ledger is worse than none, and a parked sheet is figures derived from a
  register that keeps moving under it.
- **Insights:** nothing stored, no nightly rollup. If it becomes slow, the answer
  is an index.
- **Home:** no figure on a card, and no `GET /api/v1/dashboard`. That shell is
  public, and a second service answering "how is the business doing" beside
  Insights is the one that drifts.

---

# Build order

Strictly sequential from here. Do not start a step until the previous one's
checklist is complete.

```
════════════ built ═════════════════════════
M1    Authentication & RBAC              ✅  + passkeys, added later
M2    Tenancy                            ✅
M3    Chart of accounts                  ✅
M4    Ledger & posting engine            ✅  THE critical module
M5    Parties                            ✅
M6    Payments & receipts                ✅
M7    Item master                        ✅  vocabulary later became data
M8    Inventory & WAC                    ✅
M9    Bill engine                        ✅
M10   Misc expense                       ✅
M11   Opening balances                   ✅
M12   Reports & worklists                ✅  now four tabs of Insights
M13   Audit log                          ✅
M14   Async jobs & storage               ✅
M16   Per-invoice money                  ✅  allocate screen shipped in C3
M17   Stock discipline                   ✅  negative-stock control shipped in C1
M18   Returns                            ✅
M19   Workshop jobs                      ✅  card on since C4; edit, delete
                                            and attribution shipped with it
M20   Bill counter & customer invoice    ✅  the counter retired at C4; the
                                            document and the invoice live on
M21   Jobs screen, sweep                 🟡  dashboard half deleted
M22   Staff, attendance, payroll         ✅
M23   Insights                           ✅
════════════ the card conversion ═══════════
C1    Settings + Opening balances        ✅  a workshop can go live
C2    Bills → expenses                   ✅  the P&L has overheads
C3    Transactions                       ✅  receipts on account, the allocation
                                            screen, and the journal voucher
C4    Jobs                               ✅  the trade itself; retired
                                            /bills/new, the last page shell
C5    Accounting + Ledger, merged        ✅  an expense head of the workshop's
                                            own, and a trial balance that
                                            reconciles. The `ledger` key is gone
C6    Uploads                            ← next
C7    Workshops                            History went early, 7 Sep — it
                                            needed no re-flow to speak of
C8    Verification — one workshop day
════════════ then ══════════════════════════
M15   AI capture agent                      the PRD's headline, built last
      Part G                                unscheduled: Tally, languages,
                                            SaaS billing, mobile, speaker
```

**After every C step:** move that module's coverage in `PagesRenderTest` to
`$this->get('/modules/{key}')`, flip `'enabled' => true`, delete its section from
[hidden-modules.md](hidden-modules.md), and update this document's module map.

# Testing rules that apply to every module

1. **Tenant isolation.** Every new tenant-owned model uses `BelongsToTenant`.
   `TenantIsolationInvariantTest` fails the build otherwise — never add an
   exemption without a written reason.
2. **The trial balance must reconcile** after every scenario, in every module
   from M4 onwards. Make it an assertion helper and use it everywhere.
3. **No stored balances.** If a number can be derived from `journal_entries` or
   `stock_movements`, derive it. Stored aggregates drift.
4. **Money is `DECIMAL`.** Never float, anywhere, for any reason.
5. **Run `--order-by=random`** before calling a module done. Order-dependent
   tests hide real bugs.
6. **Update the module's doc** in `docs/` as part of the module, not afterwards.
7. **A module is not done until its card is on.** A screen that works and is
   unreachable is indistinguishable, from where a workshop sits, from one that
   was never built. Convert, move the coverage, flip the flag, and delete the
   module's section from [hidden-modules.md](hidden-modules.md) in the same
   change.

---

Local development credentials are in [CLAUDE.md](../CLAUDE.md) §10, which is the
only place they are written down. Do not copy them here, and do not reset them.
