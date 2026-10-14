# Opening Balances

> **Card status: on** — converted in C1, with Settings, because either alone
> left go-live blocked. The module opens on the **declaration** (§2A.1) and every
> import ever run sits behind "Show list", with the count on the Show control.
>
> The *position* — the owner's stake, the go-live date and the trial balance —
> travels with the **form**, not the list: it is what somebody about to declare
> their whole financial history reads before committing, and what they want to
> see the instant they have. `GET /opening-balances` answers with the position
> and the receipts together, so the list costs no second request.
>
> **The preview-then-post discipline is unchanged**, and must stay that way. The
> post button is disabled until the preview has run against the text *currently*
> in the box, so an edit made after a preview cannot be committed on the strength
> of the preview it invalidated. That is the safety property, not a courtesy.

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
| `variant_id` | stock only: the exact variant — written by the Stock card's template |

`opening_qty`, `opening_quantity` and `opening_stock` are read as `quantity`; they
are the template's spelling. The file can be **chosen** as well as pasted: "Choose
a CSV file" reads it in the browser into the same box, so a file and a paste are
one request, one preview, and the same rule that an edit after a check takes the
post button away. Its name is copied to the file-name field for the record.

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
| Stock for a variant that has already moved | Bought, sold, counted or consumed since go-live, so its history started without this figure. Declared beneath it, the shelf would look right while every sale out of the empty shelf kept a cost of goods taken from nothing. The row says to record a count instead |
| The same variant twice in one file | The second row names the line of the first. Keep one row with the whole quantity |

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
- [x] The Stock card's template declares by `variant_id` and ignores rows left
      empty; a template with nothing filled in is nothing to import
- [x] A variant that has already moved is refused and pointed at a count; a
      reversed opening declaration is not a trading history

## A second way in: one variant, from the Items drawer

The paste box is not the only caller any more. A product written without its
opening stock had no way to acquire one — `opening_stock` is on the create form
and deliberately withheld from the edit — and the substitute people reached for,
Stock's **Record a count**, posts the wrong document:

| | Posting | Dated |
| --- | --- | --- |
| Record a count (template G) | `Dr Inventory / **Cr COGS**` | today |
| Opening declaration (template H) | `Dr Inventory / **Cr Opening Balance Equity**` | go-live |

Crediting COGS puts the whole value of the shelf into the current period's gross
profit as though the workshop had earned it. That is right for shrinkage found at
a stock-take and wrong for stock the workshop already owned.

So the Items drawer offers **Declare opening stock** on a variant, and it is not
a second implementation of anything: it builds one structured `rows[]` entry and
posts it to `POST /opening-balances`, through the same service, the same
resolution and the same guards as a pasted file. It is offered only while the
variant's shelf is empty and it has never moved (`has_trading_history` on the
Stock row), needs `UPDATE:WORKSPACE` like every other declaration,
and previews before it posts — because `OPENING_PLAN_HAS_ERRORS` carries a count
and a sentence about fixing the file, and there is no file.

**A row may name `variant_id`, and then nothing is matched.** The screen knows
which variant it is looking at, and putting a known id through the name matcher
would be discarding the one certain thing it has: two variants of one product
share a name, so a shop with "Bearing 6204" and "Bearing 6204 ZZ" would have a
near-miss decide which shelf a declaration landed on. An id that does not resolve
is an error on that row and never a quiet fall back to the name beside it — a
fall-back is what would turn a stale id in a held page into a declaration against
the wrong variant. A file without a `variant_id` column behaves exactly as before.

The id is **appended to the fingerprint only when present**, and both halves of
that matter. Without it, two variants of one product declared at the same
quantity and cost hashed identically and the second was refused outright as a
file already imported — a correct figure the product would not accept. Adding an
empty segment unconditionally would instead rehash every fingerprint already
stored, and a workshop re-pasting last month's file would stop being told it had
already been imported. `tests/Unit/OpeningRowTest.php` holds both shut.

## A third way in: the Stock card's template

The drawer is one variant at a time, and a workshop that went live with hundreds
of products created and no opening stock will not open hundreds of drawers. So the
Stock card offers **Opening-stock template**: a CSV of the variants its filters
are showing, to fill in and upload here. Again nothing is reimplemented — it is a
file written in this importer's own vocabulary.

| Column | |
| --- | --- |
| `kind` | `stock` on every row |
| `variant_id` | resolves the row exactly; no name is matched |
| `item`, `variant`, `sku`, `unit` | for the person filling it in. `unit` is the base unit the quantity is counted in — there is no conversion |
| `opening_qty` | **empty** — what was on the shelf at go-live |
| `unit_cost` | the variant's `purchase_price` where the catalogue has one, blank otherwise. A row still needs a cost or a total to post |

Three decisions in it are load-bearing.

**It is not the Stock export with a column added.** The export's headers are a
report's, and this importer reads three of them as something else: `Category` is
an alias for `kind`, so every row would be refused; `Value` is `amount`, which
overrides quantity × cost, so an out-of-stock row would declare nothing; and
`Quantity` is the position today, not at go-live.

**Only variants that have never moved are in it, and the server refuses the
rest.** On a live workshop "out of stock" is as often *sold out* as *never
entered*. A variant bought and sold back to nought looks exactly like one nobody
entered, and opening stock on it is stock that was never there. "Never moved"
means no stock movement outside an `opening` transaction — a reversal keeps the
original's type, so reversing a declaration does not count as history. The
template leaves those rows out so nobody counts a shelf only to be told no; the
refusal in `OpeningBalanceService::resolveStock()` is what holds, whatever file a
row arrives in. A variant created with opening stock on the Items form posted a
stock adjustment and so has history, which is right — it was declared.

**A row left without a quantity is dropped, not refused.** Somebody exports five
hundred variants and counts eighty; making them delete the other four hundred and
twenty first would be the re-keying this importer exists to prevent. The parser
drops a row that names a `variant_id` and carries neither a quantity nor an
amount, the same way it drops a spacer line. A prefilled unit cost does not make a
row filled.

**A variant named twice in one file is refused on its second row.** The
per-variant guard reads the ledger, and nothing in a file is posted until every
row has resolved — so two filtered templates pasted together, or a row copied
down a spreadsheet, would otherwise declare one shelf twice. The service keeps the
line each variant was first declared on and refuses the repeat naming it. New
variants are keyed by their preview placeholder, so the preview catches what the
commit would double. This holds for name-matched rows as well as template rows.

The template is written from what the Stock card holds, which is at most the first
2,000 variants; past that the toast says so and asks for a narrower filter rather
than handing over a file that reads as the whole catalogue.

The button needs the same two grants as the upload — there is no point offering a
file somebody may not post — and the Stock endpoint carries `has_trading_history`
and `purchase_price` on every row for it. The history is one query per page,
`StockLedgerService::tradedVariantIds()`, never one per row.

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
| A row may name `variant_id`, and then nothing is matched | The screen knows which variant it is looking at; a fuzzy match on a name two variants share is the one way this path lands on the wrong shelf |
| The variant id joins the fingerprint only when it is set | Two variants of one product otherwise hash alike and the second is refused; adding it always would rehash every file already imported |
| One opening figure per variant per file | The ledger guard cannot see rows not yet posted; a repeat would put stock on the shelf twice |
| Opening stock is refused on a variant that has moved | Sold out and never entered both read as out of stock; an opening figure beneath a sale leaves that sale's cost of goods wrong for good |
| The template is its own file, not the export | The export's headers mean other things to this importer, and a report is not an input |
| A template row with no quantity is ignored | Export everything, fill in what was counted, upload as it stands |
