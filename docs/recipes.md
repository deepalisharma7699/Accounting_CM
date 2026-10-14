# Recipes — what a made thing consumes

A rewinding shop sells *winding*. It is one line on the invoice, at one price
per rating — "5 HP rewind, ₹4,500" — and producing it consumes copper, varnish,
sleeve and sheet off the shelf.

The catalogue could not say that. An item either held stock of its own or held
none, and a service that held none took nothing out of anything. So the wire was
bought (`Dr Inventory`), was never issued, and four things moved wrong together
and in the same direction:

* the shelf figure for every material climbed and never fell;
* Inventory on the balance sheet was overstated by the whole of it;
* cost of goods sold was understated by the same;
* and the winding line reported a margin of ~100%, because its only cost was
  material nobody had booked.

A recipe is the fix. `item_components` says what one of a made thing consumes,
and billing it issues those materials through the ordinary posting engine.

---

## Where it hangs, and why there

**On the variant, not the item.** A 5 HP rewind and a 10 HP rewind are the same
service and different amounts of copper — the same reason stock is counted per
variant rather than per item, applied to the other end of the relationship.

**Only on something that holds no stock of its own.** Enforced by
`ItemComponentService`, and refused rather than resolved: a recipe on a *stocked*
parent leaves a question with no good answer — does billing it issue the parent,
or the parts, or both? — and every answer to that is a kit, which needs an
assembly document to put the kit on the shelf in the first place. That is a
different feature and this is deliberately not half of it.

**One level.** A material may not itself be made from a recipe. Expanding a
recipe inside a recipe means walking a graph at the moment a bill is posted,
where a cycle entered months earlier stops being a data problem and becomes a
counter that hangs mid-sale.

That guard is reachable, which is worth knowing before somebody deletes it as
dead code: the two rules above look disjoint — a parent holds no stock, a
material must — but a product in a stock-holding *category* with `is_stock` off
can be given a recipe and have `is_stock` turned on afterwards. It is then a
material with a recipe of its own, and `RECIPE_WOULD_NEST` is what catches it.

---

## Where it is written

On the Items form, in the variant block, under **What it consumes** — offered
whenever the product holds no stock of its own, on a **create as well as an
edit**, and on a variant added to an existing product. A recipe names other
products, the copper and the varnish already on the shelf, so nothing about it
waits for the rewind to exist: one save writes the rewind and its recipe, and a
refusal leaves neither (`POST /items` takes `variants[].components`, inside the
same transaction as the product).

The edit form is opened from the catalogue list, so **the list and the product
carry each variant's recipe** (`ItemVariantService::attachRecipes()`). They did
not, and that was the worst failure this feature had: the form read "nothing
yet" for a rewind that had a recipe, and because a recipe write is a
*replacement*, the next save of that form — a price change — sent an empty one
and wiped it. A variant that arrives without a `components` key at all (a
server that has not run the schema step) is treated as *unknown*, not empty:
the section is not offered and nothing is sent.

## Every refusal is made when the recipe is written

A wrong recipe is found at the counter, with a customer waiting, or at a stock
take months later. So everything the posting would refuse is refused earlier,
where somebody can act on it, and each refusal is pinned to the field it is
about (`components`, or `is_stock`):

| Refused | Code | Why |
| --- | --- | --- |
| A fraction of something counted in whole units — 0.5 PCS of sleeve | `RECIPE_QUANTITY_FRACTIONAL` | The stock ledger refuses it at posting, so every bill for the rewind would be |
| Stock tracking turned **off** on a material a recipe consumes | `RECIPE_MATERIAL_IN_USE` (409) | Every bill for the rewind would then have nothing to issue |
| Stock tracking turned **on** for something with a recipe | `RECIPE_PARENT_WOULD_HOLD_STOCK` | The recipe would be silently ignored and the bill would issue the product from an empty shelf |
| Deleting a material variant a recipe consumes | `RECIPE_MATERIAL_IN_USE` (409) | Said in words; the foreign key would refuse it as a database error |
| A recipe on something another recipe already consumes | `RECIPE_WOULD_NEST` | The nesting rule, asked from the other side |

**Clearing a recipe is always allowed**, whatever the product has become since —
it is the way out of each of the states above.

Deleting a variant that *has* a recipe takes the recipe with it, row by row.

## History

A recipe save is a **diff**, not a delete-and-rewrite: a material added, a
quantity changed and a material removed are each one entry, written through the
model so the audit trail sees them, and a save that changes nothing writes
nothing. It used to bulk-delete and recreate on every save — which fires no
audit event for the deletion — so History showed every material "added" again
each time anybody saved the rewind's price, and never a removal.

## On the bill

Under a made line the bill says what it will consume: "Consumes 8.5 kg copper".
When a material is **also** on the bill as a line of its own, that note turns
amber and says both are taken off the shelf. That is usually the copper written
twice — once by hand, often off a job card's parts list, and once through the
recipe. It is said rather than refused: a job that really did use more than the
recipe records the extra as a part, and that is a legitimate bill.

