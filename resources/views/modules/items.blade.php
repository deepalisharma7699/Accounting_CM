
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
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

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

                {{-- ── The category's own fields ────────────────────────────
                     Built from the server's schema for the chosen category, never
                     written here. This is the whole point of the module: an admin
                     adds "Lumens" to a category and this section grows a Lumens
                     box, with no change to this file. --}}
                <section id="item-attributes-section" class="hidden rounded-[12px] border border-muted bg-muted/30 p-4"
                         data-variant-half>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 class="text-[0.8125rem] font-semibold text-foreground">
                            Specification
                            <span class="ml-1 font-normal text-muted-foreground" id="item-attributes-for"></span>
                        </h3>
                        <button type="button" class="btn btn-ghost btn-sm" id="item-attributes-configure"
                                data-requires-permission="UPDATE:ITEMS">
                            <x-icon name="settings" :size="14" />
                            Configure fields
                        </button>
                    </div>
                    <div id="item-attributes" class="grid grid-cols-1 gap-4 sm:grid-cols-2"></div>
                    <p class="field-error hidden" data-error-for="attributes"></p>
                </section>

                {{-- ── Identification and pricing ───────────────────────────── --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div data-variant-half>
                        <label for="item-sku" class="field-label">
                            SKU <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="item-sku" name="sku" type="text" class="field-input"
                               autocomplete="off" placeholder="e.g. MOT-5HP-1440">
                        <p class="field-error hidden" data-error-for="sku"></p>
                    </div>

                    <div data-variant-half>
                        <label for="item-barcode" class="field-label">
                            Barcode <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="item-barcode" name="barcode" type="text" class="field-input"
                               autocomplete="off" inputmode="numeric" placeholder="Scan or type">
                        <p class="field-error hidden" data-error-for="barcode"></p>
                    </div>

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

                    <div data-variant-half>
                        <label for="item-purchase-price" class="field-label">
                            Purchase price <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">₹</span>
                            <input id="item-purchase-price" name="purchase_price" type="text" inputmode="decimal"
                                   class="field-input pl-7 text-right font-mono" placeholder="0.00">
                        </div>
                        {{-- Says what it actually does, which is one thing.
                             "For reference and price suggestions" promised a
                             suggestion nothing makes: no screen reads this back,
                             and a purchase line's rate deliberately never does —
                             see docs/purchase-module.md. The cost the books use
                             is the weighted average of what was really paid,
                             derived from the stock movements. --}}
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Used as the value of any opening stock you record below. The cost the books carry
                            is what you actually pay on purchases.
                        </p>
                        <p class="field-error hidden" data-error-for="purchase_price"></p>
                    </div>

                    <div data-variant-half>
                        <label for="item-sell-price" class="field-label">
                            Selling price <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">₹</span>
                            <input id="item-sell-price" name="sell_price" type="text" inputmode="decimal"
                                   class="field-input pl-7 text-right font-mono" placeholder="0.00">
                        </div>
                        <p class="field-error hidden" data-error-for="sell_price"></p>
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
                            {{-- The placeholder reads as an instruction, not as a
                                 numeral. "18" sitting greyed in a right-aligned
                                 monospace box is indistinguishable from a value
                                 somebody has already accepted, and what it saved
                                 was 0%. --}}
                            <input id="item-gst" name="gst_rate" type="text" inputmode="decimal"
                                   class="field-input pr-8 text-right font-mono" placeholder="Rate in %">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="item-gst-hint">A percentage — 18, not 0.18.</p>
                        <p class="field-error hidden" data-error-for="gst_rate"></p>
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

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" id="item-stock-fields" data-variant-half>
                        <div>
                            <label for="item-opening-stock" class="field-label">
                                Opening stock <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <div class="relative">
                                <input id="item-opening-stock" name="opening_stock" type="text" inputmode="decimal"
                                       class="field-input pr-14 text-right font-mono" placeholder="0">
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground"
                                      data-uom-suffix></span>
                            </div>
                            {{-- Posted as an ordinary stock adjustment through the
                                 same engine the stock screen uses, so there is no
                                 second way for stock to come into existence. --}}
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                What is already on the shelf. Recorded as a stock adjustment.
                            </p>
                            <p class="field-error hidden" data-error-for="opening_stock"></p>
                        </div>

                        <div>
                            <label for="item-opening-cost" class="field-label">
                                Opening stock cost <span class="font-normal text-muted-foreground">(per unit)</span>
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">₹</span>
                                <input id="item-opening-cost" name="opening_cost" type="text" inputmode="decimal"
                                       class="field-input pl-7 text-right font-mono" placeholder="0.00">
                            </div>
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Defaults to the purchase price above.
                            </p>
                            <p class="field-error hidden" data-error-for="opening_cost"></p>
                        </div>

                        <div>
                            <label for="item-reorder" class="field-label">
                                Reorder level <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <input id="item-reorder" name="reorder_level" type="text" inputmode="decimal"
                                   class="field-input text-right font-mono" placeholder="0">
                            <p class="mt-1.5 text-xs text-muted-foreground">Order more when it drops to this.</p>
                            <p class="field-error hidden" data-error-for="reorder_level"></p>
                        </div>

                        <div>
                            <label for="item-min-stock" class="field-label">
                                Minimum stock <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <input id="item-min-stock" name="min_stock" type="text" inputmode="decimal"
                                   class="field-input text-right font-mono" placeholder="0">
                            <p class="mt-1.5 text-xs text-muted-foreground">Never let it fall below this.</p>
                            <p class="field-error hidden" data-error-for="min_stock"></p>
                        </div>
                    </div>
                </div>

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
                <button type="reset" class="btn btn-ghost">Clear</button>
            </div>
</form>

{{-- Create / edit a variant. Its attribute fields are built from
     GET /items/meta rather than rendered here, because which fields exist depends
     on the item's type — and an attribute schema copied into the markup is a copy
     that drifts. --}}
<div id="variant-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="variant-modal-title">
    <div class="modal-panel max-w-xl">
        <form id="variant-form" novalidate>
            <input type="hidden" name="id">
            <input type="hidden" name="item_id">

            <div class="flex items-center justify-between border-b border-muted px-6 py-4">
                <h2 id="variant-modal-title" class="text-base font-bold text-foreground">New variant</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div id="variant-attributes" class="grid grid-cols-1 gap-4 sm:grid-cols-2"></div>

                <p class="field-error hidden" data-error-for="attributes"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="variant-sku" class="field-label">
                            SKU <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="variant-sku" name="sku" type="text" class="field-input" autocomplete="off">
                        <p class="field-error hidden" data-error-for="sku"></p>
                    </div>

                    <div>
                        <label for="variant-label" class="field-label">
                            Name it <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="variant-label" name="label" type="text" class="field-input"
                               autocomplete="off" placeholder="Built from the specification">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Only if your fitters ask for it by another name.
                        </p>
                        <p class="field-error hidden" data-error-for="label"></p>
                    </div>

                    <div>
                        <label for="variant-price" class="field-label">
                            Selling price <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="variant-price" name="sell_price" type="text" inputmode="decimal"
                               class="field-input text-right font-mono" placeholder="0.00">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Leave blank if you quote per job.
                        </p>
                        <p class="field-error hidden" data-error-for="sell_price"></p>
                    </div>

                    <div>
                        <label for="variant-markup" class="field-label">
                            Target markup <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <div class="relative">
                            <input id="variant-markup" name="markup_percent" type="text" inputmode="decimal"
                                   class="field-input pr-8 text-right font-mono" placeholder="0">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Suggests a price over cost once stock exists.
                        </p>
                        <p class="field-error hidden" data-error-for="markup_percent"></p>
                    </div>

                    <div id="variant-reorder-field">
                        <label for="variant-reorder" class="field-label">
                            Reorder level <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <div class="relative">
                            <input id="variant-reorder" name="reorder_level" type="text" inputmode="decimal"
                                   class="field-input pr-10 text-right font-mono" placeholder="0">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground"
                                  id="variant-reorder-unit"></span>
                        </div>
                        <p class="field-error hidden" data-error-for="reorder_level"></p>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save variant</button>
            </div>
        </form>
    </div>
</div>


