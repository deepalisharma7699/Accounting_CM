{{--
    Staff — the people who work for the workshop, M22.

    ## Four sections, four §2A workspaces

    Staff, attendance, payroll and advances are four things a workshop does with
    the same nine people, and they are one card because they are only ever opened
    one after another. Inside, each is an ordinary level-1 workspace built from
    the *shared* renderer — `mountWorkspace()` is called once per section, on the
    section's own root, so every one of them inherits the form/list swap, the
    single switch control, the count badge and the Escape step without a line of
    per-module flow code (§2A, and the rule against a per-module variant).

    So each `[data-staff-section]` carries exactly one `[data-ws-form]` and one
    `[data-ws-list]`, and the workspace puts its own heading above them. The tab
    strip is the only thing this file adds on top, and it is level 1 — the design
    already sanctions tabs there for "the whole module: list, filters, bulk work".

    ## What is not written here

    **The designations.** They are rows in `staff_designations`, published by
    GET /api/v1/staff/meta, and a copy in this markup would go stale the moment
    an owner added one — the exact failure the catalogue module was rebuilt to
    remove. The same goes for the attendance statuses and the two salary bases,
    which are enums and arrive from the same endpoint: a client never writes
    either list out, so neither can drift from the server's.

    **Any money.** Every figure on every screen below arrives from the guarded
    /api/v1 endpoints once the page module runs.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1's section switch. Not navigation: it swaps which of the four
         workspaces is attached, and each keeps its own state (§3.6). --}}
    <div class="tab-strip mb-5" role="tablist" data-staff-tabs>
        <button type="button" class="tab" role="tab" data-staff-tab="people" aria-selected="true">
            <x-icon name="id-card" :size="15" />
            Staff
            <span data-tab-count data-count="people"></span>
        </button>
        <button type="button" class="tab" role="tab" data-staff-tab="attendance" aria-selected="false">
            <x-icon name="check-circle" :size="15" />
            Attendance
        </button>
        <button type="button" class="tab" role="tab" data-staff-tab="payroll" aria-selected="false">
            <x-icon name="dollar-sign" :size="15" />
            Payroll
        </button>
        <button type="button" class="tab" role="tab" data-staff-tab="advances" aria-selected="false">
            <x-icon name="credit-card" :size="15" />
            Advances
            <span data-tab-count data-count="advances"></span>
        </button>
    </div>

    {{-- ==================================================================
         1. STAFF — the list of people, and the form that adds one
         ================================================================== --}}
    <div data-staff-section="people">

        <div data-ws-form>
            <section class="surface form-card">
                <div class="form-head">
                    <span class="tile-icon bg-violet-50 text-violet-600">
                        <x-icon name="id-card" :size="17" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-bold text-foreground">Add somebody to the staff</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                            What they are called, what they do, and how they are paid.
                        </p>
                    </div>
                </div>

                <div data-employee-form-slot></div>

                <p class="hint mt-5">
                    <x-icon name="info" :size="15" />
                    <span>
                        A <strong>monthly salary</strong> is paid in full unless something is marked against
                        it — so a day nobody records is a day paid. A <strong>daily wage</strong> is the other
                        way round: it is earned only on the days that are actually marked present.
                    </span>
                </p>
            </section>
        </div>

        <div data-ws-list>

            <header class="mb-5 flex flex-wrap items-center justify-end gap-2">
                <div class="search-pill w-60">
                    <x-icon name="search" :size="15" />
                    <input type="search" id="staff-search" class="w-full"
                           placeholder="Search by name or phone…" aria-label="Search staff">
                </div>

                {{-- Filled from GET /api/v1/staff/meta. Never listed here. --}}
                <label for="staff-filter-designation" class="sr-only">Filter by designation</label>
                <select id="staff-filter-designation" class="field-input h-[2.375rem] w-auto min-w-40 py-0">
                    <option value="">Any designation</option>
                </select>

                <button type="button" class="btn btn-secondary btn-sm" id="staff-manage-designations"
                        data-requires-permission="WRITE:STAFF">
                    <x-icon name="sliders-horizontal" :size="14" />
                    Designations
                </button>

                <button type="button" class="btn btn-ghost btn-sm" id="staff-export">
                    <x-icon name="download" :size="14" />
                    Export
                </button>
            </header>

            {{-- Four figures. Three are also filters; the headcount is a count
                 and nothing else, so it does not pretend to be clickable. --}}
            <div class="tile-row">
                <div class="stat-tile">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-violet-50 text-violet-600">
                        <x-icon name="users" :size="16" />
                    </span>
                    <span>
                        <span class="block text-[22px] font-bold leading-none text-foreground" id="staff-stat-total">—</span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">On the staff</span>
                    </span>
                </div>

                <button type="button" class="stat-tile stat-tile-action" data-staff-filter="monthly">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                        <x-icon name="clipboard-list" :size="16" />
                    </span>
                    <span>
                        <span class="block text-[22px] font-bold leading-none text-foreground" id="staff-stat-monthly">—</span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">Monthly salary</span>
                    </span>
                    <span class="ml-auto text-border" data-stat-chevron>
                        <x-icon name="chevron-right" :size="14" />
                    </span>
                </button>

                <button type="button" class="stat-tile stat-tile-action" data-staff-filter="daily">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                        <x-icon name="clock" :size="16" />
                    </span>
                    <span>
                        <span class="block text-[22px] font-bold leading-none text-foreground" id="staff-stat-daily">—</span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">Daily wage</span>
                    </span>
                    <span class="ml-auto text-border" data-stat-chevron>
                        <x-icon name="chevron-right" :size="14" />
                    </span>
                </button>

                {{-- What is out with the staff. The one figure on this screen
                     that is money the workshop is owed rather than money it
                     owes, which is why it is the tile with a filter on it. --}}
                <button type="button" class="stat-tile stat-tile-action" data-staff-filter="advance">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                        <x-icon name="credit-card" :size="16" />
                    </span>
                    <span>
                        <span class="block text-[22px] font-bold leading-none text-foreground" id="staff-stat-advance">—</span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">Advances out</span>
                    </span>
                    <span class="ml-auto text-border" data-stat-chevron>
                        <x-icon name="chevron-right" :size="14" />
                    </span>
                </button>
            </div>

            <div class="mb-5 flex flex-wrap items-center gap-2" id="staff-pills">
                <button type="button" class="pill" data-pill="all" aria-pressed="true">Everybody</button>
                <button type="button" class="pill" data-pill="monthly" aria-pressed="false">Monthly</button>
                <button type="button" class="pill" data-pill="daily" aria-pressed="false">Daily</button>
                <button type="button" class="pill" data-pill="advance" aria-pressed="false">Owes an advance</button>
                <button type="button" class="pill" data-pill="left" aria-pressed="false">Left</button>

                <button type="button" id="staff-clear-filters"
                        class="ml-1 hidden items-center gap-1 px-3 py-1.5 text-xs text-muted-foreground transition hover:text-secondary-foreground">
                    <x-icon name="x" :size="12" />
                    Clear filters
                </button>
            </div>

            <div class="surface overflow-hidden rounded-[14px]">
                <div class="overflow-x-auto rounded-t-[14px]">
                    <table class="w-full min-w-[860px] border-collapse">
                        <thead>
                            <tr class="border-b border-border bg-background text-left" id="staff-head">
                                <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    data-sort="name" scope="col">Name</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Designation</th>
                                <th class="th-sort px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    data-sort="pay_rate" scope="col">Pay</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">This month</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Advance out</th>
                                <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    data-sort="joined_on" scope="col">Joined</th>
                                {{-- `relative`, and it matters: `sr-only` is absolutely positioned, so
                                     without a positioned ancestor it is laid out at the far right of the
                                     table, escapes the scroller's clipping and scrolls the page
                                     sideways on a phone (§7.3). --}}
                                <th class="relative px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="staff-body" class="divide-y divide-muted"></tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
                    <p id="staff-summary" class="text-[0.78125rem] text-muted-foreground"></p>
                    <div class="flex items-center gap-1" id="staff-pager"></div>
                </div>
            </div>

        </div>{{-- /data-ws-list --}}
    </div>{{-- /people --}}

    {{-- ==================================================================
         2. ATTENDANCE — the day sheet, and the month register behind it
         ================================================================== --}}
    <div data-staff-section="attendance" hidden>

        <div data-ws-form>
            <section class="surface form-card">
                <div class="form-head">
                    <span class="tile-icon bg-emerald-50 text-emerald-600">
                        <x-icon name="check-circle" :size="17" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-bold text-foreground">Mark the day</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                            Everybody on the payroll that day. Only what is different from normal needs a mark.
                        </p>
                    </div>
                </div>

                <div class="mb-4 flex flex-wrap items-end gap-2">
                    <div>
                        <label for="attendance-date" class="field-label">Day</label>
                        <input type="date" id="attendance-date" class="field-input w-auto min-w-44">
                    </div>

                    <button type="button" class="btn btn-secondary btn-sm" id="attendance-today">Today</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="attendance-prev"
                            aria-label="Previous day">
                        <x-icon name="chevron-left" :size="14" />
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" id="attendance-next"
                            aria-label="Next day">
                        <x-icon name="chevron-right" :size="14" />
                    </button>

                    <span class="grow"></span>

                    {{-- The whole point of a day sheet: nine taps become one. --}}
                    <button type="button" class="btn btn-secondary btn-sm" id="attendance-all-present"
                            data-requires-permission="UPDATE:STAFF">
                        <x-icon name="check-circle" :size="14" />
                        All present
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" id="attendance-all-clear"
                            data-requires-permission="UPDATE:STAFF">
                        Clear all
                    </button>
                </div>

                <p class="mb-3 text-[0.78125rem] text-muted-foreground" id="attendance-summary"></p>

                <div class="surface overflow-hidden rounded-[12px]">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] border-collapse">
                            <thead>
                                <tr class="border-b border-border bg-background text-left">
                                    <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Name</th>
                                    <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Status</th>
                                    <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Note</th>
                                </tr>
                            </thead>
                            <tbody id="attendance-body" class="divide-y divide-muted"></tbody>
                        </table>
                    </div>
                </div>

                <div class="form-foot">
                    <button type="button" class="btn btn-primary" id="attendance-save"
                            data-requires-permission="UPDATE:STAFF">
                        <x-icon name="check-circle" :size="15" />
                        Save the day
                    </button>
                    <p class="text-xs text-muted-foreground">
                        A day with no mark is not a blank — it is paid on a monthly salary and unpaid on a
                        daily wage.
                    </p>
                </div>
            </section>
        </div>

        <div data-ws-list>

            <header class="mb-5 flex flex-wrap items-end gap-2">
                <div>
                    <label for="register-month" class="field-label">Month</label>
                    <input type="month" id="register-month" class="field-input w-auto min-w-44">
                </div>
                <span class="grow"></span>
                <button type="button" class="btn btn-ghost btn-sm" id="register-export">
                    <x-icon name="download" :size="14" />
                    Export
                </button>
            </header>

            {{-- The legend, painted from GET /staff/meta so the six states and
                 the colour each is drawn in exist in exactly one place. --}}
            <div class="mb-4 flex flex-wrap items-center gap-2" id="register-legend"></div>

            <div class="surface overflow-hidden rounded-[14px]">
                <div class="overflow-x-auto rounded-t-[14px]">
                    <table class="w-full border-collapse text-center" id="register-table">
                        <thead id="register-head"></thead>
                        <tbody id="register-body" class="divide-y divide-muted"></tbody>
                    </table>
                </div>

                <div class="border-t border-muted px-4 py-3">
                    <p id="register-summary" class="text-[0.78125rem] text-muted-foreground"></p>
                </div>
            </div>

        </div>{{-- /data-ws-list --}}
    </div>{{-- /attendance --}}

    {{-- ==================================================================
         3. PAYROLL — run a month, and the months already run
         ================================================================== --}}
    <div data-staff-section="payroll" hidden>

        <div data-ws-form>
            <section class="surface form-card">
                <div class="form-head">
                    <span class="tile-icon bg-blue-50 text-blue-600">
                        <x-icon name="dollar-sign" :size="17" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-bold text-foreground">Run a month</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                            Computed from the attendance register as it stands right now.
                        </p>
                    </div>
                </div>

                <div class="mb-4 flex flex-wrap items-end gap-2">
                    <div>
                        <label for="payroll-month" class="field-label">Month</label>
                        <input type="month" id="payroll-month" class="field-input w-auto min-w-44">
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="payroll-recompute">
                        <x-icon name="refresh-cw" :size="14" />
                        Recompute
                    </button>
                </div>

                {{-- Shown when the month has already been paid. Never a refusal
                     at this point: looking at a month that has been run is a
                     legitimate thing to do, and the refusal belongs at Post. --}}
                <p class="mb-4 hidden rounded-[10px] border border-amber-200 bg-amber-50 px-3.5 py-3 text-[0.8125rem] text-amber-800"
                   id="payroll-existing" role="status"></p>

                <div class="surface overflow-hidden rounded-[12px]">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px] border-collapse">
                            <thead>
                                <tr class="border-b border-border bg-background text-left">
                                    <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Name</th>
                                    <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Basis</th>
                                    <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Days paid</th>
                                    <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Earned</th>
                                    <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Advance out</th>
                                    <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Recover</th>
                                    <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                        scope="col">Net</th>
                                </tr>
                            </thead>
                            <tbody id="payroll-body" class="divide-y divide-muted"></tbody>
                            <tfoot id="payroll-foot" class="border-t-2 border-border"></tfoot>
                        </table>
                    </div>
                </div>

                {{-- How the net was handed over. The shared component every other
                     settlement in the application uses (§5.2) — a second
                     implementation of mode chips is exactly what it exists to
                     prevent. --}}
                <div class="mt-5" id="payroll-payments-host" data-requires-permission="WRITE:STAFF"></div>

                <div class="mt-4" data-requires-permission="WRITE:STAFF">
                    <label for="payroll-notes" class="field-label">Note</label>
                    <input type="text" id="payroll-notes" class="field-input" maxlength="400"
                           placeholder="Optional — appears on the voucher">
                </div>

                <div class="form-foot">
                    <button type="button" class="btn btn-primary" id="payroll-post"
                            data-requires-permission="WRITE:STAFF">
                        <x-icon name="check-circle" :size="15" />
                        Post payroll
                    </button>
                    <p class="text-xs text-muted-foreground" id="payroll-post-hint"></p>
                </div>
            </section>
        </div>

        <div data-ws-list>
            <div class="surface overflow-hidden rounded-[14px]">
                <div class="overflow-x-auto rounded-t-[14px]">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr class="border-b border-border bg-background text-left">
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Month</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Voucher</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Staff</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Gross</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Recovered</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Paid out</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody id="payroll-runs-body" class="divide-y divide-muted"></tbody>
                    </table>
                </div>

                <div class="border-t border-muted px-4 py-3">
                    <p id="payroll-runs-summary" class="text-[0.78125rem] text-muted-foreground"></p>
                </div>
            </div>
        </div>{{-- /data-ws-list --}}
    </div>{{-- /payroll --}}

    {{-- ==================================================================
         4. ADVANCES — pay one, and everything already out
         ================================================================== --}}
    <div data-staff-section="advances" hidden>

        <div data-ws-form>
            <section class="surface form-card">
                <div class="form-head">
                    <span class="tile-icon bg-amber-50 text-amber-600">
                        <x-icon name="credit-card" :size="17" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-bold text-foreground">Pay an advance</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                            Money against a salary not yet earned. It comes back off the next payroll.
                        </p>
                    </div>
                </div>

                <form id="advance-form" novalidate>
                    <p class="mb-4 hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                       data-form-banner role="alert"></p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="advance-employee" class="field-label">Who <span class="req">*</span></label>
                            <select id="advance-employee" name="employee_id" class="field-input" required>
                                <option value="">Choose somebody…</option>
                            </select>
                            <p class="mt-1.5 text-xs text-muted-foreground" id="advance-outstanding-hint"></p>
                            <p class="field-error hidden" data-error-for="employee_id"></p>
                        </div>

                        <div>
                            <label for="advance-date" class="field-label">Date</label>
                            <input type="date" id="advance-date" name="date" class="field-input">
                            <p class="field-error hidden" data-error-for="date"></p>
                        </div>
                    </div>

                    {{-- No amount field: the amount *is* the split, exactly as it
                         is for a vendor payment. A second figure could disagree
                         with the first, and the disagreement would be a debit in
                         Staff Advance that no cash box could account for. --}}
                    <div class="mt-4" id="advance-payments-host"></div>

                    <div class="mt-4">
                        <label for="advance-notes" class="field-label">Note</label>
                        <input type="text" id="advance-notes" name="notes" class="field-input" maxlength="400"
                               placeholder="Optional — appears on the voucher">
                        <p class="field-error hidden" data-error-for="notes"></p>
                    </div>

                    <div class="form-foot">
                        <button type="submit" class="btn btn-primary" data-requires-permission="WRITE:STAFF">
                            <x-icon name="plus" :size="15" />
                            Pay advance
                        </button>
                        <button type="button" class="btn btn-ghost" data-advance-clear>Clear</button>
                    </div>
                </form>

                <p class="hint mt-5">
                    <x-icon name="info" :size="15" />
                    <span>
                        An advance is money the workshop is owed back, not a cost — it sits in
                        <strong>Staff Advance</strong> until payroll recovers it. An advance typed wrong is
                        cancelled rather than edited, and cancelling it stops it counting immediately.
                    </span>
                </p>
            </section>
        </div>

        <div data-ws-list>

            <header class="mb-5 flex flex-wrap items-end gap-2">
                <div>
                    <label for="advances-filter-employee" class="sr-only">Filter by person</label>
                    <select id="advances-filter-employee" class="field-input h-[2.375rem] w-auto min-w-48 py-0">
                        <option value="">Everybody</option>
                    </select>
                </div>
                <span class="grow"></span>
                <button type="button" class="btn btn-ghost btn-sm" id="advances-export">
                    <x-icon name="download" :size="14" />
                    Export
                </button>
            </header>

            <div class="surface overflow-hidden rounded-[14px]">
                <div class="overflow-x-auto rounded-t-[14px]">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr class="border-b border-border bg-background text-left">
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Date</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Voucher</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Who</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Amount</th>
                                <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Still out</th>
                                <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                    scope="col">Note</th>
                                <th class="relative px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="advances-body" class="divide-y divide-muted"></tbody>
                    </table>
                </div>

                <div class="border-t border-muted px-4 py-3">
                    <p id="advances-summary" class="text-[0.78125rem] text-muted-foreground"></p>
                </div>
            </div>

        </div>{{-- /data-ws-list --}}
    </div>{{-- /advances --}}
