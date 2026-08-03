# Ledger & Posting Engine

The accounting core. Every number this product will ever report — an account
balance, a party's outstanding, stock value, GST payable, the P&L — is a sum
over one table, `journal_entries`, and the only thing that writes to it is the
posting engine.

Everything built after this module inherits its correctness. A bug here is
invisible for months and then unrecoverable, which is why it carries more test
coverage than anything else in the codebase.

> *A ledger is just a filtered view of journal entries by account. You never
> write to a ledger directly — you write balanced journal entries, and every
> ledger is a query.* — the PRD

## The four rules

Everything below is a consequence of these.

1. **Debits equal credits, or nothing posts.** Checked in whole paise, and the
   whole transaction is refused rather than adjusted. There is no rounding line
   and no plug.
2. **Money is `DECIMAL`, never float.** [`Money`](../app/Support/Money.php)
   holds an integer number of paise and every operation is integer arithmetic.
3. **Nothing is stored that can be derived.** There is no balance column
   anywhere — not on an account, not on a transaction, not in a report table.
4. **What is posted is permanent.** A posted transaction is never edited or
   deleted; it is corrected by a reversing entry, which leaves both the mistake
   and the correction on the record.

## Why money is not a float

`0.1 + 0.2 !== 0.3` in binary floating point. A balance check written against
floats therefore *rejects entries that are correct* — and, on other inputs,
accepts ones that are not. Both failures are silent.

`Money` parses at the boundary and never multiplies its way in:

```php
Money::of('1234.50')      // a decimal string, from a column or a form
Money::of(1234.50)        // a float from json_decode — converted via its
                          // decimal representation, never by ×100
Money::of(1234)           // rupees
```

Amounts leave over the API as **strings**, not JSON numbers, because a JSON
number is parsed straight back into a float by every client that receives it.
The front end never calls `Number()` on one either: `formatMoney()` in
[`ui.js`](../resources/js/ui.js) groups the digits themselves.

`MoneyTest` pins all of this down, including a hundred entries of ₹0.10 summing
to exactly ₹10.00.

## Schema

### `transactions` — the business event

```
id, tenant_id, type, status, source, party_id, date, total, notes,
draft_lines (json), created_by, posted_at, reverses_id, timestamps
```

`date` is when the event happened, never when it was entered — a bill captured
on Monday may be dated Friday, and every report is built on this column.

`party_id` is the counterparty, added by M5. Nullable and legitimately so: a
depreciation journal and a correcting entry have no other side to them. It is
what turns a control-account balance into a per-party one.

`total` is one side of the transaction, not the sum of both. It exists for
listing and search; no report derives anything from it.

### `journal_entries` — the ledger

```
id, tenant_id, transaction_id, account_id, line_no,
debit, credit, date, memo, created_at

  index (tenant_id, account_id, date, id)     one account's ledger
  index (tenant_id, date)                     the day book, the trial balance
  unique (transaction_id, line_no)

  CHECK ((debit = 0) <> (credit = 0))         exactly one side carries the amount
  CHECK (debit >= 0 AND credit >= 0)          the side carries the direction
```

`created_at` only, and no `updated_at`: an entry is written once, so a column
claiming to record its last modification would be a permanent lie.

`date` is copied from the transaction. It is a duplicate, but an immutable one —
a posted transaction's date can never change — and it turns every ledger and
every period report into a single-table indexed range scan instead of a join.

The two CHECK constraints restate rules the engine already enforces. This
module's premise is that a silently corrupted ledger is unrecoverable, so the
rules are also stated where nothing can bypass them: a future import script, a
migration, a mistake. `PostingEngineTest` proves the database itself refuses a
line written on both sides, neither side, or with a negative amount.

Both tables are tenant-owned, use `BelongsToTenant`, and declare `tenant_id`
NOT NULL — enforced by `TenantIsolationInvariantTest`.

## Where a draft lives

**Journal entries exist only for a posted transaction.** A draft holds its
intended lines in `transactions.draft_lines`, outside the ledger entirely.

