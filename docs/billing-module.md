# Bill Engine & Misc Expense

*The engine. The screens that drive it are [purchase-module.md](purchase-module.md)
and [sales-module.md](sales-module.md).*

> **M10's expense half has no screen.** Sales and Purchase are converted and on;
> the **Bills** card, which is where an expense is written, is switched off, and
> `POST /transactions/expense` has exactly one caller in the front end — that
> module's `pages/bills.js`. So rent, electricity and the rest cannot be recorded
> today, and the P&L reports a margin against overheads of nil. When Bills is
> converted it becomes the expense module and **its list is not rebuilt**: Sales,
> Purchase and Insights' Day Book already draw it (§5.1). See
> [hidden-modules.md](hidden-modules.md).

Sales, purchases and running costs — M9 and M10.

**The big one.** Everything above converges here: M3's chart, M4's engine, M5's
parties, M6's payment split, M7's catalogue and M8's weighted average cost all
meet on a single document. Nothing in this module is new machinery; almost all of
it is composition, and the parts that are not are the tax arithmetic and the
place-of-supply rule.

> *GST computed from HSN/SAC; intra vs inter-state from state codes. COGS from
> average cost at the moment of sale.* — the roadmap

## The three documents

```
Sale       Dr Sundry Debtors        invoice total, tax included
           Cr Sales                 goods, at taxable value
           Cr Service Income        labour, at taxable value
           Cr GST Output            the tax
           Dr COGS / Cr Inventory   per stock line, at weighted average cost
           Dr Cash-Bank-UPI / Cr Sundry Debtors    whatever was collected now

Purchase   Dr Inventory             per stock line, at taxable value
           Dr COGS                  anything bought and not stocked
           Dr GST Input             the tax, claimable
           Cr Sundry Creditors      invoice total, tax included
           Dr Sundry Creditors / Cr Cash-Bank-UPI  whatever was paid now

Expense    Dr <an expense account>  the amount before tax
           Dr GST Input             where the tax is claimable
           Cr Cash / Bank / UPI     however it was paid
```

### Why templates A and B are one class

The roadmap calls a counter sale A and a rewinding job B. They are the same
document. A motor sold over the counter and a rewind billing labour, copper and a
bearing differ only in the *mix* of lines — and everything hard about a bill is
identical in both: the tax arithmetic, the intra/inter-state split, the cost of
what left the shelf, the part-payment at the counter.

Writing that twice would mean two places for the same rounding decision to drift
apart, and one of them would be the one that ends up on a government return. So
the mix is data: a line whose item is a service credits Service Income, a line
whose item is goods credits Sales, and nothing else changes.

### Why revenue splits two ways and cost does not

A rewinding shop's single most useful question is whether it makes its money from
parts or from skill, and the only place that can be answered is the revenue side.
So Sales and Service Income are kept apart — aggregated one line each, because
they are the same kind of thing within themselves.

Cost is *not* aggregated: one `Dr COGS / Cr Inventory` pair per stock line, each
memoed with the variant. That makes the Inventory ledger readable as "what left
the shelf and what it was worth" rather than a column of totals, and it is what
pairs each line with the movement that gives it a margin.

### Why an expense is not a purchase

A purchase is something bought **to sell or to fit**; it either becomes inventory
or becomes cost of goods. An expense is what it costs to **be open**. Keeping the
two apart is the whole reason a P&L can separate gross margin from overheads, and
conflating them would make both figures useless.

That is also why a purchase's non-stock lines go to COGS rather than to Misc
Expense: a part bought to order for a specific job is consumed the moment it
arrives, and it belongs beside the copper the same job used.

## GST

Three things decide the tax, and none of them is typed by the person writing the
bill:

| | Comes from |
| --- | --- |
| the **rate** | the item — the rate follows the HSN code, which is a property of what the thing *is* |
| the **shape** | two state codes — `PlaceOfSupply` |
| the **base** | quantity × price − discount |

### `GstRate` is basis points, not a float

18% as a float is 0.17999999999999999. A rate is multiplied by an amount to
produce a figure on a government return, and a return that is a rupee out is a
return that has to be explained. So 18.00% is 1,800 basis points, and the whole
calculation is integer arithmetic rounded once at the end — the same rule
`Money` and `Quantity` already follow.

### The halves are not both rounded

₹762.71 of tax split in two is ₹381.355 each. Rounding both gives ₹762.72 — a
paisa the invoice total does not contain, and the kind of discrepancy that makes
a customer's accounts department telephone.

CGST takes the floor and **SGST takes the remainder**, so the two always add back
to exactly the tax computed. `an_odd_tax_splits_in_two_without_leaving_a_paisa_behind`
is that case.

### An unknown counterparty state means intra-state

Not a shrug — the correct default. A party with no GSTIN is almost always an
unregistered walk-in, and an unregistered recipient's place of supply is where
the goods are handed over: the workshop's own counter. Defaulting the other way
would put IGST on the trade's most common document, and IGST charged where CGST
and SGST were due is a correction the department has to be asked for.

### The split lives on the line, not in the ledger

Phase 1 has **one** GST Output account and one GST Input account, and the ledger
carries the total. The three-way split is document detail in exactly the sense
`transaction_payments.mode` is: the return needs it, the trial balance does not,
and inventing three accounts per direction would make every workshop's chart
harder to read for a distinction their accountant already makes from the invoice.

## Discounts

Two ways of taking money off, one place that decides what either means:
`Posting\BillDiscount`. §4.4 names discount arithmetic as one of the things
that must live in exactly one place, and this is it.

### A percentage is resolved on the server, never in the browser

`4237.29 * 0.1` is `423.72900000000004` in JavaScript, and a form that rounded
it would be a second implementation of arithmetic the server already owns — the
same argument the preview makes about totals, and it holds harder here, because
a discount is not merely *displayed* from the client's figure, it is **applied**
from it.

