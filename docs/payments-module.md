# Payments & Receipts

Money moving. The simplest real transactions in the product, and deliberately
the first ones with a business document behind them rather than a hand-written
voucher.

Everything before this was either plumbing (M1–M3) or the engine itself
(M4–M5). This is the first proof that the engine works end to end at minimum
complexity — which is exactly why it comes before billing, where a mistake would
be one of five things going wrong at once.

> *A payment reduces `payable` and a receipt reduces `receivable` with no new
> reporting code.* — [implementation-roadmap.md](implementation-roadmap.md)

That turned out to be literally true. M6 adds two enum cases, two templates and
one table. It adds **no reporting code at all**: the outstanding position was
already a sum over `journal_entries`, and a settlement is just more of those
rows.

## The three rules

1. **A settlement never touches GST.** Structurally, not by omission — neither
   GST account is reachable from the settlement templates.
2. **A settlement always names a party, and that party holds the matching
   role.** A payment is with a vendor; a receipt is with a customer.
3. **Overpayment leaves a credit balance.** It is never refused. The money is in
   the bank; the books have to say so.

## Why a payment never touches GST

Tax is a property of the **invoice**. It was charged when the bill was raised,
and the liability to the department arose then — not when somebody got round to
paying.

Recognising it again on payment would double-count it. Recognising it *only* on
payment would make an unpaid invoice's tax invisible, which is worse: a GST
return filed on collections when the law asks for invoices. Either way the return
is wrong, and wrong in a way that surfaces at assessment rather than at entry.

So `SettlementTemplate` cannot reach `SystemAccount::GstInput` or
`GstOutput` at all, and `a_settlement_never_touches_gst` asserts it over both
directions and both control accounts.

## The two templates

### Template D — vendor payment

```
Dr Sundry Creditors (2100)    the whole amount
  Cr Cash in Hand (1010)      one line per payment mode
  Cr Bank Account (1020)
  Cr UPI / Wallet (1030)
```

Both sides fall: a liability is discharged and an asset leaves. That is what
settling a debt *is*.

### Template E — customer receipt

```
Dr Cash in Hand (1010)        one line per payment mode
Dr Bank Account (1020)
  Cr Sundry Debtors (1400)    the whole amount
```

One asset becomes another, which is why a receipt changes the workshop's net
worth by nothing at all — the profit was recognised when the invoice was raised.

Both are subclasses of `SettlementTemplate`, which declares only the control
account and the side. A payment and a receipt are the same document read in
opposite directions, and writing them twice would give the same rounding, memo
and balance decisions two places to drift apart.

### One control line, several settlement lines

The party's side is **one** line for the whole amount, however many ways the
money arrived. A counterparty owes a single figure, and splitting their side of
it across three rows because the cash came in three tenders makes a statement
unreadable for no gain.

The settlement side is one line **per split**, and two lines that happen to share
an account are not merged. A cheque and a bank transfer both land on Bank; they
are two movements, the memos distinguish them, and the balance check is
indifferent — several lines on one account are still one sum.

## Payment modes

```
cash    → Cash in Hand  (1010)
bank    → Bank Account  (1020)
upi     → UPI / Wallet  (1030)
cheque  → Bank Account  (1020)   ← the one mapping that is not one-to-one
```

Each mode names its own asset account because that is what the workshop
*reconciles*: the cash box against the till, the bank against a passbook, UPI
against the app. A single "Cash/Bank" account would make every one of those
impossible.

### Why a cheque is not its own account

A "Cheques in Hand" account is only correct alongside a clearing workflow —
deposit, clear, bounce — and Phase 1 has none. Without one, every cheque ever
written would sit in that account for ever, and the bank balance the owner
reconciles against their passbook would be permanently short by the total of
them.

Folding the cheque into Bank makes the bank balance right on the day the cheque
is written. That is one day early rather than permanently wrong, which is the
better of the two available errors. When a clearing workflow exists, the account
and the mode mapping change in one place — `PaymentMode::settlementAccount()`.

The mode is still recorded, so nothing is lost: "which cheque was that" stays
answerable.

### A cheque must carry its number

The one required reference. A cheque without its number cannot be matched against
a bank statement, chased when it bounces, or stopped — and the moment anybody
needs it is precisely the moment it has gone wrong. Cash has nothing to
reference; a UPI or NEFT reference is worth capturing but recoverable from the
statement, so neither is forced.

Enforced in `PaymentSplit::fromInput()` rather than in a form request, because
M11's importer and M15's capture agent reach the engine without passing through
one.

## Schema

### `transaction_payments`

```
id, tenant_id, transaction_id, mode, amount, reference, line_no, created_at

  unique (transaction_id, line_no)
  index  (tenant_id, mode)          "every cheque written in March"

  CHECK (amount > 0)                zero settles nothing; the type carries the direction
```

### Why this is not a stored balance