That is a structural guarantee rather than a filter. If drafts lived in
`journal_entries` with a status, then every ledger query, every balance, every
report and every future module would have to remember to exclude them — and the
one that forgot would be wrong in a way nobody notices. Instead an unauthorised
transaction is simply *absent*.

`draft_lines` is nulled in the same statement that posts, so the intended lines
and the written ones never both exist and disagree.

A draft may be saved unbalanced. It is work in progress, and refusing to save a
half-written voucher only pushes people into posting before they are ready. The
balance is enforced at the moment it starts to matter — posting.

## The three states

| Status | In the ledger | Editable | Meaning |
| --- | --- | --- | --- |
| `draft` | ✗ | ✅ | Written, not authorised. Nothing has moved |
| `posted` | ✅ | ❌ | In the books, permanently |
| `reversed` | ✅ | ❌ | Cancelled by a reversing transaction; entries remain |

The transition `posted → reversed` is the **only** change a posted transaction
ever undergoes. [`Transaction`](../app/Models/Transaction.php) enforces that in
its own `updating` hook, not merely in the service, so no future code path can
route around it. `JournalEntry` refuses every update and delete outright.

## Reversal

Accounting corrects by addition. `PostingEngine::reverse()` posts a new
transaction with every line of the original on the opposite side:

* the original keeps all of its entries and moves to `reversed`;
* the reversal carries `reverses_id`, so both ends of the pair are navigable;
* the net effect on every account is nil, and the trial balance still
  reconciles;
* nothing is recalculated — whatever the original put into the books, the
  reversal takes back out, so the pair nets to zero even if the rules that
  produced the original have since changed.

The reversal is dated **today** by default, so a correction lands in the period
it was decided in. Passing a date allows a same-period correction, which is what
an accountant closing a month wants.

## Posting templates

A template is where "a sale" becomes "debit the customer, credit Sales, credit
GST Output, debit COGS, credit Inventory". One class per transaction type, so
that double entry is never re-derived — slightly differently each time — in a
controller, an importer and an AI draft.

```
PostingTemplate            the interface: input -> lines
ManualJournalTemplate      the only one today
PostingTemplateRegistry    which template posts which type
```

A type with no registered template **cannot be posted at all**. A transaction
whose accounting consequences are undefined must not reach the ledger by falling
through to a default.

Templates resolve accounts through
`ChartOfAccountService::system(SystemAccount::Cogs)` — never by name, never by
id — so a workshop that renames or renumbers its chart changes nothing here. See
[accounting-module.md](accounting-module.md).

| Template | Type | Lands in |
| --- | --- | --- |
| Manual journal | `journal` | **M4** |
| Vendor payment (D), customer receipt (E) | `payment`, `receipt` | **M6** — see [payments-module.md](payments-module.md) |
| Sale (A), job (B), purchase (C) | `sale`, `purchase` | M9 |
| Misc expense (F) | `expense` | M10 |
| Opening balances | `opening` | M11 |

Each later module adds its enum case *and* its template in the same slice.

## The engine

[`PostingEngine`](../app/Services/Accounting/PostingEngine.php) is the only
thing in the application that writes to `journal_entries`. It does the same
four things every time:

1. Build the lines from the type's template.
2. Validate them.
3. Write the transaction and its entries in **one** database transaction.
4. Refuse everything else.

Validation runs cheapest-and-most-specific first, so the message names the
actual problem — "line 3 has no amount" rather than "debits and credits do not
balance":