So a payload carries `discount` **or** `discount_percent`, never both, per line
and again for the bill. The pair is refused outright (`prohibits`), because
"₹100 or 10%?" is a question the payload has failed to answer and picking one
silently is how a bill comes to carry a discount nobody chose. The browser sends
the unused key **not at all** rather than as `null`: Laravel counts a
present-but-empty key as present, so `{discount: null, discount_percent: '10'}`
would have every discounted bill refused.

### A bill discount is pushed into the lines, before tax

It cannot sit on the invoice footer. GST is charged per line at the line's own
rate, so a workshop billing an 18% motor and a 12% rewind and then taking ₹1,000
off the bottom has reduced the taxable value of both — by how much is a question
only apportionment answers. Deducting after tax would charge the customer GST on
money they were never asked for, and put a figure on the GST return that no line
supports.

So each line's `discount_amount` grows by its share, the tax follows
automatically, and three things fall out for free:

* **`ReturnService` needs no change.** It already pro-rates a credit note from
  `transaction_lines.discount_amount`, so half a bill-discounted line credits
  half the bill discount, with nothing anywhere knowing it was ever a
  bill-level figure.
* **A purchase arrives on the shelf at the discounted cost.** Stock comes in at
  the line's taxable value, and that arrival is what recomputes the weighted
  average — which is the right answer, because a trade discount genuinely
  lowers what the goods cost. A footer-level deduction would have reached the
  invoice and not the shelf.
* **There is no `bill_discount` column.** The lines already imply it, and a
  stored copy would be a second source of truth for a figure that is only ever
  read back as the sum of what it became.

The share is apportioned over each line's **taxable value** — its own gross less
its own discount — not over its gross. A line already given 50% off has half as
much left to discount, and spreading over the gross would take more off it than
remains.

### Largest remainder, not "round each and hope"

₹1,000 across three ₹1,000 lines is ₹333.33 three times: ₹999.99, a paisa the
customer was promised and did not get. Rounding up gives ₹1,000.02 and a bill
that does not add up. Each line takes the floor of its exact share and the odd
paise go, one each, to the lines with the largest fractional part — the same
rule `GstBreakdown` uses for the CGST/SGST halves, for the same reason: a split
has to add back to the thing it split.

### More off than there is on is clamped, not refused

A full warranty job billed at zero is a real thing a workshop does, so each line
simply surrenders its whole base. What that must never produce is a negative
taxable value and tax owed *to* the customer. A bill discounted all the way to
nothing is then refused by the engine's existing rule that a voucher carrying no
amount is not a document — deliberately left refusing, because such a bill
creates no receivable, no revenue and no tax, and the only thing it would record
is stock leaving. That is what a stock adjustment is for.

### Where it is applied, and why that is the whole guarantee

Inside `BillTemplate::documentLinesFrom()` — the method `BillPreviewService`
calls to price a bill. The confirmation screen and the posted invoice therefore
cannot disagree about a discount, because they are the same call. Applying it in
`build()` would have made it invisible to the preview, and the first anyone would
know of it is a customer holding an invoice for less than the screen quoted.

The preview also reports `gross` and `discount` alongside `taxable`, so a panel
showing a discount can show what it was a discount from. `discount` is read as
*gross less taxable* rather than by summing what was asked for — a clamped
over-discount must report what actually came off.

## Round off — `tenants.round_off_invoices`

An 18% bill lands on ₹6,701.10 and a counter holding a five-hundred-rupee note
does not find ten paise. A workshop that has switched this on charges ₹6,701 and
books the difference to **Round Off** (`5999`). Off by default: a rounding policy
changes what a customer is charged, and switching one on underneath a workshop
that never asked for it is a surprise on their next invoice rather than a
default. Posted documents are untouched either way.

### The lines never move — only the party's side

The taxable value and the GST are what the return is filed on. Rounding them to
make a total tidy would put ₹1,022 on a return that ₹5,678.90 at 18% does not
support. So the invoice's revenue, tax and cost lines keep their paise, the
control-account line carries the rounded figure, and the residue is a posting of
its own:

```
Dr Sundry Debtors    6,701.00      ← rounded: what the customer is asked for
  Cr Sales           5,678.90      ← unrounded: what was sold
  Cr GST Output      1,022.20      ← unrounded: what the return says
Dr Round Off             0.10      ← the paise the workshop gave up
```

Rounded up, the residue is a credit instead. On a purchase both sides flip: the
workshop owing ten paise less than the invoice adds up to has *gained* them, so
Round Off is credited. Getting that direction backwards would double the error
rather than cancel it — and the voucher would still balance, which is why
`BillTemplate::roundingLines()` spells it out rather than leaving it to a sign.

### Why it is a real account and not a display rule

Because the receivable is the figure that has to move. Without it, a customer who
handed over ₹6,701 for an invoice reading ₹6,701 would still owe ten paise — on a
statement, in an ageing report, and in the list of who to chase, forever. Once
the receivable moves, the books need the residue booked or they do not balance.

Round Off is an **expense** that spends much of its life with a credit balance,
which is correct rather than odd: rounding up gains paise and rounding down loses
them, in roughly equal measure, and the account is the running net. Filing it
under income would be worse — nobody earned it, and a reader asking "what did the
rounding cost us this year" looks among the expenses.

### Why 5999 and not 5300

Every other seeded account sits at the bottom of its band, so a workshop adding
one of its own reaches for the next round number up — the chart screen's "add an
account" form literally offers `5300` as its placeholder. Claiming a code a
workshop would type collides with the ones that followed the suggestion, and the
collision surfaces as a failure to provision rather than a message anybody can
act on. `5999` is the one number in the band nobody types by choice.

