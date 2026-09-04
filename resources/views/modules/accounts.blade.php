
{{--
    The Accounting screen from the design: three views of the books behind one
    tab strip — the ledgers and what they stand at, the journal entries that put
    them there, and the chart those ledgers are arranged into.

    They are one screen rather than three because they are one question asked at
    three zoom levels, and the answer to each is derived from the same rows. But
    they are *not* one grant. The chart is READ:ACCOUNTS, which is what this page
    is gated on; every balance on it comes from READ:LEDGER and every journal row
    from READ:TRANSACTIONS, and neither is implied by the first.

    So, exactly as the catalogue does with stock: elements that need a grant this
    page does not itself carry are marked `data-ledger-only` or carry
    `data-requires-permission`, and are **removed** rather than blanked. A column
    of dashes would read as "these accounts are all at zero" when it means "not
    yours to see", and that is a worse answer than no column at all.
--}}
<div class="mx-auto max-w-[1280px]">

    <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold leading-tight tracking-tight text-foreground">Accounting</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                View accounting records, ledger balances, and manage the chart of accounts.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- One search box for three tabs, and it is cleared when the tab
                 changes: the placeholder names what is being searched, so a term
                 carried over would silently be matched against a different
                 kind of record. --}}
            <div class="search-pill w-60">
                <x-icon name="search" :size="15" />
                <input type="search" id="filter-search" class="w-full"
                       placeholder="Search ledgers, account code…" aria-label="Search">
            </div>

            {{-- Type and archived-state live behind this rather than on the
                 toolbar. They are set once and left alone, and two permanent
                 selects would crowd out the pills people actually reach for. --}}
            <div class="relative" data-panel-for="ledger coa">
                <button type="button" id="filter-toggle" class="btn btn-secondary btn-sm h-[2.375rem]"
                        aria-expanded="false" aria-haspopup="true">
                    <x-icon name="sliders-horizontal" :size="14" />
                    Filter
                    <span id="filter-count"
                          class="hidden rounded-full bg-accent px-1.5 text-[0.6875rem] font-semibold text-primary"></span>
                </button>

                <div id="filter-panel" class="surface absolute right-0 top-full z-30 mt-1 hidden w-64 p-3">
                    <label for="filter-status" class="field-label">Archived</label>
                    <select id="filter-status" class="field-input mb-3" aria-label="Filter by archived state">
                        <option value="1">Active only</option>
                        <option value="0">Archived only</option>
                        <option value="">Active &amp; archived</option>
                    </select>

                    <label for="filter-side" class="field-label">Normal balance</label>
                    <select id="filter-side" class="field-input" aria-label="Filter by normal balance">
                        <option value="">Debit &amp; credit</option>
                        <option value="debit">Increases on debit</option>
                        <option value="credit">Increases on credit</option>
                    </select>
                </div>
            </div>

            {{-- Dates, for the journal tab only — a period means nothing to a
                 chart of accounts. --}}
            <div class="relative hidden" data-panel-for="journal" data-requires-permission="READ:TRANSACTIONS">
                <button type="button" id="period-toggle" class="btn btn-secondary btn-sm h-[2.375rem]"
                        aria-expanded="false" aria-haspopup="true">
                    <x-icon name="clock" :size="14" />
                    Period
                    <span id="period-count"
                          class="hidden rounded-full bg-accent px-1.5 text-[0.6875rem] font-semibold text-primary"></span>
                </button>

                <div id="period-panel" class="surface absolute right-0 top-full z-30 mt-1 hidden w-64 p-3">
                    <label for="filter-from" class="field-label">From</label>
                    <input type="date" id="filter-from" class="field-input mb-3" aria-label="From date">

                    <label for="filter-to" class="field-label">To</label>
                    <input type="date" id="filter-to" class="field-input mb-3" aria-label="To date">

                    <button type="button" id="clear-period" class="btn btn-secondary btn-sm w-full">
                        Clear period
                    </button>
                </div>
            </div>

            {{-- Exports what is on screen, not what is in the books: the rows the
                 current tab, search and filters have narrowed to. Anything else
                 would hand somebody a file that disagrees with the table they
                 were looking at when they asked for it. --}}
            <button type="button" id="export-csv" class="btn btn-secondary btn-sm h-[2.375rem]">
                <x-icon name="download" :size="14" />
                Export
            </button>

            <button type="button" id="new-account" class="btn btn-primary btn-sm hidden h-[2.375rem]"
                    data-requires-permission="WRITE:ACCOUNTS">
                <x-icon name="plus" :size="15" />
                New account
            </button>
        </div>
    </header>

    <div class="tab-strip mb-6" id="accounting-tabs" role="tablist" aria-label="Accounting views">
        <button type="button" class="tab" role="tab" data-tab="ledger" aria-selected="true"
                aria-controls="panel-ledger">
            <x-icon name="book-open" :size="14" />
            Ledger Accounts
        </button>

        {{-- Journal entries are transactions, which is a different grant from the
             chart this page is gated on. Removed outright for a user without it,
             rather than shown and then failing on the fetch. --}}
        <button type="button" class="tab" role="tab" data-tab="journal" aria-selected="false"
                aria-controls="panel-journal" data-requires-permission="READ:TRANSACTIONS">
            <x-icon name="file-text" :size="14" />
            Journal Entries
        </button>

        <button type="button" class="tab" role="tab" data-tab="coa" aria-selected="false"
                aria-controls="panel-coa">
            <x-icon name="layers" :size="14" />
            Chart of Accounts
        </button>
    </div>

    {{-- ================================================================== --}}
    {{-- Tab 1 — Ledger Accounts                                            --}}
    {{-- ================================================================== --}}
    <section id="panel-ledger" role="tabpanel" aria-label="Ledger accounts">

        {{-- Counts, not balances, so these four survive a user who holds
             READ:ACCOUNTS without READ:LEDGER. --}}
        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="stat-tile">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                    <x-icon name="book-open" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-ledgers">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Total Ledgers</span>
                </span>
            </div>

            <button type="button" class="stat-tile stat-tile-action" data-stat-filter="asset">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                    <x-icon name="dollar-sign" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-assets">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Asset Accounts</span>
                </span>
                <span class="ml-auto text-border"><x-icon name="chevron-right" :size="14" /></span>
            </button>

            <button type="button" class="stat-tile stat-tile-action" data-stat-filter="liability">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-rose-50 text-rose-500">
                    <x-icon name="credit-card" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-liabilities">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Liability Accounts</span>
                </span>
                <span class="ml-auto text-border"><x-icon name="chevron-right" :size="14" /></span>
            </button>

            <button type="button" class="stat-tile stat-tile-action" data-stat-filter="pl">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-purple-50 text-purple-600">
                    <x-icon name="bar-chart" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-pl">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Income &amp; Expenses</span>
                </span>
                <span class="ml-auto text-border"><x-icon name="chevron-right" :size="14" /></span>
            </button>
        </div>

        <div class="mb-5 flex flex-wrap items-center gap-2" id="ledger-pills">
            <button type="button" class="pill" data-pill="all" aria-pressed="true">All Accounts</button>
            <button type="button" class="pill" data-pill="asset" aria-pressed="false">Assets</button>
            <button type="button" class="pill" data-pill="liability" aria-pressed="false">Liabilities</button>
            <button type="button" class="pill" data-pill="income" aria-pressed="false">Income</button>
            <button type="button" class="pill" data-pill="expense" aria-pressed="false">Expenses</button>
            <button type="button" class="pill" data-pill="equity" aria-pressed="false">Equity</button>
        </div>

        <div class="surface overflow-visible rounded-[14px]">
            <div class="overflow-x-auto rounded-t-[14px]">
                <table class="w-full min-w-[860px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-background text-left">
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Ledger Name</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Account Type</th>
                            <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col" data-ledger-only>Balance</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Last Updated</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Status</th>
                            <th class="px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="ledger-body" class="divide-y divide-muted"></tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
                <p id="ledger-summary" class="text-[0.78125rem] text-muted-foreground"></p>

                <button type="button" id="add-ledger" class="btn btn-secondary btn-sm hidden"
                        data-requires-permission="WRITE:ACCOUNTS">
                    <x-icon name="plus" :size="13" />
                    Add Ledger
                </button>
            </div>
        </div>
    </section>

    {{-- ================================================================== --}}
    {{-- Tab 2 — Journal Entries                                            --}}
    {{-- ================================================================== --}}
    <section id="panel-journal" role="tabpanel" aria-label="Journal entries" class="hidden">

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="stat-tile">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                    <x-icon name="file-text" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-entries">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Total Entries</span>
                </span>
            </div>

            <div class="stat-tile">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                    <x-icon name="check-circle" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-posted">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Posted</span>
                </span>
            </div>

            <div class="stat-tile">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                    <x-icon name="clipboard-list" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-drafts">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Drafts</span>
                </span>
            </div>

            {{-- Reversed rather than the design's "AI Generated". The counts
                 endpoint publishes a breakdown by type and by status and not by
                 source, so an AI tile here could only ever show a zero it had
                 not counted — and a fabricated zero on a tile is worse than a
                 tile that answers a slightly different question. Filtering by
                 source is still available on the pills below, which is a filter
                 rather than a claim about a total. --}}
            <div class="stat-tile">
                <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-purple-50 text-purple-600">
                    <x-icon name="refresh-cw" :size="16" />
                </span>
                <span>
                    <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-reversed">—</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Reversed</span>
                </span>
            </div>
        </div>

        {{-- Sources rather than the design's mix of types and sources: "AI
             Generated" there is a source and "Sale" is a type, and one pill row
             that switched meaning halfway along would filter on whichever the
             last click happened to mean. Types are on the Transactions screen,
             which is the screen organised around them. --}}
        <div class="mb-5 flex flex-wrap items-center gap-2" id="journal-pills">
            <button type="button" class="pill" data-pill="all" aria-pressed="true">All Entries</button>
            @foreach (\App\Enums\TransactionSource::cases() as $source)
                <button type="button" class="pill" data-pill="{{ $source->value }}"
                        aria-pressed="false">{{ $source->label() }}</button>
            @endforeach
        </div>

        <div class="surface overflow-visible rounded-[14px]">
            <div class="overflow-x-auto rounded-t-[14px]">
                <table class="w-full min-w-[920px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-background text-left">
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Journal ID</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Date</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Particulars</th>
                            <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Debit</th>
                            <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Credit</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Status</th>
                            <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                                scope="col">Source</th>
                            <th class="px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="journal-body" class="divide-y divide-muted"></tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
                <p id="journal-summary" class="text-[0.78125rem] text-muted-foreground"></p>

                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1" id="journal-pager"></div>

                    {{-- Says what this screen is: a reader. Writing a voucher
                         happens on Transactions, where the forms live. --}}
                    <span class="flex items-center gap-2 rounded-[8px] border border-border bg-background px-3 py-1.5">
                        <x-icon name="lock" :size="11" class="text-muted-foreground" />
                        <span class="text-[0.71875rem] text-muted-foreground">Read only — post entries from Transactions</span>
                    </span>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --}}
    {{-- Tab 3 — Chart of Accounts                                          --}}
    {{-- ================================================================== --}}
    <section id="panel-coa" role="tabpanel" aria-label="Chart of accounts" class="hidden">
        <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-5" id="coa-tiles" data-ledger-only></div>

        <div class="space-y-3" id="coa-groups"></div>

        <p id="coa-summary" class="mt-4 text-[0.78125rem] text-muted-foreground"></p>
    </section>
