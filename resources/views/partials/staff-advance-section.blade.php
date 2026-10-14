{{--
    Paying a member of staff an advance, and everything already out — M22.

    One partial, two hosts: the Staff card's Advances tab and Money in & out's
    Staff advance tab. Both mount `components/staff-advance.js` over it, which
    finds everything by its data- hook. `$prefix` keeps the ids — which exist
    only so each <label for> points at its control — distinct between the two,
    because the shell keeps both modules alive at once.

    Exactly one [data-ws-form] and one [data-ws-list], which is what
    `mountWorkspace()` looks for.
--}}

<div data-ws-form>
    <section class="surface form-card">
        <div class="form-head">
            <span class="tile-icon bg-amber-50 text-amber-600">
                <x-icon name="credit-card" :size="17" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-bold text-foreground">Pay an advance</h3>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    Money against a salary not yet earned. It comes back off the next payroll.
                </p>
            </div>
        </div>

        <form id="{{ $prefix }}-advance-form" novalidate data-advance-form>
            <div class="form-grid">
                <div>
                    <label for="{{ $prefix }}-advance-employee" class="field-label">Who <span class="req">*</span></label>
                    <select id="{{ $prefix }}-advance-employee" name="employee_id" class="field-input" required data-advance-employee>
                        <option value="">Choose somebody…</option>
                    </select>
                    <p class="mt-1.5 text-xs text-muted-foreground" data-advance-outstanding-hint></p>
                    <p class="field-error hidden" data-error-for="employee_id"></p>
                </div>

                <div>
                    <label for="{{ $prefix }}-advance-date" class="field-label">Date</label>
                    <input type="date" id="{{ $prefix }}-advance-date" name="date" class="field-input" data-advance-date>
                    <p class="field-error hidden" data-error-for="date"></p>
                </div>
            </div>

            {{-- No amount field: the amount *is* the split, exactly as it
                 is for a vendor payment. A second figure could disagree
                 with the first, and the disagreement would be a debit in
                 Staff Advance that no cash box could account for. --}}
            <div class="mt-4" data-advance-payments></div>

            <div class="mt-4">
                <label for="{{ $prefix }}-advance-notes" class="field-label">Note</label>
                <input type="text" id="{{ $prefix }}-advance-notes" name="notes" data-advance-notes class="field-input" maxlength="400"
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
            <label for="{{ $prefix }}-advances-filter-employee" class="sr-only">Filter by person</label>
            <select id="{{ $prefix }}-advances-filter-employee" class="field-input w-auto min-w-48 py-0" data-advance-filter-employee>
                <option value="">Everybody</option>
            </select>
        </div>
        <span class="grow"></span>
        <button type="button" class="btn btn-ghost btn-sm" data-advances-export>
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
                <tbody class="divide-y divide-muted" data-advances-body></tbody>
            </table>
        </div>

        <div class="border-t border-muted px-4 py-3">
            <p class="text-[0.78125rem] text-muted-foreground" data-advances-summary></p>
        </div>
    </div>

</div>{{-- /data-ws-list --}}
