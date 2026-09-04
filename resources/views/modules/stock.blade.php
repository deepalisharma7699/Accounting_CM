
{{--
    The inventory menu: what is on the shelf, item by item and variant by
    variant.

    **Read-only, and it opens on its list.** §2A.10 names Stock as read-mostly,
    and it is the plainest case of it — nothing is created here. There is no
    level-1 create form and therefore no "Show list" switch: the module lands
    straight on the table, because "how many are left" is the only question
    anybody opens it to ask. `mountWorkspace(..., { canCreate: false })` is what
    says so, and it is the same frame every other module uses rather than a
    layout of this module's own.

    **The one control that changes stock is still here.** "Record a count" posts
    a stock-adjustment transaction like any other; it is not an add-item button
    and it is gated on WRITE:TRANSACTIONS, so most staff never see it. There is
    deliberately no field anywhere that writes a quantity directly — that would
    be a second write path, and everything M8 guarantees rests on there not
    being one.

    **A row is an item; its variants open underneath it.** The shelf holds a
    3 HP motor and a 5 HP motor, not "a motor", but a workshop with four variants
    per item scrolls four times as far if every one of them is a top-level row.
    So the item row carries the rolled-up position and expands to the variants
    behind it, and it is a *variant* that opens the stock card — the movements
    are what a variant has, not what a family has.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, and the only surface this module has. --}}
    <div data-ws-list>

    <header class="mb-6 flex flex-wrap items-start justify-end gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="search-pill w-60">
                <x-icon name="search" :size="15" />
                {{-- Reaches the variant label and the SKU as well as the item
                     name: a fitter looking for "1440" is after a motor by its
                     speed, and the family name is the one nobody remembers. --}}
                <input type="search" id="filter-search" class="w-full"
                       placeholder="Search item, code, SKU or rating…" aria-label="Search stock">
            </div>

            {{-- Category and archived sit behind this rather than on the
                 toolbar: they are set once and left alone, and two permanent
                 selects would crowd out the status pills, which are what people
                 actually reach for. --}}
            <div class="relative">
                <button type="button" id="filter-toggle" class="btn btn-secondary btn-sm h-[2.375rem]"
                        aria-expanded="false" aria-haspopup="true">
                    <x-icon name="sliders-horizontal" :size="14" />
                    Filter
                    <span id="filter-count"
                          class="hidden rounded-full bg-accent px-1.5 text-[0.6875rem] font-semibold text-primary"></span>
                </button>

                <div id="filter-panel" class="surface absolute right-0 top-full z-30 mt-1 hidden w-64 p-3">
                    {{-- The options are rows an admin edits, fetched from
                         GET /items/meta. Writing them here would be a copy that
                         goes stale the moment a category is added. --}}
                    <label for="filter-type" class="field-label">Category</label>
                    <select id="filter-type" class="field-input mb-3" aria-label="Filter by category">
                        <option value="">All categories</option>
                    </select>

                    <label for="filter-archived" class="field-label">Archived</label>
                    <select id="filter-archived" class="field-input"
                            aria-label="Include archived variants">
                        <option value="1">Active only</option>
                        <option value="0">Include archived</option>
                    </select>
                </div>
            </div>

            <div class="relative">
                <button type="button" id="sort-toggle" class="btn btn-secondary btn-sm h-[2.375rem]"
                        aria-expanded="false" aria-haspopup="true">
                    <x-icon name="arrow-up-down" :size="14" />
                    Sort
                </button>

                <div id="sort-panel" class="row-menu hidden" role="menu"></div>
            </div>

            {{-- Exports what the filters have narrowed to, not the page on
                 screen — a stock report that stopped at row 25 because that is
                 where the pager happened to be would be worse than no export. --}}
            <button type="button" id="export-csv" class="btn btn-secondary btn-sm h-[2.375rem]">
                <x-icon name="download" :size="14" />
                Export
            </button>

            {{-- The only way stock changes. There is deliberately no "edit
                 quantity" control beside it: a screen that could change a
                 position without writing a transaction would be a second write
                 path, and the whole module is built on there not being one. --}}
            <button type="button" id="new-adjustment" class="btn btn-primary btn-sm h-[2.375rem] hidden"
                    data-requires-permission="WRITE:TRANSACTIONS">
                <x-icon name="clipboard-list" :size="14" />
                Record a count
            </button>
        </div>
    </header>

    {{-- Shown only to somebody who can read the books, and only when the two
         sides differ. They are written in the same database transaction from the
         same figure, so a difference means something reached the Inventory
         account without a stock movement — almost always a manual journal, which
         M4 deliberately allows because it is the correction mechanism for
         everything else. A permanent "they agree" banner would be noise; one
         that only ever appears when they do not is the alarm it is meant to be. --}}
    <div id="reconciliation" class="mb-4 hidden"></div>

    {{-- The four figures. Three of them are also filters; stock value is a
         figure and nothing else, so it does not pretend to be clickable.

         Negative has a tile of its own rather than being folded into "low",
         because they are different problems: low stock is a purchasing
         decision, negative stock means a sale was recorded before the purchase
         that supplied it. A screen that showed them alike would train people to
         ignore the second. --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="stat-tile">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                <x-icon name="layers" :size="16" />
            </span>
            <span class="min-w-0">
                <span class="block truncate font-mono text-[22px] font-bold leading-none text-foreground"
                      id="stat-value">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Stock value</span>
            </span>
        </div>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="out">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-slate-100 text-slate-500">
                <x-icon name="inbox" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-out">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Out of stock</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="low">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                <x-icon name="alert-triangle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-low">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">At or below reorder</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="negative">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-rose-50 text-rose-500">
                <x-icon name="x-circle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-negative">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Negative</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>
    </div>

    {{-- The same five states the tiles filter to, named in full. The tiles are
         the shortcut and these are the control: one `state.status` behind both,
         so they can never disagree. --}}
    <div class="mb-5 flex flex-wrap items-center gap-2" id="filter-pills">
        <button type="button" class="pill" data-pill="" aria-pressed="true">Everything</button>
        <button type="button" class="pill" data-pill="in_stock" aria-pressed="false">In stock</button>
        <button type="button" class="pill" data-pill="low" aria-pressed="false">Low stock</button>
        <button type="button" class="pill" data-pill="out" aria-pressed="false">Out of stock</button>
        <button type="button" class="pill" data-pill="negative" aria-pressed="false">Negative</button>

        <button type="button" id="clear-filters"
                class="ml-1 hidden items-center gap-1 px-3 py-1.5 text-xs text-muted-foreground transition hover:text-secondary-foreground">
            <x-icon name="x" :size="12" />
            Clear filters
        </button>
    </div>

    <div class="surface overflow-hidden rounded-[14px]">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[920px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left">
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Item</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Category</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Variants</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">On hand</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Avg cost</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Value</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Status</th>
                    </tr>
                </thead>
                <tbody id="stock-body"></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
            <p id="stock-summary" class="text-[0.78125rem] text-muted-foreground"></p>

            <div class="flex items-center gap-1" id="stock-pager"></div>
        </div>
    </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One variant's stock card, read without leaving the list.

    A drawer rather than the centred modal the forms use, and for the reason the
    stylesheet gives: checking why a figure is what it is is a glance mid-scan,
    and the row you came from should still be visible so you can tell you opened
    the one you meant.
--}}
<div id="stock-card-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="stock-card-title">
    <div class="drawer-panel max-w-[44rem]">
        <div class="drawer-head justify-between">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-violet-50 text-violet-600">
                    <x-icon name="layers" :size="16" />
                </span>
                <div class="min-w-0">
                    <h3 id="stock-card-title"
                        class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                    <p id="stock-card-subtitle" class="truncate text-xs text-muted-foreground"></p>
                </div>
            </div>

            <button type="button" class="btn btn-ghost btn-icon shrink-0" data-modal-close aria-label="Close">
                <x-icon name="x" :size="16" />
            </button>
        </div>

        {{-- The position now, above the movements that add up to it. --}}
        <div id="stock-card-position" class="grid grid-cols-3 gap-3 border-b border-muted px-6 py-4"></div>

        <div class="flex-1 overflow-y-auto">
            <table class="w-full min-w-[560px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left text-[11.5px] uppercase tracking-wide text-muted-foreground">
                        <th class="px-4 py-3 font-semibold" scope="col">Date</th>
                        <th class="px-4 py-3 font-semibold" scope="col">Movement</th>
                        <th class="px-4 py-3 text-right font-semibold" scope="col">Qty</th>
                        <th class="px-4 py-3 text-right font-semibold" scope="col">Rate</th>
                        <th class="px-4 py-3 text-right font-semibold" scope="col">On hand</th>
                    </tr>
                </thead>
                <tbody id="stock-card-body"></tbody>
            </table>
        </div>

        <div class="drawer-foot">
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{-- Recording a count. Quantities here are the *difference* the count found,
     signed — which is why the field is labelled "difference" and not "quantity
     on hand": typing what is on the shelf and typing how far out the books were
     are different numbers, and confusing them would post the wrong one. --}}
<div id="adjustment-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="adjustment-title">
    <div class="modal-panel max-w-3xl">
        <form id="adjustment-form" novalidate>
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold text-foreground" id="adjustment-title">Record a count</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    Enter the difference the count found — <span class="font-medium">−2</span> for two fewer than
                    the books say, <span class="font-medium">+1</span> for one more. A shortage is written off at
                    what the books were carrying it at; found stock needs a cost.
                </p>
            </header>

            <div class="max-h-[55vh] space-y-4 overflow-y-auto px-5 py-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        <span class="field-label">Date</span>
                        <input type="date" name="date" class="field-input" required>
                        <span class="field-error" data-error="date"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Note</span>
                        <input type="text" name="notes" class="field-input" maxlength="500"
                               placeholder="Stock-take, March">
                        <span class="field-error" data-error="notes"></span>
                    </label>
                </div>

                <div id="adjustment-lines" class="space-y-3"></div>

                <button type="button" id="add-adjustment-line" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" :size="15" />
                    Add a line
                </button>

                <div class="field-error" data-error="adjustments"></div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Post the correction</button>
            </footer>
        </form>
    </div>
</div>