An existing workshop that has already numbered an account 5999 will need it
renumbered before `php artisan accounts:seed` can give it the Round Off account.

### It lives behind `documentTotal()`, and that is the guarantee

`BillPreviewService` asks the template what the document is worth rather than
adding the lines up itself, so the confirmation screen and the posted invoice
round identically — the same seam a bill discount uses, for the same reason. The
preview reports `round_off` alongside `total`, so the panel shows the line the
invoice will carry instead of a total that quietly disagrees with the figures
above it.

### Credit notes round too — and two part returns can leave a rupee behind

A credit note is priced from the same lines, so it reaches the same unrounded
figure and rounds the same way. **A full return therefore nets the customer to
nothing**, which is only true because both documents round.

**Two part returns of the same invoice may not.** ₹500.25 × 2 is charged as
₹1,001; each half returned on its own is ₹500.25 rounded down to ₹500; ₹1,001
charged against ₹1,000 credited leaves the customer owing a rupee on an invoice
they returned in full. Every document rounded correctly and on its own, which is
exactly the problem — nothing is tracking what the original rounding did.

It is bounded (under fifty paise per credit note), it lands on the **party's
balance** where a workshop can see it and write it off rather than silently in an
account nobody reads, and the alternatives are worse: not rounding credit notes
gives a note reading ₹500.25 among documents that are all whole rupees, and
making each note depend on what earlier ones did gives a total that changes with
the order the goods came back in. Asserted in `RoundOffTest` rather than left to
be discovered.

## The customer's copy — M20

Everything above is what the workshop knows. This is the piece of paper the
customer ends up holding: `GET /transactions/{id}/invoice` for the workshop's
own print copy, and `/i/{token}` for the link they were sent.

### It is built from its own list of fields, not by filtering the internal one

`TransactionResource` carries the cost of every line, the margin on the sale,
whether it went out below cost, the ledger entries and the stock movements. None
of that may reach the person the workshop sold to — the buying price is its
negotiating position with its supplier, and with this customer next time.

The way to be sure it never does is **not** a flag on that resource deciding when
to omit them. It is `InvoiceDocumentService`, which builds the customer's document
out of a different list of fields, so adding a field to the internal one cannot
leak it. There is no branch in that file that could include a cost.

`InvoiceShareTest` asserts the absence twice: once on the API payload, and once
on the bytes of the public page — because the document is embedded in that page,
and "is it rendered" is a different question from "was it sent".

### One partial, one renderer, two copies

`partials/invoice-document.blade.php` is the markup and
`components/invoice-document.js` paints it. Both copies of the invoice go through
them: the workshop's print sheet, mounted hidden in the application's layout, and
the customer's page. **A difference between the two copies of an invoice is a
dispute** — and a dispute nobody can settle, since each party is reading their own
version. One partial and one renderer is how they are kept identical structurally,
rather than by remembering to change both.

Which columns exist is decided from the document rather than fixed: IGST replaces
CGST + SGST, and the Discount column appears only if something was discounted. A
fixed set means a column of zeroes on most invoices, and a column of nothing is a
column somebody has to read past.

### Printing does not leave the page

The sheet is a hidden `#invoice-print` in `layouts/app.blade.php`; Print paints it
and calls `window.print()`. The print rule keeps whichever child of `body`
*contains* `[data-invoice-document]` and hides every other one — `body > *`
rather than a list of the chrome, deliberately, because the topbar, the shell, the
confirm modal and the toast host are all direct children of body and so is
anything added later. A rule naming them is one somebody has to remember to
extend, and the failure mode is a customer's invoice printed with a toast across
it.

It asks `:has()` which branch holds the document rather than naming the branch,
and that is not tidiness either. It used to read `body > *:not(#invoice-print)`,
which was true of this layout and false of the customer's page at `/i/{token}` —
that page has no such wrapper and renders the document inside its `<main>`, so the
rule meant to isolate the invoice was the rule that hid the only thing on the
page, and Print there produced a blank sheet. Neither host is in the selector now,
so neither can fall out of it.

The block also **redefines the sheet's ink for paper**. `--color-border` is
#e5e7eb: a hairline that reads on a lit panel and that a printer drops outright.
Every rule on the document drew in it — the box around the lines, the rule under
each row, the rule over the settlement — so the invoice came out of the preview
with no border anywhere on it. The one border that did print was the amount in
words, and only because it had been given a literal colour instead of the token.
Overriding the token on `.invoice-sheet` inside the print block is what corrects
all of them at once; restating each border is the second copy this file exists to
avoid, and the literal #999 was already that copy starting.

No second window, so nothing for a pop-up blocker to swallow and nothing to lose
the drawer to.

### The link has no expiry — it is revoked or it works

A customer keeps an invoice. They show it back at the counter six months later
when the motor comes in again, they forward it to whoever pays their bills, and
they open it from a message thread long after it was sent. A link that quietly
stopped working would be indistinguishable, to them, from the workshop having
deleted the record — and the workshop hears about it as "your system is broken"
rather than as "that link expired".

So the lifetime is a decision somebody makes, not a clock. Revocation is one call,
it is immediate, and re-sharing mints a *different* token: anybody holding the old
URL keeps holding a URL that answers 404 forever. That is what makes "stop
sharing" mean something.

### Why `invoice_shares` is a table and not a column

Because `transactions` refuses to be written to. The model allows exactly `status`
to change once a document is posted, plus two write-once provenance columns — and
a share is none of those. It is issued after posting, revoked later, and issued
again after that, which is the one shape a write-once column cannot hold. Widening
that guard for a non-financial column would leave the next person a precedent for
widening it again, and the guard is the reason a posted figure can be trusted.

It is also the honest model: publishing a document is an act with its own author
and its own end, not a property of the document any more than posting it was.

