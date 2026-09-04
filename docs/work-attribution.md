# Work attribution

Who did the work an invoice was raised for — "Ramesh fitted it, Sunil wound it" —
and what that adds up to per person.

It exists to answer two questions a repair shop asks constantly and could not
answer before: **which of my people did this motor**, and **how much is this
person getting through**. Both are read off the sale, because the sale is the one
document that already exists for every job that was billed.

Read [sales-module.md](sales-module.md) for the document this hangs off. The
staff side has no file of its own yet — the reasoning for the employee record and
the designation master is in their migrations, which argue it at length. This
file covers only the join between the two.

## What it is made of

```
Designation Master (Staff workspace)
   └─ "Ask for this on a sale"  → staff_designations.track_on_sales

Sale form (level 1, shared bill-document)
   └─ components/staff-attribution.js      one picker per ticked trade
        └─ GET /transactions/meta          → staff_slots: trades + names only
        └─ POST /transactions/sale         → staff: [{designation_id, employee_id}]

Sales drawer (level 2)
   └─ "Work done by Fitter · Ramesh"    [Change]
        └─ level 3 #sales-staff-modal    → PATCH /transactions/{id}/staff

Staff drawer (level 2)
   └─ "Work done"  jobs · invoiced · the last ten
        └─ GET /staff/{employee}/work
```

One table, `transaction_staff`: `tenant_id`, `transaction_id`, `employee_id`,
`designation_id`, unique on `(transaction_id, designation_id)`.

## The decisions

### There is no fitter column and no winder column

The trades are **rows**, exactly as product categories, brands and units are.
`staff_designations` already existed and its own migration argues the case at
length: a rewinding shop has Winders and Varnishers, the pump dealer across the
road has Drivers and Loaders, and nobody can write that list down in advance.

So the sale form asks about whatever the workshop ticked. A shop that starts
varnishing adds a designation, ticks one box, and has a third picker on the
invoice screen that afternoon — no migration, no deployment, no change to this
codebase. `components/staff-attribution.js` renders the boxes and does not know
what a fitter is.

The rejected alternative was `transactions.fitter_id` and
`transactions.winder_id`. It is two columns instead of a table and it is wrong in
the specific way CLAUDE.md already forbids: a hard-coded vocabulary is a master
list nobody maintains, and the third trade ends up typed into the Notes field.

### Only ticked trades, not every trade

A sale form carrying a Driver box, an Accountant box and a Watchman box asks the
counter six questions to record two facts. A form that asks questions nobody
answers is a form whose answers stop being trusted, so `track_on_sales` is the
flag and it defaults to **false** — an existing workshop's designations are all
switched off on the day this deploys, apart from anything already called Fitter
or Winder, which the migration takes to mean what it says.

The tick lives in the Designation Master, beside the trades themselves, rather
than in a settings screen. It is a decision about the workshop, not about an
invoice.

### It is not `transactions.employee_id`

That column exists and means something else: who a staff **advance** was handed
to. It is `STAMPABLE_ONCE_POSTED` — write-once — because re-pointing it would let
one employee's advance be recovered from another's salary.

Attribution is a different kind of fact. There are two of them per invoice, it is
a label on work rather than a claim about a counterparty, and — see below — it
has to stay editable. One column could hold neither fact honestly.

### The counter can name a fitter without being able to read a wage

**Only OWNER holds `STAFF`.** That is deliberate and it is about privacy rather
than authority: what each person in a workshop earns is not something the clerk
at the counter needs in order to do their job. A `DATA_ENTRY` user has no grant
there at all.

But that clerk is exactly who raises invoices. Fetching the pickers from `/staff`
would either 403 the sale form for its main user or force a wage-reading grant
onto everybody who writes a bill, and the second is how a permission model
quietly stops meaning anything.

So the roster is published by **`GET /transactions/meta`**, under
`READ:TRANSACTIONS`, carrying `{id, name}` and nothing else — no rate, no basis,
no joining date, no phone number. It rides on a request the sale form was already
making, so it costs no round trip.

This is the same line CLAUDE.md draws for a party's outstanding: between one fact
somebody needs to do their job and the records behind it, **never between the
name and the money**.

Reading somebody's *throughput* is on the other side of that line, and
`GET /staff/{employee}/work` needs `READ:STAFF`. "Which of my people is getting
the work through" is a question about staff, and its answer sits beside their
wages on the same screen.

### A wrong name is correctable, and the invoice is still immutable

This is the only edit in the application that reaches a document already in the
books, and it is worth being clear about why it is safe.

Nothing about attribution is a figure. Correcting it moves no ledger entry, no
stock movement, no total, and nothing on the customer's copy. The reason a posted
transaction is immutable — a report run yesterday must still produce yesterday's
numbers — is untouched by swapping one name for another.

