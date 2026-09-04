# Inventory & Weighted Average Cost

What is on the shelf, what it is worth, and what it costs to take some off.

M7 built the catalogue and deliberately reserved no columns for stock. This
module is the reason: **quantity on hand and average cost are sums over
`stock_movements`**, and there is nowhere in the schema for either of them to be
edited directly. The roadmap's first invariant is therefore true by construction
rather than by discipline.

> *Weighted average cost, recalculated on every stock-IN.* — the PRD

## The table

`stock_movements` stands in the same relation to inventory that `journal_entries`
stands in to money.

| Column | |
| --- | --- |
| `transaction_id` | **NOT NULL.** Stock moving is a business event with an accounting consequence |
| `variant_id`, `item_id` | Stock is counted per **variant**, never per item |
| `type` | `in` / `out` / `adjust` / `opening` — *why* it moved |
| `quantity` | **Signed.** The direction is the sign |
| `unit_cost` | The rate this movement was struck at — document detail |
| `value` | **Signed, and authoritative.** What the Inventory account moved by |

Everything the product reports about stock is a query over it:

```
qty_on_hand   =  SUM(quantity)
stock_value   =  SUM(value)
avg_cost      =  stock_value ÷ qty_on_hand
```

There is no fourth number.

### Why quantity and value are signed

So that a position is one indexed sum. The alternative — a positive magnitude
plus a direction the caller decodes — means every read re-implements the same
`CASE` expression, and the first one that gets it wrong reports stock a workshop
does not have.

This is the opposite of {@see `journal_entries`}, where the side is a separate
column, and the difference is deliberate: a ledger line *has* a side, and a stock
movement does not. It has a direction, and there is nowhere else to put it.

### Why `value` is authoritative and `unit_cost` is not

`value` is what the Inventory account moved by, to the paise, and summing it is
what keeps the stock ledger and the money ledger equal. `unit_cost` is the rate —
document detail, in exactly the sense `transaction_payments.mode` is: useful on a
voucher, never the source of a total.

## Weighted average cost, in one paragraph

Buy 10 kg of copper at ₹700 and the position is 10 kg worth ₹7,000. Buy 10 kg
more at ₹800 and it is 20 kg worth ₹15,000 — **₹750/kg, which is neither price
paid, and that is the point.** Issue 3 kg and the position loses its *share* of
the value, ₹2,250, leaving 17 kg still at ₹750/kg — because issuing stock cannot
make the remainder dearer or cheaper than it was a second earlier.

Only an arrival changes the average, and it does so by arithmetic nobody has to
perform.

### Why an issue takes a share rather than quantity × average

Because the average is a rounded number. Over a long run of odd quantities,
`quantity × round(value ÷ quantity)` and `value × quantity ÷ total` diverge, and
the difference accumulates in the Inventory account as **stock value with no
stock behind it**. Taking the share means an issue of *everything* takes exactly
the whole value, so a variant that sells out leaves the Inventory account at
exactly zero.

`StockLedgerTest::issuing_everything_leaves_the_position_at_exactly_zero` is that
case, at a rate chosen so it cannot come out even.

### Why the average follows insertion order, not date order

The current position is a sum over every movement, so ordering does not affect
it. It affects a *back-dated* "as at" report, which sums by date and can
therefore disagree with the order in which the workshop actually learned the
costs. The position now is always right; a historical valuation is as right as
the dates it was given.

## Negative stock: refused by default, allowed by setting — **revised in M17**

> **This section reverses the decision M8 originally made.** M8 allowed negative
> stock and warned about it. M17's decision D6 refuses it by default and gives
> the workshop a switch. The original reasoning is kept below, because it is
> still correct — it is just not the whole picture.

**M8's reasoning, unchanged.** Blocking sounds safer and is not. A fitter records
the sale of a bearing on Tuesday; the supplier's invoice reaches the office on
Friday. Refusing Tuesday's sale does not produce the bearing — it produces a
workshop that stops recording sales, and the ledger ends up missing the revenue
as well as the stock.

**What M17 added.** That is true of *that* workshop. It is not true of the one
the workshop-flow brief describes, which asks to be told *"Only 5 PCS available
in stock."* before it promises a customer a sixth. A counter that will cheerfully
sell what is not there is a counter whose stock figures nobody trusts, and
untrusted figures are the reason this module exists.

Both workshops are real, so the answer is a setting rather than a new absolute:

