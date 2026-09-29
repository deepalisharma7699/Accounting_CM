# Execution plan — finishing the product

The run that takes this from seventeen cards on and two off to **finished, end to
end, with the AI capture agent excepted**. Ten steps, three passes, one gate
between each.

**Done since this was written, on 7 September 2026.** **History** is on — it was
the read-mostly half of C7 and needed no re-flow to speak of, so it took
`mountWorkspace(..., { canCreate: false })` and the flag ahead of its step; C7 is
Workshops alone now. And the **go-live cleanup migration has been taken out of the
automatic path**: `database/manual/` is not scanned by `migrate`, the file carries
a guard that refuses unless it is asked for explicitly, and CLAUDE.md §4.5 is the
rule that keeps the next one out too.

**What this file is.** [implementation-roadmap.md](implementation-roadmap.md)
says *what* to build and what each thing must not rebuild. This says in *what
order*, behind which gates, and what "done" means for the whole. Where the two
disagree about the shape of a step, the roadmap wins and this file is wrong.
Where they disagree about order, this file wins and the roadmap gets corrected —
which has happened once already, and is recorded below.

**The AI capture agent is parked.** M15 was scheduled after the conversions and
is now out of scope entirely by the owner's decision on **7 September 2026**.
Part F of the roadmap stays where it is, unnumbered work waiting on a decision to
restart it. Nothing in this plan depends on it, and — this is the part worth
holding on to — nothing in this plan is *cheaper* if it is built first.

---

## The finish line

Six conditions, all of them testable. The product is finished when every one
holds.

1. **Every module in the registry is on.** `config/modules.php` carries no
   `'enabled' => false`, and `docs/hidden-modules.md` has no module sections left
   in it.
2. **Every endpoint has a caller, or no longer exists.** A route that answers and
   that no screen reaches is either given its screen or deleted; there is no
   third state, because the third state is what this audit kept finding.
3. **One test walks a workshop's day** and the invariants hold throughout it —
   the trial balance reconciles, stock value equals the Inventory account, no
   allocation exceeds its bill.
4. **A workshop can operate without a developer**, including the recoveries: a
   locked-out owner can get back in, and a new fitter can be given an account
   without somebody inventing a password for them.
5. **A customer can be handed every document the brief promised** — the invoice
   from any screen that raises one, and the statement of what they owe.
6. **`php artisan test --order-by=random` is green**, and the four status
   documents agree with the code.

---

## Scope

**In.** The three hidden modules (C6, C7), the verification step the workshop
redesign never ran (C8), and seven open points carried out of the audit (P1–P7).

**Out, and deliberately.** M15 and everything under it — voice, image capture,
the draft infrastructure, the resolution engine. Also everything in the
roadmap's **Part G**: Tally sync, a native mobile app, in-application regional
languages, SaaS billing, the smart speaker, batch and lot tracking, unit
conversion. Each of those is a phase, not a loose end, and three of them are
refusals with reasons already written down. There is a short honest note at the
foot of this file about which of them a *commercial* launch would still need,
because "finished" and "sellable" are not the same word.

**Numbering.** The conversion steps keep **C1–C8**. The open points take **P1–P7**
and nothing else in the project uses a P. Module numbers stay M1–M23 and are not
reused — the discipline that stopped M16–M20 from meaning two things at once.

---

## What the pre-flight already found

Every conversion so far has turned up something that was not a re-flow: C1 found
three settings the API accepted and no screen offered, C2 a missing `client_ref`,
C4 a payload the endpoint was throwing away, C5 a CSV export that had never once
produced a file. That is four out of five, which stopped being a coincidence some
time ago — so the sweep was run across the whole application before writing this
plan rather than one module at a time.

**Eight endpoints answer requests that no screen in this application makes.**
They are listed here once, and each is assigned to the step that owns it. The
scan was `route:list` against every path built in `resources/js`; dynamically
built paths were then checked by hand, which cleared three false positives
(`/reports/*`, `/transactions/purchase` and the job estimate pair are all
reached through a variable).

