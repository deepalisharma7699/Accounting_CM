{{--
    Personal — the owner's own diary. One tab of Money in & out.

    Money out of the owner's own pocket, so nothing here touches the books: no
    voucher, no ledger line, no report reads it. It sits on this card because it
    is still "money out" to the person keeping it, and the tab is drawn only for
    PERSONAL_EXPENSES, which OWNER alone is given.

    Exactly one [data-ws-form] and one [data-ws-list], which is what
    `mountWorkspace()` looks for. The categories and the ways of paying come from
    `GET /personal-expenses/meta` — never written here, for the reason the
    catalogue's vocabulary is not.
--}}
<div data-ws-form>

    {{-- The form's home when it is the create surface. `adoptForm()` moves this
         one node into the entry drawer for an edit and back again. --}}
    <div data-personal-form-slot>
        <form id="personal-form" novalidate class="space-y-4">

            {{-- Only in the drawer, where the same node is the edit form. --}}
            <header class="hidden items-start justify-between gap-4 border-b border-border pb-3"
                    data-form-chrome="modal">
                <div>
                    <h3 class="text-sm font-bold text-foreground">Edit this entry</h3>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        A diary line, so it is simply corrected — nothing in the books moves.
                    </p>
                </div>
            </header>

            <section class="surface form-card">
                {{-- The chrome wrapper only shows and hides; the row inside is
                     its own, so it reads the same before and after an edit. --}}
                <div data-form-chrome="inline">
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span class="tile-icon bg-rose-50 text-rose-600">
                            <x-icon name="dollar-sign" :size="17" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-bold text-foreground">Spent from your own pocket</h3>
                            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                                A private diary — only owners can see it, and none of it goes into the shop's books.
                                A shop cost, such as rent, a part or the electricity, goes on <strong>Expense</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 form-grid">
                    <div>
                        <label for="personal-date" class="field-label">Date <span class="req">*</span></label>
                        <input id="personal-date" name="date" type="date" class="field-input" required>
                        <p class="field-error hidden" data-error-for="date"></p>
                    </div>

                    <div>
                        <div class="flex items-baseline justify-between gap-2">
                            <label for="personal-category" class="field-label">What it was for <span class="req">*</span></label>
                            {{-- Not offered from the drawer: the category list is a
                                 drawer of its own, and nothing opens over a drawer
                                 but a confirm (§2.2). --}}
                            <span data-form-chrome="inline">
                                <button type="button" class="text-xs font-semibold text-primary hover:underline"
                                        data-personal-manage-categories
                                        data-requires-permission="UPDATE:PERSONAL_EXPENSES">Edit categories</button>
                            </span>
                        </div>
                        <select id="personal-category" name="category_id" class="field-input" required>
                            <option value="">Choose…</option>
                        </select>
                        <p class="field-error hidden" data-error-for="category_id"></p>
                    </div>

                    <div>
                        <label for="personal-amount" class="field-label">Amount <span class="req">*</span></label>
                        <input id="personal-amount" name="amount" type="text" inputmode="decimal"
                               class="field-input font-mono" required autocomplete="off" placeholder="0.00">
                        <p class="field-error hidden" data-error-for="amount"></p>
                    </div>

                    <div>
                        <label for="personal-paid-by" class="field-label">Paid by <span class="req">*</span></label>
                        <select id="personal-paid-by" name="paid_by" class="field-input" required></select>
                        <p class="field-error hidden" data-error-for="paid_by"></p>
                    </div>
                </div>

                <div class="mt-4">
                    <label for="personal-notes" class="field-label">Note</label>
                    <input id="personal-notes" name="notes" type="text" class="field-input" maxlength="500"
                           autocomplete="off" placeholder="School fees — second term">
                    <p class="field-error hidden" data-error-for="notes"></p>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3" data-form-chrome="inline"
                 data-requires-permission="WRITE:PERSONAL_EXPENSES">
                <button type="submit" class="btn btn-primary">Record it</button>
            </div>

            <div class="hidden items-center justify-end gap-2 border-t border-border pt-4"
                 data-form-chrome="modal">
                <button type="button" class="btn btn-ghost" data-personal-edit-cancel>Cancel</button>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>

<div data-ws-list>

    <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
        <div class="search-pill min-w-56 flex-1">
            <x-icon name="search" :size="16" />
            <input type="search" data-personal-filter="search" class="w-full bg-transparent text-sm outline-none"
                   placeholder="Search the notes…" aria-label="Search personal expenses">
        </div>

        {{-- A diary is read by the month, so that is where it opens. --}}
        <select data-personal-filter="period" class="field-input w-auto min-w-36" aria-label="Period">
            <option value="this-month">This month</option>
            <option value="last-month">Last month</option>
            <option value="last-3-months">Last 3 months</option>
            <option value="this-year">This year</option>
            <option value="all">All time</option>
        </select>

        <select data-personal-filter="category" class="field-input w-auto min-w-44" aria-label="Filter by category">
            <option value="">Every category</option>
        </select>

        <select data-personal-filter="paid_by" class="field-input w-auto min-w-32" aria-label="Filter by how it was paid">
            <option value="">Any way of paying</option>
        </select>

        <button type="button" data-personal-clear class="btn btn-ghost btn-sm">Clear</button>

        <button type="button" class="btn btn-secondary btn-sm" data-personal-manage-categories
                data-requires-permission="UPDATE:PERSONAL_EXPENSES">
            <x-icon name="sliders-horizontal" :size="14" />
            Categories
        </button>
    </div>

    {{-- What the filter adds up to, across every matching entry and not only
         this page — and where it went, largest first. A chip filters by it. --}}
    <div class="surface mb-4 p-4" data-personal-summary aria-live="polite"></div>

    <div class="surface overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                               tracking-wide text-muted-foreground">
                        <th class="px-4 py-3 font-semibold">Date</th>
                        <th class="px-4 py-3 font-semibold">What it was for</th>
                        <th class="px-4 py-3 font-semibold">Note</th>
                        <th class="px-4 py-3 font-semibold">Paid by</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    </tr>
                </thead>
                <tbody data-personal-body></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
            <p data-personal-pager-summary class="text-[0.8125rem] text-muted-foreground"></p>

            <div class="flex gap-2">
                <button type="button" data-personal-page="prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
                <button type="button" data-personal-page="next" class="btn btn-secondary btn-sm" disabled>Next</button>
            </div>
        </div>
    </div>
</div>