</div>

{{--
    One member of staff, read without leaving the list — level 2.

    What it is for is the bottom half: what is out with them, and what they have
    been paid month by month. A rate on its own does not answer "why was
    September short"; the payslip that says nineteen of thirty-one days does.
--}}
<div id="employee-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="employee-drawer-title">
    <div class="drawer-panel max-w-[520px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary
                                 text-sm font-bold text-primary-foreground" id="employee-drawer-initials"></span>
                    <div class="min-w-0">
                        <h3 id="employee-drawer-title" class="truncate text-base font-bold leading-tight text-foreground"></h3>
                        <p id="employee-drawer-designation" class="mt-0.5 truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="employee-drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground" id="employee-drawer-rate-label">Pay</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="employee-drawer-rate">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Advance out</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="employee-drawer-advance">—</p>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="employee-drawer-body"></div>

        <div class="drawer-foot">
            <button type="button" id="employee-drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:STAFF">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" id="employee-drawer-advance-action" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="WRITE:STAFF">
                <x-icon name="credit-card" :size="13" />
                Pay advance
            </button>
            <button type="button" id="employee-drawer-delete" class="btn btn-ghost btn-sm hidden !text-rose-600"
                    data-requires-permission="DELETE:STAFF">
                <x-icon name="trash" :size="13" />
                Delete
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{--
    One month's payroll, read without leaving the list — level 2.

    The payslips are the whole of it: the ledger holds one voucher for the run,
    and who got what is only here.
--}}
<div id="payroll-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="payroll-drawer-title">
    <div class="drawer-panel max-w-[680px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 id="payroll-drawer-title" class="truncate text-base font-bold leading-tight text-foreground"></h3>
                    <p id="payroll-drawer-subtitle" class="mt-0.5 truncate text-xs text-muted-foreground"></p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span id="payroll-drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2">
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Gross</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="payroll-drawer-gross">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Recovered</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="payroll-drawer-recovered">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Paid out</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="payroll-drawer-net">—</p>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="payroll-drawer-body"></div>

        <div class="drawer-foot">
            <button type="button" id="payroll-drawer-export" class="btn btn-secondary btn-sm">
                <x-icon name="download" :size="13" />
                Export
            </button>
            <button type="button" id="payroll-drawer-reverse" class="btn btn-ghost btn-sm hidden !text-rose-600"
                    data-requires-permission="UPDATE:STAFF">
                <x-icon name="refresh-cw" :size="13" />
                Reverse
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{--
    The Designation Master — level 2.

    The staff module's counterpart to the catalogue's brand drawer, and it exists
    for the same reason: what the people in a workshop are called is different in
    every workshop, and a typed designation is a master list nobody maintains.
--}}
<div id="designation-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="designation-drawer-title">
    <div class="drawer-panel max-w-[440px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h3 id="designation-drawer-title" class="text-base font-bold text-foreground">Designations</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        What the people here are called — Fitter, Winder, Helper.
                    </p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="16" />
                </button>
            </div>

            <form id="designation-form" class="mt-4 flex gap-2" novalidate
                  data-requires-permission="WRITE:STAFF">
                <input type="text" id="designation-name" class="field-input" maxlength="80" required
                       placeholder="Add a designation…" aria-label="New designation">
                <button type="submit" class="btn btn-primary shrink-0">
                    <x-icon name="plus" :size="15" />
                    Add
                </button>
            </form>
            <p class="field-error hidden" data-error-for="name"></p>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="designation-list"></div>

        <div class="drawer-foot">
            <p class="text-xs text-muted-foreground">
                One somebody holds can be archived, never deleted.
            </p>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Done</button>
        </div>
    </div>
