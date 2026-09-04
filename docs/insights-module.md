# Insights

What the numbers *mean*, as opposed to what they are — M23.

**No figure here is stored.** Every one is a sum over `transaction_lines`,
`stock_movements`, `journal_entries` or `payroll_lines`, computed at the moment
it is asked for. There is no `insight_stats` table, no nightly rollup and no
cache between requests — the rule M4 set and every module since has inherited.

This is the module most likely to be handed a rollup for speed, and the one where
a stale number would do the most damage: a workshop whose insights screen
disagreed with its own P&L would stop trusting both. If something here becomes
slow the answer is an index, not a copy.

---

## One card, not two

There was a `reports` card, switched off, holding M12's four statements. It is
now the last four tabs of this module and the card is gone.

Two cards would both have answered "how is the business doing", and an owner
looking for sales-by-month would have had to guess which of them had it. They
would also have needed two period pickers, two stats strips and two fetch layers
— and the second copy of each is the one that drifts (§5.1).

| Tabs | Question | Grant |
| --- | --- | --- |
| Overview, Sales, Purchase, Stock, Money owed | Is anything wrong, and where do I look | `READ:LEDGER` |
| People | The same, about wages | `READ:LEDGER` **and** `READ:STAFF` |
| Day book, P&L, GST, Parked drafts | What is the figure | `READ:LEDGER` |

**The statements were not rewritten.** Those four tabs still fetch
`GET /reports/*` — exactly what they fetched before. A second URL for one answer
is a second thing to keep in step, and the second one always drifts. What moved
is only where the markup is rendered: from `document` writes in a page module to
strings in `components/report-statements.js`, because a tabbed module detaches
the surface it is not showing and `document.querySelector` finds nothing there.

---

## Why the lines and not the ledger

The P&L reads `journal_entries`, and it is right to: that is the statement. But
the ledger has one Sales account and one COGS account, so it cannot say *which*
items earned the margin, *who* bought them, or how much was given away in
discount. Those questions live on the document lines, and M9 wrote the columns
knowing something would eventually ask.

A line's **cost** is a join to the `stock_movements` row that costed it, on
`transaction_line_id`. Not a second copy of a figure the stock ledger already
owns (§4.3, §4.4).

### The reconciliation is the point, not an afterthought

The two agree whenever every rupee of income arrived through a bill. They cannot
when somebody posts a manual journal straight to Sales — which M4 deliberately
allows, because it is the correction mechanism for everything else.

The overview states the difference **and never repairs it**, even when it is nil.
This module reads; it does not fix. Stating it either way is what lets a reader
tell "nothing to see" from "we did not check" — the same rule the GST summary's
reconciliation already follows.

---

## The comparison is the product

A number on its own is not an insight. Every headline carries the same figure for
the preceding window of **equal length**, so ₹4,20,000 becomes "₹4,20,000, up
18%".

`ReportPeriod::previous()` decides what "the window before" is, and the length is
what it preserves, not the calendar name: the window before 1–31 March is 29
January to 28 February, because comparing 31 days against 28 would report a 10%
fall in a shop that traded identically every day. February is what the picker is
for.

It returns **null** where there is no honest answer — all-time has no before, and
an open-ended custom range has no length to step back by. A null delta paints
nothing at all: no arrow, no dash, no "0%". So does a move from zero, because a
workshop's first invoice is not a 100% improvement on nothing.

---

## What each panel is for

### Overview

Headline tiles with deltas, the revenue-and-margin trend, the goods/labour split,
and **the exception feed** — the part somebody acts on. Rows with nothing behind
them are dropped rather than rendered as zeroes: a list that always shows the same
eight entries, six of them saying "0", is a list nobody reads. Every row carries
the tab it is about, because a finding you cannot act on from the screen it
appears on is a finding you will not act on.

### Sales, and Purchase

One renderer with a direction, exactly as the two modules are one document engine
with a direction. The asymmetry is the same one the bill form makes: a purchase
arrives at a cost it states, so there is no margin to report and nothing can be
below cost. Those sections are **absent** on that side rather than empty.

