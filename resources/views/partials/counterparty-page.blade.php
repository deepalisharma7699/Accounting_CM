{{--
    The Customers and Vendors screens, which are one screen twice.

    They are the design's two counterparty screens, and they differ only in
    wording and in which side of the position they lead with — so the markup
    lives here once and each page supplies a `$copy` array rather than a copy of
    this file. Two near-identical templates is how "Outstanding" gets fixed on
    one screen and left wrong on the other.

    Both read the same `parties` table, filtered on role *membership*. A
    counterparty holding both roles appears on both screens, marked as such,
    because they are one record with one combined ledger — the alternative is
    two records whose halves of a single balance never meet, which is precisely
    what the parties table exists to prevent.

    $copy keys: role, otherNoun, noun, nounPlural, icon, tone, addLabel,
    formSubtitle, searchLabel, namePlaceholder, nameColumn, outstandingColumn,
    dateColumn, dueLabel, historyTab, lifetimeLabel, sinceLabel, createLabel.

    The heading and the two subtitles are not among them. They belong to the
    workspace, which paints them from the config in resources/js/pages/
    counterparty.js — a copy here would be a second place for a module to be
    renamed in.
--}}
<div class="mx-auto max-w-[1280px]">

    {{--
        Level 1, form mode — where the module lands (§2A.1).

        The form itself is not written here: it is `#quick-party-form`, the one
        the bill counter's pickers open, *moved* into this slot. Create is a
        level-1 surface and edit is a level-2 one, and a module that wrote the
        fields out twice would have two sets of ids and two places for a
        validation rule to be added to only one of (§4.4, §5.1). `adoptForm()` in
        resources/js/workspace.js does the moving.
    --}}
    <div data-ws-form>
        <section class="surface form-card">
            <div class="form-head">
                <span class="tile-icon {{ $copy['tone'] }}">
                    <x-icon :name="$copy['icon']" :size="17" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-foreground">{{ $copy['addLabel'] }}</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">{{ $copy['formSubtitle'] }}</p>
                </div>
            </div>

            <div data-party-form-slot></div>
        </section>
    </div>

    {{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
         other is held detached by the workspace, so its search, its filters and
         its fetched rows survive every trip to the form and back (§2A.2,
         §2A.6). --}}
    <div data-ws-list>

    {{-- No title and no "Add" button: the heading and the one switch control
         between the two surfaces belong to the workspace, in the same slot in
         both modes. A second create button here is what §2A.3 forbids. --}}
    <header class="mb-6 flex flex-wrap items-start justify-end gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="search-pill w-56">
                <x-icon name="search" :size="15" />
                <input type="search" id="filter-search" class="w-full"
                       placeholder="{{ $copy['searchLabel'] }}" aria-label="Search {{ $copy['nounPlural'] }}">
            </div>

            {{-- Archived and "has a GSTIN" live behind this rather than on the
                 toolbar: they are set once and left alone, and two permanent
                 selects would crowd out the filters people actually reach for. --}}
            <div class="relative">
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

                    <label for="filter-gstin" class="field-label">GSTIN</label>
                    <select id="filter-gstin" class="field-input mb-3" aria-label="Filter by GSTIN">
                        <option value="">With &amp; without</option>
                        <option value="1">Has a GSTIN</option>
                        <option value="0">No GSTIN</option>
                    </select>

                    {{-- The counterparty who is both. Worth a filter of its own:
                         they are the ones whose two positions get settled
                         against each other, and they are invisible on a screen
                         that only ever shows one side. --}}
                    <label for="filter-both" class="field-label">Also a {{ $copy['otherNoun'] }}</label>
                    <select id="filter-both" class="field-input" aria-label="Filter by dual role">
                        <option value="">Either way</option>
                        <option value="1">Only those who are both</option>
                        <option value="0">{{ ucfirst($copy['nounPlural']) }} only</option>
                    </select>
                </div>
            </div>

            <div class="relative">
                <button type="button" id="sort-toggle" class="btn btn-secondary btn-sm h-[2.375rem]"
                        aria-expanded="false" aria-haspopup="true">
                    <x-icon name="arrow-up-down" :size="14" />
                    Sort
                </button>

                <div id="sort-panel" class="row-menu hidden"></div>
            </div>
        </div>
    </header>

    {{-- The four figures. Three of them are also filters; the total is a count
         and nothing else, so it does not pretend to be clickable. --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="stat-tile">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                <x-icon :name="$copy['icon']" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-total">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Total {{ ucfirst($copy['nounPlural']) }}</span>
            </span>
        </div>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="settled">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                <x-icon name="check-circle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-settled">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Settled</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="due">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                <x-icon name="alert-triangle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-due">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">{{ $copy['dueLabel'] }}</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="recent">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-violet-50 text-violet-600">
                <x-icon name="user-plus" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-recent">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Recently Added</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2" id="filter-pills">
        <button type="button" class="pill" data-pill="all" aria-pressed="true">All {{ ucfirst($copy['nounPlural']) }}</button>
        <button type="button" class="pill" data-pill="settled" aria-pressed="false">Settled</button>
        <button type="button" class="pill" data-pill="due" aria-pressed="false">{{ $copy['dueLabel'] }}</button>
        <button type="button" class="pill" data-pill="recent" aria-pressed="false">Recently Added</button>

        <button type="button" id="clear-filters"
                class="ml-1 hidden items-center gap-1 px-3 py-1.5 text-xs text-muted-foreground transition hover:text-secondary-foreground">
            <x-icon name="x" :size="12" />
            Clear filters
        </button>
    </div>

    <div class="surface overflow-visible rounded-[14px]">
        <div class="overflow-x-auto rounded-t-[14px]">
            <table class="w-full min-w-[900px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left" id="parties-head">
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="name" scope="col">{{ $copy['nameColumn'] }}</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Phone</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">GSTIN</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="outstanding" scope="col">{{ $copy['outstandingColumn'] }}</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="activity" scope="col">{{ $copy['dateColumn'] }}</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="status" scope="col">Status</th>
                        <th class="px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="parties-body" class="divide-y divide-muted"></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
            <p id="parties-summary" class="text-[0.78125rem] text-muted-foreground"></p>

            <div class="flex items-center gap-1" id="parties-pager"></div>
        </div>
    </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One counterparty, read without leaving the list.

    A drawer rather than a page of its own: their history is read while thinking
    about them, and losing the list to see it is what makes people stop looking.
--}}
<div id="party-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="drawer-title">
    <div class="drawer-panel max-w-[500px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary
                                 text-sm font-bold text-primary-foreground" id="drawer-initials"></span>
                    <div class="min-w-0">
                        <h3 id="drawer-title" class="truncate text-base font-bold leading-tight text-foreground"></h3>
                        <p id="drawer-subtitle" class="mt-0.5 truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            {{-- The two figures the drawer exists to answer: what is left owing,
                 and how much has gone through the relationship. --}}
            <div class="mt-4 grid grid-cols-2 gap-2">
                {{-- The label as well as the figure is set by the JS: a
                     position that has gone the other way is an advance, not a
                     negative debt, and "Outstanding −₹5,000" is the reading that
                     sends somebody chasing money the workshop is holding. --}}
                <div class="rounded-[10px] px-3 py-2.5" id="drawer-outstanding-tile">
                    <p class="mb-0.5 text-[11px] text-muted-foreground" id="drawer-outstanding-label">
                        {{ $copy['outstandingColumn'] }}
                    </p>
                    <p class="text-[17px] font-bold" id="drawer-outstanding">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">{{ $copy['lifetimeLabel'] }}</p>
                    <p class="text-[17px] font-bold text-foreground" id="drawer-lifetime">—</p>
                </div>
            </div>
        </div>

        <div class="tab-strip shrink-0 px-6 pt-3" role="tablist" id="drawer-tabs">
            <button type="button" class="tab" role="tab" data-tab="overview" aria-selected="true">Overview</button>
            <button type="button" class="tab" role="tab" data-tab="history" aria-selected="false"
                    data-requires-permission="READ:TRANSACTIONS">{{ $copy['historyTab'] }}</button>
            <button type="button" class="tab" role="tab" data-tab="payments" aria-selected="false"
                    data-requires-permission="READ:TRANSACTIONS">Payments</button>
            <button type="button" class="tab" role="tab" data-tab="activity" aria-selected="false"
                    data-requires-permission="READ:AUDIT">Activity</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="drawer-body"></div>

        <div class="flex gap-2 border-t border-muted px-6 py-4">
            <button type="button" id="drawer-statement" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="READ:LEDGER">
                <x-icon name="file-text" :size="13" />
                Statement
            </button>
            <button type="button" id="drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:PARTIES">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{-- Create / edit — the shared record form, which the bill counter opens too.
     Included rather than written here: it was a copy of the counter's drawer
     with different fields and different validation, and the two had already
     drifted. See components/quick-party.js. --}}
