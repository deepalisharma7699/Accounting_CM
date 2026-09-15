{{--
    Accounting — C5, and the merge is the point.

    Accounting and Ledger were two cards answering one question at two zoom
    levels: "what does this account stand at". Two cards would have meant two
    period pickers and two trial-balance renderers, and the second copy of each
    is the one that drifts — so there is one card, keyed `accounts`, and the
    `ledger` key is gone from the registry (§5.1, §4.4).

    ```
    card → CREATE ACCOUNT            ← always lands here (§2A.1, §2A.5)
         → "Show list (37)"          → the books, in two views over one period
              ├── Chart of accounts  — every account, grouped, what it stands at
              └── Trial balance      — with the reconciliation stated, not implied
         → row → drawer (level 2)    → the running statement, the CSV, Edit
         → confirm (level 3)
    ```

    ## Two grants, one card

    The chart is `READ:ACCOUNTS`, which is what the card itself is gated on.
    Every *figure* on this screen — the balance column, the type totals, the
    trial balance, the running statement — is `READ:LEDGER`, and neither grant
    implies the other. So a holder of the first without the second gets the
    chart with **no figures at all**: the marks below are `data-ledger-only` and
    `pages/accounts.js` **removes** them rather than blanking them. A column of
    dashes reads as "every account is at zero", which is a claim about the books
    rather than about the reader's permissions — the same judgement Insights'
    People tab makes about the wage tile.

    ## What is deliberately not here

    **The journal-entry list.** It was this screen's second tab, and every row of
    it is on the Day Book, which lists every posted document including the
    journals Sales and Purchase do not show. A fourth copy would be screens
    answering one question (§5.1). **A party's ledger and statement** likewise:
    Customers and Vendors carry those, gated the same way.

    ## Why search and the archived filter narrow the chart and not the balance

    A trial balance whose rows were filtered would not reconcile. Its totals come
    from the server, over every account with movement in the period — narrow the
    rows in the browser and the column no longer adds up to the figure under it,
    with nothing on screen saying so. So those two controls belong to the chart
    view and are hidden on the other; the **period** is the one control both
    share, because it changes what every figure means rather than which of them
    are shown.
--}}
<div class="mx-auto max-w-[1180px]">

    {{-- ==================================================================
         Level 1, form mode — where the module lands (§2A.1).

         Creating an expense head is a real create act, and until this card was
         switched on no workshop could perform it: `POST /accounts` has exactly
         one caller in the whole front end and it is this module. The seeded
         fifteen were all a workshop would ever get.
         ================================================================== --}}
    <div data-ws-form>

        {{-- What the last save did. A line above the cleared form rather than a
             toast, for C3's reason: §2A.8 empties the form the instant it posts,
             and a toast is gone before somebody has read the code. --}}
        <div data-account-outcome role="status"
             class="mb-4 hidden items-start gap-3 rounded-[10px] border border-emerald-200 bg-emerald-50
                    px-4 py-3 text-[0.8125rem] text-emerald-900"></div>

        {{-- The form's home when it is the create surface. `adoptForm()` moves
             this one node into the drawer for an edit and back again — the
             fields are written once, so a validation rule cannot be added to one
             copy and left off the other (§4.4, §5.1). --}}
        <div data-account-form-slot>
            <form id="account-form" novalidate class="space-y-4">
                <input type="hidden" name="id">

                {{-- Only in the drawer, where the same node is the edit form. --}}
                <header class="hidden items-start justify-between gap-4 border-b border-border pb-3"
                        data-form-chrome="modal">
                    <div>
                        <h3 class="text-sm font-bold text-foreground">Edit account</h3>
                        <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                            The name, the number and what belongs in it. The <em>type</em> is fixed once an
                            account exists: reclassifying it would move every entry already posted to it
                            onto a different financial statement.
                        </p>
                    </div>
                </header>

                {{-- Shown when the account being edited is one the posting engine
                     resolves by key. Its locked controls are disabled with this
                     as their reason rather than hidden: the answer belongs where
                     the question is asked. --}}
                <p data-account-system-note
                   class="hidden items-start gap-2 rounded-[10px] border border-amber-200 bg-amber-50/60
                          px-3.5 py-3 text-[0.8125rem] text-amber-800">
                    <x-icon name="lock" :size="16" class="mt-0.5 shrink-0" />
                    <span>
                        This is a system account. You may rename it and edit its description — the posting
                        engine finds it by an internal key, not by its name — but its number and type are
                        fixed.
                    </span>
                </p>

                <section class="surface p-5 sm:p-6">
                    <div data-form-chrome="inline">
                        <h3 class="text-sm font-bold text-foreground">A new account on the chart</h3>
                        <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                            An expense head of the workshop's own — Diesel, Workshop rent, rewinding wire
                            scrap — or any other account the seeded chart does not cover. Nothing is ever
                            deleted from a chart of accounts: an account that has been posted to is
                            archived instead, so its entries keep their name.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="account-type" class="field-label">Type <span class="req">*</span></label>
                            <select id="account-type" name="type" class="field-input" required>
                                <option value="">Select a type…</option>
                                {{-- From the enum. The five types are code, not data: each one decides
                                     which side increases the account and which statement it lands on,
                                     so a sixth is a change to the arithmetic and not a row somebody
                                     adds. That is the catalogue's vocabulary test, applied in the
                                     other direction. --}}
                                @foreach (\App\Enums\AccountType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-muted-foreground" data-type-hint>
                                Decides which side increases the account.
                            </p>
                            <p class="field-error hidden" data-error-for="type"></p>
                        </div>

                        {{-- The band comes from GET /accounts/types, never from a
                             copy here: the ranges are the server's rule, and a
                             second copy of them would be wrong the day one moved. --}}
                        <div>
                            <label for="account-code" class="field-label">Code <span class="req">*</span></label>
                            <input id="account-code" name="code" type="text" inputmode="numeric" maxlength="4"
                                   class="field-input font-mono" required autocomplete="off" placeholder="5300">
                            <p class="mt-1.5 text-xs text-muted-foreground" data-code-hint>
                                Four digits, inside the band for the chosen type.
                            </p>
                            <p class="field-error hidden" data-error-for="code"></p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label for="account-name" class="field-label">Name <span class="req">*</span></label>
                        <input id="account-name" name="name" type="text" class="field-input" required
                               autocomplete="off" maxlength="120" placeholder="Workshop Rent">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div class="mt-4">
                        <label for="account-description" class="field-label">Description</label>
                        <textarea id="account-description" name="description" rows="2" maxlength="255"
                                  class="field-input !h-auto py-2"
                                  placeholder="What belongs in this account?"></textarea>
                        <p class="field-error hidden" data-error-for="description"></p>
                    </div>
                </section>

                {{-- Absent rather than blanked for a caller without the grant: a
                     disabled Save asks somebody to work out for themselves why it
                     will not press, and the workspace lands them on the list so
                     this surface is never the one they are looking at. --}}
                <div class="flex items-center justify-end gap-3" data-form-chrome="inline"
                     data-requires-permission="WRITE:ACCOUNTS">
                    <button type="submit" class="btn btn-primary">Create account</button>
                </div>

                <div class="hidden items-center justify-end gap-2 border-t border-border pt-4"
                     data-form-chrome="modal">
                    <button type="button" class="btn btn-ghost" data-account-edit-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ==================================================================
         Level 1, list mode. Exactly one of the two is in the DOM at a time —
         the other is held detached by the workspace, so a half-typed account
         and the list's period both survive every trip between them (§2A.2,
         §2A.6).
         ================================================================== --}}
    <div data-ws-list>

        {{-- Two views, one period. Removed outright for a caller without
             READ:LEDGER: with no figures anywhere there is only the chart, and a
             switch to a view that would be blank is a switch to nothing. --}}
        <div class="tab-strip mb-4" data-account-views role="tablist" aria-label="Accounting views"
             data-ledger-only>
            <button type="button" class="tab" role="tab" data-view="chart" aria-selected="true">
                <x-icon name="layers" :size="14" />
                Chart of accounts
            </button>
            <button type="button" class="tab" role="tab" data-view="trial" aria-selected="false">
                <x-icon name="bar-chart" :size="14" />
                Trial balance
            </button>
        </div>

        <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">

            {{-- Both of these narrow the chart, and both are hidden on the trial
                 balance. See the note at the top of this file: a statement whose
                 rows are filtered no longer adds up to the total under them. --}}
            <div class="search-pill min-w-52 flex-1" data-view-for="chart">
                <x-icon name="search" :size="16" />
                <input type="search" data-filter-search class="w-full bg-transparent text-sm outline-none"
                       placeholder="Account name or code…" aria-label="Search the chart of accounts">
            </div>

            <select data-filter-status class="field-input w-auto min-w-40" data-view-for="chart"
                    aria-label="Filter by archived state">
                <option value="1">Active only</option>
                <option value="0">Archived only</option>
                <option value="">Active &amp; archived</option>
            </select>

            {{-- The one control both views share, and the reason they are one
                 screen: it decides what every figure on either of them means. --}}
            <div class="flex flex-wrap items-center gap-2" data-ledger-only>
                <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
                    From
                    <input type="date" data-filter-from class="field-input w-auto" aria-label="Period from">
                </label>

                <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
                    To
                    <input type="date" data-filter-to class="field-input w-auto" aria-label="Period to">
                </label>

                <button type="button" data-clear-period class="btn btn-ghost btn-sm">Whole history</button>
            </div>

            <button type="button" data-export class="btn btn-secondary btn-sm ml-auto">
                <x-icon name="download" :size="14" />
                Export
            </button>
        </div>

        {{-- ---------------------------------------------------------------
             View 1 — the chart of accounts.
             --------------------------------------------------------------- --}}
        <div data-view-panel="chart">
            {{-- One tile per type: how many accounts, and what they come to.
                 There is deliberately no grand total — assets plus expenses is
                 not a number anybody wants. --}}
            <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-5" data-chart-tiles></div>

            <div class="space-y-3" data-chart-groups></div>

            <p data-chart-summary class="mt-4 text-[0.78125rem] text-muted-foreground"></p>
        </div>

        {{-- ---------------------------------------------------------------
             View 2 — the trial balance.

             The single most important figure in the module: if the two sides
             differ, everything else on this screen is suspect. So the
             reconciliation is *stated* above the table rather than left to be
             worked out by comparing two columns.
             --------------------------------------------------------------- --}}
        <div data-view-panel="trial" class="hidden" data-ledger-only>
            <div data-reconciliation class="mb-4"></div>

            <div class="surface overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                                       tracking-wide text-muted-foreground">
                                <th class="px-4 py-3 font-semibold">Account</th>
                                <th class="px-4 py-3 font-semibold">Type</th>
                                <th class="px-4 py-3 text-right font-semibold">Debits</th>
                                <th class="px-4 py-3 text-right font-semibold">Credits</th>
                                <th class="px-4 py-3 text-right font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody data-trial-body></tbody>
                        <tfoot data-trial-foot></tfoot>
                    </table>
                </div>

                <div class="border-t border-border px-4 py-3">
                    <p data-trial-summary class="text-[0.8125rem] text-muted-foreground"></p>
                </div>
            </div>
        </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One account, read without leaving the list — level 2.

    A drawer rather than a page: a balance is looked at while thinking about the
    row above it, and losing the list to see it is what makes people stop
    checking. It holds the running statement over the period the list is set to,
    the CSV of the whole of it, and — as a *state* of the same drawer, with the
    create form adopted into it — the edit.
--}}
<div id="account-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="account-drawer-title">
    <div class="drawer-panel max-w-[620px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-emerald-50 text-emerald-600">
                        <x-icon name="book-open" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="account-drawer-title"
                            class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p data-drawer-subtitle class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span data-drawer-status></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="px-6 py-5" data-drawer-body></div>

            {{-- Where the create form is adopted for an edit. Empty otherwise —
                 the fields live in exactly one place in this module. --}}
            <div class="hidden px-6 py-5" data-account-edit-slot></div>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-muted px-6 py-4" data-drawer-actions></div>
    </div>
</div>
