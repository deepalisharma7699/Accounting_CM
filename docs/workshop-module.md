# Workshop Jobs

> **Card status: on since C4.** The bench is a §2A module: "Book something in"
> is the create form, the list is behind "Show list", and a job opens in a drawer
> carrying the pipeline, the parts, the estimate and **Generate bill** — which
> mounts the shared bill document on the create surface and posts `{job}/bill`.
> Converting it also finished three things the API had accepted and no screen
> ever sent: correcting a job card, deleting a job, and the half of a workshop
> bill — the inclusive-tax flag, a bill discount, and **who did the work** —
> that `BillJobRequest` had been dropping. It retired the counter at
> `/bills/new` with it, which was the last page shell in the application. The
> step's full record is Part E of
> [implementation-roadmap.md](implementation-roadmap.md).

The thing on the bench — M19, and the brief's §16 to §18.

Every other module in this application describes something that happened to the
workshop's books. This one describes a physical object with a fault. Its
statuses are about the object, its parts are a shopping list, and none of it
reaches the ledger until somebody decides to bill it.

Most of what comes through the door is a motor. A good deal of it is a cooler, a
table fan or a pump, and now and then it is something nobody expected — so the
bench asks **what kind of thing** arrived and then asks what *that* kind is
described by. See [What came in](#what-came-in).

> *Motor received → inspected → estimated → repaired → delivered → billed.*
> — the brief, §16

## Why it is not a draft sale

A job exists before any money does. A pump motor is received on the 3rd, opened
up on the 5th, quoted on the 6th, approved on the 9th, rewound over the following
week and billed when the customer comes for it.

Modelling that as a draft invoice was the obvious shortcut and it is wrong twice
over.

* It would put a document with a customer and no items in the books' draft queue
  for a fortnight, where every worklist and every "finish your unposted work"
  prompt would nag about it — and where somebody would eventually post it to make
  the nagging stop.
* It would be false. A draft is a document somebody has started writing; a job is
  an object with a burnt winding. `in_progress` is not a state an invoice can be
  in.

## The tables

`workshop_jobs`, `workshop_job_parts`, and a nullable `transactions.workshop_job_id`.

The qualifier is not decoration. `jobs` is Laravel's queue table and `job_runs`
is M14's record of background work, so three different things were competing for
one word. The workshop one takes it because it is the one a reader is least
likely to guess wrong — in this trade a "job" is the motor, and only a programmer
would read it as a queued closure. The same reasoning names
`App\Enums\WorkshopJobStatus`, the `WORKSHOP_JOBS` permission and the
`/api/v1/workshop-jobs` routes. The *web* route is `/jobs`, because nothing on
that side routes the queue and a fitter should not have to think about why the
word is qualified.

### What came in

A job carries three columns about the thing itself, and they are the whole of the
answer:

| | |
| --- | --- |
| `category_id` | Which kind of thing — an `item_categories` row |
| `kind_label` | That category's name, **copied** at intake |
| `specs` | The answers, keyed by attribute — `item_variants.attributes` shape |

beside `brand`, `model` and `serial_no`, which every electric thing has and which
are what a customer quotes down the phone.

**There is no `hp` column and no `phase` column, and there must not be again.**
There were, until the bench was generalised, under an intake heading that said
"The motor" — a product type written into a schema and into a Blade template,
which is the same failure the catalogue's vocabulary rule already records against
`ItemType` and against a typed brand. It cost a real workshop something every
week: a cooler came in and the only fields the job card offered were two that
mean nothing about a cooler, under a heading telling the counter it had the wrong
screen.

#### Why the catalogue's vocabulary and not a second list of kinds

Because `item_categories` already answers exactly this question — what kinds of
thing exist and what to record about each — and `item_attributes` is already the
question set, with data types, units, fixed option lists, inheritance from a
parent category, and an admin screen to edit all of it. A `job_categories` table
beside it would be a second master, a second schema resolver, a second admin
screen and two vocabularies to keep in step (§4.4, §5.1).

So a workshop that starts repairing coolers adds a Cooler category from the Items
card, and the bench asks what a cooler is described by. No column, no migration,
no deployment — the catalogue module's own acceptance criterion, one module
along. The fields are drawn by `components/attribute-fields.js`, which is also
what the Items create form draws a variant's specification with.

#### Three things about it that are the opposite of the catalogue's

**Nothing is required.** `is_required` on an attribute says a *product* cannot
exist without it — a motor with no rating is not a catalogue entry anybody could
sell. A job is a physical object that is already on the bench: a pump is wheeled
in at four in the afternoon by a driver who knows none of it, and a form that
refused to book it in would be a form that got a job card written on paper
instead. The kind itself is optional for the same reason.

**Nothing is coerced or checked against the options.** The catalogue describes
what the workshop deals in and can hold its own values to it; this describes a
competitor's forty-year-old unit, and a plate reading a voltage nobody put in the
dropdown is a fact about the object rather than a mistake to refuse.

**Keys the kind does not ask about are dropped.** A bag whose keys no schema
explains cannot be labelled, printed or put back into a form, so a job with no
kind holds no specification at all. Inactive attributes still count: an admin
switching a field off must not blank it on the next edit of every job that
answered it.

#### Why the label is copied

The rule the `brand` and `model` columns beside it already follow. A job card is
the record of a physical object on a day: the casing said "Motor" when it arrived
and must still say so next year, after somebody has renamed the category or
archived it. It also means a list row renders with no join, `search=cooler` finds
the coolers without one, and a job whose category was deleted still says what came
in rather than going blank.

#### How the bag becomes words

`{"hp": "7.5"}` is unreadable on its own. `JobService::attachSpecSchema()`
resolves the labels and units through the categories a page of jobs spans — **one
lookup per distinct category**, not per row, because a bench is mostly motors and
twenty-five jobs are typically two categories. `WorkshopJobResource` then sends
both: `specs`, the raw bag the edit form writes back, and `specs_display`, the
same values labelled, unitised and in the order the category asks them.

`WorkshopJob::equipmentLabel()` is the one-liner every screen prints — "Motor
7.5 HP, 3 ph · Crompton CR-1234", two groups: what the thing is, and whose it is.
It summarises the first two fields the category asks about, which for every kind
seeded or templated are the two that identify one at a counter. **Nothing from
the bag reaches it without the resolved schema**: a bare "7.5 3 1440" says less
than leaving it out.

> MySQL's JSON type normalises an object's key order, so a stored bag never comes
> back in the order it was written. That is why the display order comes from the
> schema and not from the bag — and why a test asserting on `specs` compares
> without regard to order.

### What is deliberately absent

**No total, and no amount of any kind, on `workshop_jobs`.** What a job is worth
is the bill raised from it, derived on read from `transactions`. A stored total
would be a second copy of the invoice that disagrees with it the first time a
line is changed — the same mistake as a stored party balance or a `qty_on_hand`
column, neither of which exists either.

**No reservation.** See below.

## Decision D2: a part on a job moves no stock

A row in `workshop_job_parts` is a **note about what will be billed**. It
reserves nothing, allocates nothing and does not touch `stock_movements`. The
bearing leaves the shelf when the invoice posts, in one movement, written by the
same posting engine that writes every other movement in the application.

Issuing stock when a part is added to a job is tempting and wrong, and wrong in a
way that takes months to notice. It would mean stock could move without a posted
transaction — which is the single invariant the entire inventory module rests on.
The Inventory account equals Σ(qty × cost) *because* nothing writes a movement
except a posting. Break it once and the stock ledger and the books drift apart
with nothing to reconcile them by, and the drift shows up as an unexplainable
figure at a stock take.

The cost of the decision is real and much smaller: a part written onto a job is
not yet subtracted from what the shelf shows, so two jobs can both plan to use
the last bearing. That is a conversation between two fitters, and the refusal
lands honestly at the moment either bill is posted — M17's `assertCanIssue()`.

## Decision D3: an estimate is a field, not a transaction

`workshop_jobs.estimate_lines` is JSON in the same shape a bill's `items` takes,
plus `estimate_approved_at`.

An estimate that posted journal entries would be claiming revenue nobody has
agreed to, and a customer who said no would leave a cancelled invoice on a job
that never happened. Storing it in the bill's own shape means converting a
quotation into an invoice is a copy rather than a translation — and the only
thing that can differ between what was quoted and what was billed is something
somebody deliberately changed.

Replacing an estimate clears its approval. A customer who agreed to ₹1,200 has
not agreed to ₹1,800.

## Billing re-enters nothing

`JobService::billPayloadFor()` produces the exact payload
`POST /api/v1/transactions/sale` accepts, and `bill()` hands it to
`TransactionService::create()`. So the tax arithmetic, the stock issue, the cost
of goods sold, the document numbering, the duplicate protection and the
negative-stock refusal are all the engine's. There is no second bill engine here
and there must never be one: the GST on a workshop invoice ends up on a
government return, and two implementations of it agree right up until the month
they do not.

Three writes happen inside one database transaction:

1. the sale itself;
2. `transactions.workshop_job_id`, stamped write-once — it joins
   `opening_import_id` in `Transaction::STAMPABLE_ONCE_POSTED`, and may only go
   from null to set;
3. `workshop_job_parts.transaction_line_id` on each part, pointing at the invoice
   line it became.

A crash between them would leave a job that could be billed a second time for
bearings that have already left the shelf.

### Why a job cannot be billed twice

Not by a flag, which somebody would have to remember to set — by the third write
above. A part that already points at a line is not offered to the next invoice,
so a second bill finds nothing left and is refused with `JOB_NOTHING_TO_BILL`.
That stays true however the first invoice was raised.

A long repair *is* legitimately billed more than once — an advance against the
estimate, the balance on collection — and the second invoice carries only what
was added since. This is also why the link lives on the transaction rather than a
`bill_transaction_id` column on the job: one column could express neither pair.

### A cancelled job bills nothing

The brief's scenario 10. `WorkshopJobStatus::isBillable()` is true only for
`in_progress`, `ready` and `delivered`, so a job nobody authorised cannot produce
an invoice whatever parts were optimistically listed on it while the estimate was
being argued about.

**And the screen says so.** That rule had been enforced in the enum and at the
endpoint and shown nowhere: the job card simply left **Generate bill** out when
the status did not allow one, which reads as a product that offers the button
sometimes and not others for no stated reason. It is painted and **disabled**
now, with the refusal underneath it — the same judgement the Roles module makes
about a system role, and for its reason: the answer belongs where the question is
asked. Three refusals, all of them the server's own:

| Why | What the card says |
| --- | --- |
| Cancelled | *`JOB/26-27/41` was cancelled, so there is nothing on it to bill.* |
| Nothing done yet | *…is received. Move it to in progress before billing it — an invoice now would be charging for work nobody has started.* |
| Nothing on the card | *Nothing has been written onto this job yet. Add the parts and the labour first.* |
| Already billed in full | *Everything on `JOB/26-27/41` has already reached an invoice. Add what else was fitted.* |

## Whether it has been invoiced is a second signal

`WorkshopJobStatus` is about the motor. Whether the customer has been charged is
a different question and the enum says so where `Delivered` is declared: a
regular customer's pump goes home on Friday against an invoice raised at the end
of the month, and a job billed in advance sits on the shelf until somebody comes
for it. Folding one into the other means lying about one of them.

So there is a second, derived state — `App\Enums\JobBillingState`, three cases:

| State | Badge | When |
| --- | --- | --- |
| `unbilled` | *(silent)* | Nothing stands against the job. |
| `part_billed` | **Part billed** (amber) | Invoiced, with work still on the card. |
| `billed` | **Invoiced** (green) | Everything on the card has reached an invoice. |

It is sent on the resource as `billing_state`, `billing_state_label` and
`billing_state_tone` — the tone travelling with it so a screen never maps a state
to a colour — and it is **absent** wherever `billed` is absent, because "nothing
has been billed" and "nobody asked" are different answers.

`unbilled` paints nothing at all. Most of a bench has not been billed, and a
badge on every row says nothing.

**Never stored.** `WorkshopJob::billingState()` reads the invoices that point at
the job and the parts that point at their invoice lines, so reversing a bill
takes the badge away with nothing having to remember — which is what
`test_reversing_the_invoice_takes_the_badge_away` holds shut. `billed['live']`
is the count that drives it: `billed['count']` is every document the job has
produced, reversals included, because the job card lists them all.

A listing does not load the parts, so the repository counts the unbilled ones in
a second subquery (`parts as unbilled_parts_count`) and the state falls out of
that. A detail read has the parts in hand and uses those.

### Handing the customer their invoice

Every posted invoice off a job opens in `#invoice-preview` — the one sheet, with
**Print** and **Share** — from a row in the job card's own list of them, and a
bill lands there the moment it posts. None of that is this module's code: it is
`components/invoice-delivery.js`, which Sales mounts too, and there is exactly one
invoice sheet in the application for the reason
[billing-module.md](billing-module.md) records.

## The pipeline

```
received ─┬─> inspection ─┬─> estimate ──> in_progress ──> ready ──> delivered
          │               │                   ▲             │
          └───────────────┴───────────────────┘             │
                                     ▲                      │
                                     └──────────────────────┘
                          (a motor that failed its test run)

  anything unfinished ──> cancelled
```

Declared on the enum, the way `TransactionStatus` declares that only a draft may
be edited, so the screen's pipeline control, the API's refusal and the `meta`
endpoint all read one answer. Two exceptions to forward-only, and both are
deliberate:

* **Cancelled is reachable from anywhere unfinished.** A customer who changes
  their mind does so at whatever point they change it at.
* **Ready may go back to in progress.** It failed the test run. Not an exception
  in a rewinding shop; a Tuesday.

`delivered` is terminal. Whatever comes back next week is a new job with its own
complaint, not this one reopened — which would silently rewrite how long the
first repair took.

## Numbering

Job cards take `JOB/26-27/41` from the same locked counter every invoice number
comes from — `DocumentNumberService::assignSeries()`, under
`SELECT … FOR UPDATE` inside the caller's database transaction. Two motors on two
benches carrying one ticket number is the same unrecoverable mess as two invoices
carrying one number.

Unlike an invoice, the number is assigned at **creation**. A job has to be
labelled before anybody can put a sticker on the casing, and there is no draft
state for it to be discarded from — so numbering it early leaves no gap in the
series.

## Permissions

`WORKSHOP_JOBS`, with all four actions. DATA_ENTRY holds READ, WRITE and UPDATE:
booking a motor in, moving it along the bench and writing parts onto it is what
the person at the counter does all day. DELETE stays with the owner and grants
less than it sounds like — a job with a bill against it cannot be deleted by
anybody, because the invoice has to keep the job that explains it.

`POST {job}/bill` additionally needs **WRITE:TRANSACTIONS**. Raising an invoice
is capturing a business event whichever screen it was reached from, and a jobs
grant that quietly conferred the ability to post to the ledger would be a hole in
the permission model rather than a convenience.

## Endpoints

| | |
| --- | --- |
| `GET /workshop-jobs` | The worklist. `open=1`, `status=`, `overdue=1`, `search=` |
| `GET /workshop-jobs/meta` | The statuses, the legal moves from each, the counts, and the **kinds** that can be booked in |
| `GET /workshop-jobs/{job}` | The job card |
| `GET /workshop-jobs/{job}/bill-preview` | The payload the counter opens pre-filled |
| `POST /workshop-jobs` | Book something in |
| `PATCH /workshop-jobs/{job}` | What came in and the complaint — not the status, not the customer |
| `PUT /workshop-jobs/{job}/status` | A pipeline move |
| `POST /workshop-jobs/{job}/parts` | Write a part on. Moves no stock |
| `DELETE /workshop-jobs/{job}/parts/{part}` | Refused once it has been billed |
| `PUT /workshop-jobs/{job}/estimate` | Replace the quotation. Clears any approval |
| `POST /workshop-jobs/{job}/estimate/approve` | The customer said yes |
| `POST /workshop-jobs/{job}/estimate/apply` | Copy the quotation onto the job as parts |
| `POST /workshop-jobs/{job}/bill` | Raise the invoice, through the ordinary sale path |
| `DELETE /workshop-jobs/{job}` | Only ever reaches a job nothing has been billed against |

`meta` publishes the kinds rather than leaving a client to fetch them from
`GET /items/meta`, and that is not convenience. The items route is behind
`READ:ITEMS` and this one is behind `READ:WORKSHOP_JOBS`, and the person booking
a motor in is exactly the person who may hold the second and not the first —
fetching the intake form's fields from the items route would 403 the form for its
main user. It is the trap M22's attribution pickers avoid by riding on
`GET /transactions/meta` rather than on `/staff`. The list is filtered to
categories that `holds_stock`: in this application a category that holds none is
one whose things are produced at the moment they are sold — an hour of rewinding
— and nobody wheels one of those through a door. That is a property of the
category rather than a flag invented for this module, which is why there is no
`repairable` column to keep in step with it.

`category_id` and `specs` travel together on a `PATCH`. The bag is filtered
against whichever category the job ends up under, so correcting a motor to a
cooler cannot leave a motor's answers behind — `hp` is not a field a cooler has,
and a bag its kind cannot read is one nothing can print.

Neither the customer nor the status can be changed through `PATCH`. The status
has a verb of its own so that saving a typo correction can never deliver a motor
that is still on the bench; the customer cannot change at all, because an invoice
may already explain a repair for them. A motor booked in against the wrong
customer is corrected by cancelling and re-booking — cheap while nothing has been
billed, and honest once something has.

## What a screen has to keep saying

That adding a part moves no stock. It is obvious in this document and baffling at
a counter, so `/jobs` says it where parts are added rather than leaving it to the
schema.
