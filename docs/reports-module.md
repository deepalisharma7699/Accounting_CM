# Reports & Worklists

Reading the books at every zoom level — M12.

**No report has numbers of its own.** Every figure is a sum over
`journal_entries`, `stock_movements` or `transaction_lines`, computed at the
moment it is asked for. There is no reporting table, no nightly rollup and no
cache between requests — for the reason M4 gave and every module since has
inherited: a stored aggregate agrees with its entries right up until one of them
is written without the other, and nobody notices for months.

That is also why this module needed almost no new machinery.

## What was already there

| Report | Where it lives | Since |
| --- | --- | --- |
| Trial balance | `GET /ledger/trial-balance` | M4 |
| Account ledger, with a running balance | `GET /ledger/accounts/{id}` | M4 |
| Transaction list — filters, search, provenance | `GET /transactions` | M4 |
| Drill-down: transaction → lines → journal entries | `GET /transactions/{id}` | M4, extended by M9 |
| Party statement and outstanding | `GET /parties/{id}/ledger` | M5 |
| Stock summary and per-variant movement history | `GET /stock/summary`, `GET /stock/variants/{id}` | M8 |

None of them is re-exposed under `/reports`. A second URL for one answer is a
second thing to keep in step, and the second one is always the one that drifts.

## What M12 adds

Four things nothing had asked for yet.

| Report | Answers |
| --- | --- |
| **Day book** | What did we actually do that day |
| **Profit & loss** | Is the workshop making money, and where |
| **GST summary** | What is the tax, rate by rate |
| **Parked drafts** | What did somebody start and never finish |

## The day book

Every voucher in a period, **forwards**, with its ledger lines already loaded.

The oldest report there is, and still the one somebody reaches for when a figure
looks wrong: not "what does this account hold" but "what did we actually do".

It is deliberately not `GET /transactions` with a filter. A day book is read
oldest-first, the way the day happened, where a transaction list is read
newest-first; and it carries every line of every voucher, where a list carries a
count. Bending one query to do both would mean a listing page that sometimes
loaded every entry in the workshop.

Drafts are absent structurally rather than by a filter — they have no journal
entries at all, so a day book that included them would show vouchers with no
lines.

## Profit & loss

Three figures, reported separately and **never netted**:

```
revenue          Σ income accounts
− cost of sales  the COGS account
= gross margin   does the trade itself work

− overheads      every other expense account
= net
```

Keeping cost of sales out of overheads is the entire reason M10 refused to let an
expense be a purchase. A workshop with an 8% gross margin has a pricing problem;
one with a 40% margin that still loses money has a rent problem — and a statement
that added the two together would say neither.

### Why it reads the chart and not a list of names

A workshop adds its own expense accounts, and a report built from a fixed list
would silently omit every one of them. Income and expense are properties of
`AccountType`, so the statement is assembled from whatever the chart actually
holds.

The only account singled out by name is **COGS**, because gross margin needs cost
of sales separated from overheads and nothing else does. Everything the workshop
chose to add is an overhead, which is right: an account somebody created is a cost
of being open until they say otherwise.

Balance sheet accounts are excluded. They carry forward year to year rather than
being part of a period's result.

## GST summary

Output tax charged and input tax paid, **rate by rate**, read from
`transaction_lines`.

That is the point of the report. Phase 1 has one GST Output account and one GST
Input account, so the journal knows what tax was charged but not at what rate,
nor how it split into CGST, SGST and IGST — and a return is filed rate by rate.
M9 wrote those columns knowing this report would need them.

### The reconciliation is not the source

The ledger balances of the two tax accounts are reported alongside. If they ever
disagree with the sum of the document lines, something reached a tax account
without a bill behind it — almost always a manual journal, which M4 deliberately
allows because it is the correction mechanism for everything else.

The difference is **shown, and never repaired**. This module reads; it does not
fix. Surfacing it is how a manual correction stays a decision rather than a
surprise on a return, and it is stated even when it agrees so that a reader can
tell "nothing to see" from "we did not check".

## The parked-draft worklist

Everything started and never authorised, **oldest first** — the opposite of every
other listing in the product, because a worklist is ordered by what needs
attention most.

Unfiltered by period, deliberately. A draft is outstanding work rather than an
event, and the one from three months ago is precisely the one somebody needs to
see; hiding it because the date picker says "this month" would defeat the purpose.

