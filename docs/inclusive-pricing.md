# Rates quoted with the GST already in them

A counter prices two ways. "Ten thousand plus tax" is how a rewind is quoted;
"eleven eight" is how a part with the figure printed on the box is sold. Until
this existed only the first could be typed, and the second had to be worked
backwards by hand before it was entered — or, far more often, was not.

This is one flag, in three places, and one piece of arithmetic.

```
₹11,800 quoted with the tax in it, at 18%
    taxable  ₹10,000.00        ← extracted, not multiplied
    CGST        ₹900.00
    SGST        ₹900.00
    total    ₹11,800.00        ← the figure that was quoted
```

## Why this is not only a convenience

Because of the purchase side. **Stock is valued at the taxable value of the
line**, net of claimable tax, and that arrival is what recomputes the weighted
average cost — see [purchase-module.md](purchase-module.md).

A workshop entering a supplier's MRP-inclusive rate as though it were exclusive
was therefore carrying its shelf at the price *including* tax, inflated by the
whole rate. Nothing about that is visible afterwards: there is no average column
to correct, because the average *is* the sum of the movements, and every margin
computed from it is wrong by the same proportion with nothing on any screen
saying so. The old behaviour was not merely missing a mode; it was quietly
mis-costing every workshop that buys at printed prices.

## The arithmetic

`GstRate::baseWithin()` divides by one-and-the-rate in integer paise —
`118 × 10000 ÷ (10000 + 1800)` — and `GstBreakdown::within()` takes the tax as
**what is left over**, never as a second multiplication.

That subtraction is the whole guarantee. Extracting a base and then re-applying
`taxOn()` to it is two roundings, and the pair does not reliably land back on the
figure somebody typed. A customer handing over a hundred-rupee note for a
hundred-rupee price is the one thing this mode exists for, so base + tax comes
back to the quoted amount exactly, at every rate, on every awkward paisa. The
CGST and SGST halves already followed the same floor-and-remainder rule for the
same reason: a split has to add back to the thing it split.

`GstBreakdown::split()` is then shared by both constructors. The intra/inter-state
shape is the same question whichever way the rate was quoted, and a second copy
of it would be a second answer on a government return.

## Where the flag lives, and why in two places

| | Column | What it does |
|---|---|---|
| Product | `items.price_includes_tax` | A **default**. Prefills the toggle on a bill line. |
| Document | `transaction_lines.price_includes_tax` | The **fact**. What that line was actually struck on. |

The item's flag decides nothing on its own. A shop that sells parts at their
printed price still quotes the occasional job before tax, and the same bill
carries both — so the line is where the answer is recorded, and the catalogue
only saves somebody from re-stating it forty times a day.

It sits on `items` beside `gst_rate` and `hsn_sac` rather than on `item_variants`
beside `sell_price`. The three are one statement about the product — what it is
taxed at, under which code, and on which basis it is quoted — and a per-variant
copy would mean a family whose 5 HP is priced inclusive and whose 7.5 HP is not,
which is a distinction no workshop makes and one more thing to keep in step.

**The line's copy cannot be derived and must never be dropped.** ₹100 at 18%
quoted before tax and ₹118 at 18% quoted with it are the same `taxable_value`,
the same `cgst_amount` and the same `line_total`. Only `unit_price` differs, and
nothing can tell from ₹100 or ₹118 alone which was meant. Two things reproduce a
line rather than merely read it, and both are wrong without the column:

* `ReturnService` restates an invoice line as a credit note from its price, its
  discount and its rate. Adding tax where the invoice extracted it would credit
  back ₹21.24 against ₹18 that was charged, and the pair would not net out on the
  return that reports both. It is pinned exactly as `gst_rate` and the
  intra/inter-state shape already are.
* `components/bill-revision.js` loads a posted document back into the create form
  to correct it. A toggle that came up wrong would restate the whole document at
  a total the original never carried.

