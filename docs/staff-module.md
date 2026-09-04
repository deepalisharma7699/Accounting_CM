# Staff — M22

The people who work *for* the workshop: who they are, what they are paid, who
was in on which day, what a month adds up to, and the money handed over against
a salary not yet earned.

One card, four sections, and each section is an ordinary §2A workspace built
from the shared renderer.

---

## 1. What this module is not

**It is not Users.** USERS is who may sign in; STAFF is who is on the payroll.
Most of a workshop's fitters and helpers have never touched the software and
never will, and the owner's son who runs the counter has a login and is not on
the salary sheet. One resource for both would mean that granting somebody the
ability to add a login also let them read every wage in the building.

**It is not Parties.** A party's position lives in Receivables or Payables —
that is what `PartyRole::controlAccount()` means. An employee's does not: what
is owed to staff sits in Salary Expense and Staff Advance, which are different
accounts read by different screens answering a different question. The
`parties.roles` column was written with a "staff" role in mind; that turned out
to be the wrong shape, because none of what matters about an employee — the
basis, the rate, the joining date, the attendance — has anywhere to live on a
party.

---

## 2. The grant

`STAFF`, held by `OWNER` and by nobody else.

It is the one grant in this application withheld for **privacy** rather than for
authority. Every other exclusion in `RoleSeeder` is about what a clerk may
change; this one is about what each person in the workshop earns, which is not
something the person on the till needs in order to capture the day's
transactions. `DATA_ENTRY` has no route into this module at all.

Inside the module the line falls where the **money** starts. Paying an advance
and posting a payroll run reach the ledger, so both additionally require
`WRITE:TRANSACTIONS` — the same boundary `workshop-jobs` draws between recording
a repair and billing it. A staff grant cannot quietly become the ability to move
cash out of the till.

---

## 3. The two bases, and the rule that does most of the work

A workshop pays two kinds of people, and the difference is not a label — it
decides the denominator of the arithmetic and it decides what an **unmarked day**
means.

| | Monthly salary | Daily wage |
|---|---|---|
| Rate means | ₹18,000 a month | ₹550 a day |
| A month is worth | the rate, pro-rated over the days the month has | the rate × days marked |
| An unmarked day | **paid** | **not paid** |
| A holiday or week off | paid | not paid |
| A paid leave | paid | paid |

### Why an unmarked day is not a blank

A workshop does not mark attendance every single day. Somebody is away, the
sheet is filled in on Saturday, the month gets busy. So payroll has to decide
what silence means, and the honest answer differs:

- **Monthly** — a salary is owed unless something is recorded against it.
  Treating silence as absence would dock a fitter three days' pay because nobody
  opened the screen during Diwali week, and the employee would be the one to
  discover it.
- **Daily** — a wage is earned by turning up, and the mark is the evidence that
  somebody did. Treating silence as a day worked would pay a helper for a
  fortnight nobody can account for, with nothing on the sheet to question it
  against.

Both defaults fail towards the thing that gets noticed: an underpayment is
raised the same afternoon, an overpayment is not raised at all.

That decision lives in exactly one place — `SalaryBasis::unmarkedDayIsPaid()` —
and nothing else in the codebase is allowed to make it a second time. In
particular the attendance screens return an unmarked day as unmarked rather than
defaulting it to present, because filling the gap in the UI would be making the
decision again in the layer least likely to be looked at when a payslip is
queried.

### Why the month is its own denominator

₹18,000 is ₹600 a day in a 30-day month and ₹580.65 in a 31-day one. The
*salary* does not change, so the day rate must. Pro-rating against a fixed 30
would pay a month's salary plus a day every March, May, July, August, October,
December and January.

### Halves, in integers, until the last step

A half day is the only fraction this trade uses, and `0.5 + 0.5 + 0.5` is not
1.5 in binary floating point. Everything is counted in **half-days** and divided
exactly once, at the end, in integer paise rounded half away from zero — the
same rule `Money` applies to rupees. `payroll_lines` stores `paid_half_days` and
`period_half_days` for the same reason; the API divides by two at the boundary
so a client never has to know.

`PayrollCalculator` is the only place any of this happens. The preview, the
posting and the stored payslip all go through it, because three implementations
of "what is Ramesh owed for September" would disagree eventually, and the
disagreement would surface as an employee holding a payslip that does not match
what they were handed.

