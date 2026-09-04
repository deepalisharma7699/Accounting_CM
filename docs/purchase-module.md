# Purchase

What the workshop bought in, what it cost, and what is still owed for it.

Its mirror on the selling side is [sales-module.md](sales-module.md), which
shares nearly all of this machinery and documents only what differs. Where the
two disagree, they disagree for one reason: a purchase arrives at a cost it
states, and a sale issues at an average that is on no document.

**Almost none of this module is new.** The posting, the stock arrival, the debit
note, the vendor payment and the per-bill settlement position were all built in
M9, M16 and M18 and had no screen. Purchase is the screen, plus one card in the
registry — and it added **zero endpoints, zero migrations and zero permissions**.

## What it is made of

```
Purchase card
   └─ level 1  workspace.js              §2A: form ⇄ list, one switch control
        ├─ form   components/bill-document.js   the document, shared with /bills/new
        │           ├─ party-picker  + quick-party    the supplier, created inline
        │           ├─ item-picker   + quick-item     the goods, created inline
        │           └─ payment-rows                   what was paid at the counter
        │           └─ bill-revision.js               a posted bill back in the form,
        │                                             shared with Sales
        └─ list   pages/purchase.js               bills and debit notes, filtered
             └─ level 2  #purchase-drawer         one document
                  ├─ view    lines, tax, what is owed, warnings
                  ├─ pay     → POST /transactions/payment, allocated to this bill
                  ├─ return  → POST /transactions/{id}/return
                  ├─ correct → bill-revision.js → POST /transactions/{id}/revise
                  └─ level 3 confirmAction()  for reverse, correct and discard
```

Only `pages/purchase.js` and `modules/purchase.blade.php` are the module's own —
and of those two, the correction banner is the only markup that is not the shared
partial. Everything else is shared, and deliberately: the same document raised
here and at the counter must price identically, or the two screens will
eventually disagree about what a bill comes to.

## The decisions

### Purchase is its own card, and Bills narrowed to sales and expenses

The registry used to argue for one Bills module covering everything, on the
grounds that a screen making somebody "choose a transaction type before offering
an invoice form" is organised around the ledger rather than around the work.

Under §2A that argument inverts. A module opens **on its create form**. A combined
module would have to open by asking sale-or-purchase — which is exactly the
ledger-shaped screen the original objection was against. One card per document
kind lands straight on the right form, with the right counterparty, and nothing
to choose first.

### The rate box starts empty on a purchase

The item picker knows an item's selling price, and a sale line is prefilled with
it. A purchase line is **not**, and this is the one place in the module where
getting it wrong is unrecoverable.

Stock arrives at the taxable value of the line, and that arrival is what
recomputes the weighted average cost. A selling price seeded into a cost basis
inflates the average permanently: no later edit fixes it, because there is no
average column to fix — the average *is* the sum of the movements. Every margin
computed afterwards is wrong by the difference, and nobody can tell from the
figures that it happened.

A supplier's invoice always states its own price, so there is nothing to save by
guessing. The average cost is shown under the box as a hint, so somebody can see
at a glance that a price has moved — but it is never a value, because a value can
be accepted by tabbing past it.

**And the picker no longer says otherwise.** The line under the search box read
"Stock, unit and price come from the shelf" on both directions — true of a sale,
and on a purchase a promise the form deliberately breaks. A rate box that then
opened at 0.00 read as a prefill that had failed rather than one withheld on
purpose, and the obvious repair somebody reaches for is to wire the item's stated
purchase price into it, which is the exact mistake this section exists to
prevent. The hint is now the host's to supply, and on a purchase it says the rate
comes from the supplier's invoice.

An item's `purchase_price`, typed when the product was created, is not that rate
and is not stored as one. It is a reference figure, used as the unit cost of the
opening-stock adjustment if one is recorded, and nothing reads it afterwards.

### Paying and returning are states of the drawer, not dialogs over it

Both are forms. A form stacked on a drawer is level 3 doing level 2's job, which
§2.2 says to redesign as a step-based flow. So the drawer's body has three states
and its footer changes with them. The only thing that opens over the drawer is
`confirmAction()`, for the two acts that cannot be undone.

### The footer offers only what the document can still have done to it

| Document | Offered |
| --- | --- |
| Draft | Post, Discard |
| Posted purchase, nothing paid and nothing returned | Record payment, **Edit**, Return to vendor, Reverse |
| Posted purchase, part-paid or part-returned | Record payment, Return to vendor, Reverse |
| Posted purchase, settled | Return to vendor, Reverse |
| Posted debit note | Reverse |
| Reversed | nothing but Close |

A debit note can be reversed because one raised against the wrong bill is as much
a mis-posting as the bill was, and reversal is the only correction once either is
in the books. It cannot be paid or returned against: there is nothing to pay on
one, and nothing to send back off one.

### Correct is reverse-and-repost, and it says so