## Where the server falls back

A line that sends no `price_includes_tax` takes the **item's** default rather
than `false`. An API caller that has never heard of the toggle then still gets
the treatment the workshop set on the product, instead of having tax silently
added on top of a price that already had it in. The default is `false` until
somebody sets it, so nothing that posts today changes.

The form always sends the key explicitly, because a line deliberately flipped
*back* to exclusive has to be able to say so against an item that says otherwise.

## Discounts

Subtracted **before** the tax is extracted, in the terms the price was quoted in.

₹18 off a ₹118 inclusive part leaves ₹100 to pay, which is what somebody taking
₹18 off it meant; the base and the tax then follow from that ₹100. Discounting
the extracted base instead would hand back ₹18 of goods *and* ₹3.24 of tax, and
the customer would pay ₹96.76 for an ₹18 reduction.

A discount on the **whole bill** is apportioned over each line's own basis —
`BillLine::discountBase()`. "₹1,000 off" means ₹1,000 off the taxable value of
the lines quoted before tax, which is what this application has always done and
what those customers save plus the tax on it; and ₹1,000 off what is actually
paid for the lines quoted with tax in. Each is what somebody typing that figure
against that line would have meant, so each line is asked rather than one basis
being imposed on both.

## What the two screens show

**The entry form** keeps the Amount column pre-tax on every line, so it still
totals to the "Taxable value" in the footer. A column mixing ₹118 and ₹100 for
the same ₹100 of goods cannot be added up by eye. The quoted figure is not lost:
the rate box carries a per-unit breakdown under it — `₹100.00 + ₹18.00 GST` — and
the confirmation marks the rate `incl`.

**The customer's invoice prints the rate before tax**, on every line, whichever
way it was typed. A tax invoice's rate column sits beside a taxable value and a
tax column and has to be the same kind of figure; printing the ₹118 that was
typed next to a taxable value of ₹100 gives the recipient's accounts department a
row that does not multiply out, on the one document whose whole job is to be
their evidence for an input tax credit. `InvoiceDocumentService::beforeTax()`
converts the rate and the discount together, through the same
`GstRate::baseWithin()` the posting engine used, so the document cannot disagree
with the ledger behind it.

The **taxable value is the authoritative column** — it is exact, and it is what
the return is filed on. On an awkward quantity the printed rate can be a paisa
off multiplying into it, which is inherent to quoting inclusive and is why the
two are not derived from each other. The customer's total is unaffected either
way.

## What it deliberately does not do

**No document-level switch.** One bill routinely carries a part at its printed
price and labour quoted before tax, and a single control for the document would
make that bill unwritable.

**No category default.** What a shop charges on a kind of thing is a property of
the tax code and belongs in the Category Master beside `default_gst_rate`;
whether it writes the figure with the tax folded in is a habit of the shop, and
one it applies to some products and not others. A default that cascaded would be
set once and then quietly wrong for every service the shop ever adds.

**Nothing on expenses.** An expense takes a GST *amount* in rupees off the
supplier's bill, not a rate — there is no rate to extract from, and adding one
would mean modelling an expense as a line.

**Nothing already posted moves.** Every existing line carries `false`, which is
exactly what it meant. Flipping an item's default changes what the *next* bill
prefills and no invoice already issued, which is the same guarantee `gst_rate`
beside it gives.

## Tests

`tests/Unit/GstTest.php` holds the arithmetic, including the round-trip property
across six rates and seven amounts — the guarantee that a quoted price always
adds back to itself.

`tests/Feature/Accounting/InclusivePricingTest.php` holds the rest: that the
figure quoted is the figure charged, that the same supply reaches the books
identically whichever way it was typed, that a purchase values stock net of the
tax inside the rate, that a line takes the item's default and may overrule it in
either direction, that discounts land where they were meant, that a credit note
restates its invoice's basis, and that the customer's invoice prints a rate
before tax.
