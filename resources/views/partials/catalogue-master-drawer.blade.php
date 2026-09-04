{{--
    The Category, Brand and Unit masters — the catalogue's vocabulary.

    A drawer rather than a page of its own (§1.5, §2): there is one page in this
    product, and configuring what a category asks for is something done *while*
    looking at the catalogue, not somewhere you navigate away to. It opens over
    the Items workspace and gives it straight back.

    ## Why a drill-down rather than nested modals

    A category's fields are a list, and a list with add, edit, reorder and delete
    inside a modal is the scroll trap §2.1 forbids. So the drawer has two levels
    of its own: the master list, and one category's fields. Moving between them
    swaps the drawer's body — it does not stack a second surface on top. Only the
    confirmations are level 3, and nothing opens over those.

    Everything inside is drawn by resources/js/pages/catalogue-master.js from
    /api/v1/item-categories, /api/v1/item-brands and /api/v1/units. Nothing about
    a category, a brand or a unit is written into this file: the whole point of
    the module is that the list is rows an admin edits.
--}}
<div id="catalogue-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="catalogue-drawer-title">
    <div class="drawer-panel max-w-[560px]">

        <div class="flex items-start gap-3 border-b border-muted px-6 py-4">
            {{-- Shown only on the drill-down, where it is the way back to the
                 list. Absent at the top level, where the close button is. --}}
            <button type="button" id="catalogue-back"
                    class="btn btn-ghost btn-icon hidden shrink-0" aria-label="Back to all categories">
                <x-icon name="chevron-left" :size="18" />
            </button>

            <div class="min-w-0 flex-1">
                <h3 id="catalogue-drawer-title" class="truncate text-[15.5px] font-bold leading-tight text-foreground">
                    Catalogue setup
                </h3>
                <p id="catalogue-drawer-subtitle" class="mt-0.5 text-xs text-muted-foreground">
                    What each kind of product records, and how it is counted.
                </p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon shrink-0" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </div>

        {{-- Hidden on the drill-down: inside one category there is nothing to
             switch to, and leaving the strip up would offer a jump that silently
             abandoned what was being edited. --}}
        <div class="tab-strip shrink-0 px-6 pt-3" role="tablist" id="catalogue-tabs">
            <button type="button" class="tab-trigger" role="tab" data-catalogue-tab="categories">Categories</button>
            {{-- Beside Categories rather than on a screen of its own: brand and
                 category are picked from the same form one field apart, and a
                 shop that learned to add a category one way should not have to
                 learn a second way to add a brand. --}}
            <button type="button" class="tab-trigger" role="tab" data-catalogue-tab="brands">Brands</button>
            <button type="button" class="tab-trigger" role="tab" data-catalogue-tab="units">Units</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="catalogue-body"></div>

        <div class="flex shrink-0 items-center gap-2 border-t border-muted px-6 py-3.5" id="catalogue-foot"></div>
    </div>
</div>

{{-- Create or edit a category. A short form over the drawer — level 3, and the
     last level: nothing opens over it. --}}
<div id="category-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="category-modal-title">
    <div class="modal-panel max-w-lg">
        <form id="category-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-muted px-6 py-4">
                <h2 id="category-modal-title" class="text-base font-bold text-foreground">New category</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4 px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div>
                    <label for="category-name" class="field-label">Name</label>
                    <input id="category-name" name="name" type="text" class="field-input" required
                           autocomplete="off" placeholder="e.g. Water Pump, Bearing, Apparel">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        What the create form offers in its category dropdown.
                    </p>
                    <p class="field-error hidden" data-error-for="name"></p>
                </div>

                <div>
                    <label for="category-parent" class="field-label">
                        Sits under <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <select id="category-parent" name="parent_id" class="field-input"></select>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        A subcategory asks for its parent's fields as well as its own.
                    </p>
                    <p class="field-error hidden" data-error-for="parent_id"></p>
                </div>

                <div>
                    <label for="category-description" class="field-label">
                        Description <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="category-description" name="description" type="text" class="field-input"
                           autocomplete="off">
                    <p class="field-error hidden" data-error-for="description"></p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="category-unit" class="field-label">Usually counted in</label>
                        <select id="category-unit" name="default_unit_code" class="field-input"></select>
                        <p class="field-error hidden" data-error-for="default_unit_code"></p>
                    </div>

                    <div>
                        <label for="category-gst" class="field-label">
                            Usual GST rate <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <div class="relative">
                            <input id="category-gst" name="default_gst_rate" type="text" inputmode="decimal"
                                   class="field-input pr-8 text-right font-mono" placeholder="18">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                        </div>
                        <p class="field-error hidden" data-error-for="default_gst_rate"></p>
                    </div>

                    <div>
                        <label for="category-hsn" class="field-label">
                            Usual HSN/SAC <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="category-hsn" name="default_hsn_sac" type="text" inputmode="numeric"
                               class="field-input" placeholder="4 to 8 digits">
                        <p class="field-error hidden" data-error-for="default_hsn_sac"></p>
                    </div>
                </div>

                {{-- Copied onto a new product, never referenced by an existing
                     one — so correcting these next March does not restate what
                     every product already charges. --}}
                <p class="rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5 text-xs text-secondary-foreground">
                    These three are starting points, copied onto each new product. Changing them later
                    never alters a product that already exists.
                </p>

                <div class="space-y-3 border-t border-muted pt-4">
                    <div class="flex items-start gap-2.5">
                        <input id="category-holds-stock" name="holds_stock" type="checkbox"
                               class="mt-0.5 size-4 rounded border-border" checked>
                        <label for="category-holds-stock" class="text-sm text-secondary-foreground">
                            Products in this category are kept in stock
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                Turn off for labour and services — an hour is produced when it is sold,
                                so there was never any of it on a shelf.
                            </span>
                        </label>
                    </div>
                    <p class="field-error hidden" data-error-for="holds_stock"></p>

                    <div class="flex items-start gap-2.5">
                        <input id="category-sac" name="uses_sac_code" type="checkbox"
                               class="mt-0.5 size-4 rounded border-border">
                        <label for="category-sac" class="text-sm text-secondary-foreground">
                            Billed under a SAC code rather than an HSN code
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                Services take a SAC; goods take an HSN.
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save category</button>
            </div>
        </form>
    </div>