| Endpoint | What it is | Verdict | Step |
| --- | --- | --- | --- |
| `GET /parties/{party}/statement` | The customer and vendor statements of the brief's §14 and §15, with `PartyStatementService` behind them | **Needs a screen** | P1 |
| `GET /attachments/{attachment}` | One stored file — what a drawer would read | **Needs a caller** | C6 |
| `GET /jobs` | The queue index; only `/jobs/{uuid}` is polled | **Needs a caller** | C6 |
| `GET /parties/meta` | The roles a party can hold, published so filters are not hard-coded | Decide | P5 |
| `GET /stock/meta` | Movement kinds and position statuses, same purpose | Decide | P5 |
| `GET /transactions/counts` | Per-type counts for a tabbed screen — the tabs were deleted in C2 | Likely **delete** | P5 |
| `PUT /roles/{role}/permissions` | Grants ride in the role `PATCH` body instead | Likely **delete** | P5 |
| `PUT /users/{user}/status` | Status rides in the user `PATCH` body instead | Likely **delete** | P5 |

**`GET /parties/{party}/statement` is the serious one.** It is the same shape as
the gap C3 closed — a specified deliverable, built, tested, and unreachable
because nothing ever called it. A workshop cannot today send a customer the one
document a customer actually asks for: what you billed me, what I paid, what is
left. It is distinct from `{party}/ledger`, which the drawer *does* read and
which is the accountant's view; both were meant to exist and the controller says
so in as many words.

**Two smaller things, found the same way.** `IndexTenantRequest` validates
`sort` and `direction` and `pages/tenants.js` sends neither — a sort the API
supports and the screen does not offer (C7 owns it). And **CLAUDE.md §10 does not
record the platform administrator**, although one is seeded and present in the
local database: `admin@example.com`, no tenant, the `ADMIN` role, its password in
`.env` as `ADMIN_PASSWORD`. Whoever starts C7 will otherwise hit a wall — the
Workshops card is `workspace => false` and the demo owner cannot see it at all —
and the obvious way out of that wall is creating an account, which §10.3
forbids. Adding a row to §10 is part of C7.

---

## The order

```
──── Pass 1 · the last two cards ───────────────────────────────
C6   Uploads                          1 session
C7   Workshops  ✅    (History ✅)    1 session
                                      ▸ gate: every card is on
C8   One workshop day                 1 session
                                      ▸ gate: the modules agree

──── Pass 2 · the open points ──────────────────────────────────
P1   Party statements                 1–2 sessions
P2   Invoice delivery, extracted  ✅  1–2 sessions
P3   The advance receipt              ½ session
P4   A worklist that shrinks          1 session
P5   The orphan sweep                 ½ session
P6   Reset, invitation, mail          2–3 sessions
                                      ▸ gate: no developer required

──── Pass 3 · close ────────────────────────────────────────────
P7   Documents, memory, one green run ½ session
                                      ▸ gate: finished
```

Nine to twelve sessions. Strictly sequential inside a pass; the two passes are
sequential too, and the reason is the gate between them.

**C8 has moved, and that is a deliberate change to the roadmap.** Part E
schedules the workshop-day test last, after everything. It belongs immediately
after C7 instead, because the property it tests — that all the modules are
reachable and still agree with one another — *becomes true* at C7 and at no
later point. Written then, it protects the six steps that follow it. Written
last, it protects nothing and is the step most likely to be dropped when the end
is in sight. The roadmap's Part E has been corrected to match.

---

## The ritual every step follows

Written once, so no step below restates it.

**1 · Pre-flight, before a line is changed.** Grep the module's FormRequest
against its markup and list what the request accepts that no control sends. List
every endpoint in the module's route group and check each has a caller. Grep
each control's parameters against its endpoint's limits — the `per_page=1000`
against a cap of 200 that C5 found had been answering 422 since the day it
shipped. Anything this turns up is *part of the step*, not a follow-up.

**2 · Build to the shape in Part E**, and read that step's **do not rebuild**
list first. Some of what these screens do has been taken over by a card that is
already on, and converting the whole of an old screen is how a second
implementation gets written by accident (§5.1).

**3 · Verify against the step's checklist** in Part E, plus §8.1 for every
affected feature and §8.2 for anything that touches stock.