</div>

{{--
    One ledger, read without leaving the list.

    A drawer rather than a page: the balance is looked at while thinking about
    the row above it, and losing the list to see it is what makes people stop
    checking.
--}}
<div id="ledger-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="ledger-drawer-title">
    <div class="drawer-panel max-w-[560px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600">
                        <x-icon name="book-open" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="ledger-drawer-title"
                            class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p id="ledger-drawer-subtitle" class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="ledger-drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="ledger-drawer-body"></div>

        <div class="flex gap-2 border-t border-muted px-6 py-4">
            <button type="button" id="ledger-drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:ACCOUNTS">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" id="ledger-drawer-statement" class="btn btn-secondary btn-sm hidden" data-ledger-only>
                <x-icon name="download" :size="13" />
                Statement
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{-- One journal entry: what it did to the books, line by line. --}}
<div id="journal-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="journal-drawer-title">
    <div class="drawer-panel max-w-[560px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600">
                        <x-icon name="file-text" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="journal-drawer-title"
                            class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p id="journal-drawer-subtitle" class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="journal-drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="journal-drawer-body"></div>

        <div class="flex gap-2 border-t border-muted px-6 py-4">
            <a id="journal-drawer-open" href="#" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-up-right" :size="13" />
                Open in Transactions
            </a>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{-- Create / edit an account --}}