**The row is its own audit trail.** `AuditResource` deliberately covers no
transaction, on the grounds that nothing about a posted one changes and
`created_by`/`posted_at` already record who and when. The same argument holds here
— `created_by`/`created_at` and `revoked_by`/`revoked_at` are the trail, on the row
the act produced — which is why a revoked share is kept rather than deleted. "This
invoice was public between Tuesday and Friday, and Kavita ended it" is a question a
workshop can be asked, and a deleted row answers it with silence.

### Tenancy comes *from* the token

Nothing on a request to `/i/{token}` says which workshop is being asked for. The
token does, which makes it the same shape of problem authentication solves: a
credential has to be resolved before the identity it carries exists. So the token
lookup runs unscoped — reason 1 in `TenantContext::runWithoutScope()` — and it is
the only unscoped read on that path. Everything after it runs inside `runFor()` for
the tenant the token named, so a share row somehow pointing at another workshop's
transaction resolves to nothing rather than rendering it.

Forty characters of `Str::random` is 62^40 of keyspace — the same order as a
session id, and the reason the link needs no second factor. The per-IP throttle on
the route is not really about guessing it; it is about the cost of *serving* the
guesses.

### What stops the link, besides revoking it

Shareability is asked again on **every read**, not only when the link was issued.
An invoice reversed on Thursday stops opening on Thursday, without anything having
had to remember to revoke it — and a document that the books say did not happen is
the worst thing a customer could be left holding a link to.

A bad token, a revoked token and a reversed document all answer the same 404.
Distinguishing them would confirm to somebody guessing that a guessed token had
once been real.

### Why only a sale and a credit note

`TransactionType::isCustomerDocument()` — the mirror of `isPurchaseDocument()`,
and held on the enum for the same reason. A purchase is excluded although it is
equally a document with items on it: the vendor wrote it, the vendor's numbering
is on it, and a workshop publishing somebody else's invoice under its own
letterhead is not a feature. A debit note is the workshop's own and could
defensibly be added; it is left out until the Purchase module asks, because a link
nothing issues is a public route nobody tests.

### Tax Invoice, or Bill of Supply

The heading is not the transaction type's label. "Tax Invoice" is the phrase that
makes the document the recipient's evidence for an input tax credit, and a
workshop with no GSTIN is making no taxable supply — its document is a **Bill of
Supply**, and printing "Tax Invoice" over it would be a claim it is not entitled to
make, on a page it hands to somebody who may act on it.

### The amount in words

On the document because the figure in words is the one a digit cannot be added to.
`AmountInWords` writes it in **Indian grouping** — crore and lakh, never million —
because 1,50,000 as "One Hundred Fifty Thousand" is not a translation, it is a
different number system, and on a page an assessing officer may read the local
convention is the correct one. It works from `Money::minor()` throughout, so
ninety-nine paise cannot print as ninety-eight for want of a rounding rule.

### Sharing is WRITE, printing is READ

Two different acts, and the split is deliberate. Printing draws a document the
reader is already looking at, so requiring more than READ would mean the one person
allowed to look up an invoice could not hand the customer a copy. Sharing publishes
it outside the workshop — that goes with WRITE:TRANSACTIONS, which is the grant the
clerk who raised the bill holds, because sending the customer their invoice is the
last step of raising it. READ would let everybody who may read the day book publish
any invoice on it.

## `transaction_lines`

The document, as distinct from its accounting. A bill's ledger lines say "credit
Sales ₹4,237.29"; this says "three 5 HP motors at ₹1,412.43, HSN 8501, 18%".

**The computed figures are stored** — taxable value, the three tax columns, the
line total — and that is not a breach of the no-stored-aggregates rule:

* **The tax on an invoice is fixed at the moment it is issued.** If a workshop
  corrects an item's GST rate next March, every invoice already sent must still
  say what it said. Recomputing on read would silently rewrite documents the
  customer is holding a copy of.
* **The CGST/SGST/IGST split cannot be recovered from the ledger at all.**

The description, the unit and the HSN code are snapshotted for the same reason: a
variant renamed next year must not change what last year's invoice sold.

### What is *not* stored: the cost

A line's cost would be a second copy of its stock movement's value, and the two
would eventually disagree. Instead `stock_movements.transaction_line_id` points
back at the line, so

```
margin  =  line.taxable_value  −  ABS(movement.value)
```

is a join. `a_bills_margin_comes_from_the_movement_rather_than_a_stored_copy`
asserts the link exists.

**Null, not zero, where there is no cost.** A labour line has no cost of goods —
an hour is produced at the moment it is sold — and reporting ₹0 would claim a 100%
margin on the workshop's most valuable work.

## Payment terms

The split is **optional** on a bill and **required** on a settlement, and that
distinction is what `TransactionType::acceptsPaymentSplit()` exists to state
separately from `isSettlement()`.

| | |
| --- | --- |
| Credit | no split — a complete document with nothing paid against it |
| Partial | ₹2,000 of a ₹5,000 invoice; the remainder stays in Receivables |
| Full | the whole invoice, collected at the counter |

Why the payment is part of the bill at all: at a counter it is one event. Two
documents means the second can be forgotten, and a receivable that never existed
sits on a statement.

What it may not do is **exceed the invoice**. That is not a credit balance waiting
to be used — M5 deliberately allows one of those, from a receipt with no invoice
behind it. This is a typo on a document whose own total contradicts it, and
posting it would leave the customer's receivable negative by an amount nobody
meant.

The settlement lines are never merged into the control line. "₹5,000 invoiced,
₹2,000 collected" is two facts, and a voucher showing only the ₹3,000 net can
answer neither question.

## `documentTotal` — why a bill's total is not its debits