**4 · Ship, which is five things and not one.** Flip `'enabled' => true`. Move
that module's coverage in `PagesRenderTest` from `$this->view('modules.x')` to
`$this->get('/modules/x')`, which asserts the route as well as the markup.
**Delete** its section from `docs/hidden-modules.md` rather than marking it done.
Update `CLAUDE.md`'s "What is left" paragraph and `config/modules.php`'s comment.
Update the `level-1-shell-migration` memory. A status document that has to be
checked against the code is worse than none.

---

# Pass 1 · The last three cards

## C6 · Uploads

**Shape, and where it is written.** Part E of the roadmap. In one line: the
upload *is* the create act, so the drop target is the form and the library is
behind "Show list", with the in-flight queue kept visually apart from the stored
library — a file still travelling is not yet one of the workshop's records.
Uploads is **not** a read-mostly module; do not give it the §2A.10 treatment.

**What the pre-flight found.** Two endpoints with no caller.
`GET /attachments/{attachment}` is the drawer this module does not yet have —
a stored file opens in a modal today, and level 2 is where one record belongs.
`GET /jobs` is the queue index, and its absence is a real defect rather than an
unused route: `pages/uploads.js` only ever calls `watchJob()` on a row it
uploaded *in this tab*, so a file still processing when the module is reopened —
or one uploaded from the counter's other browser — shows as whatever status the
list happened to carry, with nothing following it. Call `/jobs` once on open to
rebuild the queue, then watch each unsettled row.

**Do not rebuild.** `AttachmentService`, the sniffed media type, the
read-back-before-ready rule, or `watchJob()` in `job-progress.js` — the poller
exists and this module is its only consumer. Do **not** add an `attachments`
resource to the data bus: nothing else in the application attaches a file, the
module's own reload after its own write is sufficient, and a bus row nothing
listens to is what §7.1 refuses. Add it the day a bill drawer can attach
evidence.

**Risk: low.** 340 lines of page JS, 72 of markup, no other module reads its
data, and its grant is already seeded.

**Done when** a supplier's bill can be photographed at the counter and found
again — and when an upload that was still processing at the end of one session
is still being followed at the start of the next.

---

## C7 · Workshops

**History is done**, on 7 September, ahead of this step: it was one filtered list
with no create, no drawer and no detail modal, so the conversion was a
`data-ws-list` wrapper and `mountWorkspace(..., { canCreate: false })`. It was
worth taking out of order because the trail is the whole safeguard on the one
write in this application that edits a posted document, and until the card went on
that safeguard existed with nobody able to read it. What is left here is Workshops:
§2A.1, with the owner block all-or-nothing.

**What the pre-flight found.** `IndexTenantRequest` validates `sort` and
`direction`; the screen sends neither. Wire the column headers rather than
delete the parameters — the API caps and validates them already, and a platform
list is the one place sorting by created date earns its keep.

**The credential wall, and how it is got round.** Workshops is
`'workspace' => false`: it is the one module about *other people's* books, and
`owner@demo.test` cannot see the card at all. A platform administrator is
already seeded in the local database — `admin@example.com`, no tenant, the
`ADMIN` role — and its password is in `.env`. **Sign in as that account to
verify this step**, and add it to CLAUDE.md §10 as a second row so the next
person does not conclude they need to create one. Do not create an account
(§10.3), and do not reset either password.

**Risk: low, with one exception.** Provisioning a workshop writes a tenant *and*
an owner in one act, and half of that is a workshop nobody can sign in to. It is
covered by a test; run it.

**Done when** the platform can onboard and suspend a workshop, and
`config/modules.php` contains no `'enabled' => false`.

> **Gate — every card is on.** Nineteen modules, nineteen cards.
> `docs/hidden-modules.md` now describes an empty set: rewrite it as the record
> of what hiding cost and what was found on the way out, or delete it and fold
> its two surviving notes — the removed dashboard service, and what "off" meant —
> into the roadmap. Do not leave it describing modules that are on.

---

## C8 · One workshop day

The step the workshop redesign planned and never ran, moved up to here for the
reason given above. Everything else converts a screen; this one proves the
modules still agree with one another once they are all reachable.