</div>

{{--
    Editing one member of staff — level 2.

    `#employee-form` is written once and *moved* — into the level-1 slot for a
    create, into this drawer for an edit. Two copies of these fields would be two
    sets of ids, two submit handlers and two places for a pay rule to be added to
    only one of (§4.4, §5.1). It starts life inside the drawer panel so it is
    never visible before `adoptForm()` has decided where it belongs, and a caller
    without WRITE:STAFF — whose section lands on the list — never sees it at all.
--}}
<div id="employee-modal" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="employee-modal-title">
    <div class="drawer-panel max-w-[560px]" data-employee-modal-slot>

        <form id="employee-form" novalidate>
            <input type="hidden" name="id">

            <div class="hidden items-start justify-between border-b border-muted px-6 py-4"
                 data-form-chrome="modal">
                <div>
                    <h2 id="employee-modal-title" class="text-base font-bold text-foreground">Edit</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="employee-modal-subtitle"></p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4" data-form-body>
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="employee-name" class="field-label">Name <span class="req">*</span></label>
                        <input id="employee-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="e.g. Ravi Sharma">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    {{-- Filled from GET /api/v1/staff/meta. Never listed here. --}}
                    <div>
                        <label for="employee-designation" class="field-label">Designation</label>
                        <div class="flex gap-2">
                            <select id="employee-designation" name="designation_id" class="field-input">
                                <option value="">None</option>
                            </select>
                            <button type="button" class="btn btn-secondary btn-icon shrink-0"
                                    data-employee-add-designation aria-label="Manage designations"
                                    data-requires-permission="WRITE:STAFF">
                                <x-icon name="plus" :size="15" />
                            </button>
                        </div>
                        <p class="field-error hidden" data-error-for="designation_id"></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="employee-basis" class="field-label">Paid <span class="req">*</span></label>
                        <select id="employee-basis" name="salary_basis" class="field-input" required></select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="employee-basis-hint"></p>
                        <p class="field-error hidden" data-error-for="salary_basis"></p>
                    </div>

                    <div>
                        <label for="employee-rate" class="field-label">
                            <span id="employee-rate-label">Monthly salary</span> <span class="req">*</span>
                        </label>
                        <input id="employee-rate" name="pay_rate" type="number" class="field-input" required
                               min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                        <p class="field-error hidden" data-error-for="pay_rate"></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="employee-joined" class="field-label">Joined</label>
                        <input id="employee-joined" name="joined_on" type="date" class="field-input">
                        <p class="field-error hidden" data-error-for="joined_on"></p>
                    </div>

                    {{-- On the edit form only: adding somebody who has already
                         left is not a thing anybody does, and a field for it on
                         the create form would be a question with one answer. --}}
                    <div class="hidden" data-employee-left>
                        <label for="employee-left" class="field-label">Left</label>
                        <input id="employee-left" name="left_on" type="date" class="field-input">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            They come off the day sheet and the next payroll. Months already paid are untouched.
                        </p>
                        <p class="field-error hidden" data-error-for="left_on"></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="employee-phone" class="field-label">Phone</label>
                        <input id="employee-phone" name="phone" type="tel" class="field-input"
                               autocomplete="off" placeholder="98765 43210">
                        <p class="field-error hidden" data-error-for="phone"></p>
                    </div>

                    <div>
                        <label for="employee-email" class="field-label">Email</label>
                        <input id="employee-email" name="email" type="email" class="field-input"
                               autocomplete="off">
                        <p class="field-error hidden" data-error-for="email"></p>
                    </div>
                </div>

                <div>
                    <label for="employee-address" class="field-label">Address</label>
                    <textarea id="employee-address" name="address" class="field-input" rows="2"
                              maxlength="500"></textarea>
                    <p class="field-error hidden" data-error-for="address"></p>
                </div>

                <div>
                    <label for="employee-notes" class="field-label">Notes</label>
                    <textarea id="employee-notes" name="notes" class="field-input" rows="2"
                              maxlength="500"></textarea>
                    <p class="field-error hidden" data-error-for="notes"></p>
                </div>
            </div>

            {{-- The drawer's footer. --}}
            <div class="hidden gap-2 border-t border-muted px-6 py-4" data-form-chrome="modal">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save</button>
            </div>

            {{-- The level-1 footer. "Clear" rather than "Cancel": there is nothing
                 to cancel out of — the form is where the section lives, and
                 leaving it is what the switch control above is for. --}}
            <div class="form-foot" data-form-chrome="inline">
                <button type="submit" class="btn btn-primary" data-requires-permission="WRITE:STAFF">
                    <x-icon name="plus" :size="15" />
                    Add to staff
                </button>
                <button type="button" class="btn btn-ghost" data-employee-clear>Clear</button>
            </div>
        </form>

    </div>
</div>