---

## 4. The ledger

Two posting templates, both registered in `AccountingServiceProvider` alongside
every other one, so there is still exactly one file that says which types can
reach the ledger.

### Template I — a staff advance

```
Dr Staff Advance (1500)      the whole amount
  Cr Cash / Bank / UPI       per payment mode
```

**An asset, not an expense.** The workshop is owed the money back; it comes back
by being deducted from the next payroll rather than by anybody paying it in, but
that is a detail of *how* it is recovered, not of whether it is owed. Booking it
straight to Salary Expense would report the cost twice — once when the advance
went out and again when the month was run — and would leave the workshop with no
figure at all for what is currently out with its staff.

The control side is a **debit**, unlike a vendor payment: handing over the money
creates an asset. Both sides of a payment fall; here one falls and one rises.
That is the difference between paying a debt and making a loan.

### Template J — a month's payroll

```
Dr Salary Expense (5200)     the month's gross, everybody together
  Cr Staff Advance (1500)    whatever was recovered from earlier advances
  Cr Cash / Bank / UPI       what actually went out
```

**One voucher for the whole run**, not one per employee. That is what the event
is — a workshop pays its staff on the 7th — and it also keeps every wage in the
building out of a ledger that `READ:LEDGER` opens, which is a different grant
from `READ:STAFF` and deliberately so. Who got what is `payroll_lines`, which is
not recoverable from those three lines at all.

**The recovery credits Staff Advance rather than reducing the expense.** ₹5,000
advanced in August is a debit balance against the employee's name; September's
payroll expenses the full ₹18,000 they earned and hands over ₹13,000, and the
₹5,000 difference clears the advance. Netting it off the cost instead would
understate what the workshop spends on wages by exactly the amount it lends its
staff.

Both accounts were seeded from the first day of the product and sat unused for
eleven modules — see `SystemAccount::isDeferredToLaterPhase()`, which is now
empty and kept rather than deleted. This module posted to them without a
migration, without a backfill, and without a workshop having to do anything.

---

## 5. What is deliberately not built

### No salary-payable liability

A run is **settled in full at posting**: gross = advances recovered + the payment
split, and a run that does not balance is refused rather than plugged. A
workshop that pays on the 7th dates the run the 7th.

This is a deliberate omission, on the same judgement the purchase module makes
about landed cost: half a payables ledger is worse than none. Accruing salary
without per-employee settlement, or settlement without ageing, would produce a
liability account nobody could reconcile against anybody. If a workshop needs
this, it is a slice of its own with a control account, a settlement flow and a
statement — not a column.

The one edge it does handle: a month where every rupee earned had already been
advanced posts with **no payment split at all** — `Dr Salary Expense / Cr Staff
Advance`. That is why `TransactionType::Payroll` is *not* `isSettlement()`: a
run is the month's wage bill, and the split is only how the remainder was handed
over. Calling it a settlement would refuse to post a perfectly real payroll.

### No draft payroll

A parked payroll sheet is a set of figures derived from an attendance register
that keeps moving under it. Somebody would open a fortnight-old draft, see a
total, and pay a month that three subsequent absences had already made wrong —
with the stale figure looking exactly as authoritative as a fresh one.

So the sheet is computed on demand, checked on screen, and either posted or
abandoned. `payroll_runs` is written at the moment it posts and is a record of
what was paid rather than of what somebody intended to pay.

**The consequence to keep in mind: what the operator saw is not what is posted.**
`PayrollService::post()` recomputes the sheet from scratch; the only thing
carried over from the screen is the human decision — how much of each advance to
recover — which is exactly the part a machine cannot re-derive.

Correcting a run means **reversing** it, which cancels the entries by their
mirror image and frees the month to be run again. The payslips are kept: they
are the record of what was paid out and then taken back, and they stop counting
for advance recovery the instant the run leaves `posted`, because that read is
scoped to live runs.

### No overtime

Converting hours into money needs a standard-hours-per-day figure that no
workshop in this trade agrees on, and a figure that is wrong pays every overtime
hour wrong for ever. A workshop paying for a Sunday marks the Sunday present
rather than the week off.

