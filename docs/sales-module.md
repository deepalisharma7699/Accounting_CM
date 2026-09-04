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

The "paid in full" shortcut fills in what is left on *this* invoice rather than
its total, because a part-paid invoice being topped up is the common case at a
counter. "On credit" is not offered on that surface at all — it records money
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
| Draft | Post, Discard |
| Posted invoice, nothing collected and nothing credited | Collect payment, Accept return, **Correct**, Repeat, Print, Share, Reverse |
| Posted invoice, part paid or part credited | Collect payment, Accept return, Repeat, Print, Share, Reverse |
| Posted invoice, settled | Accept return, Repeat, Print, Share, Reverse |
| Posted credit note | Print, Share, Reverse |
| Reversed | nothing but Close |

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

### Sharing is a row, and revoking is its whole lifetime

The link has **no expiry**: a customer keeps an invoice, and a link that quietly
stopped working reads as the workshop having deleted the record. So revoking is
the only way one ends, and re-sharing after a revocation mints a *different*
token — otherwise "stop sharing" would be undoable.

Shareability is re-asked on every read, which is what makes a reversed invoice
stop opening without anything having to remember. A bad token, a revoked one and
a reversed one all answer the same 404. See
[billing-module.md](billing-module.md#the-customers-copy--m20).

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
- `ReturnTest`, `SettlementApiTest`, `AllocationTest`, `RoundOffTest`,
  `InvoiceShareTest`, `BillPostingTest` — the acts, each where it lives.
- `PagesRenderTest` — the module's markup, fetched through `/modules/sales`
  rather than rendered, so the fragment route is asserted too.
