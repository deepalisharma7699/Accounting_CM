# Item Master & Variants

The catalogue: what the workshop sells, fits, consumes and charges for.

**Catalogue only.** There is no quantity, no cost and no stock value anywhere in
this module — those are M8's, derived from `stock_movements`. The one thing M7 has
to get right is *identity*: being able to say which specific thing was bought or
sold, so that M8 can count it and M9 can price it.

> *Composite-SKU motor catalogue.* — the PRD

## The design problem

A rewinding shop's catalogue holds four things with almost nothing in common:

| | Identified by | Counted in | Holds stock |
| --- | --- | --- | --- |
| A finished motor | its electrical rating — 5 HP, 3 phase, 1440 RPM | pieces | ✅ |
| A bearing | a size — 6205 | pieces | ✅ |
| Copper wire | a gauge — 22 SWG | kilograms | ✅ |
| Rewinding labour | nothing; an hour is an hour | hours | ❌ |

Getting all four into one table without either forty mostly-null columns or a
shapeless attribute bag is the whole problem this module solves. The answer is a
**two-level split** plus a **per-type attribute schema**.

## The two levels

```
items          the family    "3-Phase Induction Motor"    HSN code, GST rate, unit
item_variants  the thing     "5 HP / 3 ph / 1440 RPM"     attributes, price, SKU
```

The family carries what the tax authority and the accountant care about. The
variant carries what the customer asks for.

That split is not tidiness. One HSN code and one GST rate cover forty motor
ratings; repeated forty times, two of them eventually disagree, and the one that
is wrong puts a wrong figure on a government return. Meanwhile **the trade does
not deal in families** — nobody buys "a three-phase induction motor", so the
variant is what has a price, a stock level and a cost. M8 counts stock per
variant; M9 prices a bill line from one.

## The attribute schema

> **Superseded.** The per-type schema described below became a **table** — see
> [catalogue-master.md](catalogue-master.md). `ItemType` and `UnitOfMeasure` no
> longer exist; a category is a row an admin edits and its fields are rows under
> it. What follows is kept because the *reasoning* still holds and the four
> seeded categories are these four types, at these exact keys.

`ItemCategory::attributeSchema()` declares what each category is described by,
resolved from `item_attributes` and inherited down `parent_id`:

| Category | Required | Optional |
| --- | --- | --- |
| Motor | rating (HP), phase, speed (RPM) | frame size, mounting |
| Part | size | material |
| Bulk material | gauge | grade |
| Service | *nothing* | *nothing* |

*(Brand was an optional attribute of `part`, then a column on `items`, and is now
a **row of the Brand Master** the product points at — see
[catalogue-master.md](catalogue-master.md). Every trade asks whose a thing is, so
it never belonged inside one category's template; and once every trade asks it,
"Crompton" has to be one word the shop keeps rather than one somebody spells
afresh on each product.)*

Three things about it are deliberate.

**Validation lives in the service** — not in a form request. M11's importer and
M15's capture agent create variants without passing through one, and a motor
whose HP was never captured is not identifiable by *anybody* afterwards. That is
a permanent problem, not a validation message somebody missed.

**Optional attributes are never demanded.** Workshops differ in how much they
record, and refusing a bearing because nobody typed its material would push
people into not recording the bearing.

**A fixed value set only where one genuinely exists.** Phase is 1 or 3 and there
is no third possibility, so it is constrained. Frame size is open, and pinning it
to a list would make the product wrong about the next frame.

A category with no fields accepts **no** attributes at all — which is how the
seeded Service category is set up. An hour of rewinding is an hour of rewinding,
and an attribute bag on one would only ever be filled in wrong.

### Stored in schema order

`{rpm, hp, phase}` in and `{hp, phase, rpm}` out, always. Two reasons: the
derived label reads the way somebody reciting a specification does — "5 HP / 3 ph
/ 1440 RPM" rather than whichever order the form serialised — and two equivalent
variants compare equal as stored JSON. The same reasoning that orders a party's
roles by the enum.

### A blank attribute is absent, not empty

A form submits every field it renders. Storing an untouched optional box as `""`
is noise that then has to be filtered out everywhere it is read.

## How the price is quoted

`items.price_includes_tax` says whether the selling price is written with the GST
already in it — a tick under the GST rate on the item form, for a shop that
prices parts at the figure printed on the box.

It is a **default and nothing more**. It prefills the toggle beside a bill line's
rate box; the line carries its own copy and is what actually decides the
arithmetic, so flipping this restates no document already issued. There is
deliberately no category default: what a shop charges on a kind of thing is a
property of the tax code, and whether it folds the tax into the figure is a habit
it applies to some products and not others.

See [inclusive-pricing.md](inclusive-pricing.md).

## Stock capability

Two flags, and the asymmetry between them is the point:

```
ItemType::canHoldStock()   capability  — a service never can
items.is_stock             the choice  — within that capability
Item::tracksStock()        both        — what M8 acts on
```

A **service can never hold stock**, and the flag is overruled rather than merely
defaulted: an hour is produced at the moment it is sold, and an opening balance
of forty hours would be inventing an asset that does not exist. Asked for
explicitly, it still comes back false.

A **part bought to order** may legitimately be marked as not stocked. That is a
real arrangement, so the flag is honoured there. Read through `tracksStock()`
rather than off the column, so nothing has to remember the pairing.

## Units

```
counted   piece, set, coil          whole numbers
measured  kg, metre, litre          fractions are ordinary
time      hour                      fractions are ordinary
```

`UnitOfMeasure::isFractional()` is what decides whether "2.5" is a legitimate
quantity or a typo. 2.5 kg of copper is ordinary; 2.5 bearings is a mistake
somebody should be told about before it reaches the stock ledger. M8 and M9 both
need that, so it is stated once.

The unit **defaults from the type**, so the ordinary case needs no decision: a
motor is counted in pieces and copper is weighed.

## What cannot be changed

`type` and `base_uom` are absent from the update path entirely, for the same
reason an account's type is:

* reclassifying an item would silently reinterpret every quantity recorded
  against it and move it to a different section of every report;
* changing "each" to "kilogram" would turn 40 pieces into 40 kilograms in every
  report ever run.

If the type was wrong, the item was the wrong item. Archive it and add the right
one. The API accepts the fields and ignores them; the UI shows them disabled
rather than hidden, so the record still reads completely.

## Schema

### `items`

```
id, tenant_id, name, code, type, hsn_sac, gst_rate, base_uom,
is_stock, is_draft, description, is_active, timestamps

  unique (tenant_id, name)
  unique (tenant_id, code)
  index  (tenant_id, is_active, name)     the listing
  index  (tenant_id, type, is_stock)      the type filter, M8's sweep
  index  (tenant_id, is_draft)            the review queue
```

**`name` is unique per workshop.** Two rows called "Copper Wire" split one stock
balance in half and both halves look plausible — the same failure the unique
party name prevents.

**`code` is optional.** A workshop that has never used codes should not have to
invent one to record its first item, and a unique index on a nullable column
still lets any number of items have none.

**`hsn_sac` is one column.** HSN for goods, SAC for services: the same field in
the same position on a GST invoice, and an item is one or the other, never both.
`Item::taxCodeLabel()` says which word a form should use. Nullable, because a
workshop below the registration threshold has no use for it and forcing a guess
would put a wrong code on every bill.

**`gst_rate` is a DECIMAL percentage** — 18.00, not 0.18 and not a float. It gets
multiplied by an amount to compute tax, and that is the one place a rounding
error becomes a figure on a government return.

### `item_variants`

```
id, tenant_id, item_id, sku, barcode, label, attributes,
sell_price, purchase_price, markup_percent,
reorder_level, min_stock, is_draft, is_active, timestamps

  unique (tenant_id, sku)
  unique (tenant_id, barcode)
  index  (tenant_id, item_id, is_active)  the variant picker
  index  (tenant_id, is_draft)

  CHECK  prices and levels are non-negative
```

**`item_id` cascades on delete** — the only cascade in the schema. A variant is
not an independent record: "5 HP / 1440" is uninterpretable without knowing it is
a motor. So the protection sits on the item instead, which cannot be deleted once
anything references it.

**`label` is nullable and `display_label` is derived.** Both are sent over the
API, and the distinction matters: the first is what the workshop typed, which may
be nothing; the second is what to *show*. A fitter asking for "the small
Crompton" has to be able to find it under that name, so a stored label wins — but
an edit form must round-trip the stored one without overwriting it with the
derived one.

**No quantity and no cost column, in either table.** `qty_on_hand` and
`avg_cost` are sums over `stock_movements`, which is the entire point of that
table. A `sell_price` is not a cost and `markup_percent` is not a margin: cost is
M8's weighted average *at the moment of sale*, so a margin stored here would be
stale the next time stock arrived. `suggestedPriceFrom(Money $cost)` takes the
cost as an argument for exactly that reason, and M9 computes the real margin per
line.

**`sell_price` is nullable and never defaulted to zero.** A motor rewind is quoted
per job; a zero would say "free".

**`purchase_price` is not the exception to the rule above.** It is what the
workshop *expects* to pay, written down beside what it charges, and nothing in
the books reads it: it never reaches a valuation, and it never prefills a
purchase line — a rate suggested from here would restate the weighted average
every time somebody tabbed past it, which is the failure
[purchase-module.md](purchase-module.md) exists to prevent. What stock actually
cost is the weighted average of the movements, and only that. The one thing it
legitimately values is opening stock on a variant that has never been purchased
through this product, which is the single moment there is no average to offer.