A posted transaction is immutable and stays that way — every report already run
off it would silently change otherwise, and a ledger that can be edited after the
fact is not a ledger. But *enforcing* that by simply not offering an Edit left
somebody who had typed 20 where 30 was delivered to work out on their own that
the repair is a partial debit note, or a reversal followed by re-entering the
whole bill. Both are correct and neither is discoverable, and "fix a typo on a
bill" is the commonest thing a clerk needs to do.

So **Correct** loads the bill back into the module's own create form and posts
to `POST /transactions/{id}/revise`, which reverses the original and issues the
corrected document in its place *inside one database transaction*. Two documents,
the audit trail intact, and the stock moving by the difference — exactly what a
bookkeeper doing it by hand would produce, minus the chance of doing only half of
it. A banner sits over the form the whole time naming the bill being corrected,
because "Review & post" is a materially different act while it is up.

The whole of that flow is **`components/bill-revision.js`**, mounted by Purchase
and by Sales and never copied into either. The parts that go wrong in a second
copy are not the obvious ones: the banner surviving onto a blank document, the
correction handle being dropped from the autosaved draft, the client reference
being regenerated per attempt instead of per correction, and a correction being
allowed to park as a draft. Each is a one-line mistake with a several-document
consequence. A host supplies a direction and a noun and closes the drawer
afterwards, because only it knows which drawer that is.

The same loader is what **Repeat** uses — the Sales drawer's "the same six lines
again", which touches and references nothing, and writes an ordinary invoice.
Direction decides one thing in it: a *correction* always carries the original's
rate, on either side of the counter, because re-typing a known figure is how a
quantity correction turns into an accidental change to the cost basis. A
*repeated purchase* deliberately does not, for the reason a new purchase's rate
box is empty — stock arrives at the line's taxable value, and a stale rate
carried forward from last quarter's delivery would put a wrong cost on the shelf.

Four refusals, and each is a case where reverse-and-repost is the wrong repair
rather than merely an unusual one:

| Refused | Because |
| --- | --- |
| A draft | Nothing is in the books; `PATCH` edits it in place |
| An already-reversed document | It has been cancelled; correcting a cancellation is writing a new bill |
| Anything that is not a bill | See below |
| A bill with money against it | The payment would be left pointing at a cancelled document |
| A bill with a note against it | The same orphan, through goods rather than money |
| A sale whose cost basis has moved since | See below — the one refusal with no acknowledgement |

**Bills only, and this is not squeamishness.** A bill is a purchase or an
invoice. A note is not one on either side: it is already the correction to
something else, so correcting one is a decision about which correction stands —
which is a reversal and a fresh note, deliberately taken. Nor is a receipt, a
payment or a journal, none of which has document lines to correct.

**And the two bills are not corrected on the same terms.** A purchase arrives at
its own stated cost, so cancelling one and posting the corrected one puts the
Inventory account back exactly where it was — the figure is on the document. A
sale issues at whatever the weighted average was on the day, and that number is
not on the document at all. Reversing restores the goods at the cost they left
at; the replacement issues them at whatever the average is *now*. If a delivery
landed in between, the two do not cancel, and the difference is a residue in
COGS that nothing afterwards can find.

So a sale is correctable, and the posting engine refuses the case where it would
not be honest. It compares the unit cost of every variant on the reversal against
the unit cost on the replacement, after both have been written and before the
transaction commits: identical, and the correction stands; moved, and
`REVISION_WOULD_RESTATE_COST` rolls the whole thing back naming the part, the
cost it sold at and the cost it would now sell at.

That is a post-condition on what the stock ledger actually did, not a second
opinion about what it should have done — §4.3 is that there is one source of
truth for a stock calculation, and this asks it rather than reimplementing it. It
is exact for a price-only correction, where the cost cannot move and the check
never fires, and for a quantity correction, where it fires precisely when a
delivery has landed in between. A labour-only invoice moves no stock and is
always correctable.

Alone among the refusals, it carries **no acknowledgement path**. Negative stock
is a state somebody can accept and then fix with a count; "the cost of goods sold
on last quarter's invoice is now a different number" is not something anybody can
meaningfully agree to. The message says what to do instead: take the goods back
with a credit note and raise a fresh invoice, which is two honest documents at
two honest costs.

A correction is always posted, never drafted. A draft correction would leave the
original reversed and its replacement nowhere, which is a worse record than the
mistake being corrected.

### Reversing cannot silently take the shelf below zero

The posting engine exempts reversals from the negative-stock refusal, and that
exemption is right: a known error must never become permanent on the grounds that
the shelf has moved since. What was wrong was that it was also *silent*. A
purchase of ten reversed after seven had left by another route posted a position
of minus seven, a negative Inventory valuation and a store-wide stock value below
zero, with nothing said at the one moment somebody could still have chosen the
debit note instead.

So the exemption stays and the silence goes. Reversing a purchase or a debit note
is refused first, naming what is short and by how much, and goes through on an
explicit acknowledgement — the shape M17 chose for the same question on a bill,
because a rule nobody can get past is a rule people work around by not recording
the correction at all. Silent, as every other negative-stock rule is, where the
workshop has turned `allow_negative_stock` on.