**Margin excludes labour from its denominator and not from its amount.** An hour
is produced at the moment it is sold, so a labour line has no cost of goods —
counting it would report the whole of it as margin. The amount is real and is
reported in full; the percentage is taken against goods revenue alone. This is
`TransactionLine::margin()`'s judgement applied to the aggregate.

**Items are ranked by margin, not revenue.** The biggest seller is very often not
the part paying the rent, and no other screen in the application can tell them
apart.

**Discount has never been totalled anywhere before.** It is routinely the largest
number an owner has not seen: 4% average discount against a 12% margin is a third
of the profit, one line at a time. It is not coloured red — a discount is a
decision, and what it needs is to be visible.

### Stock

Value over time (a *closing balance*, drawn as an area, not a flow), turnover,
what has stopped moving, what needs reordering, and what stock-takes wrote off.

**Dead stock is the panel that pays for the module.** `stock_movements` has
carried the last-issue date per variant since M8 and nothing has ever asked it. A
variant never issued at all is included and said so plainly: "never sold" and "not
sold since April" are different problems — one is a buying mistake, the other a
part that has gone out of use.

**Shrinkage is invisible in the P&L on purpose.** `StockAdjustmentTemplate` posts
a write-off to COGS rather than to an account of its own, because a separate
account would report a healthier gross margin than the workshop actually earns.
The consequence is that a shortage is indistinguishable from a sale in the
statement — so it is surfaced here, from the movements. Written off and found are
shown **apart, never netted**: a workshop that lost ₹40,000 and found ₹38,000 has
a counting problem, not a ₹2,000 one.

Turnover is `null` rather than zero on a workshop holding no stock. "0.00" would
read as "your stock is not moving" when the truth is that there is none.

### Money owed

The one part of this module answering a question nothing else could.
`PartyLedgerService` says a customer owes ₹40,000; `BillService` says invoice #212
has ₹8,000 on it. Neither says whether that ₹40,000 is this week's trading or a
debt from March.

**The ageing is not filtered by the period, deliberately.** A position is not an
event: the invoice from March is precisely the one the report exists to surface.
The period still drives collection efficiency, which genuinely is about a window.

**Terms nobody agreed to are not a deadline.** `tenants.payment_due_days` is
nullable because a counter trade settles on the spot. Where it is null every
bucket is measured from the **invoice date** and the panel says so. Silently
treating "no terms" as "due immediately" would put a workshop's whole ledger in
the 90-day bucket and send somebody chasing customers who are not late by any
agreement anybody made.

**A negative position is not a small debt.** A customer who has paid ahead appears
in no bucket — the ageing is built from open documents, and an over-payment is a
balance with no document behind it. It is reported separately as *credit held*, in
blue, because showing it in the amber that means "chase this" everywhere else
would send somebody after money the workshop is holding.

#### Why the ageing and a party's balance can differ

They count different things, and the panel reconciles them rather than hiding it:

```
the ageing        open documents — a bill leaves when something is allocated to it
a party balance   the ledger — a receipt reduces it the moment it posts
```

Bank a cheque without saying which invoice it settles and the customer's balance
is already nil while the invoice is still open. Both are true. Allocating is a
deliberate act — `SettlementService` will not guess which invoice somebody meant —
so the unallocated total is reported as a **worklist**, not netted away and not
used to clear the invoice.

The "is this bill open" test is literally the same SQL expression the bills list's
`outstanding` filter uses, shared through
`TransactionRepository::outstandingBills()`. An ageing that disagreed with the list
it links to would discredit both.

### People

**Gated on `READ:STAFF` as well as `READ:LEDGER`, and that is a privacy line
rather than an authority one.** STAFF is the one grant in this application
withheld because of what it reveals about individuals: what each person earns is
not something the clerk at the counter needs. A caller without it gets an overview
with **no wage tile at all** — absent, not blanked, because a tile reading "—"
tells somebody there is a number there.