A sale of ₹11,800 that took ₹8,000 of stock off the shelf debits the customer
₹11,800 *and* debits cost of goods sold ₹8,000. Both are real, both on the same
side, and their sum is not what anybody would call the value of the invoice.

Rather than teach every list to subtract cost of goods sold, the template that
knows what the document is worth says so — `StatesItsOwnTotal`.

## Drafts

A draft bill stores the **request**, in `transactions.draft_payload`, and is
re-composed when it is posted. Storing the derived lines would freeze two numbers
that must not be frozen:

* the cost of goods sold, which is the weighted average *at the moment of
  posting*;
* the tax, which follows a rate the workshop may correct before anybody
  authorises the draft.

`a_draft_bill_writes_no_lines_and_is_re_priced_when_it_posts` posts a fortnight-old
draft after a delivery at a higher price and asserts it costs at today's average.

A draft's `items` are echoed back **unpriced and untaxed** — `taxable_value: null`
rather than `"0.00"`, because a zero would be read as "no tax".

## Reversal

A reversal mirrors the ledger entries, the payment split and the stock movements,
and carries **no `transaction_lines` of its own**. It cannot: the CHECK
constraints require positive quantities and non-negative amounts, so a negated
copy is not representable — and it should not be, because a credit note's lines
*are* the original's. The link is `reverses_id`, and the UI reads the original.

`reversing_a_sale_returns_the_stock_and_nets_every_account_to_nothing` asserts
that Receivables, Sales, GST Output, COGS and Cash all return to zero and the
stock comes back at the value it left at.

## Warnings, not refusals

| | |
| --- | --- |
| `BILL_LINE_BELOW_COST` | Clearing old stock below cost is a real decision, and so is a job quoted before the copper price moved |
| `STOCK_NEGATIVE` | Reported on a bill that *did* take stock below zero — which, since M17, only happens where the workshop has allowed it |

They are computed **on read**, by `BillService`, not raised once at posting.
"Why is this month's margin down" is asked long after the toast has gone, so a
warning has to be there when somebody opens the bill — and the same call serves
the 201 that confirms it was posted.

> **Revised in M17.** `STOCK_NEGATIVE` used to be the whole story: a bill for
> stock the shelf did not hold posted, and warned. It is now **refused** unless
> `tenants.allow_negative_stock` is on — decision D6, written up in
> `docs/inventory-module.md`. The warning survives for the workshops that turn
> the setting on, because allowed is not the same as unremarked.

## Before it is posted: `POST /transactions/preview` — M17

The brief's §12 confirmation step. It prices a payload and writes nothing: line
totals, the GST split, the grand total, and every variant the bill would take
more of than the shelf holds.

Three things about it are deliberate:

* **A separate verb, not a flag on `sale`.** Committing to the ledger must never
  be something that happened because a boolean was left out — the same reasoning
  that gives `opening-balances/preview` a route of its own.
* **It is the server's arithmetic.** The preview goes through the same `BillLine`
  the posting would build, so the figure on the confirmation screen is the figure
  on the invoice. A browser computing it would be a second implementation of the
  tax rounding, in a language whose numbers are floats.
* **It reports every shortfall, not the first.** `PostingEngine` refuses on the
  first one, which is right at the moment of posting and wrong here: somebody
  correcting a six-line bill should not have to fix it one refusal at a time.
  `can_post` says whether the workshop's settings would let it through.

### The confirmation never opens on a figure the boxes have already changed

The preview is debounced, so between the last keystroke and the response the
document holds a total that is out of date — and the confirmation used to be
built from whatever the last completed request had said. Reviewing immediately
after typing a rate showed 0.00 in the dialog while the panel behind it already
read the new total. Nothing wrong ever posted, because the server prices it
again on the way in, but "the figure I just entered is missing" is not something
to make somebody guess about at the moment of committing.

So `refreshPreview()` marks the figures stale *synchronously*, before the wait
starts, and bumps a sequence number that invalidates anything already in the
air — a slow response landing after a fast one is the same stale figure by
another route. "Review & post" overtakes the wait and holds until the current
figures land.

### The post control stays live on an empty document

Disabled, it was indistinguishable from broken: the press did nothing, no
message appeared and no request went out. It now opens on a sentence naming what
is missing — the counterparty, the items, or both — and puts the cursor in the
box that answers it. A line that is *wrong* still disables it, because that case
already paints its reason on the row and in the totals panel.

### A purchase line large enough to be a typo asks once

Above ₹5,00,000 on one line, the confirmation carries a tick that must be set
before it will post. Not a limit — a workshop buying a lathe legitimately goes
past it, and a rule that refused would be worked around by splitting the line,
which is worse than the typo it was guarding. Inside the confirmation rather
than in a dialog over it, because §2.2 allows nothing above level 3 and a second
modal is the thing people click through without reading.

Purchases only. A sale of that size is a shop's best day of the year and does not
want a challenge; a purchase of it is a debt the workshop takes on, and `999999`
typed into a quantity box is the commonest way to do that by accident.

It takes no locks and says so: the stock figures are true at the instant it
answers, and are re-checked under a lock when the bill is actually posted.

## Submitting the same bill twice — M17

The brief's §28. A clerk taps **Save**, the network stalls, they tap it again —
and without protection the workshop has billed the customer twice and moved the
stock twice.

The client generates a UUID **once**, when the operator starts writing the
document, and sends it as `client_ref` on every attempt. `transactions` carries
it under a unique index per tenant, written in the same `INSERT` as everything
else. The second attempt gets the first transaction back with **200** rather than
201, so a client can tell a fresh save from a replay.

A repeat is *returned*, not refused: the clerk did nothing wrong and needs the
bill, not an error. Where two attempts race past the lookup, the index catches the
second and `TransactionService::create()` reads the winner's row instead of
failing. A repeat also does not re-run allocation — the first attempt already
decided which invoices the money settled, and recomputing would quietly move a
decision somebody may have corrected in between.