@include('partials.quick-party-modal')

{{-- The statement. Every entry that moved their position, both sides of it, for
     the counterparty who is both — a combined ledger, not this screen's half. --}}
<div id="party-ledger-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="party-ledger-title">
    <div class="modal-panel max-w-4xl">
        <div class="flex items-start justify-between gap-4 border-b border-border px-6 py-4">
            <div>
                <h2 id="party-ledger-title" class="text-base font-bold text-foreground">Statement</h2>
                <p id="party-ledger-subtitle" class="mt-0.5 text-[0.8125rem] text-muted-foreground"></p>
            </div>
            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </div>

        <div id="party-ledger-position" class="border-b border-border px-6 py-4"></div>

        <div class="max-h-[50vh] overflow-y-auto">
            <table class="w-full min-w-[700px] border-collapse">
                <thead class="sticky top-0 bg-card">
                    <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="px-6 py-3 font-semibold">Date</th>
                        <th class="px-4 py-3 font-semibold">Particulars</th>
                        <th class="px-4 py-3 text-right font-semibold">Debit</th>
                        <th class="px-4 py-3 text-right font-semibold">Credit</th>
                        <th class="px-6 py-3 text-right font-semibold">Balance</th>
                    </tr>
                </thead>
                <tbody id="party-ledger-rows"></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-6 py-3">
            <p id="party-ledger-summary" class="text-[0.8125rem] text-muted-foreground"></p>

            <div class="flex gap-2">
                <button type="button" id="ledger-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
                <button type="button" id="ledger-next" class="btn btn-secondary btn-sm" disabled>Next</button>
            </div>
        </div>
    </div>
</div>

