
{{-- The bench — M19's screen, and the brief's §16 to §18 and §23.
     |
     | `/jobs` here where the API says `workshop-jobs`: nothing on the web side
     | routes M14's background queue, so the word is free, and a fitter looking
     | for the job list should not have to think about why it is qualified. --}}

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Jobs</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            What is on the bench, what it is waiting for, and what is ready to go home. Parts written onto a
            job move no stock — the bearing leaves the shelf when the invoice posts.
        </p>
    </div>

    <button type="button" class="btn btn-primary hidden" data-new-job
            data-requires-permission="WRITE:WORKSHOP_JOBS">
        <x-icon name="plus" :size="17" />
        Book a motor in
    </button>
</header>

{{-- Tabs from the server's own status catalogue, with counts. Written from
     GET /workshop-jobs/meta rather than listed here, so a state added to the
     enum appears without this file being touched. --}}
<div class="tab-strip mb-4 flex flex-wrap gap-1" data-tabs role="tablist"></div>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="search-pill min-w-56 flex-1">
        <x-icon name="search" :size="16" />
        <input type="search" id="job-search" class="w-full bg-transparent text-sm outline-none"
               placeholder="Job number, customer, serial number or complaint…" aria-label="Search jobs">
    </div>

    <button type="button" id="filter-overdue" class="pill" aria-pressed="false">
        Past their promised date
    </button>

    <button type="button" id="clear-job-filters" class="btn btn-ghost btn-sm">Clear</button>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        {{-- §23's columns. `Amount` is what has been billed off the job, derived
             from the invoices that point at it — there is no total column on the
             jobs table, deliberately. --}}
        <table class="w-full min-w-[940px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide
                           text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">Job</th>
                    <th class="px-4 py-3 font-semibold">Customer</th>
                    <th class="px-4 py-3 font-semibold">Motor</th>
                    <th class="px-4 py-3 font-semibold">Complaint</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 text-right font-semibold">Billed</th>
                    <th class="px-4 py-3 font-semibold">Received</th>
                </tr>
            </thead>
            <tbody id="jobs-body"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="jobs-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="jobs-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="jobs-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

{{-- The job card: the pipeline, the motor, the parts, the estimate and the way
     to a bill. Opened over the list, because a fitter moving three motors along
     the bench should not have to navigate back each time. --}}
<div id="job-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="job-modal-title">
    <div class="modal-panel max-w-4xl">
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="job-modal-title">Job</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="job-modal-subtitle"></p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="max-h-[70vh] overflow-y-auto" id="job-modal-body"></div>
    </div>
</div>

{{-- Booking a motor in. A drawer rather than a page, because it is four fields
     and a complaint, and because the counter is usually holding the motor. --}}
<div id="job-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="job-drawer-title">
    <div class="drawer-panel">
        <form id="job-form" novalidate class="flex h-full flex-col">
            <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <div>
                    <h2 class="text-base font-bold text-foreground" id="job-drawer-title">Book a motor in</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        A job number is issued straight away, so there is something to write on the casing.
                    </p>
                </div>

                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </header>

            <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem]
                          text-rose-700" data-form-banner role="alert"></p>

                <div data-job-party-host></div>

                <label class="field">
                    <span class="field-label">What the customer reported</span>
                    <textarea name="complaint" class="field-input" rows="3" maxlength="1000" required
                              placeholder="Winding burnt, not starting"></textarea>
                    <span class="field-error hidden" data-error-for="complaint"></span>
                </label>

                {{-- The motor, and every field of it optional. A pump is wheeled
                     in at four in the afternoon by a driver who does not know its
                     brand, and a form that refused to book it in would be a form
                     that got a job card written on paper instead. --}}
                <fieldset class="grid gap-4 sm:grid-cols-2">
                    <legend class="field-label mb-2">The motor <span class="font-normal text-muted-foreground">(whatever is known)</span></legend>

                    <label class="field">
                        <span class="field-label">Rating (HP)</span>
                        <input type="text" name="hp" class="field-input" maxlength="20" placeholder="7.5">
                        <span class="field-error hidden" data-error-for="hp"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Phase</span>
                        <select name="phase" class="field-input">
                            <option value="">Not known</option>
                            <option value="1-phase">1-phase</option>
                            <option value="3-phase">3-phase</option>
                        </select>
                    </label>

                    <label class="field">
                        <span class="field-label">Brand</span>
                        <input type="text" name="brand" class="field-input" maxlength="60" placeholder="Crompton">
                    </label>

                    <label class="field">
                        <span class="field-label">Model</span>
                        <input type="text" name="model" class="field-input" maxlength="60">
                    </label>

                    <label class="field sm:col-span-2">
                        <span class="field-label">Serial number</span>
                        <input type="text" name="serial_no" class="field-input font-mono" maxlength="60">
                        <span class="mt-1.5 block text-xs text-muted-foreground">
                            The one field that identifies this motor rather than its kind — and the one a
                            customer quotes down the phone.
                        </span>
                    </label>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        <span class="field-label">Received</span>
                        <input type="date" name="received_date" class="field-input">
                        <span class="field-error hidden" data-error-for="received_date"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Promised back</span>
                        <input type="date" name="promised_date" class="field-input">
                        <span class="field-error hidden" data-error-for="promised_date"></span>
                    </label>
                </div>

                <label class="field">
                    <span class="field-label">Notes</span>
                    <textarea name="notes" class="field-input" rows="2" maxlength="1000"></textarea>
                </label>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Book it in</button>
            </footer>
        </form>
    </div>
</div>

@include('partials.quick-item-modal')

