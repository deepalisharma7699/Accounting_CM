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
id, tenant_id, item_id, sku, label, attributes, sell_price,
markup_percent, reorder_level, is_draft, is_active, timestamps

  unique (tenant_id, sku)
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

Every message names the fix rather than only the refusal — "a motor needs its
rating", "add the variant to the existing item instead".

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
what makes people stop looking.

## Tests

```bash
php artisan test --filter='Item|PagesRender'
```

| File | Proves |
| --- | --- |
| `ItemTest` | The record: the four types coexisting, attribute validation per type, labels, stock capability, immutable type and unit, naming, duplicates, drafts, deletion, prices, tenancy |
| `ItemApiTest` | The HTTP surface, the published schema, permissions, tenant isolation — and that **no endpoint reports a quantity or a cost** |
| `PagesRenderTest` | The shell, the review queue, and that the attribute schema is not hardcoded in the markup |

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
