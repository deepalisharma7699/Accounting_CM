# Category Master, Attributes, Brands & Units

What each kind of product records, and how it is counted.

This is the module that made the inventory **generic**. Before it, the kinds of
thing the catalogue could hold were four cases in `ItemType` and the units were
seven cases in `UnitOfMeasure` — both in PHP, both requiring a developer and a
deployment to change. A shop that started selling water pumps, LED lamps or
shirts could not describe them.

> **The acceptance criterion.** An admin creates a category, defines its fields,
> and can immediately use it on the create form — with no new database column, no
> new API, no new form, no new component and no new code.

---

## The shape

```
ItemCategory          the template     "Water Pump"      holds_stock, tax, default unit
  └─ ItemAttribute    the questions    HP, Head, LPM     type, unit, required, options
        ↓
   ONE universal form  ←──  ItemBrand   whose it is      "Crompton", "SKF"
        ↓
Item                  the product      "Crompton 5HP"    name, brand_id, HSN, GST, unit
  └─ ItemVariant      the thing        "5 HP / 1440"     the answers, SKU, price
        ↓
StockMovement         the shelf        +24, −2, −1       unchanged since M8
```

Two things are worth stating plainly because they are what kept this change
cheap:

**The values never moved.** A variant's specification has lived in the
`item_variants.attributes` JSON bag since M7. This module changed where the
*rules* come from — a table instead of an enum — and not one stored value was
rewritten. The four seeded categories carry the enum's own attribute keys, so
`hp` is still `hp` and every variant that existed before validates unchanged.

**The stock ledger never moved either.** `stock_movements` is untouched. Quantity
on hand and weighted average cost are still sums over it, still written only by
the posting engine, still balanced against the Inventory account in the same
database transaction.

---

## The tables

### `item_categories`

| Column | |
| --- | --- |
| `parent_id` | Self-referencing. A subcategory asks for its parent's fields **and** its own |
| `holds_stock` | **Capability.** False for labour — an hour is produced when it is sold |
| `uses_sac_code` | Whether its tax code is an HSN (goods) or a SAC (services) |
| `default_unit_code`, `default_hsn_sac`, `default_gst_rate` | **Copied** onto a new product, never referenced by an existing one |
| `is_system` | The four that were `ItemType`. Renameable, archivable, never deletable |

The defaults are copied and not referenced, and that is the whole point of them:
correcting a category's usual GST rate next March must not restate what every
invoice already issued under it charged.

**A rate the form did not send is the category's, not zero.** `POST /items`
resolves a missing `gst_rate` from `default_gst_rate`, and a client that sends
`'0'` for an empty box defeats that — `'0'` is a value, not an absence, so the
fallback never fires and the product saves at 0% GST whatever its category
charges. Nothing on the screen says so; the first sign is a purchase line taxed
at nothing, months later, on every bill the product has ever appeared on. Both
create forms send `null` for an untouched box, show which of the two the filled
rate is, and refuse to save a blank one where the category has no default to
answer with. An explicit `0` still means 0 — exempt goods are real, and there
would otherwise be no way to say so.

### A family with nothing under it is still findable

A stocked family with no variant has no stock row, so a picker searching stock
cannot see it — and it *is* stocked, so the `is_stock=0` query that finds
services excludes it too. Between them it was invisible: typing "motor 3"
returned the same bare "Nothing matched" as a name nobody had ever entered, which
is the one answer that sends somebody off to create a duplicate.

`GET /items?has_variants=0` is the query that reaches it, counting only *active*
variants — nothing can be billed against an archived one, so for this question a
family whose only variant was retired has none. The picker asks for a handful of
them alongside its other two sources and offers them last, badged ADD A VARIANT,
opening the form that adds the missing specification rather than putting an
unpostable row on the bill.

### `item_attributes`