## Returns — M18

`POST /transactions/{id}/return`, taking `{lines: [{line_no, quantity}]}` and
nothing else. Everything a credit note needs — prices, discount, tax rate, the
cost of the stock — is read off the invoice being credited, because a credit note
has to give back what was actually charged and a payload that could state its own
prices could give back something else.

### Why this is not `reverse`

They look alike and are different acts.

| | `reverse` | `return` |
| --- | --- | --- |
| Says | the document was a mistake | some of what it supplied has come back |
| The original becomes | `reversed` | still `posted` |
| Scope | the whole document | a quantity, per line |
| Repeatable | no | yes, until nothing is left |
| Values stock at | the original movement's cost | the original movement's cost |
| Column | `reverses_id` | `against_transaction_id` |

The two are never both set. A customer bringing back one of four bearings has
not cancelled the invoice, and a system that could only express cancellation
would make them do so — leaving the ledger claiming the original invoice was an
error, which is not a thing anybody should have to explain to a customer holding
it.

### One template, inverted

`SalesReturnTemplate extends SaleTemplate` and overrides three things: the type,
which side the party sits on, and where the stock is valued from. Every body line
goes through `BillTemplate::sideFor()`, so "a return is the same document
inverted" is stated once and cannot be half-applied. The tax arithmetic, the
intra/inter-state split, the discount handling and the document total are the
same code — which matters because one of those numbers ends up on a government
return.

`PurchaseReturnTemplate` is the same trick on `PurchaseTemplate`. It is also the
one return that can be **refused for stock**: sending back five bearings when the
shelf holds three would take the position negative, and M17's D6 applies to it
exactly as it applies to a sale.

### The three things pinned from the original

| | Why |
| --- | --- |
| The **GST rate**, per line | A workshop that corrects a mis-set rate in March must not credit a February invoice at a rate that invoice never carried |
| The **inter-state shape** | A credit note against a CGST + SGST invoice has to be CGST + SGST, even if the customer's address has been corrected since |
| The **stock value** | Put back at what it left at, never at today's average — the same rule a reversal follows, and for the same reason: the average has moved, and a bearing returned at a price it never left at leaves the Inventory account holding the difference for ever |

The stock value is computed by `ReturnService` rather than by the template,
because it is a share of *what remains* — three bearings out at ₹1,000, one back
at ₹333.33, the last two worth exactly ₹666.67. Computing each return as a
fraction of the original would strand a paisa on the last one, permanently,
against stock that is physically on the shelf.

### What it refuses

| | |
| --- | --- |
| `RETURN_TARGET_INVALID` | Only a sale or a purchase supplied anything to take back |
| `RETURN_NOT_POSTED` | A draft supplied nothing; a reversed bill is already cancelled whole |
| `RETURN_LINE_UNKNOWN` | The bill has no such line |
| `RETURN_EXCEEDS_REMAINING` | More than was billed, counting what came back earlier — stated in units, because somebody is standing at a counter holding the goods |
| `RETURN_EMPTY` | A credit note for nothing would take a number out of the series for no reason |

Rows naming the same line are folded before the check, or two rows of three
against a line of four would each be measured against the full remainder and both
pass.

### What the invoice is then worth

`BillService::settlementFor()` gains a third input beside the counter payments
and the allocations: `credited`, summed over the posted returns against the bill.
It is reported **beside** `paid` rather than folded into it — nobody handed over
any money, the goods came back — and both reduce `due`. A bill returned in full
is `paid` without a rupee having moved, and drops off the outstanding list, which
is the point of counting it towards the status at all.

`transaction_lines.against_line_id` is what makes "how much of this line has
already come back?" exact. Matching a return to the *bill* and then to a variant
would credit the wrong price on the very common invoice that carries the same
bearing twice at two prices.

## Per-invoice money — M16

`transactions.doc_no` is the number a human refers to the bill by,
`INV/26-27/1001` — assigned at posting only, so a discarded draft leaves no gap
in the series. See `docs/payments-module.md` for the numbering itself and for
`transaction_allocations`, which is what makes a bill's `paid`, `due` and
`payment_status` answerable.

`TransactionResource` reports them on every posted sale and purchase, and on
nothing else: a receipt has no payment status, because it *is* the payment.

## What the engine refuses

| | Why |
| --- | --- |
| A bill with no lines | An invoice for no items is not a document anybody can act on |
| A stocked item with no variant | Stock is counted per variant; "a motor" leaves every rating's position unknowable |
| A variant belonging to another item | The bill would charge for one thing and take another off the shelf |
| A sale to a party who is not a customer | Debiting Sundry Debtors *is* the claim that they owe us |
| A split larger than the invoice | See above |
| An expense on a non-expense account | Rent posted to Sundry Debtors reads as a customer owing money, and nobody finds out until somebody tries to collect |

## API

| | |
| --- | --- |
| `POST /transactions/sale` | Templates A and B |
| `POST /transactions/purchase` | Template C |
| `POST /transactions/expense` | Template F |
| `GET /transactions/{id}` | The document, its tax summary, its margin and its warnings |
| `PATCH /transactions/{id}` | Drafts only — `items` for a bill, refused for anything else |
| `GET /transactions/{id}/invoice` | **M20** — the customer's copy, for printing. READ |
| `POST /transactions/{id}/share` | **M20** — publish it; idempotent, returns the live link. WRITE |
| `DELETE /transactions/{id}/share` | **M20** — stop the link, permanently. WRITE |
| `GET /i/{token}` | **M20** — the customer's page. No account, throttled per IP, `noindex` |