That promise is prose everywhere it is written down, and prose does not fail a
build — so `ItemTest::a_buying_price_is_recorded_and_never_becomes_a_cost` holds
it against the shelf: a bearing noted at ₹410, bought at ₹700 and ₹800, is worth
₹750 apiece and issues at ₹750. It also asserts that `suggestedPriceFrom()` still
takes its cost as an **argument**, because the day that falls back to this column
is the day a price quoted from a stale note becomes a price quoted from the
books.

**A barcode is not case-folded and a SKU is.** A SKU is typed, so folding it
makes `bl-6205` and `BL-6205` the same code; a barcode is scanned, and folding it
would stop the stored value matching the label it was read from.

**`min_stock` is the floor and `reorder_level` is the trigger.** A shop orders at
20 and panics at 5. Both are read: `StockPosition::isLow()` is at or below the
trigger, `isBelowMinimum()` is **strictly** under the floor, and a row carries
both levels and both verdicts rather than one status a screen has to unpick.

The asymmetry between the two comparisons is deliberate. A trigger has to fire on
the day the shelf reaches it — running out is the case the reminder exists for.
A floor is a line the stock is still standing on when it is exactly there, and a
workshop told it had broken its own rule at precisely the number it wrote down
would stop reading the alarm.

They are not the same set, which is why the second one had to be read. A variant
with a floor and no trigger is never `is_low`, correctly — nobody said what low
means for it — so until `isBelowMinimum()` existed, a part somebody had written
"never below 5" against could sit at 2 with **no screen in the product**
mentioning it. `StockApiTest::the_floor_is_a_second_level_and_a_shortage_of_its_own`
walks a shelf down through both levels and holds that shut.

## Adding a product

One submission produces the product, **every variant declared on it**, and their
opening stock. `ItemService::createWithVariants()`, inside one database
transaction, because the parts are not independently useful: a product with no
variant cannot be sold, priced or counted, and a variant whose opening stock
failed to post would show zero on the shelf while the workshop has five.

A motor family is bought in three ratings and catalogued in one sitting. Making
that one save and two trips through a drawer is the two-screen shape this
endpoint exists to remove — and it is also how the *specification* gets skipped:
by the third rating nobody is re-typing the HP.

### Two shapes, one meaning

```jsonc
// The longhand. What the create form sends, one entry per block.
{ "name": "Crompton Induction Motor", "category_id": 3, "variants": [
    { "sku": "MOT-3",   "attributes": {"hp": "3", "phase": "3", "rpm": "1440"} },
    { "sku": "MOT-5",   "attributes": {"hp": "5", "phase": "3", "rpm": "1440"},
      "opening_stock": "4", "opening_cost": "13500.00" }
] }

// The shorthand. One variant, flat.
{ "name": "Ball Bearing 6205", "category_id": 4, "with_variant": true,
  "sku": "BRG-6205", "attributes": {"size": "6205"} }
```

Every key inside a `variants[]` entry is the flat key, `variant_label` included,
ungainly as that reads in an array. The two shapes are **one shape**: the flat
keys are the one-variant shorthand, and a divergence in a single key name is the
sort of thing that is discovered by an importer having saved a hundred rows with
no label on any of them. `variantPayload()` reads both without knowing which it
was handed.

The shorthand is kept because most callers are one. The importer walks a
spreadsheet a row at a time, and an API client adding a single bearing should not
have to wrap it in an array to say so. `variants[]` wins when it is present **and
non-empty** — an empty array is not "no variants", because a client that sent one
by accident alongside `with_variant` asked for a variant.

### A refusal names the block it is about

