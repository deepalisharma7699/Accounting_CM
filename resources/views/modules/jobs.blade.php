{{--
    Jobs — the bench. M19, and the brief's §16 to §18 and §23. C4.

    ```
    card → BOOK SOMETHING IN          ← always lands here (§2A.1, §2A.5)
         → "Show list (12)"           → what is on the bench
         → row → drawer (level 2)     → the pipeline, the parts, the estimate
                                      → Generate bill → the document, at level 1
                                      → confirm (level 3)
    ```

    ## Why the bill is a level-1 surface and not a drawer state

    Because it is the document, not a view of one. `partials/bill-document.blade.php`
    is a two-column form with a searched item picker, a line table, a payment
    split and a sticky totals panel; §2.1 calls a list with filters and forms
    inside a dialog a scroll trap, and this is the same animal. So the form
    surface holds **two panes** — booking something in, and billing one — with
    exactly one shown, the same judgement §2A.2 makes one level up about the form
    and the list.

    The document itself is never written here. It is the shared partial, the same
    one Purchase and Sales mount, and it must not be copied: the fields, the
    error slots and the `data-` hooks are a contract with
    `components/bill-document.js`, and a second copy is a second place for that
    contract to go stale (§5.1).

    ## The one thing this screen has to keep saying

    **A part written onto a job moves no stock.** It is said where parts are
    added, because it is obvious in a design document and baffling at a counter:
    adding a part reserves nothing, and the bearing leaves the shelf when the
    invoice posts. That is decision D2, and the invariant everything in M8 rests
    on is that stock only ever moves through a posted transaction.

    ## What comes in is not always a motor

    Most of it is. A good deal of it is a cooler, a table fan or a pump, and now
    and then it is something nobody expected. So the intake form asks **what kind
    of thing** came in and then asks what *that* kind is described by — the
    catalogue's own `item_categories` and `item_attributes`, published by
    `GET /workshop-jobs/meta` and drawn by `components/attribute-fields.js`, the
    same renderer the Items create form uses.

    There is deliberately **no HP box and no Phase select in this file**, and
    there must not be one again. They were two motor fields under a heading that
    said "The motor", which told the counter it had the wrong screen every time a
    cooler came in — the same failure the catalogue's vocabulary rule already
    records against a hard-coded product type and a typed brand. A workshop that
    starts repairing something new adds a category from the Items card, and the
    bench asks the right questions with no deployment.

    ## What is not here

    **No quick "create a new item" on the job card's part picker.** The one
    quick-add dialog in this module belongs to the bill document, which is a
    level-1 surface — so it is detached exactly when the drawer is open over the
    list, and a second copy of `#quick-item-modal` would be two nodes with one
    id. A part that is not in the catalogue is added from the Items card, which
    is one click away, and the picker says so.

    **No `item_id` on the intake form.** The column exists and the request
    accepts it — the catalogue row for an exact product the workshop *sells* — but
    nothing in the application reads it, and a field written by a form and read
    by nothing is a field that goes wrong quietly. The free text beside it is the
    record of what actually came through the door, which is the question the
    counter is answering.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, form mode — where the module lands (§2A.1). Two panes, exactly
         one of them shown; `pages/jobs.js` swaps them. --}}
    <div data-ws-form>

        {{-- ------------------------------------------------------------------
             Pane 1 — booking something in.
             ------------------------------------------------------------------ --}}
        <div data-job-intake>

            {{--
                What the last invoice off a job did.

                A line above the cleared form rather than a toast, for C3's
                reason: §2A.8 empties the form the instant something posts, and a
                toast is gone before somebody has finished reading the number.
            --}}
            <div data-job-outcome
                 class="mb-4 hidden items-start gap-3 rounded-[10px] border border-emerald-200 bg-emerald-50
                        px-4 py-3 text-[0.8125rem] text-emerald-900" role="status"></div>

            <div data-job-form-slot>
                <form id="job-form" novalidate class="space-y-4">

                    {{-- Only in the drawer, where the same node is the edit form. --}}
                    <header class="hidden items-start justify-between gap-4 border-b border-border pb-3"
                            data-form-chrome="modal">
                        <div>
                            <h3 class="text-sm font-bold text-foreground">Correct the job card</h3>
                            <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                                What came in, the complaint and when it was promised. Whose it is and
                                when it arrived are what the job was opened on, and are not edited here.
                            </p>
                        </div>
                    </header>

                    <section class="surface form-card">
                        {{-- Whose it is, and when it arrived: both are settled
                             when the job is opened, so neither travels into the
                             drawer with the rest of the fields. --}}
                        <div data-form-chrome="inline">
                            <h3 class="text-sm font-bold text-foreground">Whose it is, and when it came in</h3>
                            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                                A job number is issued straight away, so there is something to write on the
                                casing before it goes on the bench.
                            </p>

                            <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_11rem]">
                                <div data-job-party-host></div>

                                <label class="field">
                                    <span class="field-label">Received</span>
                                    <input type="date" name="received_date" class="field-input">
                                    <span class="field-error hidden" data-error-for="received_date"></span>
                                </label>
                            </div>
                        </div>

                        <label class="field mt-4">
                            <span class="field-label">What the customer reported</span>
                            <textarea name="complaint" class="field-input" rows="3" maxlength="1000" required
                                      placeholder="Winding burnt, not starting"></textarea>
                            <span class="field-error hidden" data-error-for="complaint"></span>
                        </label>
                    </section>

                    {{-- What came in, and every field of it optional — its kind
                         included. A pump is wheeled in at four in the afternoon
                         by a driver who does not know its brand, and a form that
                         refused to book it in would be a form that got a job card
                         written on paper instead.

                         There is no HP box and no Phase select here any more, and
                         there must not be again: a workshop takes in coolers,
                         fans and pumps as well as motors, and two fields that
                         mean nothing about any of them told the counter it had
                         the wrong screen. What is asked comes from the chosen
                         kind's own question set, published by
                         GET /workshop-jobs/meta and drawn by
                         components/attribute-fields.js. --}}
                    <section class="surface form-card">
                        <h3 class="text-sm font-bold text-foreground">
                            What came in <span class="font-normal text-muted-foreground">(whatever is known)</span>
                        </h3>
                        <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                            A motor most days, a cooler or a fan the rest of the time. Say which and the
                            form asks what that kind is described by.
                        </p>

                        <div class="mt-4 form-grid">
                            {{-- Written from the server's category list. Never a
                                 list of kinds in this template: a copy in the
                                 markup goes stale the moment an admin adds one,
                                 which is the failure the catalogue was rebuilt to
                                 remove. --}}
                            <label class="field">
                                <span class="field-label">
                                    Kind <span class="font-normal text-muted-foreground">(optional)</span>
                                </span>
                                <select name="category_id" class="field-input" data-job-kind>
                                    <option value="">Not sure yet</option>
                                </select>
                                <span class="mt-1.5 block text-xs text-muted-foreground" data-job-kind-hint></span>
                                <span class="field-error hidden" data-error-for="category_id"></span>
                            </label>

                            <label class="field">
                                <span class="field-label">Brand</span>
                                <input type="text" name="brand" class="field-input" maxlength="60"
                                       placeholder="Crompton" autocomplete="off">
                                <span class="field-error hidden" data-error-for="brand"></span>
                            </label>

                            <label class="field">
                                <span class="field-label">Model</span>
                                <input type="text" name="model" class="field-input" maxlength="60"
                                       autocomplete="off">
                                <span class="field-error hidden" data-error-for="model"></span>
                            </label>

                            <label class="field">
                                <span class="field-label">Serial number</span>
                                <input type="text" name="serial_no" class="field-input font-mono" maxlength="60"
                                       autocomplete="off">
                                <span class="mt-1.5 block text-xs text-muted-foreground">
                                    The one field that identifies this one rather than its kind — and the
                                    one a customer quotes down the phone.
                                </span>
                                <span class="field-error hidden" data-error-for="serial_no"></span>
                            </label>
                        </div>

                        {{-- What the chosen kind asks about. Empty and hidden
                             until there is a kind, because the questions belong
                             to it: a motor is described by a rating and a phase,
                             a lamp by a wattage, and nothing sensible is asked of
                             a thing nobody has named yet. --}}
                        <div class="mt-5 hidden border-t border-border pt-5" data-job-specs-section>
                            <h4 class="text-[0.8125rem] font-semibold text-foreground">
                                Its specification
                                <span class="font-normal text-muted-foreground">(all optional)</span>
                            </h4>
                            <p class="mt-1 text-xs text-muted-foreground">
                                What this kind is described by, from the Category Master. Fill in whatever
                                the plate says — nothing here is insisted on.
                            </p>

                            <div class="mt-3 form-grid" data-job-specs></div>
                        </div>

                        <div class="mt-5 form-grid border-t border-border pt-5">
                            <label class="field">
                                <span class="field-label">
                                    Promised back <span class="font-normal text-muted-foreground">(optional)</span>
                                </span>
                                <input type="date" name="promised_date" class="field-input">
                                <span class="mt-1.5 block text-xs text-muted-foreground">
                                    What the bench is measured against. A job past it is flagged on the list.
                                </span>
                                <span class="field-error hidden" data-error-for="promised_date"></span>
                            </label>

                            <label class="field">
                                <span class="field-label">Notes</span>
                                <textarea name="notes" class="field-input" rows="2" maxlength="1000"></textarea>
                                <span class="field-error hidden" data-error-for="notes"></span>
                            </label>
                        </div>
                    </section>

                    {{-- Absent rather than blanked for a reader: a disabled Save
                         asks somebody to work out for themselves why it will not
                         press, and the workspace lands such a caller on the list
                         so this surface is never the one they are looking at. --}}
                    <div class="flex items-center justify-end gap-3" data-form-chrome="inline"
                         data-requires-permission="WRITE:WORKSHOP_JOBS">
                        <button type="submit" class="btn btn-primary">Book it in</button>
                    </div>

                    <div class="hidden items-center justify-end gap-2 border-t border-border pt-4"
                         data-form-chrome="modal">
                        <button type="button" class="btn btn-ghost" data-job-edit-cancel>Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ------------------------------------------------------------------
             Pane 2 — the invoice off a job.

             The banner is here rather than in the shared partial for Purchase's
             reason: it is the one thing about this form that is the Jobs
             module's own, and the partial stays exactly the document three
             screens share. It is loud on purpose — pressing "Review & post"
             here issues an invoice against a job and marks its parts as billed,
             which is a materially different act from writing a sale.
             ------------------------------------------------------------------ --}}
        <div data-job-bill class="hidden">

            <div data-job-bill-banner
                 class="mb-4 flex flex-wrap items-start gap-3 rounded-[10px] border border-amber-200
                        bg-amber-50 px-4 py-3">
                <span class="mt-0.5 shrink-0 text-amber-600"><x-icon name="alert-triangle" :size="16" /></span>

                <div class="min-w-0 flex-1 text-[0.8125rem] text-amber-900">
                    <p class="font-semibold" data-job-bill-title></p>
                    <p class="mt-1" data-job-bill-note></p>
                </div>

                <button type="button" class="btn btn-secondary btn-sm shrink-0" data-job-bill-cancel>
                    Leave it unbilled
                </button>
            </div>

            @include('partials.bill-document')
        </div>
    </div>

    {{-- Level 1, list mode. Exactly one of the two surfaces is in the DOM at a
         time — the other is held detached by the workspace, so its filters and
         its fetched rows survive every trip to the form and back (§2A.2, §2A.6). --}}
    <div data-ws-list>

        {{-- Tabs from the server's own status catalogue, with counts. Written
             from GET /workshop-jobs/meta rather than listed here, so a state
             added to the enum appears without this file being touched. --}}
        <div class="tab-strip mb-4 flex flex-wrap gap-1" data-job-tabs role="tablist"></div>

        <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
            <div class="search-pill min-w-56 flex-1">
                <x-icon name="search" :size="16" />
                <input type="search" data-job-search class="w-full bg-transparent text-sm outline-none"
                       placeholder="Job number, customer, kind, serial number or complaint…"
                       aria-label="Search jobs">
            </div>

            <button type="button" data-job-overdue class="pill" aria-pressed="false">
                Past their promised date
            </button>

            <button type="button" data-job-clear class="btn btn-ghost btn-sm">Clear</button>
        </div>

        <div class="surface overflow-hidden">
            <div class="overflow-x-auto">
                {{-- §23's columns. `Billed` is what has come off the job, derived
                     from the invoices that point at it — there is no total column
                     on the jobs table, deliberately. --}}
                <table class="w-full min-w-[940px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                                   tracking-wide text-muted-foreground">
                            <th class="px-4 py-3 font-semibold">Job</th>
                            <th class="px-4 py-3 font-semibold">Customer</th>
                            <th class="px-4 py-3 font-semibold">What came in</th>
                            <th class="px-4 py-3 font-semibold">Complaint</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            <th class="px-4 py-3 text-right font-semibold">Billed</th>
                            <th class="px-4 py-3 font-semibold">Received</th>
                        </tr>
                    </thead>
                    <tbody data-job-body></tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <p data-job-summary class="text-[0.8125rem] text-muted-foreground"></p>

                <div class="flex gap-2">
                    <button type="button" data-job-prev class="btn btn-secondary btn-sm" disabled>Previous</button>
                    <button type="button" data-job-next class="btn btn-secondary btn-sm" disabled>Next</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{--
    The job card — level 2, one record over the list.

    The pipeline, the motor, the parts, the estimate and the way to a bill.
    Correcting the card is a *state* of this surface rather than a form stacked
    over it: §2.2 allows nothing above level 3, and a form on a drawer would be
    level 3 doing level 2's job. The same node as the create form travels into
    it — `adoptForm()`, so there is never a second set of these fields.

    Nothing opens over this except the confirmations for the acts that cannot be
    undone, which is exactly what level 3 is for.
--}}
<div id="job-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="job-drawer-title">
    <div class="drawer-panel max-w-[720px]">

        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-amber-50
                                 text-amber-600">
                        <x-icon name="wrench" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="job-drawer-title"
                            class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p data-drawer-subtitle class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    {{-- Two of them: where the motor has got to, and whether it
                         has been invoiced. Neither implies the other. --}}
                    <span class="flex flex-wrap items-center justify-end gap-1"
                          data-drawer-status></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div data-drawer-body></div>

            {{-- Where the create form is adopted for an edit. Empty otherwise —
                 the fields live in exactly one place in this module. --}}
            <div class="hidden px-6 py-5" data-job-edit-slot></div>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-muted px-6 py-4" data-drawer-actions></div>
    </div>
</div>