---

## Nothing posted ever reads a recipe again

This is the part that makes the feature safe to change.

A recipe is expanded **once**, at the moment a bill is posted, into ordinary
rows in `stock_movements` written by the posting engine — the same engine, the
same valuation, the same lock as every other issue. From then on those movements
*are* the record of what was consumed. A reversal mirrors them, a margin sums
them, a stock card lists them; none of them consults the recipe.

So correcting a recipe next March cannot restate what a bill in September took
off the shelf. There is deliberately **no copy of the recipe pinned to the bill
line**, and there must not become one — the movements already are that copy, and
a second would be a second thing to keep in step.

---

## A bill line may have many stock movements

This is the assumption the feature broke, and it was load-bearing in five
places. Before recipes, a line supplied one variant and therefore moved one
quantity, and a good deal of code said so:

| Where | What it did | What it does now |
| --- | --- | --- |
| `TransactionLine::stockMovement()` | `hasOne` | `stockMovements()`, a `hasMany`; `cost()` sums |
| `BillTemplate::changesByLine()` | one change per line number | a **list** per line number |
| `SaleTemplate::bodyLines()` | one `Dr COGS / Cr Inventory` pair per line | one pair per **movement**, memoed with the material |
| `SalesInsights::lineQuery()` | joined `stock_movements` raw | joins a derived table of one row per line |
| `ReturnService` | read `$line->stockMovement` | sums through `cost()` |

Two of those are worth dwelling on, because neither fails loudly.

`changesByLine()` keyed by line number and **overwrote**, so a rewind's Inventory
and COGS lines were derived from the last material alone while its movements
carried all three. The posting engine refused it — `MovesStock` makes it compare
what the template posted against what the movements say, and the two disagreed —
so it surfaced as a refusal to post rather than as books quietly short by the
value of the copper. That assertion is the reason this was a five-minute bug
instead of a stock-take six months later.

`SalesInsights::lineQuery()` is the other. Five panels sum
`transaction_lines.taxable_value` across that join, so a line matching three
movement rows would have had its **revenue counted three times** — in the
overview, the trend, the stock/labour mix, the per-party list and the per-item
list at once, with every figure still entirely plausible.

---

## Correcting one

A **reversal** is exact and is the supported correction: it mirrors the movements
that were actually written, every material at the value it left at.

A **credit note is refused** on a line that was made from materials
(`RETURN_LINE_WAS_MADE_FROM_MATERIALS`). A credit note row names one item, one
variant and one `stock_value` — the shape of something that came off exactly one
shelf — and there is no honest way to put copper, varnish and sleeve back through
it. The plausible approximations are all wrong in the same direction: crediting
with no movement silently keeps the materials issued, and crediting against the
service variant would put stock onto a shelf that does not exist. It is also the
right answer for the trade: a customer does not return half a winding.

A **revision** is checked as any sale is. `assertRevisionKeepsTheCostItSoldAt`
compares unit cost per variant off the movements, so it now covers the materials
automatically — and a rewind invoice revised after the copper price has moved is
refused with `REVISION_WOULD_RESTATE_COST`. That is correct and it is new; the
counter will meet it.

---

## Short stock

A component issue obeys the ordinary refusal. Billing a rewind with no copper on
the shelf is refused unless the workshop has set `allow_negative_stock`, and the
bill preview says so **before** the work is promised — `BillPreviewService`
expands the same recipes through the same service, so a preview cannot promise
something the post then refuses, and two rewinds on one bill add their copper
together rather than each looking affordable on its own.

The shortfall names the **material**, not the rewind. The rewind has no shelf to
be short of.

---

## What the customer sees

Nothing. The invoice carries the service line and its price.
`InvoiceDocumentService` builds the customer's document from its own list of
fields and has no branch that could reach a component — the same rule that keeps
cost, margin and `below_cost` off it. A workshop's material consumption is its
negotiating position.

---

## Deliberately not built

* **Nesting** — refused, above.
* **Kits of stocked goods**, and the assembly document they would need.
* **Unit conversion.** A recipe is in the material's own base unit. A factor
  between a recipe and the stock ledger corrupts stock and the Inventory account
  together, silently, if it is ever wrong — the objection the catalogue already
  records.
* **Per-job actual consumption** — "we used 2.7 kg on this one". That is the
  Jobs parts list, which exists. The recipe is the standard; the parts list is
  the exception, and a job that really did use more records it there.

---

## The schema step

`item_components` is **not** a migration (§4.6). It is
`database/manual/sql/2026_09_29_001_item_components.sql`, run by an operator, and
the code works before it has been and after: `ItemComponentService::isInstalled()`
is the one place that is decided, reads answer "no recipe" without it — which is
exactly what was true of every product beforehand — and only a write refuses, out
loud, naming the step that has not been run.
