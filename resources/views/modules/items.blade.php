
{{--
    The catalogue, laid out as the Inventory screen in the design: the four
    figures first, then the filters, then the table.

    The screen reads as "what we sell and how much of it is left", which is one
    question even though it is answered by two modules. The catalogue is M7's and
    the quantities are M8's, and they arrive on separate requests behind separate
    grants — so every stock-bearing element here is marked
    `data-stock-only` and removed outright for a user who holds READ:ITEMS
    without READ:STOCK. Blanked columns would read as "nothing on the shelf"
    when they mean "not yours to see".
--}}
<div class="mx-auto max-w-[1280px]">

    {{--
        Level 1, form mode — where the module lands (§2A.1).

        The form itself is not written here: it is `#item-form`, declared once
        further down and *moved* into this slot. Create is a level-1 surface and
        edit is a level-2 one, and a module that wrote the fields out twice would
        have two sets of ids and two places for a rule to be added to only one of
        (§4.4). `adoptForm()` in resources/js/workspace.js does the moving.
    --}}
    <div data-ws-form>
        <section class="surface form-card">
            <div class="form-head">
                <span class="tile-icon bg-blue-50 text-blue-600">
                    <x-icon name="package" :size="17" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-foreground">Create inventory item</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        One form for every kind of product. Pick a category and it asks for what that
                        category records.
                    </p>
                </div>

                {{-- The Category and Unit masters. Beside the heading rather than
                     behind a menu, because "the field I need is missing" is the
                     commonest reason somebody stops halfway through this form. --}}
                <button type="button" id="manage-catalogue" class="btn btn-ghost btn-sm shrink-0"
                        data-requires-permission="UPDATE:ITEMS">
                    <x-icon name="settings" :size="15" />
                    Categories &amp; units
                </button>
            </div>

            <div data-item-form-slot></div>

            <p class="hint mt-5">
                <x-icon name="info" :size="15" />
                <span>
                    Category and unit are fixed once the item exists: reclassifying one would
                    reinterpret every quantity ever recorded against it.
                </span>
            </p>
        </section>
    </div>

    {{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
         other is held detached by the workspace, so its filters and its fetched
         rows survive every trip to the form and back (§2A.2, §2A.6). --}}
    <div data-ws-list>

    <header class="mb-6 flex flex-wrap items-start justify-end gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="search-pill w-56">
                <x-icon name="search" :size="15" />
                {{-- Search reaches variant labels and SKUs too: a fitter looking
                     for "1440" is after a motor by its speed, and the family name
                     is the one thing nobody remembers. --}}
                <input type="search" id="filter-search" class="w-full"
                       placeholder="Search items, code, HSN, SKU…" aria-label="Search items">
            </div>

            {{-- Type, stock-tracking and archived live behind this rather than
                 sitting on the toolbar: they are set once and left alone, and
                 three permanent selects would crowd out the filters people
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
                    {{-- Options come from GET /items/meta. The categories are
                         rows an admin edits, so rendering them here would be a
                         copy that goes stale the moment one is added. --}}
                    <label for="filter-type" class="field-label">Category</label>
                    <select id="filter-type" class="field-input mb-3" aria-label="Filter by category">
                        <option value="">All categories</option>
                    </select>

                    <label for="filter-stock" class="field-label">Stock tracking</label>
                    <select id="filter-stock" class="field-input mb-3" aria-label="Filter by stock tracking">
                        <option value="">Stocked &amp; not stocked</option>
                        <option value="1">Stocked</option>
                        <option value="0">Not stocked</option>
                    </select>

                    <label for="filter-status" class="field-label">Archived</label>
                    <select id="filter-status" class="field-input" aria-label="Filter by archived state">
                        <option value="1">Active only</option>
                        <option value="0">Archived only</option>
                        <option value="">Active &amp; archived</option>
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

            {{-- There is no "Add Item" button here. The one switch control at
                 the right edge of the module heading is how the form is reached,
                 and a second control doing the same thing is exactly what §2A.3
                 forbids. --}}
        </div>
    </header>

    {{-- The review queue, surfaced rather than hidden behind a filter. Items an
         import or the capture agent invented are real items that stock may
         already have been posted against, and nobody goes looking for a queue
         they were not told about. Shown only when there is something in it. --}}
    <button type="button" id="draft-banner"
            class="surface mb-4 hidden w-full items-center gap-3 border-amber-200 bg-amber-50/60 px-4 py-3 text-left
                   transition hover:bg-amber-50">
        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-amber-100 text-amber-700">
            <x-icon name="clipboard-list" :size="18" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-amber-900" id="draft-banner-title"></span>
            <span class="block text-[0.8125rem] text-amber-800">
                Auto-created from an import or a capture and not yet checked. They are already usable —
                reviewing one only confirms it.
            </span>
        </span>
        <span class="btn btn-secondary btn-sm shrink-0">Review</span>
    </button>

    {{-- The four figures. Three of them are also filters; "Total items" is a
         count and nothing else, so it does not pretend to be clickable. --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="stat-tile">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                <x-icon name="package" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-total">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Total Items</span>
            </span>
        </div>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="in_stock" data-stock-only>
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                <x-icon name="check-circle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-in-stock">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">In Stock</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="low" data-stock-only>
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                <x-icon name="alert-triangle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-low">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Low Stock</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="out" data-stock-only>
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-rose-50 text-rose-500">
                <x-icon name="x-circle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-out">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Out of Stock</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2" id="filter-pills">
        <button type="button" class="pill" data-pill="all" aria-pressed="true">All Items</button>
        <button type="button" class="pill" data-pill="in_stock" aria-pressed="false" data-stock-only>In Stock</button>
        <button type="button" class="pill" data-pill="low" aria-pressed="false" data-stock-only>Low Stock</button>
        <button type="button" class="pill" data-pill="out" aria-pressed="false" data-stock-only>Out of Stock</button>
        <button type="button" class="pill" data-pill="recent" aria-pressed="false">Recently Added</button>

        <button type="button" id="clear-filters"
                class="ml-1 hidden items-center gap-1 px-3 py-1.5 text-xs text-muted-foreground transition hover:text-secondary-foreground">
            <x-icon name="x" :size="12" />
            Clear filters
        </button>
    </div>

    <div class="surface overflow-visible rounded-[14px]">
        <div class="overflow-x-auto rounded-t-[14px]">
            <table class="w-full min-w-[980px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left" id="items-head">
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="name" scope="col">Item Name</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="type" scope="col">Category</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="code" scope="col">Code</th>
                        {{-- How many things are actually on the shelf under this
                             family. Counted with the page by the API rather than
                             stored on the item, so adding or removing a variant
                             moves it without anything having to remember to. --}}
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="variants" scope="col">Variants</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="stock" scope="col" data-stock-only>Stock</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Unit</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="cost" scope="col" data-stock-only>Avg Cost</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="price" scope="col" data-stock-only>Selling Price</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="status" scope="col">Status</th>
                        <th class="px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="items-body" class="divide-y divide-muted"></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
            <p id="items-summary" class="text-[0.78125rem] text-muted-foreground"></p>

            <div class="flex items-center gap-1" id="items-pager"></div>
        </div>
    </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One item, read without leaving the list.

    A drawer rather than a page of its own: variants and stock are read while
    thinking about the family, and losing the list to see them is what makes
    people stop looking.
--}}
@include('partials.catalogue-master-drawer')

<div id="item-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="drawer-title">
    <div class="drawer-panel max-w-[500px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="mb-1 flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600">
                        <x-icon name="package" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="drawer-title" class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p id="drawer-subtitle" class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            {{-- Only when there is something to act on. A banner that is always
                 there is a banner nobody reads. --}}
            <div id="drawer-alert"
                 class="mt-3 hidden items-center gap-2.5 rounded-[10px] border border-amber-100 bg-amber-50 px-3 py-2.5">
                <span class="shrink-0 text-amber-500"><x-icon name="alert-triangle" :size="14" /></span>
                <p class="flex-1 text-[0.78125rem] font-medium text-amber-700" id="drawer-alert-text"></p>

                {{-- Only ever shown for the review message, and only to somebody
                     who may write items. Here as well as on the row menu because
                     this is where a draft is actually read: the alternative is
                     checking the product, closing the drawer, finding the row
                     again and opening its menu.

                     Gated in renderDrawerAlert() rather than by
                     `data-requires-permission`: that gate works by toggling
                     `hidden`, and so does the draft condition, so declaring both
                     would leave whichever ran last as the answer. --}}
                <button type="button" id="drawer-clear-draft"
                        class="btn btn-ghost btn-sm hidden shrink-0 text-amber-700">Mark as checked</button>
            </div>
        </div>

        <div class="tab-strip shrink-0 px-6 pt-3" role="tablist" id="drawer-tabs">
            <button type="button" class="tab" role="tab" data-tab="overview" aria-selected="true">Overview</button>
            <button type="button" class="tab" role="tab" data-tab="variants" aria-selected="false">Variants</button>
            <button type="button" class="tab" role="tab" data-tab="history" aria-selected="false"
                    data-stock-only>Stock History</button>
            <button type="button" class="tab" role="tab" data-tab="activity" aria-selected="false"
                    data-requires-permission="READ:AUDIT">Activity</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="drawer-body"></div>

        <div class="flex gap-2 border-t border-muted px-6 py-4">
            <button type="button" id="drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:ITEMS">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" id="drawer-add-variant" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="WRITE:ITEMS">
                <x-icon name="plus" :size="13" />
                Add variant
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{--
    Correcting what is on the shelf under one variant — level 3, over the drawer.

    The form is not written here. It is `partials/stock-adjust.blade.php` and
    `components/stock-adjust.js`, which is also the Stock screen's "Record a
    count" — one act, entered two ways. This side types **what the count found**
    and the component subtracts what the books say; Stock types the difference
    directly. A second copy of a form that writes to the stock ledger is the last
    thing this application should have two of (§5.1).

    It is not an "edit quantity" field and there is deliberately no such thing
    anywhere: what it posts is a stock adjustment like any other, through the
    posting engine and the stock ledger (§4.3).
--}}
@include('partials.stock-adjust')

{{--
    Editing an item family — level 2.

    The panel is empty in the markup: `#item-form` below starts life in the
    level-1 slot and is moved in here when a row is edited, and back out again
    afterwards. One form, one set of ids, one submit handler.

    The parts that only make sense in a dialog — the title bar, the close
    button, the Cancel button, the inner scroller — are marked
    `data-form-chrome="modal"` and shown only while the form is in here. The
    level-1 alternatives are marked `data-form-chrome="inline"`.
--}}
<div id="item-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="item-modal-title">
    <div class="modal-panel max-w-2xl" data-item-modal-slot></div>
</div>

<form id="item-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-start justify-between border-b border-muted px-6 py-5 hidden"
                 data-form-chrome="modal">
                <div>
                    <h2 id="item-modal-title" class="text-base font-semibold text-foreground">Add product</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="item-modal-subtitle">
                        The fields below the category are the ones that category asks for.
                    </p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-6" data-form-body>
                {{-- ── The product ─────────────────────────────────────────── --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="item-name" class="field-label">Product name</label>
                        <input id="item-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="e.g. Crompton 3-Phase Induction Motor">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    {{-- The category decides which fields appear below, the unit,
                         whether stock is possible and how it is taxed — so it is
                         fixed once the product exists: reclassifying would
                         reinterpret a specification keyed by the old category's
                         fields. Options come from GET /items/meta, never from
                         this markup: the list is rows an admin edits. --}}
                    <div>
                        <label for="item-type" class="field-label">Category</label>
                        <select id="item-type" name="category_id" class="field-input" required></select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="item-type-hint"></p>
                        <p class="field-error hidden" data-error-for="category_id"></p>
                    </div>

                    {{-- Brand, chosen from the Brand Master rather than typed.
                         A typed name is a master list nobody maintains:
                         "Crompton", "crompton" and "Crompton Greaves" were three
                         brands to the old column and one to the shop, and the
                         listing filter believed the column. Options come from
                         GET /items/meta, never from this markup — the same rule
                         the category dropdown follows, for the same reason. --}}
                    <div>
                        <div class="flex items-end justify-between gap-2">
                            <label for="item-brand" class="field-label mb-0">
                                Brand <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            {{-- Beside the field, because "the brand I need is
                                 missing" is a thing somebody discovers halfway
                                 through this form, and sending them to a
                                 settings screen to fix it loses what they had
                                 typed. --}}
                            <button type="button" id="manage-brands"
                                    class="btn btn-ghost btn-sm -mb-1 shrink-0 px-1.5 py-0.5 text-xs"
                                    data-requires-permission="UPDATE:ITEMS">
                                <x-icon name="plus" :size="13" />
                                Add brand
                            </button>
                        </div>
                        <select id="item-brand" name="brand_id" class="field-input mt-1.5"></select>
                        <p class="field-error hidden" data-error-for="brand_id"></p>
                    </div>
                </div>

                {{-- ── How the product is identified and taxed ──────────────
                     Everything here belongs to the *family*: one HSN code, one
                     rate, one unit, however many things are on the shelf under
                     it. What tells those apart is in the variants below. --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="item-code" class="field-label">
                            Product code <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="item-code" name="code" type="text" class="field-input"
                               autocomplete="off" placeholder="e.g. MOT-3PH">
                        <p class="field-error hidden" data-error-for="code"></p>
                    </div>

                    <div>
                        <label for="item-uom" class="field-label">Counted in</label>
                        <select id="item-uom" name="base_uom" class="field-input"></select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="item-uom-hint">
                            Fixed once the product exists.
                        </p>
                        <p class="field-error hidden" data-error-for="base_uom"></p>
                    </div>

                    <div>
                        <label for="item-hsn" class="field-label" id="item-hsn-label">HSN code</label>
                        <input id="item-hsn" name="hsn_sac" type="text" inputmode="numeric" class="field-input"
                               autocomplete="off" placeholder="4 to 8 digits">
                        <p class="field-error hidden" data-error-for="hsn_sac"></p>
                    </div>

                    <div>
                        <label for="item-gst" class="field-label">GST rate</label>
                        <div class="relative">
                            {{-- Prefilled at the rate most of this trade charges,
                                 as a real value and not a greyed placeholder.
                                 This box used to suggest "18" in placeholder
                                 grey, which is indistinguishable from a figure
                                 somebody has already accepted and saved 0%; a
                                 value that is actually there saves what it
                                 shows. A category charging something else still
                                 replaces it, and a figure typed here beats both
                                 — see applyTypeToForm() in pages/items.js.
                                 Cleared is still "you decide", which is what the
                                 placeholder then says. --}}
                            <input id="item-gst" name="gst_rate" type="text" inputmode="decimal" value="18"
                                   class="field-input pr-8 text-right font-mono" placeholder="Rate in %">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="item-gst-hint">A percentage — 18, not 0.18.</p>
                        <p class="field-error hidden" data-error-for="gst_rate"></p>
                    </div>

                    {{-- Which basis the selling price above is quoted on.
                         Full-width under the pair, because it qualifies both the
                         price and the rate rather than sitting beside either, and
                         because what it changes is what a bill *charges* — the
                         one thing on this form that reaches a customer's
                         invoice. A default for the bill line's toggle and never
                         more than that: the line carries its own copy, so
                         flipping this restates nothing already posted. --}}
                    <div class="sm:col-span-2">
                        <div class="flex items-start gap-2.5">
                            <input id="item-price-incl" name="price_includes_tax" type="checkbox"
                                   class="mt-0.5 size-4 rounded border-border">
                            <label for="item-price-incl" class="text-sm text-secondary-foreground">
                                This price already includes GST
                                <span class="mt-0.5 block text-xs text-muted-foreground" id="item-price-incl-hint">
                                    For parts sold at the figure printed on the box. A bill line starts this way
                                    and can still be changed.
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- ── Stock ────────────────────────────────────────────────
                     Hidden wholesale for a category that holds none: an opening
                     quantity of labour would be inventing an asset that does not
                     exist, and offering the box teaches somebody it is possible. --}}
                <div id="item-stock-section" class="space-y-4 rounded-[12px] border border-muted p-4">
                    <div class="flex items-start gap-2.5">
                        <input id="item-stock" name="is_stock" type="checkbox"
                               class="mt-0.5 size-4 rounded border-border" checked>
                        <label for="item-stock" class="text-sm text-secondary-foreground">
                            Keep stock of this
                            <span class="mt-0.5 block text-xs text-muted-foreground" id="item-stock-hint"></span>
                        </label>
                    </div>

                    {{-- The day the shelf was counted, and there is one of it
                         however many variants declare a quantity: they post a
                         single stock adjustment between them, and a document has
                         one date. Marked `data-opening-field` with the pairs in
                         each variant block — together they are the whole of what
                         an edit withholds, because opening stock is a transaction
                         that posted once and is corrected from Stock afterwards.
                         Everything else about a variant is editable. --}}
                    <div class="max-w-xs" id="item-opening-date-field" data-opening-field>
                        <label for="item-opening-date" class="field-label">Counted on</label>
                        <input id="item-opening-date" name="opening_date" type="date" class="field-input">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            The day the shelf was counted. One stock adjustment covers every variant below.
                        </p>
                        <p class="field-error hidden" data-error-for="opening_date"></p>
                    </div>
                </div>

                {{-- ── The things on the shelf ──────────────────────────────
                     A motor family is bought in three ratings and catalogued in
                     one sitting. Saving the product and then adding each rating
                     from the drawer is the two-screen shape this form exists to
                     remove — and it is how the specification gets skipped: by the
                     third rating nobody is re-typing the HP. --}}
                <section id="item-variants-section" class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-[0.8125rem] font-semibold text-foreground">
                                <span id="item-variants-title">Variants</span>
                                <span class="ml-1 font-normal text-muted-foreground" id="item-variants-for"></span>
                            </h3>
                            <p class="mt-0.5 text-xs text-muted-foreground" id="item-variants-hint">
                                The actual things you buy and sell. One is normal; add a block per rating or size.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            {{-- Shown while one variant of several is open, which
                                 is the only time there is anywhere to go back to.
                                 A product with exactly one has no list behind its
                                 block, and a create has no list at all. --}}
                            <button type="button" class="btn btn-ghost btn-sm hidden" id="item-variant-back">
                                <x-icon name="chevron-left" :size="14" />
                                All variants
                            </button>
                            {{-- One control for the whole form rather than one per
                                 block: which fields a category asks for is the
                                 category's business, and it is the same answer in
                                 every block. Lands straight inside the category the
                                 form is on — the shortest route from "this field is
                                 missing" to the place it is added (§7.5). --}}
                            <button type="button" class="btn btn-ghost btn-sm" id="item-attributes-configure"
                                    data-requires-permission="UPDATE:ITEMS">
                                <x-icon name="settings" :size="14" />
                                Configure fields
                            </button>
                        </div>
                    </div>

                    {{-- Pane one: which variant.
                         A product with several has no single set of boxes that
                         could mean any one of them, so the half becomes a picker
                         first. Drawn by pages/items.js from the same row renderer
                         the drawer's Variants tab uses — the same four facts,
                         asked in two places (§4.4). --}}
                    <div id="item-variant-list" class="hidden space-y-2"></div>

                    {{-- Pane two: the variant itself.
                         One block per variant on a create, and exactly one — the
                         one being edited — afterwards. §2A.2's judgement applied
                         one level down: two panes, one on screen. --}}
                    <div id="item-variants" class="space-y-3"></div>

                    <button type="button" class="btn btn-secondary btn-sm" id="item-add-variant">
                        <x-icon name="plus" :size="15" />
                        Add another variant
                    </button>

                    {{-- One thing on the shelf, cloned per block by
                         pages/items.js — which stamps the block's index onto
                         every name, id, `for` and error slot. So a 422 about
                         `variants.2.sell_price` lands in the third block rather
                         than on a banner above five identical ones, and
                         re-indexing after a removal is a walk over these hooks.
                         Nothing here carries an index of its own. --}}
                    <template id="item-variant-template">
                        <div class="space-y-4 rounded-[12px] border border-muted bg-muted/20 p-4" data-variant-block>
                            <div class="flex items-center justify-between gap-3">
                                {{-- Two headings, one shown. A block on a create
                                     is "Variant 2" and has no other name yet — the
                                     number is also what its error keys are counted
                                     in. One standing for something already on the
                                     shelf takes that thing's own label. --}}
                                <h4 class="text-[0.8125rem] font-semibold text-foreground">
                                    <span data-variant-generic>Variant <span data-variant-number>1</span></span>
                                    <span class="hidden" data-variant-name></span>
                                </h4>
                                {{-- From the second block only: a product with
                                     nothing on the shelf under it cannot be sold,
                                     priced or counted. --}}
                                <button type="button" class="btn btn-ghost btn-sm hidden text-rose-600"
                                        data-remove-variant>
                                    <x-icon name="trash" :size="14" />
                                    Remove
                                </button>
                            </div>

                            {{-- What is on the shelf under this variant right
                                 now — read-only, and from M8 rather than from
                                 anything on this form. Filled only when the block
                                 is bound to a variant that already exists,
                                 because a quantity printed beside the boxes that
                                 create one reads as a figure somebody may type
                                 over. --}}
                            <p class="hidden rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2
                                      text-[0.8125rem] text-secondary-foreground"
                               data-variant-position></p>

                            {{-- The category's own fields, built from the
                                 server's schema and never written here. This is
                                 the whole point of the module: an admin adds
                                 "Lumens" to a category and every block grows a
                                 Lumens box, with no change to this file. --}}
                            <div class="hidden" data-variant-attributes-section>
                                <p class="field-label">Specification</p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-variant-attributes></div>
                                <p class="field-error hidden" data-variant-error="attributes"></p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="field-label" data-variant-label="sku">
                                        SKU <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <input type="text" class="field-input" data-variant-field="sku"
                                           autocomplete="off" placeholder="e.g. MOT-5HP-1440">
                                    <p class="field-error hidden" data-variant-error="sku"></p>
                                </div>

                                <div>
                                    <label class="field-label" data-variant-label="barcode">
                                        Barcode <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <input type="text" class="field-input" data-variant-field="barcode"
                                           autocomplete="off" inputmode="numeric" placeholder="Scan or type">
                                    <p class="field-error hidden" data-variant-error="barcode"></p>
                                </div>

                                <div>
                                    <label class="field-label" data-variant-label="purchase_price">
                                        Purchase price <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">₹</span>
                                        <input type="text" inputmode="decimal" data-variant-field="purchase_price"
                                               class="field-input pl-7 text-right font-mono" placeholder="0.00">
                                    </div>
                                    {{-- Says what it actually does, which is one
                                         thing. No screen reads it back, and a
                                         purchase line's rate deliberately never
                                         does — see docs/purchase-module.md. The
                                         cost the books carry is the weighted
                                         average of what was really paid. --}}
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Values any opening stock below. The cost the books carry is what you
                                        actually pay on purchases.
                                    </p>
                                    <p class="field-error hidden" data-variant-error="purchase_price"></p>
                                </div>

                                <div>
                                    <label class="field-label" data-variant-label="sell_price">
                                        Selling price <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">₹</span>
                                        <input type="text" inputmode="decimal" data-variant-field="sell_price"
                                               class="field-input pl-7 text-right font-mono" placeholder="0.00">
                                    </div>
                                    <p class="field-error hidden" data-variant-error="sell_price"></p>
                                </div>

                                {{-- The two that only mean anything once the
                                     variant exists, so they are shown on an edit
                                     and withheld on a create: a name is worth
                                     typing only where the specification has
                                     already failed to say it, and a markup over
                                     cost suggests a price against an average
                                     nothing has moved yet.

                                     Declared here all the same, because this
                                     block is the only variant editor there is —
                                     the drawer's pencil opens it too. A second set
                                     of these boxes elsewhere is how two editors
                                     end up asking for different fields. --}}
                                <div data-variant-edit-only>
                                    <label class="field-label" data-variant-label="label">
                                        Name it <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <input type="text" class="field-input" data-variant-field="label"
                                           autocomplete="off" placeholder="Built from the specification">
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Only if your fitters ask for it by another name.
                                    </p>
                                    <p class="field-error hidden" data-variant-error="label"></p>
                                </div>

                                <div data-variant-edit-only>
                                    <label class="field-label" data-variant-label="markup_percent">
                                        Target markup <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <div class="relative">
                                        <input type="text" inputmode="decimal" data-variant-field="markup_percent"
                                               class="field-input pr-8 text-right font-mono" placeholder="0">
                                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                                    </div>
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Suggests a price over cost once stock exists.
                                    </p>
                                    <p class="field-error hidden" data-variant-error="markup_percent"></p>
                                </div>
                            </div>

                            {{-- Hidden for a category that holds no stock, and
                                 greyed while "Keep stock of this" is off — the
                                 checkbox above is what explains why they are
                                 inert, and a box that vanishes reads as a bug. --}}
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-variant-stock>
                                <div data-opening-field>
                                    <label class="field-label" data-variant-label="opening_stock">
                                        Opening stock <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <div class="relative">
                                        <input type="text" inputmode="decimal" data-variant-field="opening_stock"
                                               class="field-input pr-14 text-right font-mono" placeholder="0">
                                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground"
                                              data-uom-suffix></span>
                                    </div>
                                    {{-- Posted as an ordinary stock adjustment
                                         through the same engine the stock screen
                                         uses, so there is no second way for stock
                                         to come into existence. --}}
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        What is already on the shelf. Recorded as a stock adjustment.
                                    </p>
                                    <p class="field-error hidden" data-variant-error="opening_stock"></p>
                                </div>

                                <div data-opening-field>
                                    <label class="field-label" data-variant-label="opening_cost">
                                        Opening stock cost <span class="font-normal text-muted-foreground">(per unit)</span>
                                    </label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">₹</span>
                                        <input type="text" inputmode="decimal" data-variant-field="opening_cost"
                                               class="field-input pl-7 text-right font-mono" placeholder="0.00">
                                    </div>
                                    {{-- Stock cannot arrive worth nothing: valued
                                         at zero it never reaches the Inventory
                                         account, and the first sale of it reports
                                         the whole price as profit. Said here,
                                         where the box is, rather than only in the
                                         refusal. --}}
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Defaults to the buying price above. A quantity needs one of the two.
                                    </p>
                                    <p class="field-error hidden" data-variant-error="opening_cost"></p>
                                </div>

                                <div>
                                    <label class="field-label" data-variant-label="reorder_level">
                                        Reorder level <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <input type="text" inputmode="decimal" data-variant-field="reorder_level"
                                           class="field-input text-right font-mono" placeholder="0">
                                    <p class="mt-1.5 text-xs text-muted-foreground">Order more when it drops to this.</p>
                                    <p class="field-error hidden" data-variant-error="reorder_level"></p>
                                </div>

                                <div>
                                    <label class="field-label" data-variant-label="min_stock">
                                        Minimum stock <span class="font-normal text-muted-foreground">(optional)</span>
                                    </label>
                                    <input type="text" inputmode="decimal" data-variant-field="min_stock"
                                           class="field-input text-right font-mono" placeholder="0">
                                    <p class="mt-1.5 text-xs text-muted-foreground">Never let it fall below this.</p>
                                    <p class="field-error hidden" data-variant-error="min_stock"></p>
                                </div>
                            </div>

                            {{-- The block's own footer, for a refusal that named
                                 no field of its own — a missing required
                                 attribute arrives as `variants.2` and nothing
                                 else. Without it the message goes to the banner
                                 above every block equally. --}}
                            <p class="field-error hidden" data-variant-error></p>
                        </div>
                    </template>
                </section>

                <div>
                    <label for="item-description" class="field-label">
                        Description <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="item-description" name="description" type="text" class="field-input"
                           autocomplete="off">
                    <p class="field-error hidden" data-error-for="description"></p>
                </div>
            </div>


            {{-- The dialog's footer: Cancel and Save, filling the width. --}}
            <div class="hidden gap-2 border-t border-muted px-6 py-4" data-form-chrome="modal">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save product</button>
            </div>

            {{-- The level-1 footer. "Clear" rather than "Cancel": there is
                 nothing to cancel out of — the form is where the module lives,
                 and leaving it is what the switch control above is for. --}}
            <div class="form-foot" data-form-chrome="inline">
                <button type="submit" class="btn btn-primary">
                    <x-icon name="plus" :size="15" />
                    Create product
                </button>
                <button type="button" class="btn btn-ghost" id="item-form-clear">Clear</button>
            </div>
</form>