<div id="account-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="account-modal-title">
    <div class="modal-panel max-w-lg">
        <form id="account-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h2 id="account-modal-title" class="text-base font-bold text-foreground">New account</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[65vh] space-y-4 overflow-y-auto px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                {{-- Shown for system accounts, whose structure is fixed. --}}
                <p id="account-system-note"
                   class="hidden items-start gap-2 rounded-[10px] border border-amber-200 bg-amber-50/60 px-3.5 py-3
                          text-[0.8125rem] text-amber-800">
                    <x-icon name="lock" :size="16" class="mt-0.5 shrink-0" />
                    <span>
                        This is a system account. You may rename it and edit its description — the posting
                        engine finds it by an internal key, not by its name — but its number and type are fixed.
                    </span>
                </p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="account-type" class="field-label">Type</label>
                        <select id="account-type" name="type" class="field-input" required>
                            <option value="">Select a type…</option>
                            @foreach (\App\Enums\AccountType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="account-type-hint">
                            Decides which side increases the account.
                        </p>
                        <p class="field-error hidden" data-error-for="type"></p>
                    </div>

                    <div>
                        <label for="account-code" class="field-label">Code</label>
                        <input id="account-code" name="code" type="text" inputmode="numeric" maxlength="4"
                               class="field-input font-mono" required autocomplete="off" placeholder="5300">
                        <p class="mt-1.5 text-xs text-muted-foreground" id="account-code-hint">
                            Four digits, inside the band for the chosen type.
                        </p>
                        <p class="field-error hidden" data-error-for="code"></p>
                    </div>
                </div>

                <div>
                    <label for="account-name" class="field-label">Name</label>
                    <input id="account-name" name="name" type="text" class="field-input" required
                           autocomplete="off" placeholder="Workshop Rent">
                    <p class="field-error hidden" data-error-for="name"></p>
                </div>

                <div>
                    <label for="account-description" class="field-label">Description</label>
                    <textarea id="account-description" name="description" rows="2" class="field-input !h-auto py-2"
                              placeholder="What belongs in this account?"></textarea>
                    <p class="field-error hidden" data-error-for="description"></p>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save account</button>
            </div>
        </form>
    </div>
</div>