- [ ] One `WorkshopFlowTest` walking a single day: purchase 10 bearings → sell 3
      → a job consuming 2 → cancel one → return one → part-pay the invoice →
      submit twice → over-sell → one combined goods-and-labour bill
- [ ] The invariants asserted *throughout* rather than at the end: the trial
      balance reconciles, stock value equals the Inventory account, every
      allocation sums to no more than its bill's total
- [ ] Every module with `'enabled' => true` answers on `/modules/{key}` and has
      an entry in the lazy `import()` registry in `shell.js` — so a flag flipped
      without its fragment cannot ship
- [ ] `docs/workshop-flow.md` written: the technical summary that plan owed
- [ ] `php artisan test --order-by=random` green

**Why it is worth a session of its own.** Every scenario in that list is already
covered somewhere. What is not covered is the *sequence* — the state each one
leaves behind for the next, which is exactly where a figure on one screen starts
disagreeing with the same figure on another. Assert the invariants between the
steps, not after them, or the test proves only that the last operation was
right.

> **Gate — the modules agree.** From here, every step in Pass 2 must leave this
> test green, and it is the fastest way to find out that one of them did not.

---

# Pass 2 · The open points

## P1 · Party statements — the document a customer actually asks for

**The gap.** `GET /parties/{party}/statement` has no caller. It is M16's
deliverable for the brief's §14 and §15 — headline totals, then one row per
invoice saying what it was worth and what is left on it, then the receipts that
settled them — with `PartyStatementService` behind it and tests over it. A
workshop cannot send a customer the one document a customer asks for.

**It is worse than a missing screen, and this is the part that looks right.**
The counterparty drawer *has* a control called **Statement**. It opens
`#party-ledger-modal`, whose heading reads "Statement", and what it renders is
`GET /parties/{party}/ledger` — the accountant's view, every entry with a running
balance. The two were always meant to be different documents; the controller says
so in as many words. So the missing thing is not merely absent, it is wearing
another document's name, and anybody who went looking concluded it was built.

**And it is in the wrong surface.** That modal is a paginated table with its own
Previous and Next buttons inside a dialog — permitted by §2.2's nesting rule and
precisely what §2.1 says must never go in one. It has been there since before the
migration.

**The work.** Retire `#party-ledger-modal`. Both documents become **tabs in the
drawer**, beside Overview, History, Payments and Activity: **Statement** (what
you billed me, what I paid, what is left) and **Ledger** (every entry, running
balance). Both behind `READ:LEDGER`, both *removed* rather than blanked when the
grant is absent — the rule C5 settled on the trial balance, because a table of
dashes is a claim about the books rather than about the reader. The party
position tile stays where it is under `READ:PARTIES` alone; the line falls
between one figure and the entries behind it, never between the name and the
money.

**Do not rebuild.** `PartyStatementService`, `components/party-position.js` for
the position line, or the pagination frame in `components/report-statements.js`.

**Size: 1–2 sessions.** Risk: low, in a module that is on — regression-test the
drawer's other four tabs.

**Done when** a customer can be handed a statement, and nothing in the product
calls the ledger by that name.

---

## P2 · Invoice delivery, extracted — so Jobs can hand over an invoice ✅

**Shipped 8 September 2026.**

**The gap it closed.** A job's invoice could not be printed or shared from the
Jobs card. C4 raised the bill and deliberately stopped there: `#invoice-preview`
was Sales' drawer plus roughly 415 lines of `pages/sales.js` — the delivery
state, the borrow-and-release custody of the one invoice sheet, print, the share
link, the WhatsApp message, revocation — and the one-sheet rule means the second
module that hands a customer a document **borrows** that machinery rather than
mounting a second copy of it.

**What was done.** `pages/sales.js` lines ~1056–1470 are now
`components/invoice-delivery.js`: `deliver()`, `loadInvoice()`, the sheet custody
pair, `printInvoice()`, `bindPrintCustody()`, the preview and the share dialog. A
host passes the posted document and what to do on close; the component owns the
drawer, the sheet and the endpoint. Sales mounts it and lost the code. Jobs
mounts it after `{job}/bill` posts, and the job bill's "number and total above
the cleared form" is now that line *plus* the preview every other posted invoice
gets — with **Print or share it** on it, and every invoice ever raised off a job
openable again from the job card's own list.