| Refusal | Error code | Status |
| --- | --- | --- |
| Fewer than two lines | `JOURNAL_TOO_FEW_LINES` | 422 |
| A line on both sides, or neither | `JOURNAL_LINE_INVALID` | 422 |
| A zero or negative amount | `JOURNAL_LINE_INVALID` | 422 |
| An account not in this workshop's chart | `JOURNAL_ACCOUNT_UNKNOWN` | 422 |
| An archived account | `JOURNAL_ACCOUNT_ARCHIVED` | 422 |
| Dated before go-live | `BOOKS_CLOSED` | 422 |
| Debits ≠ credits | `JOURNAL_UNBALANCED` | 422 |
| A settlement with no party, or the wrong role | `PARTY_REQUIRED`, `PARTY_ROLE_MISMATCH` | 422 — M6 |
| A settlement whose split is missing or disagrees | `PAYMENT_*` | 422 — M6 |
| Editing or deleting something posted | `TRANSACTION_IMMUTABLE` | 403 |
| Reversing something already reversed | `TRANSACTION_ALREADY_REVERSED` | 409 |
| Posting something that is not a draft | `TRANSACTION_NOT_A_DRAFT` | 409 |
| Reversing a draft | `TRANSACTION_NOT_POSTED` | 409 |
| A type with no template | `POSTING_TEMPLATE_MISSING` | 422 |
| Updating or deleting a ledger entry | `LEDGER_IMMUTABLE` | 500 — *a bug* |

`BOOKS_CLOSED` is M2.2's `Tenant::acceptsPostingOn()` finally enforced. It
applies to drafts as well as postings: a workshop that could save a draft it can
never post has been misled at the wrong moment.

### A `PostingLine` cannot be malformed

Lines are held as a *side* plus an amount rather than as a debit column and a
credit column, so a line that is somehow both — or neither — cannot be
constructed. Turning a client's `{debit, credit}` pair into one is the only
place that ambiguity exists, and `PostingLine::fromInput()` refuses it there,
naming the row the user is looking at.

### Concurrency

Each posting is internally balanced and commits in a single database
transaction, so whatever order concurrent postings interleave in, the total of a
set of balanced sets is balanced. The engine holds no state between calls and
reads its tenant from the context each time.

## Reading the books

[`LedgerService`](../app/Services/Accounting/LedgerService.php). Nothing here
writes; everything is derived on the spot.

**An account ledger** is its entries in date order, each carrying the running
balance after it. The running balance is cumulative from the first entry ever
posted, not from the start of the filtered period — which is why a June ledger
opens at the balance brought forward instead of at zero, and why page 2
continues from page 1 rather than restarting.

**The trial balance** reports both equalities, because a single compensating
pair of errors can satisfy one and not the other:

* *gross movements* — every debit against every credit;
* *net balances* — the same after each account is collapsed onto one side, which
  is the form a printed trial balance takes.

An account holding the opposite of its normal balance — an overdrawn bank
account, a customer in credit — is reported on the side it actually falls on.
Forcing it onto its normal side as a negative number is how a trial balance
stops adding up.

An untouched workshop returns no rows and reconciles at 0 = 0. That is a correct
answer, not an empty one.

## Endpoints