### No stored advance balance

What is out with an employee is derived on every read: the advances posted
against them, less what posted payroll has recovered. A stored balance is the
same mistake as a stored party outstanding or a `qty_on_hand` column — it agrees
with the truth right up until one of the two is written without the other.

Because both sides read **posted only**, reversing an advance stops it counting
the instant it is cancelled, and reversing a run puts the advances back, with
nothing anywhere having to remember either.

---

## 6. The vocabulary is data, not code

`staff_designations` is the module's counterpart to the catalogue's Brand
Master, and it exists for the same reason: what the people in a workshop are
called is different in every workshop, nobody can write the list down in
advance, and a designation typed onto each employee is a master list nobody
maintains. Within a month there would be three spellings of "Helper", a filter
offering all three, and no single one of them counting the trade.

It carries **no default pay rate and no default basis**. It is tempting — every
Helper in a workshop is on roughly the same money — and it would be a second
place a wage came from. What somebody is paid is an agreement with that person,
and a default that quietly filled itself in would be wrong for the one employee
whose arrangement is different, silently, on a form nobody re-reads.

The two salary bases and the six attendance states are the other half of the
distinction: those are **code**, because each one changes the arithmetic. A
candidate seventh attendance state has to pass that test — "late", "on site" and
"training" are all real things that happen in a workshop and none of them changes
what is owed, so recording them here would be putting a diary in the payroll
input.

All four lists — bases, statuses, payment modes, designations — are published by
`GET /api/v1/staff/meta`, and none of them is written into a Blade template.

---

## 7. The module, on screen

One card, four sections, each an ordinary §2A workspace: `mountWorkspace()` is
called once per section root, so every one of them inherits the form/list swap,
the single switch control, the count badge and the Escape step. There is no
per-module flow code.

| Section | Form (level 1) | List (level 1) | Drawer (level 2) |
|---|---|---|---|
| Staff | add somebody | the staff list | one person: rate, advance, pay history |
| Attendance | the day sheet | the month register | — |
| Payroll | run a month | the months already paid | one run: every payslip |
| Advances | pay an advance | what has gone out | — |

Sections are mounted **lazily**, on the first click of their tab. A workshop that
only ever marks attendance never pays for the payroll sheet.

Each section's workspace registers its own Escape handler under a key of its own,
which the shell never looks up — the shell asks for `staff`. `pages/staff.js`
registers that one and delegates to whichever section is open; without it the
last-mounted section would answer for all four, and a press on the payroll list
would swap the attendance sheet.

The staff list is fetched once and held: the list, the advance form's picker, the
advance filter and the drawer all read the same rows, so opening Advances before
Staff does not fetch twice.

---

## 8. Attributing a sale to the people who did it — **no UI yet**

"Ramesh fitted it, Sunil wound it." One row per trade per invoice in
`transaction_staff`, which makes two real questions answerable: which of my
people did this motor, and how much has this person got through this month.

### What exists

The **backend is complete**: the migration, `TransactionStaff`, the repository
and its binding, `WorkAttributionService`, the `track_on_sales` flag on a
designation, the audit resource, and the delete guards that stop a designation
or an employee being removed while invoices are credited to them.
`TransactionController` syncs attributions when a sale is posted and when one is
revised, `StoreBillRequest` accepts a `staff` key, and two routes are live:

```
GET   /api/v1/staff/{employee}/work        what they got through, and the invoices
PATCH /api/v1/transactions/{transaction}/staff   correct who did the work
```

### What does not exist

**Any way to reach it.** `components/bill-document.js` — the one form all three
sale hosts mount — has no attribution controls, so no invoice ever sends a
`staff` key and `transaction_staff` is empty on every workshop. The Staff
module's drawer does not read `{employee}/work` either. Both endpoints answer
correctly and answer about nothing.

**Any test.** Nothing in `tests/` exercises attribution: the suite is green
because none of this code is on a path anything currently takes.

So it is a working back half waiting for its front half. Finish it by adding the
pickers to the shared bill document — one per designation whose `track_on_sales`
is set — and a "work done" block to the employee drawer; or remove the slice.
Do not build a second way to record the same fact.

### Two decisions worth keeping if it is picked up