| | |
| --- | --- |
| `tenants.allow_negative_stock` | `false` by default. Editable on `PATCH /workspace` and on the Workspace screen. |
| Where it is enforced | `PostingEngine::assertStockAvailable()`, via `StockLedgerService::assertCanIssue()` |
| Message | *"Only 5 pc available in stock for …, and 6 was billed. Enter the purchase that brought it in, or allow negative stock in Workspace settings."* |
| Error code | `STOCK_INSUFFICIENT`, 422 |

Three properties of where the refusal sits are deliberate:

* **At posting, not at composition.** A draft and a preview both build issues out
  of stock nobody has bought yet, quite legitimately — parking an unfinished bill
  until the supplier's invoice arrives is what a draft is *for*. Only committing
  is refused.
* **Per variant, summed across lines.** Two lines of three bearings are six
  bearings, and a shelf of five is short. A check inside the movement builder
  cannot see that; one over the composed batch can.
* **Inside the posting transaction, after the variant lock.** The position it
  reads is the one about to be written, so two simultaneous sales of the last
  motor cannot both pass.

Two exemptions, and they are the same exemption an archived variant gets:

* a **reversal**, because a known error must never become permanent on the
  grounds that the shelf has moved since;
* a **stock adjustment**, because it is the workshop asserting what is physically
  there — the authority the books answer to, and the only tool for repairing a
  position that is already negative.

**Existing tenants are migrated to the permissive setting** where they already
hold a negative position. Turning the refusal on underneath them would break the
next bill they write for a part they are mid-way through re-stocking; switching
it off is then their decision, made deliberately, rather than one imposed by a
deployment.

Under the permissive setting nothing else changes: the bill still carries
`STOCK_NEGATIVE` as a warning, and negative stock must still never invent a cost:

| Position | An issue is valued at |
| --- | --- |
| Enough on hand | its share of the position's value |
| Exactly all of it | the whole remaining value |
| More than there is | what exists, plus the shortfall at the **last rate actually paid** |
| Never bought at all | nothing — and an adjustment worth nothing is refused, not posted |

Pricing a shortfall at zero would report a margin of 100% on a sale that made the
usual amount. Refusing a wholly valueless adjustment stops quantities moving with
no accounting trace behind them, which is the exact drift this module exists to
prevent.

`StockPosition::isNegative()` is reported separately from `isLow()` all the way
out to the screen. Low stock is a purchasing decision; negative stock is a data
problem with a different fix, and showing them the same way trains people to
ignore the second.

## Stock and the books cannot disagree

M8 is the first module to write **two kinds of record inside one transaction**.
M6 left the pattern ready: the posting engine's `DB::transaction` already carried
the header, the journal entries and the settlement rows, and stock movements join
the same wrapper rather than getting a write path of their own.

The guarantee is enforced at the choke point, not hoped for:

```
PostingEngine::assertStockUsable()
    Σ(movement.value)  ==  Σ(debits − credits) on the Inventory account
```

The templates build the Inventory line *from* the movements, so it holds by
construction there. It is asserted anyway because `PostingBatch` is public —
M11's importer and M15's capture agent can compose one by hand, and ₹15,000 of
stock on the shelf against ₹14,000 in the books is not detectable afterwards as
anything except a wrong balance.

### The one thing it cannot protect against

A **manual journal posted straight to Inventory**. M4 allows it deliberately,
because a journal is the correction mechanism for everything else, and refusing
it would remove the escape hatch the whole product depends on.

So it is surfaced instead: `GET /stock/summary` reports the shelf's value against
the Inventory account's balance, and the stock screen shows a banner when they
differ. That panel exists precisely because the assertion cannot cover this case.

## Concurrency

The cost of an issue is read from the stock ledger while a batch is composed and
written a moment later. Two simultaneous sales of the last motor would otherwise
both read the pre-sale average.

Two things close it:

1. `PostingEngine::postComposed()` wraps compose-and-post in **one** database
   transaction — used by every write path, and by `postDraft()`, which rebuilds
   its batch *inside* the transaction for the same reason.
2. `StockLedgerService` takes a `SELECT … FOR UPDATE` on the variant rows, but
   **only when it is inside a transaction**. A `lockForUpdate` outside one is
   released immediately and would be a lie about the guarantee.

A preview — `compose()` on its own — deliberately takes no lock. It is advisory
by nature, and holding write locks open across a user reading a screen would be
far worse than a preview a concurrent sale moved by a rupee.

