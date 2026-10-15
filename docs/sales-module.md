# Sales

What the workshop sold, what came back, what is still owed for it, and the copy
the customer takes away.

**Read [purchase-module.md](purchase-module.md) first.** Sales is its mirror and
deliberately shares nearly all of its machinery — the document engine, the
workspace flow, the correction component, the drawer shape, the idempotency
rules. This file covers only what is *different* on the selling side, and the
differences are not cosmetic: a purchase arrives at a cost it states, a sale
issues at a cost nothing on the document mentions, and almost every decision
below follows from that one asymmetry.

Like Purchase, this module added **zero migrations and zero permissions**. The
posting, the credit note, the receipt allocation, the customer's document and the
share link were built in M9, M16, M18 and M20 and had no §2A screen. Sales is the
screen, plus one card in the registry.

## What it is made of

```
Sales card
   └─ level 1  workspace.js                §2A: form ⇄ list, one switch control
        ├─ form   components/bill-document.js   the document, shared with Purchase
        │           ├─ party-picker  + quick-party   the customer, created inline,
        │           │                                stating what they already owe
        │           ├─ item-picker   + quick-item    the goods, rate prefilled
        │           └─ payment-rows                  cash / bank / UPI / on credit
        │           └─ bill-revision.js              Correct and Repeat, shared
        └─ list   pages/sales.js                 invoices and credit notes, filtered
             └─ level 2  #sales-drawer           one document
                  ├─ view    lines, tax, what is owed, the margin, warnings
                  ├─ pay     → POST /transactions/receipt, allocated to this invoice
                  ├─ return  → POST /transactions/{id}/return
                  ├─ correct → bill-revision.js → POST /transactions/{id}/revise
                  ├─ repeat  → bill-revision.js → a new invoice, referencing nothing
                  ├─ print   → components/invoice-document.js, in the layout's #invoice-print
                  ├─ share   → POST/DELETE /transactions/{id}/share → /i/{token}
                  └─ level 3 confirmAction()  for reverse and correct
```

`pages/sales.js` and `modules/sales.blade.php` are the module's own. Everything
else on that diagram is shared with at least one other screen, and the sharing is
load-bearing rather than tidy: an invoice raised here and an invoice raised at the
counter must price identically, and the workshop's printed copy and the
customer's web copy must not be able to differ.

## The decisions

### The rate box is prefilled here, and is not on a purchase

The mirror of Purchase's most important decision, and the reason the same
component behaves differently by direction.

A sale line opens at the item's selling price because the workshop set that price
and a counter should not retype it. Getting it wrong is an ordinary mistake with
an ordinary repair: the invoice is corrected, or a credit note is raised, and the
figure that was wrong is on the document for anybody to see.