A run belongs to the **month it is for**, not the day it was posted: a March sheet
paid on 3 April is March's cost. Only posted runs count — a reversed run is money
that was un-paid, so it is excluded rather than netted, because counting a
reversal as a negative wage would report a month in which the wage bill fell.

**Cost and attributed work sit side by side and are never divided into one
another.** A ratio would look like a productivity score and be read as one, but
attribution carries no line grain and no hours, an invoice names at most one
person per trade, and half a workshop's people never appear on a document at all.
A winder with no invoices against them is usually the person doing the stripping.
CLAUDE.md is explicit that attribution must never become an input to pay.

**An unmarked day is reported as unmarked.** What silence is worth depends on how
somebody is paid, and that decision lives in `SalaryBasis::unmarkedDayIsPaid()` and
nowhere else.

---

## Reversals: both halves drop out

Two exclusions, and they are not the same one:

```
status = posted        drops the document that WAS reversed
reverses_id is null    drops the reversal that cancelled it
```

Without the second, a reversed invoice's cancelling document would be counted as a
fresh sale. It carries no bill lines today, so the revenue would happen to come
out right and the *document count* would not — and a correction that ever gained
lines would silently double the month.

Correcting a bill through `/revise` is unaffected: that issues a **replacement**,
a new document with no `reverses_id`, counted exactly once.

`inTheBooks()` is deliberately not used. It is right for the ledger, where a
reversal's entries have to be present for the trial balance to balance. It is
wrong here.

**Stock value is the exception.** `valueTrend()` counts *every* movement,
reversals included, because that is how they cancel — and because
`StockLedgerService` does the same, which is what keeps the two agreeing. The
filter applies only to the flow questions: what was issued, what was written off.

---

## The charts

No charting library (§7.1). The module needs columns, one stacked bar and one
filled area; the alternative is shipping a general-purpose plotting engine to draw
three shapes, arriving with its own colours, fonts and idea of a tooltip.

**Columns are HTML, not SVG.** An SVG with a `viewBox` scales its *text* along
with its geometry, so a chart that fits a laptop renders microscopic axis labels
on a phone. Columns are flex children with percentage heights: they reflow at any
width and the labels stay at the document's font size. Only the area chart needs a
path, and it is stretched with `preserveAspectRatio="none"` and carries no text —
its labels are the same HTML ticks.

Negative values draw **below a zero line** rather than being clamped: a bar of
height zero would say the workshop broke even when it actually lost money.

Empty buckets are drawn. A chart built only from the buckets that have rows spaces
three trading days evenly across the width and reads as a steady trickle, when
what happened was one busy Monday and a fortnight of nothing. A gap has to look
like a gap.

Granularity follows the window — daily up to two months, weekly up to about a
year, monthly beyond — decided once in `TrendGranularity` so every panel cuts a
trend the same way. Payroll is the exception and is always monthly, because the
underlying fact has a period of its own.

Bucketing happens **in PHP, not SQL**. `DATE_FORMAT` is MySQL's, `strftime` is
SQLite's, `to_char` is Postgres's, and the alternative is the same arithmetic in
as many dialects as the application is ever run against. Grouping by the bare date
returns at most 366 rows for a year.

---

## Tenancy

Every query goes through an Eloquent model. `TenantScope` only binds queries that
go through Eloquent, so a raw `DB::table()` anywhere in this module would read
every workshop on the platform. That is the single most important constraint in
these files, and there is a test for it.

---

## What this module deliberately does not have

**No targets or budgets.** "Actual against target" needs a table somebody
maintains, and a target nobody has updated since April is worse than none.

**No forecasting.** A projection drawn from four months of a workshop's trading is
a straight line with a confidence nobody stated, and it would be quoted as a fact.

**No custom report builder.** Every panel here is a question somebody actually
asks, phrased in the workshop's own words. A builder answers none of them and asks
the user to know SQL-shaped things about their own books.

**No per-user dashboards.** One screen, one period, the same figures for everyone
who may see them — which is what makes two people able to talk about the same
number.
