# Opening Balances

> **Card status: switched off**, and this is the module whose absence is worst in
> combination. With Opening balances and Settings both off, **a real workshop
> cannot go live**: its existing debtors, creditors, stock and cash have no way
> in, so every figure the product reports starts from zero on the day the
> software is first opened. The module itself is complete and tested and waits
> only on the §2A conversion. When it is converted, keep the preview-then-post
> discipline exactly as it is — it is the safety property, not a courtesy. See
> [hidden-modules.md](hidden-modules.md).

Getting a running workshop's existing position into the books — M11.

A workshop that has been trading for eleven years does not open at zero. It has
motors on the shelf, customers who owe it money, suppliers it owes and cash in
the till, and **every figure this product reports is wrong by whatever was
already there** until somebody says what that was.

This is the one screen in the application whose job is to be used once.

## Everything's other side is Opening Balance Equity

That is the definition, not a convenience.

| Declaration | Posts |
| --- | --- |
| Opening stock | `Dr Inventory / Cr OBE` |
| Opening receivable | `Dr Sundry Debtors / Cr OBE` |
| Opening payable | `Dr OBE / Cr Sundry Creditors` |
| Any other account | `Dr/Cr the account / Cr/Dr OBE` |

An opening balance is not a transaction *with* anybody. Nothing was bought,
nothing was sold, nobody was paid and no GST was charged. What is being declared
is the workshop's own position at go-live, and the accounting name for "what the
business was worth before we started counting" is equity.

### Why not route opening stock through a purchase

Because it is what a spreadsheet import usually does, and it is wrong in three
separate ways at once:

* it reports the workshop's first month as an enormous acquisition;
* it claims input tax on stock whose tax was claimed years ago;
* it invents a supplier who was never paid.

`StockMovementType::Opening` exists so a stock report can tell the two apart
afterwards — it was reserved in M8 for exactly this.

## The residual is the owner's stake

The roadmap asks for "trial balance shown, with OBE absorbing any residual". The
honest version of that is **not an error check.**

Every line of an opening balance is posted against Opening Balance Equity, so the
books reconcile whatever is imported. There is no residual in the sense of a
difference that failed to balance, and a screen implying there might be would
teach people to distrust a number that is always correct.

What OBE ends up holding is the **owner's stake at go-live**: assets declared,
less liabilities declared. That is a real figure, it is usually the most
interesting one on the screen, and the only way it comes out wrong is if
something was left out of the file.

Which is exactly why it is shown prominently, and shown *before* anything is
posted. A workshop that forgot its ₹40,000 of cash sees a stake ₹40,000 short of
what they know it to be, on the preview, while they can still fix it.

## Re-importing cannot double a balance

Two guards, and the **second** one is the real protection.

### The fingerprint — for the common case

A SHA-256 over the canonical rows, unique per workshop. It catches a refresh, a
double click, a retry after a timeout, and it is insensitive to the things that
do not change what is being declared: column order, header spelling, blank spacer
rows, and the order of the rows themselves. Somebody who re-sorted their
spreadsheet by supplier has not changed a figure.

It is defeated by any edit at all, and a workshop that splits its opening
position across three overlapping files never trips it.

### Per target — the one that holds

A variant that already carries opening stock, a party that already has an opening
balance, an account that already has an opening entry, is **skipped** whatever
file it arrives in and however it has been edited since.

That is a property of the ledger rather than of the file, which is why it cannot
be got round. It is also why a skipped row is reported and counted rather than
refused: running the same file again is a reasonable thing to do when the first
attempt was interrupted, and telling somebody their file is broken when it is
merely already in would send them off to "fix" it — which is how a workshop ends
up with two go-live positions.

## Nothing is posted in part

A plan with any unresolvable row is refused whole, and the catalogue records it
would have created are rolled back with it.

The alternative — post what resolves, report the rest — sounds helpful and is the
worst possible outcome here: the only way to find out what landed is to reconcile
the entire go-live by hand, which is the job the import existed to avoid.

## Preview and commit run the same code

`OpeningBalanceService::plan()` resolves a file without writing anything;
`import()` runs the identical resolution and then posts it. The only difference
between the two runs is whether the records a row needs are invented or merely
counted.