Rule 3 of the testing rules says nothing derivable from `journal_entries` is
stored, and at first glance this breaks it — ₹2,000 cash is already a line on the
Cash account. Two things here are genuinely *not* in the ledger:

* **the mode**, where the mapping is not one-to-one. A cheque and a transfer both
  settle through Bank, so the ledger cannot tell them apart afterwards;
* **the reference** — a cheque number, a UPI transaction id. The thing somebody
  needs when a cheque bounces or a customer insists they have paid.

So this is *document detail*, in the same sense `transactions.total` is: a record
of how the event was described, not a second copy of its accounting consequences.
**No report derives a number from it.** The settlement lines in the ledger remain
the authority on the money.

`created_at` only, and no `updated_at`, for the same reason as a journal entry: a
settlement is written once, alongside the entries it describes, and never touched
again.

### `transactions.draft_payments`

The exact counterpart of `draft_lines`. A draft settlement's intended split lives
on the transaction row, so an unposted payment is **absent** from
`transaction_payments` rather than filtered out of it — the same structural
guarantee the ledger relies on. Nulled in the statement that posts.

## The party

A settlement's `party_id` is **required**, where a journal's is optional. A
payment attributed to nobody sits in a control account that no statement could
ever account for: the money is gone from the bank and nothing records whose debt
it discharged.

### The role must match — the one place a role gates a write

| Type | Control account | Required role |
| --- | --- | --- |
| `payment` | Sundry Creditors | vendor |
| `receipt` | Sundry Debtors | customer |

Debiting Sundry Creditors **is** the claim "we owed this business money". A
payment recorded against a customer-only party would invent a supplier
relationship that does not exist, and leave a position on a record nobody would
think to look at.

This does not contradict M5's rule that roles never filter a read. The two are
different questions:

* *which accounts does a statement cover?* — both, always, whatever roles are
  ticked. Scoping that to the roles is how an edit to a label comes to hide
  money.
* *is this posting claiming something true about the relationship?* — that is
  what the role check answers.

It is the same reasoning that stopped M5 pushing an overpayment onto the payable
side. The refusal (`PARTY_ROLE_MISMATCH`, 422) names the fix — "add the vendor
role" — rather than being a dead end, and the UI's party picker is filtered by
role so the mismatch is hard to reach in the first place.

**Skipped on a reversal**, alongside the archived-party exemption and for the
same reason: a role removed after the fact cannot be allowed to strand a known
error permanently in the books.

## Overpayment

Never refused. A customer who pays ₹6,000 against a ₹5,000 invoice shows a
receivable of **−₹1,000**; a supplier paid ₹5,000 against a ₹3,000 bill shows a
payable of −₹2,000, which is an advance and a real thing.

The decision M5 took, applied here. The money has moved and somebody is holding
it, so the books say so. Refusing would only mean it went unrecorded — and
forcing a customer's credit onto the payable side would claim a supplier
relationship that does not exist.

## Reversal

A reversal **copies the original's settlement split** rather than re-deriving it.
A bounced cheque is the same money going back the way it came, and a voucher that
could not say which cheque was returned would be useless at the only moment
anybody reads it.

Everything else is M4's reversal unchanged: the original keeps its entries and
moves to `reversed`, the pair nets to zero in every account, and the party's
outstanding returns to exactly what it was.

## The engine's new refusals

| Refusal | Error code | Status |
| --- | --- | --- |
| A settlement with no party | `PARTY_REQUIRED` | 422 |
| The party lacks the role the control account claims | `PARTY_ROLE_MISMATCH` | 422 |
| A settlement with no split | `PAYMENT_SPLIT_REQUIRED` | 422 |
| An unknown payment mode | `PAYMENT_MODE_UNKNOWN` | 422 |
| A zero or negative settlement line | `PAYMENT_LINE_INVALID` | 422 |
| A cheque with no number | `PAYMENT_REFERENCE_REQUIRED` | 422 |
| The split and the entries disagree | `PAYMENT_SPLIT_MISMATCH` | 422 |
| `lines` sent for a settlement draft | `TRANSACTION_LINES_NOT_ACCEPTED` | 422 |
| `payments` sent for a journal draft | `TRANSACTION_PAYMENTS_NOT_ACCEPTED` | 422 |

`PAYMENT_SPLIT_MISMATCH` cannot happen through a template — it builds the control
line *from* the split's total, so the two agree by construction. It is asserted
anyway because `PostingBatch` is public: M11's importer and M15's agent compose
one directly, and a ₹5,000 receipt recorded against ₹4,000 of entries would leave
the two halves of one document contradicting each other, permanently and
silently.

The last two exist because a payload in the wrong vocabulary would otherwise be
**ignored** rather than applied — the payment template does not read `lines` —
and the caller would be told their draft was updated while nothing about it was.
Silently discarding an edit to a financial document is worse than refusing it.

## Endpoints

`/api/v1`, behind `auth.jwt` and tenant-scoped by the global scope.