The share dialog moved with it. It was `#sales-share-modal`, declared in the
Sales fragment; it is `partials/invoice-share-modal.blade.php` in the layout now,
beside `#invoice-preview` and `#invoice-print`, because the shell caches a
module's root **detached** and a dialog inside Sales is not in the page while
Jobs is open. `#confirm-modal` went to `z-index: 60` in the same change: it is
level 3 and it was painting *behind* the z-55 share dialog, so "Stop sharing this
invoice?" was being asked underneath the panel that asked it.

**The three rules that must survive the move**, because each is wrong in a way
that looks right and each is already written down. The sheet is **one node**,
mounted as a direct child of `<body>`; the print rule keeps whichever child of
`body` *contains* the document, so a second copy under `<main>` prints the whole
application around the invoice. Custody is released on Print, on close, on
`beforeprint` **and** on `matchMedia('print')` — Safari has the last instead of
the events. And the payload comes from `InvoiceDocumentService`, never from
`TransactionResource`: the internal one carries cost, margin, `below_cost`, the
ledger entries and the stock movements, and none of that may reach the person the
workshop sells to.

**Size: 1–2 sessions. Risk: the highest in this plan.** It is a refactor of the
busiest module in the product, with no behaviour change to show for it. Do it in
one sitting, against C8, and verify both hosts print — including a browser print
taken with the preview open, which is the case the custody pair exists for.

**Verified.** A job's invoice prints and shares from the Jobs card, and
`pages/sales.js` contains no invoice sheet code at all —
`test_the_shell_carries_one_invoice_share_dialog_above_the_preview` and
`test_no_module_fragment_declares_a_share_dialog_of_its_own` hold both shut.

---

## P3 · The advance receipt

**The gap.** A settlement cannot be deliberately left unallocated. An empty
`allocations` array means "oldest first" to the server, not "allocate nothing" —
on the way in as well as on `POST /{id}/allocate` — so the drawer refuses to send
a cleared grid rather than silently doing the opposite of what it looks like.
That refusal is correct and should stay. What is missing is a way to say the
other thing.

**A workshop takes deposits.** A customer pays ₹5,000 against a rewind that has
not been billed yet; that receipt belongs to no invoice, and applying it
oldest-first puts it on the wrong one. This is a real case, not a preference.

**The work.** Give the request an explicit intent rather than inferring one from
an empty array: `allocate: 'oldest_first' | 'as_listed' | 'none'`, defaulting to
`oldest_first` so every existing caller keeps its behaviour and the default
becomes visible instead of implied. `none` posts the settlement on account. The
drawer gets the third option, worded as what it does — "Hold on account, do not
apply to any bill". Insights already reports an unallocated receipt as a worklist
and still refuses to guess; it now has something true to report.

**Size: ½ session.** Risk: low, but it touches `StoreSettlementRequest`, which
two routes share — check both.

---

## P4 · A worklist that shrinks

**The gap.** C3 removed drafts from the settlement screens on the principle that
money that has moved is a fact rather than a work in progress. The shared bill
document still offers **Save as a draft**, so Sales and Purchase can still park
one, and Insights' parked-draft tab is therefore a list that grows and never
shrinks: it names a document, its age and whether it has gone stale, and offers
no way to do anything about it.

**The decision, and the recommendation.** Removing the control from Sales and
Purchase would make the rule uniform, and it is the wrong call: a counter clerk
half-way through a forty-line bill when the customer walks off genuinely needs to
park it, which is not the same act as parking money that has already moved. Keep
the draft; **give the worklist teeth**.

**The work.** A draft row opens the document in its own module — `#sales?draft=`,
the pattern C4 established when Customers' "Create sale" became a module swap
with `?party=` — where it can be posted or discarded. A parked draft is re-priced
and re-costed at the moment it posts, which the tab already says; the module must
honour that on open rather than trusting the parked figures.