Two routes rather than one with a `direction` field, exactly as payment and
receipt are two: selling a motor and buying one in are different events, and the
URL should say which happened. One *request class* for both, because the payload
is genuinely identical — the opposite trade-off from journals and settlements,
where the payloads have nothing in common.

## Test checklist

**Test:** `php artisan test --filter='Bill|Expense|Stock|PostingEngine|Correction|SalesFlow'`

- [x] Sale posts template A exactly: revenue, GST output, receivable, COGS, stock OUT
- [x] Purchase posts template C: inventory, GST input, payable, stock IN, WAC recomputed
- [x] A rewinding job mixing labour + copper + bearing posts template B correctly
- [x] A **labour-only** bill posts with zero stock movement
- [x] Intra-state splits CGST/SGST; inter-state uses IGST
- [x] An odd tax splits in two without leaving a paisa behind
- [x] Selling below cost raises a warning and still posts
- [x] Trial balance still reconciles after a hundred mixed bills — *and* the shelf
      still agrees with the Inventory account
- [x] Full, partial and credit terms each leave the right receivable
- [x] Collecting more than the bill is worth is refused
- [x] A discount reduces what tax is due on
- [x] A line percentage is resolved on the server, and rounded once
- [x] Rupees and a percentage together are refused, per line and for the bill
- [x] A bill discount is shared pro rata *before* tax, each line keeping its own rate
- [x] The odd paise of a split are handed out rather than lost or invented
- [x] A discount larger than the bill is clamped, never negative
- [x] The preview quotes exactly what the posting charges
- [x] A return credits the share of the bill discount its line was given
- [x] A discounted purchase reaches the shelf at the discounted cost
- [x] A bill is charged to the paisa unless the workshop has switched rounding on
- [x] Rounding down debits Round Off, rounding up credits it, and a purchase mirrors both
- [x] Fifty paise goes up
- [x] The taxable value and the GST are untouched by the rounding
- [x] Paying the rounded figure settles the bill — nothing is left on the statement
- [x] The preview quotes the rounded total and says what it rounded
- [x] A rounded purchase still puts stock on the shelf at the line's taxable value
- [x] A full return of a rounded invoice nets the customer to nothing
- [x] Two part returns can leave up to a rupee on the party's balance, and the books still balance
- [x] The transaction total is the invoice, not the sum of its debits
- [x] A draft writes no lines and is re-priced when it posts
- [x] A reversal returns the stock and nets every account to nothing
- [x] A sale may go ahead when the shelf says there is nothing there
- [x] Expense with and without claimable GST input
- [x] Expense paid from any payment mode, and split across several
- [x] Expense booked to a workshop's own account, and refused on a non-expense one
- [x] **M20** — the customer's copy never carries a cost, a margin, `below_cost`, entries or movements
- [x] **M20** — nor does the public page, in the bytes it sends, not merely in what it renders
- [x] **M20** — a workshop with no GSTIN issues a Bill of Supply; a return calls itself a Credit Note
- [x] **M20** — the discount and the round off appear on the document as their own lines
- [x] **M20** — the amount in words uses crore and lakh, states paise only when there are any, and is singular for one
- [x] **M20** — a shared invoice opens for anybody holding the link, and is `noindex` in the markup and the headers
- [x] **M20** — sharing twice hands back the same link; sharing after a revocation mints a different one
- [x] **M20** — revoking is immediate, permanent, and not an error when nothing was shared
- [x] **M20** — reversing a shared invoice stops the link without anything revoking it
- [x] **M20** — one workshop's token opens only that workshop's invoice
- [x] **M20** — a draft and a purchase bill are both refused
- [x] **M20** — printing needs READ, publishing needs WRITE
- [x] **M20** — correcting an invoice reverses it and issues the replacement as one act
- [x] **M20** — a price-only correction leaves the shelf exactly where it was
- [x] **M20** — a quantity correction moves stock by the difference, not by both figures
- [x] **M20** — a correction after a delivery has changed the average cost is refused whole
- [x] **M20** — the refusal names the part, the cost it sold at, and the cost it would now sell at
- [x] **M20** — a refused correction writes nothing: no reversal, no replacement, no movement
- [x] **M20** — a labour-only invoice is correctable whatever the shelf has done since
- [x] **M20** — an invoice with money against it, or a credit note, is refused with a 409
- [x] **M20** — a credit note cannot itself be corrected
- [x] **M20** — tapping Correct twice corrects once
- [x] **M20** — the credit note against a corrected invoice is measured against the *replacement*
- [x] **M20** — the cancelled original cannot be credited against
- [x] **M20** — a receipt raised from an invoice's drawer settles that invoice, not the oldest open one
- [x] **M20** — a day of selling, collecting and crediting leaves the shelf agreeing with the Inventory account

## Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| A and B are one template | They are the same document with a different mix of lines; two would mean two places for the tax rounding to drift |
| Revenue splits Sales / Service Income; cost does not aggregate | Parts-versus-skill is the most useful question a rewinding shop's P&L answers, and it is answerable only on the revenue side |
| An expense is not a purchase | Gross margin and overheads are different numbers, and conflating them makes both useless |
| A purchase's non-stock lines go to COGS, not Misc Expense | Bought to order for a job is consumed on arrival, and belongs beside the copper that job used |
| Inventory is valued net of claimable tax | This is the arrival that sets the weighted average; getting the basis wrong is permanent |
| `GstRate` is basis points | The one number in the product that ends up on a government return |
| CGST floors, SGST takes the remainder | Rounding both leaves a paisa the invoice total does not contain |
| An unknown counterparty state is intra-state | An unregistered walk-in's place of supply is the counter; the other default puts IGST on the commonest document there is |
| The tax split is stored on the line | Phase 1 has one GST account, so the ledger carries the total and the split lives here or nowhere |
| The cost is **not** stored on the line | It would be a second copy of the movement's value; the link makes the margin a join |
| A percentage discount is resolved server-side | A discount is applied from the client's figure, not just shown from it, and JavaScript cannot count in paise |
| A bill discount is apportioned into the lines before tax | GST is per line at the line's own rate; a footer deduction taxes money nobody was asked for — and it is what makes returns and the stock cost basis correct for free |
| There is no `bill_discount` column | The lines already carry it; a stored copy would be a second source of truth |
| Rounding moves the party's side only | The taxable value and the GST are what the return is filed on; a tidy total is not worth a figure no line supports |
| The residue is a posted account, not a display rule | The receivable has to move or the customer owes ten paise forever — and once it moves, the books need the difference booked |
| Round Off is an expense, at 5999 | It is a cost of doing business, not revenue; and 5999 is the one code in the band a workshop would never type for an account of its own |
| Rounding is off by default | It changes what a customer is charged, and no workshop asked for it |
| Null margin on a labour line | Reporting ₹0 of cost would claim a 100% margin on the workshop's most valuable work |
| A bill accepts a payment split; a settlement requires one | At a counter, invoicing and collecting are one event — but a sale on terms is still a complete document |
| A split may not exceed the document | An advance is a receipt with no invoice, which M6 already handles; this is a typo |
| Settlement lines are never merged into the control line | "Invoiced ₹5,000, collected ₹2,000" is two facts |
| The template states the document total | A sale's debits include cost of goods sold, and reporting that as the invoice value would be wrong on every list |
| A draft stores the request | Cost of goods sold is the average at the moment of *posting* |
| A reversal carries no document lines | A credit note's lines are the original's, and a negated copy is not even representable |
| Below cost warns on read, every read | The question it answers is asked long after the toast has gone |
| The client previews the total and never computes it | A second implementation of the tax arithmetic is a second answer, and one of them goes on a return |
| **M17** — negative stock is refused by default, with a per-tenant switch | Both workshops are real: the one that bills ahead of its paperwork, and the counter that wants to be told what it can promise |
| **M17** — the refusal is at posting, never at draft or preview | A draft is exactly the tool for parking a bill until the purchase is entered |
| **M17** — `preview` is a verb, not a flag | Committing to the ledger must never be something that happened because a boolean was left out |
| **M17** — a repeat submission returns the first bill with a 200 | The clerk who tapped Save twice did nothing wrong and needs the bill, not an error |
| **M17** — the client names the document, not the server | Only the client knows that two requests are one operator's single intention |
| **M16** — a number is taken at posting and never before | A number that could be discarded is a gap somebody has to explain to an auditor |
| **M16** — `paid` and `due` are derived on every read | A stored figure is one that can disagree with the receipts behind it |
| **M18** — a return is not a reversal | A customer bringing back one of four bearings has not cancelled the invoice, and the other three are still theirs |
| **M18** — a return template is the bill template inverted | The tax arithmetic that ends up on a government return exists in exactly one place |
| **M18** — the rate and the tax shape are pinned from the original | Correcting an item's rate in March must not re-price a February credit note |
| **M18** — the returned stock is valued as a share of what *remains* | A share of the original, rounded twice, strands a paisa in Inventory against stock that is on the shelf |
| **M18** — `credited` is reported beside `paid`, not folded into it | Nobody handed over any money; both discharge the invoice and only one of them is a payment |
| **M20** — the customer's document has its own field list | A flag on the internal resource is a decision somebody can get wrong; a separate builder cannot leak a cost at all |
| **M20** — one partial and one renderer for both copies | A difference between the workshop's copy and the customer's is a dispute neither can settle |
| **M20** — the share link has no expiry | A customer keeps an invoice; a link that quietly stopped working reads as the workshop having deleted the record |
| **M20** — re-sharing mints a new token | Otherwise "stop sharing" would be undoable, and the person told the link was dead would find it alive |
| **M20** — `invoice_shares` is a table, not a column | A posted transaction refuses writes, and publishing is an act with its own author and end — the row is also its own audit trail |
| **M20** — tenancy is resolved *from* the token | Nothing else on the request says which workshop; it is the authentication path's problem, and gets the authentication path's answer |
| **M20** — shareability is re-asked on every read | A reversed invoice stops opening without anything having to remember to revoke it |
| **M20** — a bad, revoked and reversed link all answer the same 404 | Telling them apart confirms to somebody guessing that a guessed token was once real |
| **M20** — print is READ, share is WRITE | Drawing a document you may already read asks nothing extra; publishing it outside the workshop does |
| **M20** — a posted bill is corrected by reverse-and-repost, never by an edit | Every report already run off it would silently change; two documents are what a bookkeeper doing it by hand would produce |
| **M20** — the correction is one database transaction | Half of it — an original reversed with no replacement — is a worse record than the mistake being corrected |
| **M20** — a correction is always posted, never drafted | Same reason: a parked correction leaves the invoice cancelled and nothing standing in its place |
| **M20** — a sale is correctable on stricter terms than a purchase | A purchase arrives at its own stated cost; a sale issues at an average that is on no document, so the two halves need not cancel |
| **M20** — the cost check is a post-condition on the stock ledger | §4.3 — it asks what the ledger did rather than forming a second opinion about what it should have done |
| **M20** — `REVISION_WOULD_RESTATE_COST` has no acknowledgement path | Negative stock is a state somebody can accept and fix with a count; a restated cost of goods sold is not something anybody can agree to |
| **M20** — the correction's `client_ref` is minted per correction, not per attempt | Otherwise the second tap finds the original already reversed and reports an error over a correction that worked |
| **M20** — Correct is hidden once anything is against the invoice | The receipt or the credit note would be left pointing at a cancelled document; the server refuses it either way and the button mirrors that condition |