### What "stale" means, and why it is a warning

A fortnight, and it is not an expiry.

A draft does not go wrong at midnight on day fourteen — it goes wrong gradually,
as the weighted average cost behind it moves. The posting engine already handles
the correctness half by re-composing a draft when it is finally authorised, so
**nothing here can post a stale price**.

What staleness costs is different and softer: a bill nobody finished is revenue
the workshop has not recorded, and after two weeks it is revenue somebody has
forgotten. Deleting it automatically would be destroying work; saying nothing
would be losing the sale.

The totals on this screen are the least reliable figures in the module, and it
says so: a draft of a derived type is re-priced and re-costed the moment it posts.

## The period

`ReportPeriod` is where M2.2's financial year is finally used.

| Preset | |
| --- | --- |
| `this_financial_year` / `last_financial_year` | The workshop's own, via `Tenant::financialYearFor()` |
| `this_month` / `last_month` / `this_quarter` / `today` | In the workshop's timezone |
| `all` | Everything so far |
| `custom` | Two dates |

### Why a preset and not two dates

Because "this financial year" is the question people ask, and turning it into a
pair of dates in the client means the client owns the workshop's year-start
setting. It would then be right until somebody changed that setting, at which
point every saved bookmark would quietly report the wrong twelve months.

The April off-by-one is computed in one place — on the model, since M2.2 — and
this hands the answer to a report. A second implementation would be a second
thing to get wrong in February.

Resolved in the **workshop's** timezone, not the server's. "Today" in
Asia/Kolkata is a different day from "today" on a UTC server for five and a half
hours of every day, and a day book that dropped the evening's takings would be
reported as data loss.

### Two forgiving rules

* Dates in the wrong order are **swapped**, not refused. Somebody who filled the
  boxes backwards wants the range between them, and answering "no entries" would
  look like a workshop with no trade.
* An unknown or stale preset falls back to **everything**, not an error. A report
  that refused to draw because a query string was stale is worse than one that
  draws a window it states on itself.

## Authority

| Report | Grant |
| --- | --- |
| Day book, P&L, GST | `READ:LEDGER` |
| Parked drafts, meta | `READ:TRANSACTIONS` |

The split is the one M4 made and every module has kept: capturing events and
reading the workshop's whole financial position are different authorities. The
worklist is the exception, and deliberately — a parked draft is a data-entry
user's *own* unfinished work, and a worklist only the owner could see would be a
worklist nobody acts on.

The nav entry is gated on `READ:LEDGER`, because three of the four reports need
it and an entry that opened on a page a user could not load would be worse than
none.

## Test checklist

`php artisan test --filter='Report'`

- [x] Every report reconciles against the trial balance — the day book's totals
      are the trial balance's totals
- [x] Reports respect the financial year from M2.2 — an April workshop and a
      January workshop report different windows from the same day, and the window
      actually filters what is reported
- [x] A report for a workshop with no data shows zero — not an error, and never
      another workshop's numbers
- [x] The P&L separates gross margin from overheads, and carries no balance sheet
      account
- [x] A workshop's own expense account appears without being named anywhere
- [x] The GST summary reconciles against the tax accounts, and surfaces tax that
      reached one without a bill behind it
- [x] The worklist flags a stale draft, ignores the period, and empties when a
      draft is posted
- [x] A data-entry user sees their worklist and not the workshop's position

## Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| Nothing under `/reports` duplicates an existing endpoint | Two URLs for one answer means one of them drifts |
| The day book is its own query, not the transaction list with a filter | Opposite sort order, and it loads every line — one query bent to do both would make a listing page load the whole ledger |
| The P&L is assembled from the chart | A fixed list of account names silently omits every account a workshop added |
| COGS is the only account named | Gross margin is the one figure that needs cost of sales separated. Everything else is an overhead until somebody says otherwise |
| The GST reconciliation is reported, never repaired | A difference means a manual journal touched a tax account. That is allowed, and it must be visible before a return is filed |
| Stale is a warning, not an expiry | The engine already re-prices a draft on posting, so staleness costs attention rather than correctness |
| The worklist ignores the period | A draft is outstanding work, not an event. The old one is the point |
| The period is a preset resolved server-side | "This financial year" depends on a setting the client must not own a copy of |
| Bad dates are swapped and bad presets fall back | A report that refuses to draw teaches people the reports are broken |