| Column | |
| --- | --- |
| `key` | **Write-once.** The JSON key the values are stored under |
| `label`, `help_text` | What the form prints. Freely editable |
| `data_type` | `text` / `number` / `decimal` / `dropdown` / `boolean` / `date` |
| `unit_code` | Printed after the input — 5 **HP**, 1440 **RPM** |
| `is_required` | Demanded only where the product is unidentifiable otherwise |
| `options` | The fixed set, for a dropdown, in the order it should render |
| `min_value`, `max_value` | Bounds, for the numeric types |
| `display_order` | The order the form draws them |
| `is_active` | Switched off rather than deleted once products have answered it |

### `item_brands`

| Column | |
| --- | --- |
| `name` | Unique per workshop. What the dropdown offers and a bill prints |
| `code` | The shop's own short handle, upper-cased. Optional |
| `description` | Free text, for the master screen |
| `is_active` | Switched off rather than deleted once products carry it |
| `display_order` | The order the dropdown draws them |

The smallest master in the catalogue, and deliberately: a brand is an *identity*,
not a template. It carries **no default unit, no default HSN and no default GST
rate** — a category has an opinion about how a thing is taxed and counted, and a
brand has none. One that carried defaults would be a second place a rate came
from, and the two would disagree the first time a shop stocked a Crompton pump
and a Crompton motor.

`items.brand_id` points at it, and the *name is never copied beside the key*.
That is the whole reason the table exists: `items.brand` was a string somebody
typed, and a typed string is a master list nobody maintains — "Crompton",
"crompton" and "Crompton Greaves" were three brands to the column and one to the
shop, while the listing filter believed the column. The migration that added
`brand_id` turned every distinct typed value into a row and dropped the string,
so renaming a brand now renames it everywhere at once.

### `units`

| Column | |
| --- | --- |
| `code` | **Write-once.** What `items.base_uom` and every posted line store |
| `label`, `symbol` | "Kilogram", "kg" |
| `kind` | count / weight / length / volume / time / electrical / other |
| `decimals` | 0–3. **The whole of the fractional rule** |
| `is_system` | The seven that were `UnitOfMeasure` |

`decimals` replaced the enum's `isFractional()` *and* `quantityScale()`, which
were two facts that were really one: 2.5 kg is ordinary and 2.5 bearings is a
mistake *because* kilograms record three places and pieces record none. One
column cannot disagree with itself.

---

## Why `data_type` stayed an enum

Categories and units became tables because they are the **business's** vocabulary
and the business kept needing words nobody had thought of. The data types are the
**system's** capability — the set of inputs the form knows how to draw and the
validator knows how to apply. A row saying `colour_picker` would be a promise the
application could not keep: the admin would get a text box and no explanation.

There is deliberately no `measurement` type. A measurement is a number with a
unit, and `unit_code` is what makes it one; a seventh type differing from
`number` only by having a unit would be two ways to say one thing.

---

## The refusals, and why each exists

All of them follow from one fact: **the values live in
`item_variants.attributes` and nothing rewrites them.**

| Attempt | Answer |
| --- | --- |
| Delete a category with products under it | Refused — archive instead |
| Delete a category with subcategories | Refused — they inherit its fields |
| Delete one of the four seeded categories | Refused — archive instead |
| Make a category hold no stock while stocked products sit under it | Refused |
| A cycle in `parent_id` | Refused (MySQL cannot CHECK against an auto-increment column) |
| Rename an attribute's `key` | Ignored — it would orphan every stored value |
| Delete an attribute products have answered | Refused — switch it off instead |
| Make an attribute compulsory while products lack it | Refused — they would all fail their next edit |
| Narrow a dropdown below values products hold | Refused |
| Rename a unit's `code` | Ignored — it would reinterpret every quantity ever recorded |
| Delete a brand products carry | Refused — archive instead |
| Delete a unit anything is counted in | Refused — switch it off instead |
| Narrow a unit's scale below what is recorded | Refused — 12.5 kg would silently round |

Each is something an admin can reasonably want to do and something that quietly
breaks records if simply allowed. Switching off is offered everywhere deleting is
refused: it takes the definition off the form and leaves it explaining the data.

