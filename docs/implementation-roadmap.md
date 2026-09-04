# Implementation Roadmap

Module-by-module plan for the whole product, ordered so that base modules land
first and every module is independently testable.

**How to use this document:** each module has a *Test checklist* — the things
that must be true before the module is considered done. Work one module at a
time, tick its checklist, then move on. Nothing later depends on a module whose
checklist is incomplete.

## Status legend

| | Meaning |
| --- | --- |
| ✅ | Done and tested |
| 🔒 | Done and tested; the *screen* that presents it is a switched-off card. The code behind it still runs wherever it is used — M4's posting engine is under every enabled module, and only the Ledger card is off. See [hidden-modules.md](hidden-modules.md) |
| 🟡 | Partly done — see the module's gap list |
| ⬜ | Not started |

## Module map

M1–M14 are this document's plan. M16–M23 were planned separately, in
[modified-flow-plan.md](modified-flow-plan.md), and are listed here so that one
table answers "what exists". The numbering below is the real one: the row that
once read "M16–M19 · Payroll, Tally, languages, billing" was a guess made before
the workshop redesign and never became work.

| # | Module | Layer | Status | Reference |
| --- | --- | --- | --- | --- |
| **M1** | Authentication & RBAC | Base | ✅ | [auth-module.md](auth-module.md) |
| **M2** | Tenancy | Base | 🔒 | [tenancy-module.md](tenancy-module.md) |
| **M3** | Chart of Accounts | Base | 🔒 | [accounting-module.md](accounting-module.md) |
| **M4** | Ledger & Posting Engine | Accounting core | 🔒 | [ledger-module.md](ledger-module.md) |
| **M5** | Parties | Accounting core | ✅ | [parties-module.md](parties-module.md) |
| **M6** | Payments & Receipts | Accounting core | 🔒 | [payments-module.md](payments-module.md) |
| **M7** | Item Master & Variants | Inventory | ✅ | [items-module.md](items-module.md), [catalogue-master.md](catalogue-master.md) |
| **M8** | Inventory & WAC | Inventory | ✅ | [inventory-module.md](inventory-module.md) |
| **M9** | Bill Engine (Sales & Purchases) | Billing | ✅ | [billing-module.md](billing-module.md), [sales-module.md](sales-module.md), [purchase-module.md](purchase-module.md) |
| **M10** | Misc Expense | Billing | 🔒 | [billing-module.md](billing-module.md) |
| **M11** | Opening Balances | Onboarding | 🔒 | [opening-balances-module.md](opening-balances-module.md) |
| **M12** | Reports & Worklists | Back-office | ✅ | [reports-module.md](reports-module.md) |
| **M13** | Audit Log | Cross-cutting | 🔒 | [audit-module.md](audit-module.md) |
| **M14** | Async Jobs & Object Storage | Cross-cutting | 🔒 | [async-module.md](async-module.md) |
| **M15** | AI Capture Agent | AI | ⬜ | not started — no code, no table, no card |
| **M16** | Per-invoice money: numbering, allocation, ageing | Billing | 🟡 | [modified-flow-plan.md](modified-flow-plan.md) |
| **M17** | Stock discipline & duplicate protection | Inventory | ✅ | [modified-flow-plan.md](modified-flow-plan.md) |
| **M18** | Returns | Billing | ✅ | [sales-module.md](sales-module.md) |
| **M19** | Workshop jobs | Workshop | 🔒 | [workshop-module.md](workshop-module.md) |
| **M20** | The bill counter & the customer's invoice | Billing | ✅ | [billing-module.md](billing-module.md) |
| **M21** | Jobs screen, dashboard, consistency sweep | Cross-cutting | 🔒 | [modified-flow-plan.md](modified-flow-plan.md) |
| **M22** | Staff, attendance, payroll, advances | Workshop | ✅ | [staff-module.md](staff-module.md), [work-attribution.md](work-attribution.md) |
| **M23** | Insights | Back-office | ✅ | [insights-module.md](insights-module.md) |

Dependency order is strictly top to bottom for M1–M14. **M4 is the single most
important module in the product** — everything above it is plumbing and
everything below it is built on its correctness.

### Reading the 🔒 rows

A locked row is **finished work behind a switched-off card**, not work in
progress. The module was built, tested and used as a page; it is off only because
its screen has not been re-flowed to the §2A workspace shape that
[CLAUDE.md](../CLAUDE.md) now requires. What each one still holds that no enabled
card can do — an expense, a journal voucher, the trial balance, the audit trail,
go-live balances — is set out in [hidden-modules.md](hidden-modules.md), which is
the file to read before converting one.

Two rows are qualified:

- **M16 is 🟡**, not ✅. Document numbering, allocation, per-invoice paid/due and
  the ageing all work and are tested. What is missing is a screen for allocating
  a receipt *after* it was taken: `POST /transactions/{id}/allocate` and
  `GET /transactions/{id}/open-bills` have no caller in the front end.
- **M21 is 🔒 and partly deleted.** The jobs screen exists and is behind the
  `jobs` switch. The dashboard half was removed: home is the module card grid and
  carries no figures, so `GET /api/v1/dashboard` and `DashboardService` were
  deleted rather than left dormant beside M23, which answers the same question.
  The §38 consistency sweep shipped and stands.

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
- [x] Sidebar entry visible **only** to holders of `READ:TENANTS`

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
- [x] Sidebar entry gated on `READ:ACCOUNTS`

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

# Part D — Phase 1 AI

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

# Part E — Phase 2, 3 and 4

| # | Module | Phase | Notes |
| --- | --- | --- | --- |
| M16 | Payroll, attendance, staff advances | 2 | Staff Advance and Salary Expense accounts already seeded |
| M17 | Tally local sync | 2 | Journals already in Tally-compatible double-entry form |
| M18 | Tally cloud, GST reports, analytics | 3 | |
| M19 | Regional languages, multi-tenant billing | 3 | |
| M20 | Smart speaker, batch/lot tracking | 4 | `lot_id` already reserved |

---

# Suggested build order

Strictly sequential. Do not start a module until the previous one's checklist
is complete.

```
M2.1  Workshop self-service API      ✅ done
M2.2  Workshop settings              ✅ done
M3.1  Chart of accounts UI           ✅ done — M3 complete
M2.3  Tenant admin UI                ✅ done
M2.4  Sign-up & onboarding UI        ✅ done — M2 complete
════════════ base modules complete ═════════
M4    Ledger & posting engine        ✅ done — THE critical module
M5    Parties                        ✅ done
M6    Payments & receipts            ✅ done — the engine's first end-to-end
                                        proof with a business document behind it
M7    Item master                    ✅ done — catalogue only, no posting template
M8    Inventory & WAC                ✅ done — the first module to write two kinds
                                        of record inside one transaction
M9    Bill engine                    ✅ done — everything above converges here
M10   Misc expense                   ✅ done — template F
M11   Opening balances               ✅ done — template H; a workshop can
                                        now go live without a developer
M12   Reports & worklists            ✅ done — half of it was already true,
                                        and nothing existing was duplicated
════════════ Phase 1 accounting complete ═══
M13   Audit log                        ✅ done — and most of it turned out
                                          already to exist: the ledger is its
                                          own trail, so this is the master-data
                                          half nothing else covered
M14   Async jobs & storage             ✅ done — the queue *boundary* is the
                                          module: a worker has no request, so
                                          the tenant and the actor have to be
                                          carried across it
M15   AI capture agent                 ← next
```

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



Email:    owner@demo.test
Password: Owner@Demo1234