| Method | Path | Permission |
| --- | --- | --- |
| POST | `/transactions/payment` | `WRITE:TRANSACTIONS` |
| POST | `/transactions/receipt` | `WRITE:TRANSACTIONS` |
| PATCH | `/transactions/{id}` | `UPDATE:TRANSACTIONS` — drafts, `payments` or `lines` |

No new permission. `TRANSACTIONS` is authority to capture business events, and a
receipt is the most ordinary business event a workshop has — `DATA_ENTRY` holds
it, and `SettlementApiTest` asserts a clerk can record one while the trial
balance stays closed to them.

**Two routes, not one with a `direction` field.** Paying a supplier and
collecting from a customer are different events; the URL should say which
happened, and a field is something a client can get the wrong way round without
noticing. The *payload* is identical, though, which is why one
`StoreSettlementRequest` serves both — the opposite of the reasoning that keeps
journals and settlements apart, where the payloads have nothing in common.

`GET /transactions/meta` gained `payment_modes`, each with its reference label
and whether that reference is required, so a form asks for "Cheque number"
without a second copy of the mapping to keep in step.

### PATCH takes both shapes, POST does not

The asymmetry is deliberate. On a POST every field is required and the two
payloads have nothing in common, so one endpoint would validate each half
conditionally and end up validating neither. On a PATCH every field is optional
*by nature* — "leave what I did not mention alone" — so each shape is still fully
validated whenever it is present, and `TransactionService` takes whichever one the
draft's type actually uses.

## Screens

`/journal`, now titled **Transactions**.

**Three actions, not one "new transaction" that then asks what kind.** Collecting
from a customer, paying a supplier and writing a correcting voucher are different
jobs done by different people at different moments — and a receipt is much the
commonest, so it is one click and the primary button.

The settlement form is not the double-entry grid. The person recording the day's
takings should never have to know which account Sundry Debtors is, which is the
whole reason posting templates exist. It asks three things: who, when, and how
the money moved.

* The party picker is **filtered by role**, because the server refuses the
  mismatch — offering a customer in a payment form would only produce a 422 the
  user could not have predicted. Filtering is on role *membership*, so a
  counterparty who is both appears in both lists.
* One row per tender, added as needed, with a live total in whole paise from the
  input strings. The page never calls `Number()` on an amount.
* The reference field relabels itself per mode and marks itself required for a
  cheque, from `GET /transactions/meta` rather than a hard-coded copy.
* Draft and record are separate buttons, as everywhere: committing to the ledger
  is never a side effect of saving.

A draft is edited in the form that produced it — a payment draft opened in the
double-entry grid would ask the user to choose accounts its template already
decides, and the server refuses `lines` for it anyway.

The voucher modal shows the split above the journal lines: "Cheque · 402317",
which is the part of the record the ledger cannot express on its own.

## Tests

```bash
php artisan test --filter='Settlement|PaymentMode|Party|PostingEngine|PagesRender'
```

| File | Proves |
| --- | --- |
| `SettlementPostingTest` | The templates: positions, splits, GST absence, overpayment, the role rule, drafts, reversal, paise, volume, tenancy |
| `SettlementApiTest` | The HTTP surface, both endpoints, permissions, every refusal as an explanation, draft round-trips |
| `PagesRenderTest` | The three actions, the settlement form, the type filter |

`tests/Concerns/InteractsWithLedger.php` gained **`payVendor()`** and
**`receiveFrom()`**, which take the split as
`[['cash', '2000.00'], ['upi', '3000.00', 'ref']]` — the shape a test wants to
read. Use them rather than composing batches by hand; they go through
`PostingEngine::compose()`, so a test exercises the same path the API does.

There is deliberately **no `TransactionPaymentFactory`**, for the same reason
there is no `JournalEntryFactory`: a settlement row invented by a factory
describes a movement that never happened.

## Notes for the next module

* **M7** is catalogue only and does not touch the ledger. It is the one module in
  Part B with no posting template.
* **M9**'s part-paid bill implements `SettlesThroughPaymentModes` — a sale settled
  half in cash carries the same split as a receipt and must record the mode the
  same way. `PostingBatch::of(…, payments:)` and the engine's
  `writePayments()` are already there; a bill template hands over the split
  alongside its revenue, tax and stock lines and the engine writes everything in
  one `DB::transaction`.
* That wrapper is the extension point **M8** needs for stock movements. It
  already carries the header, the journal entries and the settlement rows;
  everything a business event implies commits together or not at all.
* `TransactionPaymentRepositoryInterface::totalsByMode()` exists and is unused
  today. **M12**'s day book and cash-position reports are its purpose — "the
  day's UPI collections", "every cheque written in March" — which is why `mode`
  is indexed.
* A cheque-clearing workflow, if it is ever wanted, is one new `SystemAccount`
  case plus a change to `PaymentMode::settlementAccount()` and a transaction type
  that moves money from Cheques in Hand to Bank. Nothing else in this module
  would change.