**A correction asks the question at the end, not in the middle.** Inside a
revision the reversal has taken the whole delivery off the shelf and the
replacement has not yet put it back, so a purchase of 10 corrected to 12 would
trip over a position that never exists. The reversal is acknowledged internally
and the *result* is what is checked — which is the state somebody will actually
see, and which still catches 10 corrected down to 3 when 7 have already gone.

### A purchase reversal is not a stock count

Both are stored as `adjust`, and they had to be: calling a reversal an `out`
would make it indistinguishable in a stock report from a sale that never
happened. But that left a reversed purchase and a physical count reading
identically on a stock card — a signed number and the word "Adjustment" — with no
way to tell which document had taken the stock away.

The stored type is unchanged. What is added is that the movement can name the
document behind it: `StockMovement::sourceLabel()` returns "Purchase reversed" for
a movement written by the reversal of a purchase document, and `null` for every
movement whose type already says everything. A stock card reads
`source_label ?? type_label`, so every other row is exactly as it was.

### A posted bill stays on the form

§2A.8. The counter at `/bills/new` exists to write one bill and hands the
operator to a list afterwards. A clerk working through the morning's deliveries
writes six in a row, so the document is emptied for the next entry, focus returns
to the supplier, and the new row is *flagged* rather than shown — the flash
happens whenever they next look at the list, not at a moment nobody was watching.

### Debit notes are on the same list

"What did we buy from them" and "what did we send back" are one question asked of
one supplier. Two lists have to be reconciled by hand, and the second one is
always the one nobody opens.

## Idempotency

Two references, and they are not the same one.

The **document** carries a `client_ref` minted when the form is started and reused
on every retry, including after a restored draft — so a bill that actually posted
before the tab closed cannot be written twice.

The **debit note** carries its own, minted per return being written and reset when
the drawer opens on a different document. This matters more than the first: a
duplicate return puts stock back on the shelf twice, which corrupts the ledger
rather than merely the record.

## Deliberately out of scope

Named here so nobody adds one casually, following the precedent CLAUDE.md sets
for unit conversion and batch/expiry.

**Purchase orders and goods-received notes.** There is no order → receive → bill
chain and no tables for one. Both halves are easy to get wrong in ways that are
invisible afterwards: a PO that moved stock would count goods nobody has, and a
GRN that did not would leave the shelf disagreeing with the books between arrival
and invoicing. Doing it properly is its own milestone.

**Landed cost.** Freight, duty and insurance apportioned into inventory value
touch the same weighted-average arithmetic as the rate box above, with the same
permanence when wrong.

**Vendor advances** beyond what the payment template already allows. Paying more
than is owed leaves the supplier with a debit balance rather than being refused —
the money left the bank and they are holding it, so the books must say so — but
there is no screen for managing an advance as a thing in its own right.

## What it does not have of its own

One endpoint, and everything else already existed:

| | |
| --- | --- |
| `POST /transactions/purchase` | the bill — `Dr Inventory / Dr GST Input / Cr Payables` |
| `POST /transactions/preview` | the running total, priced by the same code that posts |
| `POST /transactions/{id}/return` | the debit note — stock leaves at what it arrived at |
| `GET /transactions/{id}/returnable` | what is still returnable, line by line |
| `POST /transactions/payment` | the vendor payment — `Dr Payables / Cr Cash-Bank-UPI` |
| `POST /transactions/{id}/allocate` | which bill the money settled |
| `POST /transactions/{id}/reverse` | the correction that leaves both entries on the record |
| **`POST /transactions/{id}/revise`** | **Correct — the reversal and its replacement, as one act** |
| `GET /transactions?types[]=purchase` | the list, with paid and due derived on read |

The list's **Lines** column counts the document's own rows — `item_line_count`,
added beside the existing `line_count` rather than replacing it. The two are
different questions with different answers: `line_count` counts ledger entries,
which for a single item bought at 18% is three (`Dr Inventory / Dr GST Input /
Cr Payables`), and that is what the Journal means by a line and reads. Printing
it under a heading that means items made every single-item purchase read one or
two higher than the rows its own detail view showed.

A record from before the `PUR/YY-YY/NNNN` scheme has no document number and never
will. The column shows its internal id, muted, rather than a bare dash — the
drawer had been calling it "Purchase #11" the whole time, so the dash was saying
it had no identity at all.

`revise` takes the identical payload a new bill does — a purchase or an invoice,
whichever it is correcting — which is what stops the corrected document being
validated any less strictly than the one it replaces. It
is gated on `WRITE:TRANSACTIONS` **and** `UPDATE:TRANSACTIONS` together: it writes
two new transactions, which is WRITE, *and* changes the standing of an existing
one, which is UPDATE — and somebody trusted to raise a bill but not to alter one
should not reach it by the back door of raising two. Both grants are ones the
counter already needed, so switching this module on still re-seeds nothing.

Everything else is gated on `READ:TRANSACTIONS` and `WRITE:TRANSACTIONS`.