A duplicate SKU, a missing required attribute and an unvalued opening quantity
are each raised where their rule lives, and none of those layers knows it was
called for the third block of a repeater. `ApiException::underField()` is where
that is added: `sku` becomes `variants.2.sku`, written into `details.fields`
because [only the map reaches a form input](#refusals). A refusal that named no
field at all is pinned to `variants.2` itself, which the form gives a footer.

Only for a caller that sent `variants[]`. A flat submission gets `sku` back,
because `sku` is what it sent — which is also what keeps the shorthand's refusals
exactly as they were.

`showFormErrors()` in `ui.js` tries a key whole and then shortens it a segment at
a time, so `variants.2.sku` lands on that box, `variants.2` on the block's
footer, and `permission_ids.0` still collapses onto `permission_ids` as it always
did.

## Correcting a product

`PATCH /items/{id}` for the family and `PATCH /items/{id}/variants/{vid}` for one
thing under it. The form sends **both, one after the other, under a single busy
state** — it looks like one save and it is two requests, because a combined
endpoint would be a second write path into the catalogue: a second place a SKU is
checked for uniqueness, a second place an attribute bag is validated (§4.4).

The product call goes **first**, because the second may be a `POST`: a product
that had no variants gets its first one from this form, and a create that ran
ahead of a refusal above it would be created twice on the retry. Nothing after
the product call can be retried into a duplicate.

Which leaves one state the screen has to say out loud — the product saved and the
variant did not, which is what a SKU somebody else already used looks like. The
dialog stays open, the refusal paints on the box it is about, and a toast says
the first half went through; otherwise Cancel reads as though it cancelled both.

### What an edit withholds, and it is one thing

**Opening stock.** It is a stock adjustment that posted on the day the shelf was
counted, and there is no second one to be had by retyping the figure — correcting
it is a count, from the screen that counts. The date and both per-block boxes
carry `data-opening-field` so that rule is applied in one place.

Everything else about a variant is an ordinary edit and reaches the form:
the specification, the SKU, the barcode, both prices, the target markup, the
reorder level, the floor, and the variant's own name. Until this phase none of it
did — the form hid the whole variant half on an edit, and half those fields could
be set on a create and never corrected afterwards.

### One editor, two panes

The variant half is one set of fields with two panes over it, and which is up
depends on how many things are on the shelf:

| | Pane |
| --- | --- |
| Create | The repeater — a block per variant, submitted together as `variants[]` |
| Edit, one variant | That variant's block, straight away |
| Edit, none or several | The picker, and a pencil opens the block for the one chosen |

The one-variant case is the overwhelming majority, and it is the reason the pane
exists: for those products the family and the thing on the shelf are one record
in the user's head, and splitting them over a dialog, a drawer, a tab and a
second dialog was five clicks to correct a SKU.

**The block is the only variant editor in the module.** The drawer's pencil opens
this form on that variant rather than a dialog of its own. There was a second
editor — `#variant-modal` — and the pair had already drifted exactly as §5.1
says they do: the dialog could set a target markup the create form could not, and
the create form could set a barcode, a purchase price and a minimum stock the
dialog dropped on the floor. It is deleted rather than extended.

Two things follow from the panes being panes rather than sections. The blocks are
**emptied and rebuilt** on the way into either one, because whatever is in
`#item-variants` is what gets submitted and a hidden block still holding the
previous variant's SKU is the kind of thing that is eventually saved onto this
one. And a **disabled control is one the form is not asking about**:
`variantValue()` returns `undefined` for it, `JSON.stringify` drops the key, and
`StoreVariantRequest::payload()` leaves that column exactly as it was. Sending
null instead would wipe the reorder level and the floor of every product somebody
ever unticked "keep stock of this" on.

## Correcting what is on the shelf

Opening stock is recorded once, on the create form, and after that a variant's
position is corrected the same way every other position in the workshop is: by
posting a stock adjustment. The drawer's variant rows carry the control, and what
it opens is **the Stock screen's own count dialog** —
`partials/stock-adjust.blade.php` and `components/stock-adjust.js`, mounted here
in its second mode.

There is deliberately no "edit quantity" on this screen or on any other. A field
that wrote a position directly would be a second write path into the stock
ledger, and every guarantee M8 makes rests on there not being one (§4.3).

### The two modes are the same act, entered from opposite ends

| Host | The operator types | The component sends |
| --- | --- | --- |
| Stock, `count` | the difference the count found, signed | that, unchanged |
| Items, `variant` | what is actually on the shelf | it, minus what the books say |

Which way round the number goes is not presentation. "Two fewer than the books
say" and "two on the shelf" are different figures that post different documents,
and a screen that let them be confused would post the wrong one — so each mode
labels its own box and neither offers the other's. Items types the count because
that is what somebody standing at a shelf has; Stock types the difference because
a stock-take sheet is a list of variances.

The subtraction between them is done in **integer thousandths**, not by
subtracting two parsed floats: the column is `DECIMAL(15, 3)`, `12.3 - 4.1` is
`8.199999999999999` in IEEE 754, and `decimal:0,3` would refuse it. It is the one
piece of arithmetic this component adds, and it is the reason the two modes are
one component rather than two (§4.4).

**The difference is shown before it is posted.** It is the number that actually
reaches the ledger and the one thing this mode never asks anybody to work out, so
it is spelled out under the box as the count is typed, along with which way it
goes and how the value is decided.

### Found stock says what it will be worth

A shortage is written off at what the books were carrying it at, which is not the
counter's number to choose — so the cost box only appears once the difference is
positive.

When there is nothing on the shelf to average against, the dialog says so. The
service falls back to whatever the variant last cost, and a variant that has never
been bought has no such rate — which makes the whole document worth nothing and
gets it refused as `STOCK_ADJUSTMENT_VALUELESS`. That refusal is the right one;
finding out about it after pressing Post is not.

### What the row control needs, and why it is two grants

Removed rather than blanked, the treatment `data-stock-only` already gives the
rest of this screen, and gated on **`WRITE:TRANSACTIONS`** because posting an
adjustment is writing a transaction, plus **`READ:STOCK`** because this mode
subtracts the position and without the position there is nothing to subtract
from. A product whose category holds no stock has no control at all.

## Opening stock

The universal create form records what is already on the shelf, in the same
submission as the product and the variants under it. It is an **input**, never a
column: what it produces is an ordinary stock adjustment, posted through
`TransactionService` and the stock ledger exactly as the Stock screen's own
adjustment is, so there is no second way for stock to come into existence
(CLAUDE.md §4.3).

**An adjustment and not an `opening` transaction**, and the difference is not
cosmetic. An opening balance is the go-live declaration posted against Opening
Balance Equity; routing a product added in November through it would restate what
the workshop was worth in April. An adjustment is the workshop saying what is on
the shelf *today*, which is exactly the claim being made.

**One document per create, however many variants declared a quantity.**
Cataloguing a motor family with three ratings is one act and reads on the day
book as one, where three vouchers stamped the same minute read as three separate
stock-takes. Each line names its own variant, so the document's note names the
product.

**The count is dated when it was taken.** `opening_date` defaults to today; a
workshop entering its catalogue in the evenings of a week it counted on the
Sunday says so.

### Stock cannot arrive worth nothing

`StockLedgerService::adjustment()` values a stated increase at the last rate the
workshop actually paid when nobody says otherwise. That is the right default
everywhere except here: a variant created *by this request* has no movements at
all, so the fallback is zero every single time.

So an opening quantity above zero with no cost resolvable — neither
`opening_cost` nor `purchase_price` — is refused with
`OPENING_STOCK_NEEDS_A_COST`, and a stated zero is refused on the same ground. A
free sample carried at nothing is a real thing, but it is a stock-take somebody
goes to the Stock screen to record having decided it; reached through a catalogue
form, a zero in a cost box is a box somebody tabbed past. The consequence either
way is a shelf the Inventory account never learns about and a first sale
reporting the whole price as profit.

The rule lives in `ItemService::openingCostFor()` rather than in a form request,
because M11's importer and M15's capture agent create variants without passing
through one. The form asks the same question before the round trip; that copy is
a courtesy and the service is the rule (§6.1).

What it replaced was not a quiet zero. `StockAdjustmentTemplate` throws out a
voucher whose every line is worthless, so the whole create rolled back and the
message was `STOCK_ADJUSTMENT_VALUELESS`, about a field called `adjustments`
that the form does not have.

### Two things it skips rather than refuses

* **A category that holds no stock.** An hour of labour with an opening quantity
  typed against it is saved and warned about — refusing the product over a field
  that could never have applied to it would be worse.
* **A caller without `WRITE:TRANSACTIONS`.** Cataloguing is an ITEMS grant and
  recording a quantity is a TRANSACTIONS one. A clerk who may add a bearing but
  not write to the ledger gets the bearing, with a warning naming how many
  quantities were not recorded.

Both answer `201` with an `OPENING_STOCK_SKIPPED` warning in `meta`.

## Draft items

`is_draft` is a flag, not a separate table. A draft item is a **real item that
somebody still has to look at**, and it must be usable: M11 imports opening stock
against items it has just invented, and hiding those from the ledger would make
the import unbalanced.

So the flag drives a *worklist*, never a filter on the books. It is cleared with
`PATCH {"is_draft": false}` — reviewing one only confirms it — and set again
freely, because noticing later that a record needs checking is exactly what the
flag is for. A variant of a draft item inherits the flag, so confirming the family
surfaces its variants too.

The count comes back on `GET /items/meta` alongside the schema, because every
screen showing the catalogue wants the badge and a second round trip for one
integer is waste.

### The queue counts both, and both can be cleared

`draft_counts` has always carried `items` **and** `variants`, and the banner read
only the first — so a workshop whose import left forty unchecked ratings under
products somebody had confirmed was told there was nothing to review. Both are
counted now, and named separately in the banner, because they are cleared in
different places.

Nothing could clear either of them from the UI at all until this phase. The
endpoints existed, the flag was documented as "cleared with
`PATCH {"is_draft": false}`", and no screen sent it: the badge went on, the queue
grew, and the only route back out was a database client. A worklist that cannot
reach zero is not a worklist — it is a permanent warning, and a permanent warning
is one people stop seeing.

| | Cleared from | Confirmation |
| --- | --- | --- |
| **A family** | the row menu, or the drawer's own alert while it is open | none |
| **A rating** | the drawer's Variants tab, one row at a time | none |

Neither confirms, and that is the judgement rather than an omission: §3.5 asks
for a dialog where something is taken away, and signing off takes nothing away.
Nothing can put the flag *back* on from these screens either. Returning a record
to the queue is not something anybody wants; correcting it is, and that is the
pencil beside the control.

**One rating at a time, and never a family's worth at once.** A "confirm all"
over an import is one click that claims somebody read every row of it, which is
the single claim this flag exists to stop being made by accident.

The two flags are **independent in both directions**. Signing off a family leaves
its ratings in the queue — a variant inherits `is_draft` from the item it was
invented under, and an importer that guessed the product guessed every rating
below it too. Signing off a rating does not confirm the family above it.
`ItemApiTest::a_variant_is_archived_restored_and_signed_off_one_flag_at_a_time`
is what holds that shut.

The row badge and the queue filter follow the same rule: a family is in the queue
when **it** is waiting *or* anything under it is. Without that, confirming the
product removed its unchecked ratings from the only list that would ever have
shown them.

## Duplicates

A second variant at the same specification is **reported, never refused** — the
same treatment as a shared GSTIN in M5, and for a comparable reason. Two 5 HP /
1440 rows are usually one motor entered twice, which splits one stock balance in
half; but a workshop stocking two brands at identical ratings legitimately has
two.

```json
"meta": { "warnings": [{
  "code": "ITEM_VARIANT_DUPLICATE",
  "message": "This specification is already on Crompton 5 HP. …",
  "variant_ids": [7]
}]}
```

The match is on the attributes *named*, not on the whole document, so a second row
is a duplicate whether or not somebody typed its optional frame size.

**Within one create, the same warning is raised between the blocks themselves.**
On that path there is nothing else to collide with — the product did not exist a
moment ago — so `ItemService::duplicateSpecifications()` groups the variants it
just wrote by their **stored** attributes, which `normaliseAttributes()` has
already put in schema order. Two blocks described in different sequences still
compare equal.

```json
"meta": { "warnings": [{
  "code": "ITEM_VARIANT_DUPLICATE",
  "message": "Variants 1 and 3 are described the same way — 5 HP / 3 / 1440. …",
  "positions": [1, 3],
  "variant_ids": [7, 9]
}]}
```

They are named by **position**, because their specifications are identical by
definition and there is nothing else telling them apart. `positions` counts from
one, as the form's own headings do.

A variant with no specification at all is skipped rather than matched against
every other one like it. Labour has no attribute bag — an hour of rewinding is an
hour of rewinding — so a rule that paired empty against empty would warn about
every second block on that category, every time. What tells two of those apart is
the SKU, and a repeated SKU is refused outright.

Warnings **accumulate rather than replace**: a clerk who added three ratings,
typed one of them twice and holds no `WRITE:TRANSACTIONS` grant has two separate
things to be told, and the form toasts each.

## Archiving and deletion

Same rule as an account and a party, for the same reason.

| | When | Effect |
| --- | --- | --- |
| **Delete** | Only while nothing points at it | Row removed |
| **Archive** | Always | Hidden from pickers; history intact |

An item with variants is refused (`ITEM_IN_USE`, 409) **even though the foreign
key would cascade**: a variant deleted as a side effect of tidying up a family
name is work somebody loses without being asked. The refusal names archiving
instead.

M8's stock movements and M9's bill lines are what make this a real protection, and
both will back it with `restrictOnDelete` for anything that does not come through
the service.

### A variant is archived from the row, not from the editor

`PATCH /items/{id}/variants/{vid}` with `is_active: false`, from the Variants tab
of the drawer. A status change rather than an edit — everything already recorded
against the variant stays exactly as it is, and only what a picker offers changes
— so it belongs beside the row like the family's own archive does (§7.4), not
inside the form that corrects the fields. Archiving from within a form holding
unsaved edits would have to answer what happens to them.

Restoring is the same call with `is_active: true` and asks nothing first: it puts
something back.

### Archived ratings are hidden by default, and counted out loud

A workshop that has dealt in a part for ten years has archived more ratings than
it still stocks, and a panel where the live ones are three rows in twenty is a
panel nobody reads. So `variantRows()` shows the active ones and puts the rest
behind **"Show 4 archived"**.

What it never does is hide them silently. The footer states the number whether
they are shown or not, so the panel cannot claim a family has less under it than
it does — and where every rating has been archived it says so, rather than
rendering blank above a button, which would read as a product with nothing on the
shelf instead of one whose shelf was cleared.

The preference is **module-level, not per surface**. The drawer's Variants tab
and the item form's picker are the same list through the same renderer and are
never on screen together; two flags would be one preference somebody had to set
twice, and they would disagree.

## Refusals

| Refusal | Error code | Status |
| --- | --- | --- |
| A required attribute is missing | `ITEM_ATTRIBUTES_MISSING` | 422 |
| An attribute the type does not recognise | `ITEM_ATTRIBUTES_UNKNOWN` | 422 |
| A fixed-set attribute outside its set | `ITEM_ATTRIBUTE_VALUE_INVALID` | 422 |
| Duplicate item name | `ITEM_NAME_TAKEN` | 409 |
| Duplicate item code | `ITEM_CODE_TAKEN` | 409 |
| Duplicate variant SKU | `ITEM_SKU_TAKEN` | 409 |
| Deleting an item with variants | `ITEM_IN_USE` | 409 |
| Opening stock with nothing to value it at | `OPENING_STOCK_NEEDS_A_COST` | 422 |

Every message names the fix rather than only the refusal — "a motor needs its
rating", "add the variant to the existing item instead".

**A refusal reaches a form input only through `details.fields`.** `auth-client.js`
maps `error.fields` from `details.fields`, so the singular `details.field` that
several of these carry has never got past the banner. On a create carrying
`variants[]` the field is re-keyed per block — see
[A refusal names the block it is about](#a-refusal-names-the-block-it-is-about).

## Endpoints

`/api/v1`, behind `auth.jwt` and tenant-scoped by the global scope.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/items` | `READ:ITEMS` |
| GET | `/items/meta` | `READ:ITEMS` |
| GET | `/items/{id}` | `READ:ITEMS` |
| POST | `/items` | `WRITE:ITEMS` |
| PATCH | `/items/{id}` | `UPDATE:ITEMS` — also archive and confirm |
| DELETE | `/items/{id}` | `DELETE:ITEMS` — unreferenced items only |
| GET | `/items/{id}/variants` | `READ:ITEMS` |
| POST | `/items/{id}/variants` | `WRITE:ITEMS` |
| PATCH | `/items/{id}/variants/{variant}` | `UPDATE:ITEMS` |
| DELETE | `/items/{id}/variants/{variant}` | `DELETE:ITEMS` |

**Variants are nested, not top-level.** One has no meaning apart from its family,
and the family is what decides which attributes it must carry — so the URL says
what it belongs to even though the id alone would resolve it.

And the nesting is **enforced**, not decorative: a variant is resolved *through*
its item, so `PATCH /items/7/variants/12` where variant 12 belongs to item 3 is a
404. Both are inside the same workshop, so the tenant scope does not catch that on
its own, and without the check the caller would be told their edit applied to the
item they were looking at when it landed somewhere else. A 404 rather than a 403,
because from that URL there is no variant 12.

`GET /items/meta` publishes the categories **with their attribute schemas**, the
brands, the units and the draft counts. An attribute schema copied into JavaScript
is a copy that drifts, and the drift shows up as a motor saved without its HP.

Variants on the list are opt-in via `with_variants=1` and cost one extra query for
the whole page — the same bargain as `with_position` on parties. `variant_count`
is always there, and is `null` rather than `0` when nobody counted: an honest
payload distinguishes "none" from "not fetched". It is a `withCount` taken with
the page rather than a stored figure, which is what makes the listing's Variants
column right the moment one is added or removed — a stored count agrees with its
rows right up until one is written without the other.

Each row also carries `category_label` and `brand`, resolved through the relations
rather than duplicated onto `items`. That is what the listing's Category and Brand
read, and there is deliberately no `type_label` alias beside them: a key that
survives a rename while quietly changing what it holds is worse than one that
breaks loudly — and when the alias *was* left in a reader, the Category column
went blank.

### The permission

| | `ITEMS` | `PARTIES` | `ACCOUNTS` |
| --- | --- | --- | --- |
| `OWNER` | R W U D | R W U D | R W U |
| `DATA_ENTRY` | R **W** | R **W** | R |

`DATA_ENTRY` holds `WRITE:ITEMS` for the same reason it holds `WRITE:PARTIES`: a
part nobody has recorded yet turns up as often as a new customer, and a clerk who
had to fetch the owner to add a bearing would bill it as something else. Editing
and deleting an existing item stays with the owner.

Note what holding this does **not** grant: the stock position. M8's quantities and
costs are a separate read.

## Screens

`/items`, gated on `READ:ITEMS` plus workshop membership. The nav entry is
labelled **Items**, not "Inventory" — there are no quantities behind it until M8,
and an entry promising "Inventory" that shows no stock is worse than one that
promises less.

**The variant form is built from the server's schema.** Which fields exist depends
on the item's type, so `renderAttributeFields()` reads `GET /items/meta`: a select
where the values are genuinely fixed, a text box where the range is open, and the
required ones unmarked while the optional ones say so.

**The create form repeats that block per variant.** One `<template>` in the
markup, cloned by `addVariantBlock()`, with "Add another variant" beneath and a
Remove control that appears from the second block — a product with nothing on the
shelf under it cannot be sold, priced or counted.

Nothing in the template carries an index. `indexVariantBlock()` stamps the
position onto every `name`, `id`, `for` and `data-error-for` when a block is added
or removed, so the numbering is decided in one place: a `name="variants.0.sku"`
written into the markup would be a second, and the drift shows up as a refusal
painted into the wrong block. The attribute inputs are repainted with it, because
their ids carry the index too — and their **typed values survive the repaint**,
which matters because "Configure fields" is opened *from* this form, halfway
through filling it in, and with three blocks up a wipe costs three
specifications.

What stays outside the repeater is what belongs to the family: the name, the
category, the brand, the HSN code, the rate, the unit, "Keep stock of this" — and
`opening_date`, because every quantity posts on one stock adjustment and a
document has one date.

**On an edit that same block is the variant editor**, and beside it is a picker
for choosing which variant it stands for — see
[One editor, two panes](#one-editor-two-panes). Both panes live in
`#item-variants-section`, one on screen at a time: §2A.2's judgement applied a
level down, the same shape Jobs uses for its billing pane. The picker's rows and
the drawer's Variants tab come from **one renderer**, `variantRows()`, because
they show the same four facts — what it is, its code, its price, and what is on
the shelf — and two copies would drift on the first column either of them gained.

A bound block also prints **what is on the shelf under that variant right now**,
read-only and from M8. Only when the block stands for something that exists: a
quantity printed beside the boxes that create one reads as a figure somebody may
type over.

**The type is reflected into the form as it is chosen** — the tax code relabels
itself HSN or SAC, the unit switches to the type's default, and the stock checkbox
disables itself for a service with a sentence saying why. Telling somebody as they
choose is much better than refusing the save afterwards.

**The review queue is a banner, not a filter.** Nobody goes looking for a queue
they were not told about. It appears only when there is something in it — a
permanent banner reading "0" is a banner people stop seeing — and clicking it
filters the list.

Variants open as a panel over the list rather than a page of their own: they are
read and edited while thinking about the family, and losing the list to see them is
what makes people stop looking. The drawer's pencil hands off to the item form —
level 3 over level 2, which is where one record's fields belong — rather than
opening a dialog over a dialog (§2.2). The clipboard beside it opens the shared
count dialog at the same level, carrying the icon Stock's "Record a count" carries
because it is the same act and should not look like a second one (§7.4).

A row that is still waiting to be checked gets one more control, ahead of the
others: while a rating is unchecked it is the only thing on that row worth doing.
It disappears the moment it is used, which is the feedback — there is no state to
toggle back into.

## Tests

```bash
php artisan test --filter='Item|Stock|PagesRender'
```

| File | Proves |
| --- | --- |
| `ItemTest` | The record: the four types coexisting, attribute validation per type, labels, stock capability, immutable type and unit, naming, duplicates, drafts, deletion, prices, tenancy — and that the **buying price reaches no valuation**, asserted against the shelf rather than against the column |
| `ItemApiTest` | The HTTP surface, the published schema, permissions, tenant isolation — and that **no endpoint reports a quantity or a cost** |
| `StockApiTest` | That the drawer's count posts the document the Stock screen posts. Adding the second host changed no server code, so this is the only thing that records the contract is shared |
| `PagesRenderTest` | The shell, the review queue, that the queue has a control to clear a draft and that the control is not gated declaratively, that the attribute schema is not hardcoded in the markup, that the variant block is declared once and carries no index of its own, that it asks for **every** field a variant has, that there is no second variant form, and that both Items and Stock include one count dialog |

`ItemFactory::ofType()` sets the unit and the stock flag from the type together, so
a factory cannot produce the one combination the service refuses — a service item
that holds stock.

## Notes for the next module

* **M8** counts stock per **variant**, never per item, and `Item::scopeStocked()`
  / `ItemVariant::scopeStocked()` are the sweeps it needs — both already exclude
  services and anything the workshop marked as not stocked.
* `qty_on_hand` and `avg_cost` belong in `stock_movements`'s derivation and
  nowhere else. There is deliberately no column reserved for them here: an empty
  one is an invitation.
* `UnitOfMeasure::isFractional()` and `quantityScale()` are what a stock movement
  validates a quantity against. A fractional bearing must be caught before it
  reaches the ledger.
* `ItemVariant::suggestedPriceFrom(Money $cost)` is the hook for M8's weighted
  average: pass the cost in and the target markup produces a suggested price. It
  is a suggestion for a form, never a figure on a bill.
* **M9** reads `items.gst_rate` and `items.hsn_sac` from the *family*, and the
  cost and margin per line from M8. `reorder_level` feeds M8's low-stock view.
* **M11** and **M15** create items and variants with `is_draft: true`. Both go
  through `ItemService` and `ItemVariantService`, so the attribute rules apply to
  them unchanged — which is the reason those rules are not in a form request.
* Fuzzy resolution of "the five horse Crompton" to a variant belongs in a resolver
  of its own, not here. `ItemVariantService::othersMatching()` is exact.