A purchase line opens **empty**, because a rate typed there becomes the weighted
average cost, and a wrong average is permanent and invisible. See
[purchase-module.md](purchase-module.md#the-rate-box-starts-empty-on-a-purchase).

### A rate may be quoted with the tax already in it

Beside the rate box is a toggle reading `+GST` or `incl`, prefilled from the item
and flippable per line — because the same invoice routinely carries a part sold
at the figure printed on its box and labour quoted before tax.

Where it says `incl`, ₹11,800 is ₹10,000 of goods and ₹1,800 of GST, and the
customer is asked for the ₹11,800 that was said out loud. Nothing downstream can
tell the difference: the taxable value, the split and the total are the same
three figures a rate of ₹10,000 quoted the other way would have produced, which
is the invariant that keeps the GST return independent of how somebody typed a
line.

**The customer's copy prints the rate before tax regardless.** A tax invoice's
rate column has to be the same kind of figure as the taxable value beside it. See
[inclusive-pricing.md](inclusive-pricing.md).

### The customer's position is stated at the pick

Choosing the customer is the last moment at which "they already owe ₹42,000"
can change what the invoice says. Once it is posted, the decision to sell on
credit has been taken.

So the party picker fetches the position **on the pick** — not with the search,
which runs on every debounced keystroke and would price nine parties nobody
chose — and holds it per id for the life of that picker. It reads
`GET /parties/{id}` under `READ:PARTIES` alone. The *statement* and the *ledger*
stay behind `READ:LEDGER`: the line falls between one figure and the entries
behind it, never between the name and the money, because the counter clerk who
may raise an invoice holds PARTIES and TRANSACTIONS and no LEDGER.

A customer in credit reads "in credit", as a positive figure, in blue.
A negative receivable is somebody who has **paid ahead**, and showing it in the
amber that means "chase this" everywhere else sends somebody after money the
workshop is holding. See [parties-module.md](parties-module.md) and
`components/party-position.js`.

### Collecting is pointed at the invoice it was opened from

`POST /transactions/receipt` allocates oldest-first when nothing says otherwise,
which is the right default for money handed over at the door against no
particular bill. It is the wrong default for a receipt raised **from an
invoice's own drawer**: somebody collecting there means *this* invoice, even
where an older one is still open. So the drawer names the allocation explicitly.

The drawer opens on "Paid in full", which is what is left on *this* invoice
rather than its total, because a part-paid invoice being topped up is the common
case at a counter. "On credit" is not offered on that surface at all — it records money
that has already changed hands, and the invoice behind it is already the credit.

### The drawer shows the margin, and the customer's copy cannot

The sale side's equivalent of the cost-basis note Purchase prints, and the reason
this drawer is worth opening on a document whose total is already on the list:
revenue, cost of goods at the shelf's average, and the margin, in rose when it is
negative.

It is absent on a draft and on anything with no stock lines. Labour has no cost
of goods, and printing ₹0 there would claim a 100% margin on the workshop's most
valuable work.

None of it — cost, margin, `below_cost`, ledger entries, stock movements — may
reach the person the workshop sells to. The way that is guaranteed is structural
rather than careful: `InvoiceDocumentService` builds the customer's document from
its own list of fields, so there is no branch in that file which *could* include
a cost. Never serve a customer-facing document out of `TransactionResource`, and
never add "hide the cost" as a flag to one. The buying price is the workshop's
negotiating position — with its supplier, and with this customer next time. See
[billing-module.md](billing-module.md#the-customers-copy--m20).

### Correcting an invoice is refused on terms a purchase is not

**Correct** is the same component, the same endpoint and the same banner as
Purchase's. What differs is what the posting engine will let through.

A purchase arrives at its own stated cost, so reversing one and posting the
correction puts the Inventory account back exactly where it was. A sale issues at
whatever the weighted average was on the day, and **that figure is on no
document**. The reversal restores the goods at the cost they left at; the
replacement issues them at whatever the average is now. If a delivery landed in
between, the two do not cancel, and the difference is a residue in cost of goods
sold that nothing afterwards can find.

`PostingEngine::assertRevisionKeepsTheCostItSoldAt` compares the unit cost per
variant on the reversal against the replacement, after both are written and
before the transaction commits, and rolls the whole thing back with
`REVISION_WOULD_RESTATE_COST` if it moved. It is a post-condition on what the
stock ledger did, not a second opinion about what it should have done (§4.3).

Alone among the module's refusals it has **no acknowledgement path**. Negative
stock is a state somebody can accept and then fix with a count; "the cost of
goods sold on last quarter's invoice is now a different number" is not something
anybody can meaningfully agree to. The message says what to do instead — take the
goods back with a credit note and raise a fresh invoice, which is two honest
documents at two honest costs.

The refusal is exact rather than cautious: a price-only correction cannot move a
cost and never fires it, a quantity correction fires precisely when a delivery
landed in between, and a labour-only invoice moves no stock and is always
correctable.

### Repeat is not a correction, and shares its loader anyway

"The same six lines again" — the commonest thing a counter does after a regular
customer's third visit. It loads a posted invoice's lines into a blank document,
references nothing, touches nothing, and writes an ordinary new invoice.

It uses `components/bill-revision.js` because loading a posted document back into
the create form is exactly what that component does, and a second copy of it would
drift in the ways the component's own docblock lists. Direction decides the one
thing that differs, and it is about rates. A **correction** carries the original's
rate on either side of the counter, because retyping a known figure is how a
quantity correction becomes an accidental change to the cost basis. A **repeat**
carries them too on the sale side — quoting this customer the same price as last
time is the entire point of the button — and deliberately does **not** on a
purchase, where a stale rate carried forward from last quarter's delivery would
put a wrong cost on the shelf. The toast says which happened, so nobody discovers
it from the totals.

Repeat is offered on every posted invoice, paid or not. It leaves the document it
came from exactly as it stands.

### The footer offers only what the document can still have done to it

| Document | Offered |
| --- | --- |
| Draft | **Continue editing**, Post, Discard |
| Posted invoice, nothing collected and nothing credited | Collect payment, Accept return, **Correct**, Repeat, Print, Share, Reverse |
| Posted invoice, part paid or part credited | Collect payment, Accept return, Repeat, Print, Share, Reverse |
| Posted invoice, settled | Accept return, Repeat, Print, Share, Reverse |
| Posted credit note | Print, Share, Reverse |

The preview a freshly posted invoice lands on offers the same two, gated the same
way — see [the customer's copy comes up over it](#and-the-customers-copy-comes-up-over-it).
| Reversed | nothing but Close |

### A draft is continued, not only posted or thrown away

Somebody saves an invoice as a draft because it is not finished, so the drawer's
first offer is **Continue editing**. It puts the draft back in this module's own
create form — `components/bill-revision.js`, the same loader a correction uses —
with everything it was saved with: the party, the date, the notes, each line at
its rate with its discount in the unit it was typed in and its tax basis, the
bill discount, the payment split and a short "paid in full", and who did the
work. A banner names the draft; **Stop editing** leaves it as last saved.

From there the form's own buttons write to that draft: "Save as a draft" rewrites
it (`PATCH /transactions/{id}`), and "Review & post" rewrites it and then posts
it (`/post`). It keeps its id, and it takes its number only when it posts. If the
form is already holding unsaved work, continuing asks before replacing it. If the
draft was posted or discarded elsewhere in the meantime, the form says which,
rather than failing with a generic error. The control needs `UPDATE` and `WRITE`
on transactions — `UPDATE` to rewrite a draft, `WRITE` to reach the create form
it is finished in.

**Correct disappears the moment anything is against the invoice**, because the
receipt or the credit note would be left pointing at a cancelled document. The
server refuses it both ways — `settled` and `returnedAgainst`, both 409 — and the
button mirrors the server's own condition rather than guessing at it (§6.2).
Offering an act that would be refused teaches somebody the product is unreliable.

**Print is READ and Share is WRITE.** Drawing a document already on the screen
asks nothing more of a grant than reading it did; requiring WRITE would mean the
one person allowed to look an invoice up could not hand the customer a copy.
Publishing it outside the workshop is a different act and goes with WRITE.

A credit note can be reversed and shared but never collected against or returned
from: there is nothing to collect on one, and nothing to send back off one.

**A reversed document offers nothing at all**, print included. It is still on the
list and still opens, because nothing is erased — but it is not a document of
anything any more, and a printed copy of a cancelled invoice is the one artefact
of this module that could be handed to a customer as though it stood. Its share
link stops working for the same reason, without anything having to revoke it.

### Collecting and returning are states of the drawer, not dialogs over it

Both are forms, and a form stacked on a drawer is level 3 doing level 2's job,
which §2.2 says to redesign rather than build. The drawer's body has three states
and its footer changes with them. The only thing that opens over it is
`confirmAction()`, for the acts that cannot be undone.

### A sale may name nobody, if it is paid in full

A customer who will not give a name or a number is still a sale: the stock left,
the cash came in and the GST is owed. So the customer box may be left empty — a
**walk-in sale** — on one condition, enforced in the posting engine
(`assertWalkInSettled`) and stated on the confirmation before Post is enabled: it
is **paid in full at the counter**. A balance left on it would sit in Sundry
Debtors against nobody, where no statement lists it and no receipt can be pointed
at it (`WALK_IN_NOT_PAID_IN_FULL`). A purchase still has to name its vendor.

The option is offered **on the box itself**, not discovered at the confirmation:
the customer label reads "(optional)" on a sale and a line under the empty box
says that leaving it blank makes a walk-in sale, paid in full now. A name typed
but never picked from the list leaves the same empty id, so that state gets its
own amber line — otherwise "Ramesh" in the box reads as Ramesh's invoice. It is
the picker's `blank` option; the bill document passes it for a sale only, and a
locked picker (a workshop bill) shows none.

### How it was settled is chosen, and the boxes obey it

The payment section (`components/payment-rows.js`, shared by every screen that
takes money) used to *derive* on credit / part paid / paid in full from whatever
the amount boxes added up to, so the chips and the boxes were not connected:
"On credit" still asked for a mode and an amount, "Paid in full" filled the box
once and left it editable — and stale when a line was added afterwards, quietly
turning a paid bill into a part-paid one. The state is now held, and it decides
the screen:

- **On credit** — no payment rows at all; the summary says what goes on account.
- **Paid in full** — the amount is not typed. The last row is read-only and *is*
  the total, following it as lines change. "+ Split" adds a row above it: type
  the cash part, the last row takes the rest.
- **Part paid** — typed, and must be more than nothing and less than the total;
  either end is refused on the form with the state it should have been.

A cash row asks for no reference; every other mode asks for the thing by name,
and a cheque's number is required. `problem()` checks all of this before the
confirmation opens — the server still checks it too.

A sale starts on **Paid in full**, a purchase on **On credit**, and money that
has already moved (a receipt, an expense, payroll) on **Paid in full**. While a
sale names nobody, the other two chips are disabled with the reason beside them
(`mustSettle`) — naming a customer gives them back. The draft remembers the
chosen state.

`TransactionType::admitsWalkIn()` is the one place that says which document may do
this, and `requiresParty()` reads it. The role check still applies whenever a party
*is* named. A draft may be parked with nobody on it; the rule bites when it posts.

A walk-in sale reads "Walk-in" on the list and in the drawer. It cannot take a
credit note (`RETURN_AGAINST_WALK_IN`) — a credit note credits a customer's
account and there is none — so the drawer offers no Accept return; **Cancel this
invoice** is the way back, and it is exact, because the counter payment is
reversed with it.

### Adding the customer afterwards

Somebody who bought as a walk-in may come back for a GST invoice in their name.
The customer is not a label on a sale: it decides the place of supply, and their
GSTIN is what makes the invoice evidence for their input tax credit. So **Add
customer** — a state of the drawer, and a button on the GST invoice preview — calls
`POST /transactions/{id}/assign-customer`, which **reverses the sale and reissues
it under the customer** with a new number, through `PostingEngine::revise()`.

The server builds the replacement from the sale itself — the same lines, rates,
discounts, inclusive flags, date and counter payment, and who did the work — so
the only question asked is who. That is also why it does not share Correct's
refusal of a paid bill: that refusal protects a *receipt* allocated against the
invoice, and a walk-in sale cannot have one, while its own counter split is
reversed and posted again with it. Nothing comes off the shelf twice and no money
moves. It needs WRITE and UPDATE on transactions, as a correction does, and keeps
a correction's refusal of a sale whose cost basis has moved
(`REVISION_WOULD_RESTATE_COST`) — then the GST invoice is printed without a name.

Existing customers only: the drawer's picker has no "+ Add", because
`quick-party.js`'s form lives on the create surface, which is detached while the
drawer is open over the list, and a second copy of those fields is what §5.2 rules
out. The drawer says to add a new one on the Customers card first.

### Credit notes are on the same list

"What did we sell them" and "what came back" are one question asked of one
customer. Two lists have to be reconciled by hand, and the second one is always
the one nobody opens. The same judgement Purchase makes about debit notes.

The list filters by kind, payment status, lifecycle status, date range, an
outstanding-only toggle, and a search that matches the document number, the note
**and the customer's name** — which is the term actually typed, since somebody
holding a paper slip has the name long before the number.

### A posted invoice stays on the form

§2A.8, and it matters more here than anywhere else in the application. A counter
writes several invoices between one delivery and the next, and being thrown to a
list after each one means a trip back for every customer in the queue. The
document is emptied for the next sale, focus returns to the customer box, and the
new row is *flagged* rather than shown — the highlight happens whenever the list
is next looked at, not at a moment nobody was watching.

### …and the customer's copy comes up over it

A posted sale opens `#invoice-preview` — level 2, the customer's document as they
would receive it. It opens on the **estimate**, with **Print** and **Get GST
invoice**; the GST invoice adds **Share** (see billing-module.md, "The estimate
first"). It is the other
half of raising an invoice, and it used to be missing: the only route to the copy
was to show the list, find the row just written and open its drawer, for every
single sale.

Over the emptied form rather than instead of it, so the paragraph above is
untouched. Closing the preview leaves a blank document with the cursor already in
the customer box, and the observer that puts it back watches the drawer's class
rather than its close button — the backdrop and Escape are two of the three ways
out and neither is a click on anything.

Only for a document there is a copy *of*. A draft has no number, no priced lines
and no tax, so `onPosted` opens nothing for one; a correction that posted is an
ordinary posted sale and does come here.

### One invoice sheet, borrowed

The preview renders **no invoice markup of its own.**
`components/invoice-delivery.js` moves the
single `[data-invoice-document]` node out of `#invoice-print` while the preview
is open and hands it back before anything prints — the `adoptForm()` pattern from
`workspace.js`, for the same reason.

That is not tidiness. The print rule keeps whichever child of `body` *contains*
the document and hides every other one, so a second sheet anywhere under `<main>`
would make `<main>` a thing worth keeping, and every print from then on would
carry the whole application around the invoice — with nothing on the screen
saying so. One node makes the rule true by construction.

Three things hand it back, and the redundancy is deliberate because each covers
a different way of asking for paper. **Print** releases it before
`window.print()`. **Closing the preview** releases it, so the borrowing lasts
exactly as long as the preview does and a later Ctrl+P from anywhere cannot find
an empty `#invoice-print`. And `beforeprint`/`afterprint` — plus the
`matchMedia('print')` change, because Safari fires neither — cover a browser
print taken while the preview is open.

The preview is declared in `layouts/app.blade.php`, beside the sheet and for the
same reason: the shell caches a module's root **detached**, so a drawer declared
in `modules/sales.blade.php` would carry the document off the page with it the
moment somebody opened another module. `PagesRenderTest` asserts both — one sheet
in one child of `body`, and a body-level preview that renders none.

### Labour can be shown to the customer as one row

A repair is billed task by task — bearing change ₹100, seal repair ₹200 — and the
counter may hand the customer one **"Labour charges ₹300"** row instead. A
checkbox under the bill discount, ticked by default and shown only once the bill
has two labour lines at one GST rate, sends `combine_labour`; it is stored on
`transactions.combine_labour` (manual SQL `2026_10_14_002`) and read back on every
print, so a reprint is the document the customer was handed. It is a **sale's
only** — the engine drops it elsewhere and an edit naming it on anything else is
refused — and an unsent flag means itemised, so every caller that predates it is
unchanged. Until the SQL is run the box is accepted and every invoice prints
itemised.

It changes how the invoice is **drawn** and nothing else.
`InvoiceDocumentService::withLabourCombined()` does it, for the GST invoice and
the estimate alike, so print, the share page and the thermal receipt agree. The
lines, the ledger, Insights and the drawer keep every task. Three rules keep it a
tax invoice:

* **Labour is a service that took nothing off a shelf** — no-stock category, no
  movement. A rewind consumed copper through its recipe and keeps its own row; a
  part bought to order holds no stock either, but it is goods.
* **One row per SAC code and GST rate**, because a row of a tax invoice carries
  one of each. A lone task keeps its own name; there is nothing to combine it with.
* **Figures are added up, never recomputed.** Tax worked out again on ₹300 can
  land a paisa from what the lines posted. The row's rate is the summed taxable
  value plus the summed discount, so it multiplies out.

A credit note is always itemised: it names lines one by one, and `SalesReturn` is
not a type the flag applies to.

### Sharing is a row, and revoking is its whole lifetime

The link has **no expiry**: a customer keeps an invoice, and a link that quietly
stopped working reads as the workshop having deleted the record. So revoking is
the only way one ends, and re-sharing after a revocation mints a *different*
token — otherwise "stop sharing" would be undoable.

Shareability is re-asked on every read, which is what makes a reversed invoice
stop opening without anything having to remember. A bad token, a revoked one and
a reversed one all answer the same 404. See
[billing-module.md](billing-module.md#the-customers-copy--m20).

### Cancelling is what reversing is called on this screen

A posted document is immutable and is corrected by **reversing** it — that has
not changed and cannot. Posting a purchase recomputes the weighted average cost
of every variant on it, so once anything has been sold the shelf is valued off
that document and deleting the row could not un-blend it; a sale issues at an
average that is on no document at all, which is what `REVISION_WOULD_RESTATE_COST`
already refuses. The GST position and the audit trail have to survive either way.

What changed is the wording and the list. The control says **"Cancel this
&lt;noun&gt;"** rather than "Reverse", because people looking for "delete" do not
recognise the accounting term, and because "Cancel" alone beside "Close" in a
drawer footer reads as "cancel what I am doing". The confirmation then says what
cancelling actually does.

And the cancelled pair — the document with `status = reversed` **and** the entry
with `reverses_id` that undid it — comes **off the working list by default**,
behind a "Show cancelled" toggle. Both halves go together: hiding one would leave
a negative document referring to nothing. Nothing is hidden from the books; the
pair is untouched in the ledger, on the Day Book and on the audit trail, which is
the whole reason it is safe to take off this one screen.

It is a `hide_cancelled` filter on `GET /transactions`, and it is **opt-in at the
API**: the party statement, the journal and the expenses list are all asking
books questions where a cancelled document is part of the answer, so the default
had to stay exactly as it was. The screen also stops sending it the moment
somebody picks a state from the filter — asking for "Reversed" and being handed
an empty table would be the screen doing the opposite of what it was told.

## Idempotency

Three references, and they are not the same one.

The **invoice** carries a `client_ref` minted when the form is started and reused
on every retry, including after a restored draft, so an invoice that posted before
the tab closed cannot be written twice.

The **correction** carries one minted per correction rather than per attempt.
Without that, a second tap would find the original already reversed and be refused
— an error shown over a correction that had in fact worked.

The **credit note** carries its own, minted per return being written and reset
when the drawer opens on a different document. This matters most of the three: a
duplicate return puts stock back on the shelf twice, which corrupts the ledger
rather than merely the record.

## Deliberately out of scope

Named here so nobody adds one casually.

**Quotations and proforma invoices.** Neither is a document in the books, and
both would need their own numbering series, their own lifecycle and a conversion
step into a real invoice. A draft invoice covers "not final yet" for now.

**Delivery challans.** They move goods without billing them, which means a second
thing that writes to `stock_movements` on its own terms — the same objection
CLAUDE.md records against goods-received notes.

**Recurring invoices** and **payment reminders.** Both are scheduling problems
rather than accounting ones, and both want a job runner and a notification
channel the module does not have.

**E-invoicing and the IRN.** GST e-invoice registration is its own milestone with
its own credentials, failure modes and cancellation window. The document already
carries everything it would need.

## What it does not have of its own

No endpoint here is new:

| | |
| --- | --- |
| `POST /transactions/sale` | the invoice — `Dr Receivables / Cr Sales / Cr GST Output`, `Dr COGS / Cr Inventory`, stock OUT |
| `POST /transactions/preview` | the running total, priced by the same code that posts |
| `POST /transactions/{id}/return` | the credit note — stock back at what it left at |
| `GET /transactions/{id}/returnable` | what is still returnable, line by line |
| `POST /transactions/receipt` | the customer receipt — `Dr Cash-Bank-UPI / Cr Receivables` |
| `POST /transactions/{id}/allocate` | which invoice the money settled |
| `POST /transactions/{id}/reverse` | the correction that leaves both entries on the record |
| `POST /transactions/{id}/revise` | **Correct** — the reversal and its replacement, as one act |
| `GET /transactions/{id}/invoice` | the customer's document, for printing |
| `POST` / `DELETE /transactions/{id}/share` | publish the link; stop it, permanently |
| `GET /parties/{id}` | what the customer already owes, at the pick |
| `GET /transactions?types[]=sale&types[]=sales_return` | the list, with paid, credited and due derived on read |

`revise` is gated on `WRITE:TRANSACTIONS` **and** `UPDATE:TRANSACTIONS` together;
everything else is `READ:TRANSACTIONS` or `WRITE:TRANSACTIONS`, plus
`READ:PARTIES` for the position. All of them are grants the counter already
needed, so switching this module on re-seeds nothing.

## Verification

- `SalesFlowTest` — a counter's day in the order the drawer offers it: sell,
  sell, collect against the second while the first is still open, credit one
  back; then a correction with a credit note against the *replacement*; then the
  cancelled original refusing to be credited against. The shelf is asserted at
  every step, and stock value against the Inventory account at the end of each
  (§8.2).
- `SaleCorrectionTest` — the correction itself, including the cost-restatement
  refusal, what a refused correction leaves behind, and the double tap.
- `WalkInSaleTest` — a sale with nobody named: posted only when paid in full, its
  stock and cash, cancelling it rather than returning it, reissuing it in a
  customer's name (and the refusal once its cost has moved), and the estimate's
  figures adding up to the total.
- `ReturnTest`, `SettlementApiTest`, `AllocationTest`, `RoundOffTest`,
  `InvoiceShareTest`, `BillPostingTest` — the acts, each where it lives.
- `PagesRenderTest` — the module's markup, fetched through `/modules/sales`
  rather than rendered, so the fragment route is asserted too.