## Drafts hold the request, not a valuation

A draft of a bill or an adjustment stores **what was asked for**, in
`transactions.draft_payload`, and is re-composed when it is finally posted.

Storing the derived lines instead would freeze two numbers that must not be
frozen: the cost of goods sold, which is the weighted average *at the moment of
posting*, and the tax, which follows a rate the workshop may correct before
anybody authorises the draft. A bill parked for a fortnight and posted after two
deliveries would otherwise carry a margin computed against a price the workshop
had stopped paying.

It is the same reasoning that sends `draft_lines` back through
`PostingLine::fromInput()` rather than trusting them because they were saved
once, carried to its conclusion.

## Stock adjustment — template G

The one posting template M8 adds, and the reason it is here rather than in M9: a
stock ledger that cannot be corrected is a stock ledger nobody trusts, and the
shelf is the authority on what is on it.

```
Found      Dr Inventory  /  Cr COGS
Shortage   Dr COGS       /  Cr Inventory
```

**Why COGS, and not a write-off account of its own.** A shortage found at a
stock-take is, almost always, stock that *was* consumed and nobody recorded — the
bearing fitted on a Saturday, the metre of copper cut and not booked to a job.
Charging it to cost of goods sold puts it where the rest of that consumption
already is, which is the only place a margin calculation would look for it. A
separate account would report a healthier gross margin than the workshop actually
earns and hide the shortfall one screen further away.

The alternative is real practice and worth naming: a dedicated "Inventory
write-off" expense is right once shrinkage is a number somebody manages. M3
already lets a workshop add that account. What this template will not do is
invent it for them before anybody has asked.

**One pair of lines per movement, never netted.** A count that finds two fewer
bearings and one more motor is two corrections that happened on the same
afternoon. Netting them would make the voucher unable to say what was found —
and, when the two happened to be worth the same, would net to zero and post
nothing at all.

**A stated cost is honoured only for stock that was found.** A shortage is
written off at what the books were carrying it at, which is not the number of the
person holding the clipboard.

## Reversal

A reversal mirrors the original's movements at **exactly the value they left at**
and types them as `adjust` whichever way the original went.

Nothing is re-valued, and that is not laziness: the average has moved since, so a
return valued at today's rate would leave the Inventory account holding the
difference for ever, against stock that is physically there. And `adjust` is the
honest type — a reversal is a correction, which is what `adjust` means, and
calling the reversal of a purchase an `out` would make it indistinguishable in a
stock report from a sale that never happened.

## Permissions

`STOCK`, read-only — the same shape as `LEDGER`, and structurally so: nothing
writes to `stock_movements` except the posting engine, so a WRITE, UPDATE or
DELETE grant would be a grant over something that cannot happen. Stock is *moved*
by posting a transaction, which is `WRITE:TRANSACTIONS`.

| Role | Holds |
| --- | --- |
| `OWNER` | `READ:STOCK` |
| `DATA_ENTRY` | `READ:STOCK` — and still no `READ:LEDGER` |

A clerk billing a bearing has to know whether there is one; a clerk who cannot
see that guesses, which is how stock goes negative in the first place. They
already type the cost on every purchase they enter, so the value column tells
them nothing they did not put there themselves.

The money side of `/stock/summary` — the Inventory account's balance — is gated
inside the controller on `READ:LEDGER` rather than on the route, because the
stock half is legitimately theirs and requiring LEDGER for the endpoint would
take it away to protect one line of it.

## API

| | |
| --- | --- |
| `GET /stock` | Every inventoried variant with its position |
| `GET /stock/summary` | Totals, and the reconciliation for anyone who may read the books |
| `GET /stock/meta` | Movement types and position statuses |
| `GET /stock/variants/{id}` | One variant's stock card, with a running balance |
| `POST /transactions/stock-adjustment` | Template G |

**There is no POST, PATCH or DELETE under `/stock`.** That absence is asserted by
a test, because it is the whole design.

### Why the stock list pages in PHP

"What is running out" and "what has gone negative" are questions about a sum over
`stock_movements`, not about a column. A page the database chose would be a page
chosen *before* the question was asked — the first fifty variants alphabetically,
of which none may be low.

So one query fetches the variants and one more fetches every position behind
them, however many rows there are. The set is bounded by what a workshop
maintains by hand; M12 revisits this if a catalogue ever grows past that.