The alternative would be write-once, matching `workshop_job_id` and
`employee_id`. That was rejected because on a **sale** there is no way out of it.
Correcting a posted document normally means `revise`, which reverses the original
and issues a replacement — and
`PostingEngine::assertRevisionKeepsTheCostItSoldAt` refuses that outright with
`REVISION_WOULD_RESTATE_COST` once the weighted average has moved, with **no
acknowledgement path**. So a write-once label would simply stay wrong for ever,
and the productivity figures would credit the wrong person for as long as the
workshop kept its records.

`PATCH /transactions/{id}/staff` is gated on `UPDATE:TRANSACTIONS`, not on a
staff grant: the person who notices the wrong fitter is the person who raised the
invoice.

**Every change is on the audit trail** — `AuditResource::SaleAttribution` — and
that is the whole safeguard. Rows are matched and updated in place rather than
cleared and rewritten, so a correction reads as one row with a from and a to on
it, and re-saving the same two names writes nothing at all.

### The whole set is sent, every time

`staff` carries an entry per painted slot, including the empty ones:
`{designation_id: 4, employee_id: null}` means "the Winder box is empty".

An API that only ever added could never express a name being *removed*, which
would make a mis-picked fitter permanent — half a correction. The null is
load-bearing, and both `StoreBillRequest` and `UpdateTransactionStaffRequest`
document it as such.

### The customer never sees it

Whose hands were on the motor is the workshop's business. A customer who learns
that the apprentice wound it has been handed an argument about the price.

This is guaranteed structurally rather than by remembering: `TransactionResource`
carries `staff`, and `InvoiceDocumentService` builds the customer's document from
its **own list of fields**, so there is no branch anywhere that could include it —
the same protection that keeps the cost of every line off that document.

### A correction carries the names; a repeat does not

Both load a posted document back into the create form through
`components/bill-revision.js`, and they differ here on purpose.

A **correction** is the same repair being re-documented, so the names come with
it. Dropping them would make every correction quietly un-credit whoever did the
job — the worst way to lose the figures, because the document looks complete.

A **repeat** is new work. The same customer is having the same thing done again,
and there is no reason to think the same two people did it. Carrying the names
forward would credit somebody for a motor they never touched.

### The report counts jobs and value, and says so

`GET /staff/{employee}/work` returns two figures because neither stands in for
the other:

- **Jobs** is throughput. Eleven motors is eleven motors.
- **Invoiced** is what those documents came to — gross, matching the day book.

The second is the one an owner reaches for and the one most easily misread: a
bill that is mostly bearings credits its fitter with the bearings, because the
document does not separate the labour from the parts. Both are shown so that
neither is taken for a measure of effort on its own.

**Reversed documents are excluded.** A repair that was billed and then cancelled
is not work anybody did — and this is also what keeps a correction honest, since
a revision reverses the original and posts a replacement, which would otherwise
count the same motor twice.

Nothing here is an input to pay. Payroll computes from a rate and an attendance
sheet in one place, and a throughput figure that quietly became a piece rate
would be a second source of truth for wages, arrived at by accident. That is why
the panel sits below the pay history rather than inside it.

### Nothing loses the name that explains it

`employee_id` and `designation_id` are both `restrictOnDelete`, and both refusals
are raised in the service with a sentence rather than left to the foreign key
with a 500:

- deleting an employee counts their attributions alongside their payslips,
  advances and attendance — `EMPLOYEE_IN_USE`;
- deleting a designation counts them alongside the employees who hold it —
  `DESIGNATION_IN_USE`, with `attribution_count` in the details.

Both say "archive it instead", which is what a workshop actually means.

`transaction_id` is the one **cascade**: discarding a draft must take its
attribution with it, and nothing is lost because the draft was the only thing
that ever knew about it.

### A trade that is no longer asked about stays correctable

A document can carry a designation the workshop has since un-ticked or archived —
last year's Varnisher. The picker still paints that slot, and the service still
accepts a change to it, because a refusal about *today's* configuration must not
block a statement about the past.

The same reasoning allows an employee who has **left** to be named on a
correction, while the roster for a *new* sale offers active staff only: somebody
who has left cannot have done work being billed today, but they do not stop
having done last quarter's.

## What this deliberately does not do

**No line grain.** The attribution covers the document. A bill for one motor is
the ordinary case, and per-line pickers would put two more controls on every row
of the lines table — including the bearings and the varnish, where the question
means nothing. A workshop genuinely doing two motors for one customer on one day
raises two invoices, which it already has every other reason to do.

**No hours, no piece rate, no share of the bill.** This says who did the work, not
what the work was worth to them. The moment a percentage lands in this table it
is an input to somebody's pay, and pay is `payroll_lines`.

**No attribution on a purchase.** Goods arriving from a supplier were not fitted
by anybody in the building. The service refuses it outright — `ATTRIBUTION_NOT_A_SALE`
— rather than accepting and ignoring it, because a silent success would let the
caller believe the record was made.

**No second home on `workshop_jobs`.** The Jobs module (M19) is the natural place
for "who repaired this motor" and is `'enabled' => false` — not converted to the
§2A shell yet. When it is converted, the job screen should read and write *these*
rows through `WorkAttributionService` rather than growing its own columns; the
sale is where the record is kept because the sale is the document every billed
job has.
