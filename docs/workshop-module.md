# Workshop Jobs

> **Card status: switched off — and this is the largest domain nobody can
> reach.** The module is complete: the list, the job card, the pipeline, parts,
> the estimate and its approval, `Generate bill`, and 25 feature tests including
> the §34 walkthrough end to end. It waits only on the §2A conversion. The
> counter at `/bills/new` can *bill* an existing job but cannot create one, and
> nothing in the UI links to the counter either, so today a workshop has no way
> to record the work it actually does. It is the first module that should be
> converted. See [hidden-modules.md](hidden-modules.md).

The motor on the bench — M19, and the brief's §16 to §18.

Every other module in this application describes something that happened to the
workshop's books. This one describes a physical object with a fault. Its
statuses are about the object, its parts are a shopping list, and none of it
reaches the ledger until somebody decides to bill it.

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
| `GET /workshop-jobs/meta` | The statuses, the legal moves from each, and the counts |
| `GET /workshop-jobs/{job}` | The job card |
| `GET /workshop-jobs/{job}/bill-preview` | The payload the counter opens pre-filled |
| `POST /workshop-jobs` | Book a motor in |
| `PATCH /workshop-jobs/{job}` | The motor and the complaint — not the status, not the customer |
| `PUT /workshop-jobs/{job}/status` | A pipeline move |
| `POST /workshop-jobs/{job}/parts` | Write a part on. Moves no stock |
| `DELETE /workshop-jobs/{job}/parts/{part}` | Refused once it has been billed |
| `PUT /workshop-jobs/{job}/estimate` | Replace the quotation. Clears any approval |
| `POST /workshop-jobs/{job}/estimate/approve` | The customer said yes |
| `POST /workshop-jobs/{job}/estimate/apply` | Copy the quotation onto the job as parts |
| `POST /workshop-jobs/{job}/bill` | Raise the invoice, through the ordinary sale path |
| `DELETE /workshop-jobs/{job}` | Only ever reaches a job nothing has been billed against |

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