</div>

{{-- Create or edit one field on a category. --}}
<div id="attribute-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="attribute-modal-title">
    <div class="modal-panel max-w-lg">
        <form id="attribute-form" novalidate>
            <input type="hidden" name="id">
            <input type="hidden" name="category_id">

            <div class="flex items-center justify-between border-b border-muted px-6 py-4">
                <h2 id="attribute-modal-title" class="text-base font-bold text-foreground">New field</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4 px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div>
                    <label for="attribute-label" class="field-label">Label</label>
                    <input id="attribute-label" name="label" type="text" class="field-input" required
                           autocomplete="off" placeholder="e.g. Flow Rate, Inner Diameter, Colour">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        What the create form prints beside the box.
                    </p>
                    <p class="field-error hidden" data-error-for="label"></p>
                </div>

                {{-- Shown on an edit and never editable: it is the key the values
                     are stored under, and renaming it would orphan every one. --}}
                <div id="attribute-key-row" class="hidden">
                    <label for="attribute-key" class="field-label">Stored as</label>
                    <input id="attribute-key" type="text" class="field-input font-mono" disabled>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Fixed. Every product that answered this field is stored under this key.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="attribute-type" class="field-label">Kind of value</label>
                        <select id="attribute-type" name="data_type" class="field-input"></select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="attribute-type-hint"></p>
                        <p class="field-error hidden" data-error-for="data_type"></p>
                    </div>

                    <div data-attribute-unit>
                        <label for="attribute-unit" class="field-label">
                            Unit <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <select id="attribute-unit" name="unit_code" class="field-input"></select>
                        <p class="mt-1.5 text-xs text-muted-foreground">Printed after the box — 5 <em>HP</em>.</p>
                        <p class="field-error hidden" data-error-for="unit_code"></p>
                    </div>
                </div>

                <div data-attribute-options class="hidden">
                    <label for="attribute-options" class="field-label">Choices</label>
                    <textarea id="attribute-options" name="options" rows="4" class="field-input font-mono text-[0.8125rem]"
                              placeholder="Deep groove&#10;Tapered&#10;Needle"></textarea>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        One per line, in the order they should appear. The common one first.
                    </p>
                    <p class="field-error hidden" data-error-for="options"></p>
                </div>

                <div data-attribute-range class="hidden grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="attribute-min" class="field-label">
                            Smallest allowed <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="attribute-min" name="min_value" type="text" inputmode="decimal"
                               class="field-input text-right font-mono">
                        <p class="field-error hidden" data-error-for="min_value"></p>
                    </div>
                    <div>
                        <label for="attribute-max" class="field-label">
                            Largest allowed <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="attribute-max" name="max_value" type="text" inputmode="decimal"
                               class="field-input text-right font-mono">
                        <p class="field-error hidden" data-error-for="max_value"></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="attribute-default" class="field-label">
                            Pre-filled with <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="attribute-default" name="default_value" type="text" class="field-input"
                               autocomplete="off">
                        <p class="field-error hidden" data-error-for="default_value"></p>
                    </div>

                    <div>
                        <label for="attribute-help" class="field-label">
                            Hint <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="attribute-help" name="help_text" type="text" class="field-input"
                               autocomplete="off" placeholder="One line under the box">
                        <p class="field-error hidden" data-error-for="help_text"></p>
                    </div>
                </div>

                <div class="flex items-start gap-2.5 border-t border-muted pt-4">
                    <input id="attribute-required" name="is_required" type="checkbox"
                           class="mt-0.5 size-4 rounded border-border">
                    <label for="attribute-required" class="text-sm text-secondary-foreground">
                        Compulsory
                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            A product cannot be saved without it. Demand it only where the product is
                            unidentifiable otherwise — a motor with no rating, a bearing with no size.
                        </span>
                    </label>
                </div>
                <p class="field-error hidden" data-error-for="is_required"></p>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save field</button>
            </div>
        </form>
    </div>