**Size: 1 session.** Done when a parked draft can be cleared from the screen that
reports it, and the count goes down.

---

## P5 · The orphan sweep

Five endpoints from the pre-flight table, each needing a keep-or-delete decision
and none needing a screen. The rule is the one this plan's finish line states: an
endpoint that answers and that nothing reaches is given a caller or removed,
because the third state is what the audit kept finding.

- **`GET /transactions/counts`** — built for a tabbed transaction screen; C2
  deleted the tabs. **Delete it**, along with the repository method behind it if
  nothing else calls that.
- **`PUT /roles/{role}/permissions`** and **`PUT /users/{user}/status`** — both
  are second ways to do what the resource's own `PATCH` already does, and the
  `PATCH` is the one with a caller. **Delete them**, and check the permission
  catalogue does not name a grant that now guards nothing.
- **`GET /parties/meta`** and **`GET /stock/meta`** — these publish vocabulary so
  a client is not holding a hard-coded copy that drifts, which is the rule the
  catalogue rebuild exists to enforce. The screens *are* holding hard-coded
  copies. **Give them their callers** rather than deleting the endpoints.

**Size: ½ session.** Every deletion needs its test deleted with it and the suite
run; a route removed while a feature test still asserts it is a red build, which
is the cheap way to find the caller you missed.

---

## P6 · Password reset, invitation, and mail that leaves the building

**The gap, carried from M1 and never scheduled.** There is no forgot-password
flow, so a locked-out owner has to call whoever runs the server. There is no
invitation: `POST /users` sets a password directly, so an owner has to invent one
for each fitter and then transmit it somehow. Both are blocked on the third gap —
`MAIL_MAILER=log`, which means nothing this application sends has ever left the
building.

**Why it is in this plan and 2FA is not.** Finish-line condition 4 is that a
workshop can operate without a developer. A recovery path that ends in a phone
call to the developer fails it. 2FA does not: passkeys already carry a second
factor's worth of assurance on every device that has one, and the password is the
fallback rather than the front door. It stays in Part G.

**The work.** A real mail transport in `.env` and `config/mail.php`. Reset issues
a single-use, expiring token — `password_reset_tokens` already exists, from the
framework's own migration — and lands on a page outside the shell, the same class
of route as `/i/{token}` and admitted by §1.5 for the same reason. The invitation
is `POST /users` without a password: the account is created inactive, the invitee
sets their own, and the account activates on first sign-in.

**Three rules this must not break.** Every way into a session goes through
`AuthService::startSession()`, never round it — a third way in that skipped it
would let a suspended employee back in with a link. Both flows answer **one
message for every cause**, the way the password path answers identically for an
unknown email and a wrong password; a reset form that says "no such account" is an
account enumerator. And an invitation is not a way to grant yourself a way in: it
stays behind `WRITE:USERS`, tenant-scoped like everything else in that controller.

**Size: 2–3 sessions.** It needs a decision from you and a credential — see below.

> **Gate — no developer required.** A workshop can be onboarded, staffed,
> recovered and operated by the people who own it.

---

# Pass 3 · Close

## P7 · The documents, the memory, and one green run

Not a formality. Four documents describe the state of this product and each has
been wrong at least once during the conversion, always in the same direction —
they described work as pending after it shipped, or as finished before it did.

- [ ] `docs/hidden-modules.md` — no module sections remain. Either rewrite it as
      the record of what hiding cost and what the conversion found, or delete it
      and fold its two surviving notes into the roadmap.
- [ ] `docs/implementation-roadmap.md` — Part E's steps all ✅; Part F carries the
      note that M15 is parked rather than next; Part G carries the auth rows that
      P6 closed, removed.
- [ ] `CLAUDE.md` — the "What is left" paragraph says nothing is left; §10 has the
      platform administrator row added at C7; the C1–C8 block reads as history.
- [ ] `config/modules.php` — the `## enabled` comment says nineteen on, none off.
- [ ] The `level-1-shell-migration` memory — the conversion is finished, and what
      generalises from C6, C7 and P1–P6 is written down where the next session
      will find it.
- [ ] `php artisan test --order-by=random`, green, twice.
- [ ] The published status page refreshed against the code one last time.