---

## The universal form

`POST /api/v1/items` takes the product **and** its first variant in one
submission — because "add a Crompton 5 HP motor" is one act, and making somebody
do it in two screens is how a catalogue fills up with families that have no
variants under them and therefore cannot be sold, priced or counted.

`with_variant` says so outright rather than being inferred. An API client adding
a family it will hang four ratings off later omits it and gets the family alone;
inventing a blank variant for it would be a record that cannot be saved (for a
category that demands HP and phase) or a row nobody asked for.

**Opening stock** posts an ordinary **stock adjustment** through the same posting
engine the stock screen uses — `Dr Inventory / Cr COGS`, valued at the stated
cost. Not an `opening` transaction: that is the go-live declaration against
Opening Balance Equity, and routing a product added in November through it would
restate what the shop was worth in April.

It needs `WRITE:TRANSACTIONS`, which cataloguing does not imply. A clerk who may
add a bearing but not write to the ledger still gets the bearing, and is told
plainly that the quantity was not recorded.

---

## What the front end knows about categories

Nothing. `GET /api/v1/items/meta` publishes the categories, their resolved field
schemas, **the brands** and the units; the form draws whatever it is told. That is
why adding a category with six fields needs no front-end work — and it is not a
new arrangement, it is the one M7 already had, pointed at a table instead of an
enum.

The brand dropdown follows the same rule, which is why it is a dropdown at all:
its options are rows, published on the same payload, painted by
`paintBrandSelect()` in [items.js](../resources/js/pages/items.js) and never
written into the Blade template. Meta sends **active brands only** — an archived
brand is still the answer on every product that carries it and must not be
offered as the answer to a new one — so a product being edited under an archived
brand has that one option put back, labelled, for the length of the edit. Dropping
it silently would turn "save this description" into "and also clear the brand".

---

## Permissions

| | Grant |
| --- | --- |
| Read categories, attributes, brands, units | `READ:ITEMS` |
| Create / edit them | `UPDATE:ITEMS` |
| Delete them | `DELETE:ITEMS` |

Reading is `READ:ITEMS` because *every* screen that lists or creates a product
needs the vocabulary to render at all. Writing is `UPDATE:ITEMS`, which
`DATA_ENTRY` deliberately does not hold: a clerk should be able to add a bearing
without fetching the owner, and should not be able to restructure what every
product in the shop is asked to record.

---

## Provisioning

Every workshop is given the seeded units and categories by
`CatalogueProvisioner`, called from `TenantService` in the same breath as the
chart of accounts — a workshop with no categories cannot record a product at all.
The list lives in `CatalogueDefaults`, shared with the migration that backfilled
the workshops that already existed, so a shop set up last year and one set up
this morning have the same vocabulary.

**Brands are not seeded and never will be.** Which makes a shop deals in is the
one part of its vocabulary nobody else can guess, and a catalogue that arrived
holding "Crompton" would be a motor workshop's assumption printed on a garment
shop's products. A new workshop starts with an empty Brand Master and an "Add
brand" control beside the field.

`CatalogueDefaults::templates()` holds ready-made definitions — Bearing,
Capacitor, Wire, Water pump, LED light, Apparel — which are **offered, never
seeded**. A garment shop should not find "Capacitor" in its catalogue because the
product was written for a motor workshop. Applying one creates an ordinary
category the admin can rename, extend or delete a minute later.

---

## Not in this module

**Unit conversion.** There is no purchase-unit-to-stock-unit factor, and that is
a decision rather than an omission: every quantity in the ledger is expressed in
the item's one unit, and a factor sitting between a purchase document and the
stock ledger corrupts stock and the Inventory account together, silently, if it
is ever wrong. The master comes first; conversion is its own piece of work with
its own testing.

**Batch and expiry.** Nothing tracks either. It matters for perishables and not
for motors, and adding it touches `stock_movements` — the one table this change
deliberately left alone.