- **Not columns on `transactions`.** That is a ledger table and a row in it is
  immutable once posted. A mis-picked fitter is not a financial error and must
  not be corrected the way one is — hence the separate `PATCH …/staff` route.
- **Not `transactions.employee_id`.** That column is spoken for and means
  something else: who a staff *advance* was handed to, stamped once and never
  changed, because re-pointing it would let one employee's advance be recovered
  from another's salary. Attribution is a label on work, there are several per
  invoice, and one column could hold neither fact honestly.

**It is not an input to pay.** Nothing in attribution reaches payroll, which
computes from a rate and an attendance sheet in one place. A throughput figure
that quietly became a piece rate would be a second source of truth for wages,
arrived at by accident.

## 9. Reference

### Tables

| Table | Holds |
|---|---|
| `staff_designations` | the trades, per workshop |
| `employees` | the people, their basis and rate, joined/left |
| `staff_attendances` | one mark per person per day — **sparse** |
| `payroll_runs` | one row per month paid, posted or reversed |
| `payroll_lines` | the payslips, every figure a snapshot |
| `transaction_staff` | attribution — data layer only, see §8 |
| `transactions.employee_id` | who an advance went to, write-once |

### Endpoints

All under `READ:STAFF` unless noted.

```
GET    /api/v1/staff/meta                       bases, statuses, modes, designations
GET    /api/v1/staff                            ?with_advances=1&with_attendance=1&period=YYYY-MM
POST   /api/v1/staff                            WRITE:STAFF
GET    /api/v1/staff/{employee}                 record + advance + attendance + payslips
PATCH  /api/v1/staff/{employee}                 UPDATE:STAFF — also {"left_on": "..."}
DELETE /api/v1/staff/{employee}                 DELETE:STAFF — only if nothing points at them

GET    /api/v1/staff/designations
POST   /api/v1/staff/designations               WRITE:STAFF
PATCH  /api/v1/staff/designations/{id}          UPDATE:STAFF — also {"is_active": false}
DELETE /api/v1/staff/designations/{id}          DELETE:STAFF

GET    /api/v1/staff/attendance                 ?date=YYYY-MM-DD | ?period=YYYY-MM
PUT    /api/v1/staff/attendance                 UPDATE:STAFF — the whole day at once

GET    /api/v1/staff/payroll
POST   /api/v1/staff/payroll/preview            writes nothing, reserves nothing
GET    /api/v1/staff/payroll/{run}
POST   /api/v1/staff/payroll                    WRITE:STAFF + WRITE:TRANSACTIONS
POST   /api/v1/staff/payroll/{run}/reverse      UPDATE:STAFF + WRITE:TRANSACTIONS

GET    /api/v1/staff/advances
POST   /api/v1/staff/advances                   WRITE:STAFF + WRITE:TRANSACTIONS
POST   /api/v1/staff/advances/{id}/reverse      UPDATE:STAFF + WRITE:TRANSACTIONS
```

### Refusals

| Code | Means |
|---|---|
| `EMPLOYEE_NAME_TAKEN` | two rows with one name split one person in two |
| `EMPLOYEE_IN_USE` | they have been marked, paid or advanced — archive instead |
| `DESIGNATION_NAME_TAKEN` | two spellings of one trade |
| `DESIGNATION_IN_USE` | somebody holds it, or invoices are credited to it |
| `PAYROLL_NOTHING_TO_PAY` | the month adds up to zero — usually an unmarked daily-wage sheet |
| `PAYROLL_RECOVERY_INVALID` | a negative recovery |
| `PAYROLL_RECOVERY_EXCEEDS_GROSS` | a payslip cannot end with the employee owing money |
| `PAYROLL_DOES_NOT_SETTLE` | recovery + split ≠ gross |
| `PAYROLL_MONTH_ALREADY_RUN` | reverse the run in the way first |
| `PAYROLL_ALREADY_REVERSED` | the month is already free |
| `PAYROLL_MONTH_NOT_STARTED` | payroll is run for a month that has been worked |

### Document series

`ADV/26-27/12` for an advance, `SAL/26-27/4` for a payroll run. `SAL` rather
than `PAY`, which is already the vendor payment series: a workshop asking what it
has spent on wages this year should not have to subtract its suppliers out of the
answer.
