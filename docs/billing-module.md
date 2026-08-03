# Bill Engine & Misc Expense

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

Both of the roadmap's warning cases post:

| | |
| --- | --- |
| `BILL_LINE_BELOW_COST` | Clearing old stock below cost is a real decision, and so is a job quoted before the copper price moved |
| `STOCK_NEGATIVE` | M8's decision, seen from a bill: refusing the sale does not produce the bearing |

They are computed **on read**, by `BillService`, not raised once at posting.
"Why is this month's margin down" is asked long after the toast has gone, so a
warning has to be there when somebody opens the bill — and the same call serves
the 201 that confirms it was posted.

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

Two routes rather than one with a `direction` field, exactly as payment and
receipt are two: selling a motor and buying one in are different events, and the
URL should say which happened. One *request class* for both, because the payload
is genuinely identical — the opposite trade-off from journals and settlements,
where the payloads have nothing in common.

## Test checklist

**Test:** `php artisan test --filter='Bill|Expense|Stock|PostingEngine'`

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
- [x] The transaction total is the invoice, not the sum of its debits
- [x] A draft writes no lines and is re-priced when it posts
- [x] A reversal returns the stock and nets every account to nothing
- [x] A sale may go ahead when the shelf says there is nothing there
- [x] Expense with and without claimable GST input
- [x] Expense paid from any payment mode, and split across several
- [x] Expense booked to a workshop's own account, and refused on a non-expense one

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
| Null margin on a labour line | Reporting ₹0 of cost would claim a 100% margin on the workshop's most valuable work |
| A bill accepts a payment split; a settlement requires one | At a counter, invoicing and collecting are one event — but a sale on terms is still a complete document |
| A split may not exceed the document | An advance is a receipt with no invoice, which M6 already handles; this is a typo |
| Settlement lines are never merged into the control line | "Invoiced ₹5,000, collected ₹2,000" is two facts |
| The template states the document total | A sale's debits include cost of goods sold, and reporting that as the invoice value would be wrong on every list |
| A draft stores the request | Cost of goods sold is the average at the moment of *posting* |
| A reversal carries no document lines | A credit note's lines are the original's, and a negated copy is not even representable |
| Below cost and negative stock warn on read, every read | The question they answer is asked long after the toast has gone |
| The client previews the total and never computes it | A second implementation of the tax arithmetic is a second answer, and one of them goes on a return |