## The catalogue protects its history

`ItemVariantService::delete()` and `ItemService::delete()` now refuse anything
with stock movements behind it, and `restrictOnDelete` on `stock_movements`
backs both for anything that does not come through the service. The rule is M3's
and M5's exactly: a movement whose variant vanished loses the name that explains
it — "−3" is a number, "−3 × 5 HP / 1440" is a record.

Note that `item_variants.item_id` cascades from `items`. Without the restraint on
`stock_movements.variant_id`, deleting a family would silently take its stock
history with it.

## Test checklist

**Test:** `php artisan test --filter='Stock|Item|Quantity|PagesRender'`

- [x] `qty_on_hand` and `avg_cost` change **only** through a movement — asserted
      structurally: no such column exists on `items` or `item_variants`
- [x] Stock-OUT values at the current average and does **not** change it
- [x] WAC verified: 10 kg @ ₹700 then 10 kg @ ₹800 → ₹750/kg
- [x] Stock value in the Inventory ledger equals Σ(qty × cost) across variants —
      `assertStockAgreesWithInventoryAccount()`, asserted in every scenario
- [x] Negative stock is **refused** by default and permitted by a per-tenant
      setting — M17's D6, tested in both directions, with a draft still saved
      without refusal and a stock adjustment still exempt
- [x] Issuing an entire position leaves it at exactly zero, with no rounding
      residue in the Inventory account
- [x] A movement can be neither edited nor deleted
- [x] A batch whose Inventory line disagrees with its movements is refused
- [x] A type that does not move stock cannot carry movements
- [x] A service can never hold stock
- [x] A fractional quantity of a counted item is refused; of a measured item, is
      ordinary
- [x] A reversal puts the quantity back at the value it left at
- [x] A draft moves no stock and is re-valued when it is finally posted
- [x] A variant with stock history cannot be deleted
- [x] One workshop's stock is invisible to another
- [x] There is no route that writes stock directly

## Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| Quantity and value are signed; the type says *why*, not *which way* | A position is then one indexed sum rather than a `CASE` every caller re-writes — and `adjust` genuinely goes both ways |
| `value` is authoritative, `unit_cost` is document detail | The rate is rounded; the value is what the Inventory account actually moved by, and only one of the two can be summed |
| An issue takes its **share** of the value | Quantity × a rounded average leaves paise behind on every issue, which accumulate as stock value with no stock |
| ~~Negative stock is warned, not blocked~~ — **revised in M17** | The original reasoning holds for a workshop that bills ahead of its paperwork, and not for a counter that wants to be told what it can promise. Both are real, so it is a per-tenant setting, refused by default |
| The refusal lives at posting, not at composition | A draft is exactly the tool for parking a bill until the purchase is entered, and refusing to save one is the failure M8 warned about, arrived at from the other direction |
| A stock adjustment is exempt from the refusal | It is the workshop asserting what is physically there, and the only tool for repairing a position that is already negative |
| A shortfall is valued at the last rate paid, never zero | A 100% margin on an ordinary sale is a worse lie than an estimate |
| An adjustment worth nothing is refused | Quantities moving with no accounting trace is the drift this module exists to prevent |
| `transaction_id` is NOT NULL | Inventory value appearing with nothing on the other side is the same error as an unbalanced journal, in a table where nothing checks the totals |
| The Inventory line is asserted against the movements at the engine | `PostingBatch` is public; M11 and M15 compose by hand, and a mismatch is undetectable afterwards |
| A manual journal to Inventory is still allowed, and surfaced | M4's escape hatch stays open; the reconciliation panel is what makes it safe to leave open |
| Locks are taken only inside a transaction | `lockForUpdate` outside one is released immediately — it would be a lie about the guarantee, which is worse than no lock |
| A draft stores the request, not the derived lines | Cost of goods sold is the average *at the moment of posting*; a frozen valuation posts a number that was true once |
| A reversal is typed `adjust` and never re-valued | It is a correction, and re-valuing would leave a residue against stock that is physically there |
| A shortage is written off to COGS | It is consumption nobody recorded, and it belongs where the rest of that consumption already is |
| `STOCK` is a read-only grant, separate from `ITEMS` | Nothing writes the stock ledger but the engine — and knowing the workshop deals in 5 HP motors is not knowing four are on the shelf |
| Two nav entries, "Items" and "Stock", not one "Inventory" | They answer different questions and are gated on different grants |