That is not tidiness. A preview assembled by different code from the commit can
be right about something the commit gets wrong, and an owner who agreed to one
set of figures would find another in their books.

The resolution happens **inside** the import's database transaction, for the same
reason the posting engine re-composes a draft inside one: between a preview and a
commit another session may have added the very item this file was about to
invent, and the resolution that matters is the one holding the write.

## The file

CSV, and CSV only.

Reading `.xlsx` means a dependency that parses ZIP archives and XML from an
untrusted upload. The cost is not the megabytes — it is that a workshop's go-live
file is the one piece of user-supplied data this product parses at all, and the
smallest possible parser is the right one to point at it. "Save as CSV" is one
menu item.

What the parser *does* accept is everything a real export contains: a UTF-8
byte-order mark, `\r\n` endings, semicolons where a European locale put them,
headers in any order and any case, `₹1,24,500.00`, `(2,000)` for a negative, and
column names from whatever package the workshop used before. None of that is
generosity — each one is a file somebody would otherwise re-key by hand, and
re-keying is where transcription errors come from.

| Column | Meaning |
| --- | --- |
| `kind` | `stock`, `receivable`, `payable`, `balance` |
| `name` | the item, or the party |
| `variant` | the specification: `6204`, `22 SWG`, `5 HP / 3 ph / 1440 RPM` |
| `type` | `motor`, `part`, `bulk_material` — only when the item is new |
| `quantity` | stock only |
| `unit_cost` | stock only, per base unit |
| `amount` | what it is worth; for stock, optional and overrides qty × cost |
| `account` | balance only: an account code or name |
| `side` | balance only: `debit` or `credit`, defaulting to the account's own |
| `gstin` | party rows, optional |
| `reference` | free text, kept as the ledger memo |

A column this product has no use for is ignored rather than refused. A workshop's
own spreadsheet has a notes column, and making them delete it before they can go
live would be a poor trade.

## Matching, and where it stops

Deterministic, and **no language model anywhere near it** — M15.3's rule arriving
four modules early, for the same reason. A matcher that sometimes decides "Sharma
Motor Winding Co." is "Sharma Motors" will one day post ₹80,000 of somebody
else's debt against the wrong party, and nothing downstream can tell.

Three rungs, in order:

1. **Exact**, after folding case, punctuation and legal-form words. "M/s Sharma
   Motors Pvt. Ltd." and "SHARMA MOTORS" are one business, and treating them as
   two splits one balance in half — precisely what M5 built one parties table to
   prevent.
2. **Similar**, by edit distance, at 88% of the longer string or better. That
   lets through a dropped letter and refuses "Verma Motors", which is a different
   business in the next street.
3. **Nothing** — which is a real answer and usually the right one.

There is no "closest of a bad lot". Digits are never folded away: `6204` and
`6205` are two bearings.

## What is created, and what is refused

### Created, and always flagged draft

A row of a spreadsheet gives an item a name, a rough type and nothing else — no
HSN code, no GST rate, no sell price. Every one of those is needed before it can
be billed correctly, and a record that looks complete because nothing said
otherwise is how a workshop ends up charging 0% GST on a motor for a year. M7
built the review queue for exactly this moment.

A **new item needs its `type` stated**, and it cannot be guessed. The type fixes
the unit every quantity will ever be recorded in, and M7 made both permanent
precisely because changing them later would reinterpret every figure already
posted — "each" becoming "kilogram" turns 40 pieces into 40 kilograms in every
report ever run.

A **new variant's specification is parsed as the inverse of the label the app
prints**: segments split on `/` fill the type's required attributes in schema
order. So a file exported from this product round-trips, and a file written by
hand only has to follow what the screens already show. Fewer segments than
required attributes is refused by name rather than padded — a motor whose HP was
never captured is unidentifiable by anybody afterwards.

Declaring a payable to an existing customer **adds the vendor role** rather than
refusing the row. Onboarding is the one moment a workshop describes its whole
trading history at once, and stopping to tick a box on a record they are in the
middle of importing would be bureaucracy. It is one click to undo, and M5's rule
that roles never filter a *read* means nothing is hidden if it turns out wrong.

### Refused