</div>

{{-- Create or edit a unit. --}}
<div id="unit-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="unit-modal-title">
    <div class="modal-panel max-w-md">
        <form id="unit-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-muted px-6 py-4">
                <h2 id="unit-modal-title" class="text-base font-bold text-foreground">New unit</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4 px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="unit-label" class="field-label">Name</label>
                        <input id="unit-label" name="label" type="text" class="field-input" required
                               autocomplete="off" placeholder="e.g. Bundle">
                        <p class="field-error hidden" data-error-for="label"></p>
                    </div>

                    <div>
                        <label for="unit-symbol" class="field-label">
                            Short form <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="unit-symbol" name="symbol" type="text" class="field-input"
                               autocomplete="off" placeholder="e.g. bdl">
                        <p class="mt-1.5 text-xs text-muted-foreground">Printed on bills and stock reports.</p>
                        <p class="field-error hidden" data-error-for="symbol"></p>
                    </div>

                    <div>
                        <label for="unit-kind" class="field-label">Measures</label>
                        <select id="unit-kind" name="kind" class="field-input">
                            <option value="count">A count of things</option>
                            <option value="weight">Weight</option>
                            <option value="length">Length</option>
                            <option value="volume">Volume</option>
                            <option value="time">Time</option>
                            <option value="electrical">An electrical rating</option>
                            <option value="other">Something else</option>
                        </select>
                        <p class="field-error hidden" data-error-for="kind"></p>
                    </div>

                    <div>
                        <label for="unit-decimals" class="field-label">Fractions</label>
                        <select id="unit-decimals" name="decimals" class="field-input">
                            <option value="0">Whole numbers only</option>
                            <option value="1">One decimal place</option>
                            <option value="2">Two decimal places</option>
                            <option value="3">Three decimal places</option>
                        </select>
                        {{-- The whole of the fractional rule: 2.5 kg is ordinary
                             and 2.5 bearings is a mistake somebody should be told
                             about before it reaches the stock ledger. --}}
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            2.5 kg is ordinary. 2.5 bearings is a mistake.
                        </p>
                        <p class="field-error hidden" data-error-for="decimals"></p>
                    </div>
                </div>

                {{-- Shown on an edit and never editable: every quantity ever
                     recorded points at this code. --}}
                <div id="unit-code-row" class="hidden">
                    <label for="unit-code" class="field-label">Stored as</label>
                    <input id="unit-code" type="text" class="field-input font-mono" disabled>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Fixed. Every quantity ever recorded in this unit points at it.
                    </p>
                </div>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save unit</button>
            </div>
        </form>
    </div>
</div>

{{-- Create or edit a brand.

     Deliberately the shortest form in the catalogue: a brand is an identity, not
     a template. It carries no default unit, no default HSN and no default rate,
     because a brand has no opinion about how the thing it makes is taxed or
     counted — one that did would be a second place a rate came from, and the two
     would disagree the first time a shop stocked a Crompton pump and a Crompton
     motor. --}}
<div id="brand-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="brand-modal-title">
    <div class="modal-panel max-w-md">
        <form id="brand-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-muted px-6 py-4">
                <h2 id="brand-modal-title" class="text-base font-bold text-foreground">New brand</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4 px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div>
                    <label for="brand-name" class="field-label">Name</label>
                    <input id="brand-name" name="name" type="text" class="field-input" required
                           autocomplete="off" placeholder="e.g. Crompton, SKF, Havells">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        What the create form offers in its brand dropdown.
                    </p>
                    <p class="field-error hidden" data-error-for="name"></p>
                </div>

                <div>
                    <label for="brand-code" class="field-label">
                        Short code <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="brand-code" name="code" type="text" class="field-input font-mono"
                           autocomplete="off" placeholder="e.g. SKF">
                    <p class="field-error hidden" data-error-for="code"></p>
                </div>

                <div>
                    <label for="brand-description" class="field-label">
                        Description <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="brand-description" name="description" type="text" class="field-input"
                           autocomplete="off">
                    <p class="field-error hidden" data-error-for="description"></p>
                </div>

                {{-- Shown on an edit only. Archiving is what a brand products
                     already carry gets instead of a delete: it comes off the
                     create form and goes on naming what is already filed under
                     it. --}}
                <div id="brand-active-row" class="hidden border-t border-muted pt-4">
                    <div class="flex items-start gap-2.5">
                        <input id="brand-active" name="is_active" type="checkbox"
                               class="mt-0.5 size-4 rounded border-border" checked>
                        <label for="brand-active" class="text-sm text-secondary-foreground">
                            Offer this brand on the create form
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                Turn it off for a make the shop no longer stocks. Products that already
                                carry it keep it and go on showing it.
                            </span>
                        </label>
                    </div>
                    <p class="field-error hidden" data-error-for="is_active"></p>
                </div>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save brand</button>
            </div>
        </form>
    </div>
</div>