`/api/v1`, behind `auth.jwt` and tenant-scoped by the global scope.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/transactions` | `READ:TRANSACTIONS` |
| GET | `/transactions/meta` | `READ:TRANSACTIONS` |
| GET | `/transactions/counts` | `READ:TRANSACTIONS` |
| GET | `/transactions/{id}` | `READ:TRANSACTIONS` |
| POST | `/transactions/journal` | `WRITE:TRANSACTIONS` |
| POST | `/transactions/payment` | `WRITE:TRANSACTIONS` — M6 |
| POST | `/transactions/receipt` | `WRITE:TRANSACTIONS` — M6 |
| PATCH | `/transactions/{id}` | `UPDATE:TRANSACTIONS` — drafts only |
| POST | `/transactions/{id}/post` | `UPDATE:TRANSACTIONS` |
| POST | `/transactions/{id}/reverse` | `WRITE:TRANSACTIONS` |
| DELETE | `/transactions/{id}` | `DELETE:TRANSACTIONS` — drafts only |
| GET | `/ledger/trial-balance` | `READ:LEDGER` |
| GET | `/ledger/accounts/{account}` | `READ:LEDGER` |
| GET | `/ledger/summary` | `READ:LEDGER` |

`POST` is **per transaction type**, not one endpoint taking a discriminated
union: a journal carries raw lines, a settlement carries a party and the ways the
money moved, and one endpoint accepting both would validate neither properly.
`PATCH` is the exception, and deliberately so — every field on it is optional by
nature, so each shape is still fully validated whenever it is present. See
[payments-module.md](payments-module.md).

`post: true|false` is **required** on `POST /transactions/journal`. Committing
to the ledger is the consequential act in this product and must never be
something that happened because a field was left out. Authorising a draft is a
separate call for the same reason: saving an edit cannot commit it.

`GET /transactions/meta` publishes the types that can actually be posted, so a
client's filters and forms are built from the server's answer rather than a
hard-coded copy that drifts as M6–M11 add types.

### Filtering the list

`?type=` takes one type; `?types[]=` takes several and is what a tabbed screen
asks with — a tab means a *set* of types, because a customer receipt belongs
beside the invoice it settles rather than on a page of its own. Both narrow each
other when sent together, so `?type=sale&types[]=receipt` returns nothing rather
than one of the two being quietly dropped. An unknown type is a 422, never
ignored: silently returning a shorter list to somebody who believes they are
looking at a complete one is the worst of the three options.

`GET /transactions/counts` returns the type and status breakdown of the whole
books in one grouped query, for tab badges. It publishes the raw counts rather
than one figure per tab, because which types a tab covers is the screen's
decision — the server would otherwise need redeploying when the screen changed
its mind. It is deliberately **unfiltered**: a badge that shrank as somebody
typed in the search box would be answering a different question from the one it
looks like it is answering.

### `paid` and `balance`

Present only on types that can carry a payment split, and they mean something
narrower than their names suggest. **`paid` is what was settled on the document
itself** — money taken at the counter when the bill was written. It is not the
document's share of everything the party has since paid, because no such share
exists: a receipt reduces `Sundry Debtors` for the *party*, not for a named
invoice, and there is no allocation table linking the two.

That is a real limitation of Phase 1 and it is reported rather than papered over.
Filling `balance` from the party's outstanding instead would put one figure
against every one of their invoices, and each row would read as an answer about
itself. Per-invoice settlement is the change that would make these columns mean
what a reader expects; until then the screen labels them and says so.

Absent — not zero — for a journal, a stock adjustment and an opening balance.
Zero would read as "nothing has been paid", which invites somebody to chase it.

### Two permissions, deliberately

| | `TRANSACTIONS` | `LEDGER` |
| --- | --- | --- |
| Authority to | Capture business events | Read the whole financial position |
| `OWNER` | ✅ | ✅ |
| `DATA_ENTRY` | ✅ | ❌ |

Entering the day's takings and reading the workshop's trial balance are
different things. `LEDGER` has only a `READ` action — nothing writes to the
ledger except the engine, so there is nothing else to grant.

## Screens

| Path | Who | What |
| --- | --- | --- |
| `/journal` | `READ:TRANSACTIONS` + membership | The transaction list and the double-entry screen |
| `/ledger` | `READ:LEDGER` + membership | Trial balance, and any account's ledger |

### The four tabs

`/journal` is four views of one list, each with its own columns:

| Tab | Types | Columns |
| --- | --- | --- |
| Sales | `sale`, `receipt` | Invoice No. · Customer · Date · Total · Paid · Balance · Status |
| Purchase Bills | `purchase`, `payment` | Bill No. · Vendor · Date · Total · Paid · Outstanding · Status |
| Expenses | `expense` | Expense ID · Description · Amount · Mode · Date · Status |
| Drafts | any `draft` | Draft ID · Type · Source · Last updated · Status · Actions |

A tab is a set of types rather than a single one because a receipt is not a
different subject from the invoice it settles — it is the next thing that
happened. Splitting them would organise the screen around the posting engine.

The columns are rendered per tab rather than one table carrying the union of
them: an expense has a payment mode and no counterparty, an invoice has a
balance and no mode, and a shared head would be half empty on either tab.

`journal`, `stock_adjustment` and `opening` have no tab of their own — nothing on
this screen creates them and none belongs on a page about sales. They stay
reachable through the type filter, which **overrides** the open tab rather than
narrowing it, and says so in a chip: a filter that quietly returned nothing
inside the open tab would look like an empty workshop.

Clicking a row opens a **drawer**, not a modal — reading a voucher is a glance
mid-scan, and the row it came from should stay visible behind it. Its sub-tabs
are computed from the transaction, because a tab that opened onto "no data"
would be promising something the record cannot have: Summary always, Ledger when
there are entries, Payments when the type can carry a split, Inventory when the
posting moved stock, Timeline always.

The design's **Comments** and **Attachments** sub-tabs are deliberately absent.
There is no discussion thread on a voucher in this product, and while files
exist, nothing links one to a transaction — so both panels could only ever be
empty. They belong there the day the data does. The Timeline is built from
`created_by`, `posted_at` and the reversal pointers rather than from an audit
trail, because there deliberately is not one for transactions: nothing can change
a posted figure. See the note on [`AuditResource`](../app/Enums/AuditResource.php).

The journal form shows the balance **live** — "Out by ₹234.56" turning into
"Balanced" as the second side is typed. The server refuses an unbalanced entry
regardless, but finding that out on submit means retyping a voucher. The totals
are summed in whole paise from the input strings; the page never calls
`Number()` on an amount.

Row actions differ by status rather than being uniformly disabled: a draft
offers edit, post and discard; a posted transaction offers only reverse. The
confirmation before posting says plainly that a posted entry can never be edited.

`/ledger` opens on the trial balance with a reconciliation banner — the single
most important line on the page, stated rather than left to be inferred by
comparing two columns. Clicking any row drills into that account's ledger.

## Tests

```bash
php artisan test --filter='Money|PostingEngine|Ledger|TransactionApi'
```

| File | Proves |
| --- | --- |
| `tests/Unit/MoneyTest.php` | Decimal arithmetic, parsing, rounding, serialisation. No database |
| `PostingEngineTest` | Every invariant: balance, line shape, atomicity, immutability, drafts, reversal, go-live, tenancy, volume — and that the database's own CHECK constraints hold |
| `LedgerTest` | Running balances, pagination, periods, the trial balance, zero-state |
| `TransactionApiTest` | The HTTP surface, permissions, tenant isolation, immutability over the wire |
| `PagesRenderTest` | The two shells compile, are gated, and leak nothing to anonymous visitors |

`tests/Concerns/InteractsWithLedger.php` supplies **`assertBooksBalance()`**.
Every module from M4 onwards must leave the trial balance reconciling after
every scenario; use this rather than re-deriving it. It asserts the identity
twice over, on gross movements and on net balances.

There is deliberately **no `JournalEntryFactory`**, and `TransactionFactory`
makes drafts only. A posted transaction with factory-invented entries is exactly
the corruption this module exists to prevent, and a test starting from one would
be asserting against books that could not occur in production. To get a posted
transaction, post one through the engine — which is also the only way to get one
in the application.

## Notes for the next module

* `ChartOfAccountService::system()` and `PostingEngine::post()` are the two entry
  points. Anything that needs to move money composes a `PostingBatch` and hands
  it over.
* `PostingEngine::compose()` builds a batch **without writing it**, which is what
  M15's "show me what you are about to post" preview needs — the same lines the
  engine would write, not a second rendering of them.
* `transactions.party_id` landed with M5, together with its foreign key. A batch
  carries it as `PostingBatch::of(…, partyId:)`, and `PostingEngine` validates it
  the same way it validates accounts — see
  [parties-module.md](parties-module.md).
* A stock movement in M8 must commit inside the same `DB::transaction` as its
  journal entries. The engine already wraps the ledger write; extend it rather
  than adding a second write path. **M6 is the worked example** — settlement rows
  joined that wrapper instead of getting a write path of their own.
* `PostingBatch` carries an optional `payments` split, and a template that
  implements `SettlesThroughPaymentModes` hands one back from the same payload
  that produced its lines. M9's part-paid bill uses both.
* After adding a permission to a seeded role, `php artisan db:seed` now flushes
  the RBAC cache for that role. Without it a new grant sits in the database and
  still answers 403 for up to an hour.