| Refused | Why |
| --- | --- |
| An account the chart does not have | Accounts are structural. One created from a spreadsheet cell lands in whichever code band its name suggests, and an expense numbered 1500 sorts into the assets in every report that groups by code |
| A figure typed into Inventory | Stock is declared by listing what is on the shelf, or the books carry a value no quantity backs |
| A lump sum on Sundry Debtors or Creditors | A total nobody can break down cannot be chased or settled |
| Opening Balance Equity itself | It is the other side of every opening line; declaring it would post the account against itself |
| Stock with no value | Carried at nothing, it reports a 100% margin on the first one sold — a number nobody would question |
| A service in stock | An hour is produced at the moment it is sold. There was never any of it on a shelf |
| Anything dated before `books_start_date` | M2.2's rule, enforced by the engine (`BOOKS_CLOSED`) |

## Authority

`WRITE:TRANSACTIONS` **and** `UPDATE:WORKSPACE`.

The first is obvious — posting an opening balance is capturing a business event.
The second is what keeps it out of a data-entry user's hands: declaring what the
workshop was worth at go-live is a setup act, it belongs beside
`books_start_date`, and it is not something a clerk should be able to do while
entering the day's takings. Only `OWNER` holds both.

There is no PATCH and no DELETE. An opening balance that was wrong is corrected
the way every posted transaction is: with a reversal, which leaves both the
mistake and the correction on the record.

## Provenance

`transactions.source` is `import`, which is the reason `TransactionSource::Import`
has existed unused since M4. `transactions.opening_import_id` says *which file*,
which is the question somebody actually asks when a figure looks wrong — not "was
this imported" but "was this the one we loaded on the 3rd, with the wrong column
order".

It is **write-once**: it may go null → set and never again, guarded on the model
alongside the immutability rule it sits beside. A column that could be re-pointed
would let an import receipt claim postings it never made.

`opening_imports` holds no balances. Every figure on it is a copy of what the
ledger already says, kept because the *decision* is not recoverable from
`journal_entries` afterwards. Ask it what was declared; ask the trial balance what
is true.

## Test checklist

`php artisan test --filter='Opening'`

- [x] Import produces a reconciling trial balance — and the shelf agrees with the
      Inventory account
- [x] A deliberate mismatch surfaces as an OBE residual, not a silent error —
      asserted as an owner's stake that is short, with the books still balanced
- [x] Re-importing the same file does not double the balances — by fingerprint,
      **and** by target after the file has been edited
- [x] Fuzzy matching does not create duplicate variants
- [x] Opening stock claims no GST and owes nobody
- [x] Nothing posts when any row cannot be resolved
- [x] A counterparty owed and owing gets one document with both
- [x] Every opening record is scoped to its own workshop, and identical
      declarations in two workshops are not a duplicate
- [x] An opening balance is dated at go-live, and one dated earlier is refused

## Decisions worth carrying forward

| Decision | Why |
| --- | --- |
| Every opening line posts against OBE | The books then reconcile whatever is imported, so a mistake misstates the position rather than breaking it — and shows up as a stake that does not look like the owner's |
| The per-target guard, not the fingerprint, is the real one | A fingerprint is defeated by any edit; "this variant already has opening stock" is a fact about the ledger |
| A skipped row is reported, not refused | Re-running an interrupted file is reasonable. Calling it broken sends people off to "fix" a file that was fine |
| Refused whole, never in part | A half-imported go-live can only be unpicked by reconciling the lot by hand |
| Preview and commit are one code path | A preview that can disagree with the commit is worse than no preview |
| An item's `type` is demanded and never guessed | It fixes the unit permanently. A wrong guess cannot be corrected, only archived |
| The variant format is the inverse of the printed label | The importer reads what the screens already show, so the rule is explainable and a file this product exported round-trips |
| Accounts are never invented; items and parties are | A wrong account puts an entry on the wrong financial statement for ever. A wrong item is a draft in a review queue |
| Everything invented is flagged draft | A name and nothing else is not a billable record, and one that looks complete gets billed at 0% GST for a year |
| CSV only | The one place this product parses user-supplied data deserves the smallest parser that does the job |
| `UPDATE:WORKSPACE` as well as `WRITE:TRANSACTIONS` | Declaring the workshop's net worth is setup, not the day job |