---

## Decisions this plan makes, so nobody re-opens them mid-step

| Decision | Taken as | Reversible? |
| --- | --- | --- |
| C8 runs after C7, not last | The property it tests becomes true at C7; written then, it guards the six steps after it | Yes, but the guard is the point |
| Sales and Purchase **keep** Save as a draft | Parking a half-written bill is not the same act as parking money that has moved; the fix is a worklist with teeth (P4) | Yes |
| A settlement gains an explicit `allocate` intent rather than reading meaning into an empty array (P3) | Default stays `oldest_first`, so every existing caller is unchanged and the default becomes visible | Yes |
| 2FA stays out | Passkeys carry it on every device that has one; the password path is the fallback | Yes |
| `/parties/meta` and `/stock/meta` get callers; the other three orphans are deleted | Publishing vocabulary is a rule the catalogue already enforces; a second endpoint doing what a `PATCH` does is what §5.1 refuses | Yes |
| Statement and Ledger become drawer tabs, and `#party-ledger-modal` is retired | A paginated table inside a dialog is what §2.1 forbids, and the modal wears the wrong document's name | No — the modal is a defect |

## What this plan needs from you

**One credential and one choice, both at P6, and nothing before that.** Steps C6
through P5 need nothing that is not already in the repository.

- **A mail provider.** `MAIL_MAILER=log` today. Password reset and invitation are
  the two features in this product that do not work at all without mail leaving
  the building, and no amount of code substitutes for an account somewhere that
  will send it. SMTP credentials or an API key for whichever service you use.
- **Whether P6 is in this run at all.** It is the only step in Pass 2 that is not
  about the card grid, and it is the largest. Dropping it finishes the *product*
  and leaves the *operations* gap: a locked-out owner still telephones somebody.
  The plan recommends keeping it, because finish-line condition 4 is the one a
  paying workshop notices first.

## What "end to end" still does not include

Stated plainly, because "finished" and "sellable" are not the same word. None of
this is in the plan above, and each is a phase rather than a loose end.

| Not built | Why it is not here |
| --- | --- |
| **M15 — the AI capture agent** | Parked by decision, 7 September 2026. Part F stands as written; nothing in this plan depends on it, and nothing in it gets cheaper by building the agent first |
| **Multi-tenant SaaS billing** | The one *commercial* blocker. Tenancy, suspension and the platform surface all exist; what a workshop is charged does not. If this product is to be sold rather than run, this is the next phase after the plan above |
| **Tally sync** | The journals are already in Tally-compatible double-entry form, which is the whole of the preparation done. The connector is real work and Tally is finicky about XML |
| **Regional languages in the application** | Only the public site is bilingual. The application has no translation layer; adding one is a sweep across every screen and belongs in its own phase, never as a side effect of another |
| **A native mobile app** | The web application is already responsive on phone, tablet, laptop and desktop (§7.3). A native app earns its keep only for the capture agent's microphone and camera, so it belongs with M15 or after it |
| **Batch/lot tracking, unit conversion, purchase orders, quotations, challans, piece rates** | Refused on purpose, each with its reasoning recorded where the module lives. Half of any of them is worse than none |

---

## Tracking

| Step | What | Size | Status |
| --- | --- | --- | --- |
| **C6** | Uploads | 1 | ⬜ |
| **C7** | Workshops (History ✅ 7 Sep) | 1 | ✅ |
| **C8** | One workshop day | 1 | ⬜ |
| **P1** | Party statements | 1–2 | ⬜ |
| **P2** | Invoice delivery, extracted | 1–2 | ✅ 8 Sep |
| **P3** | The advance receipt | ½ | ⬜ |
| **P4** | A worklist that shrinks | 1 | ⬜ |
| **P5** | The orphan sweep | ½ | ⬜ |
| **P6** | Reset, invitation, mail | 2–3 | ⬜ |
| **P7** | Documents, memory, one green run | ½ | ⬜ |

Sizes are working sessions, not days. Tick a row here when its step ships, and
correct the roadmap in the same commit — the two files disagreeing is the failure
mode this plan exists to end.
