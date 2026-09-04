{{-- Add to the catalogue without leaving the bill — the brief's §5.
     |
     | It posts to the same POST /api/v1/items and POST /api/v1/items/{id}/variants
     | the Items screen uses, so what is created here is a catalogue item like any
     | other and shows up on Items the next time that page is opened. There is no
     | second way to make an item — this is the same one, reached from where the
     | gap is noticed.
     |
     | An item *and* one variant, always. Stock is counted per variant, so a
     | stocked item with no variant cannot be put on a bill at all; creating the
     | family alone would leave somebody exactly where they started.
     |
     | Extracted into a partial in M20 so the bill counter, the bills list and the
     | job screen share one copy. The behaviour is resources/js/components/quick-item.js
     | — one markup, one module, however many screens reach for it. --}}
<div id="quick-item-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="quick-item-title" style="z-index: 55">
    <div class="modal-panel max-w-xl">
        <form id="quick-item-form" novalidate>
            <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <div>
                    <h2 class="text-base font-bold text-foreground" id="quick-item-title">New item</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="quick-item-subtitle"></p>
                </div>

                <button type="button" class="btn btn-ghost btn-icon" data-quick-cancel aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </header>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                {{-- Hidden when the family already exists and only its
                     specification is missing. --}}
                <div id="quick-item-fields" class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="quick-name" class="field-label">Item name</label>
                        <input id="quick-name" name="name" type="text" class="field-input"
                               autocomplete="off" placeholder="e.g. 3-Phase Induction Motor">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            The family, not the rating — the rating goes below.
                        </p>
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    {{-- The category decides the unit, whether stock is possible
                         and which specification fields appear, so it is asked
                         first. Options come from GET /items/meta — the list is
                         rows an admin edits, not a fixed set. --}}
                    <div>
                        <label for="quick-type" class="field-label">Category</label>
                        <select id="quick-type" name="category_id" class="field-input"></select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="quick-type-hint"></p>
                        <p class="field-error hidden" data-error-for="category_id"></p>
                    </div>

                    <div>
                        <label for="quick-uom" class="field-label">Counted in</label>
                        <select id="quick-uom" name="base_uom" class="field-input"></select>
                        <p class="field-error hidden" data-error-for="base_uom"></p>
                    </div>

                    <div>
                        <label for="quick-hsn" class="field-label" id="quick-hsn-label">HSN code</label>
                        <input id="quick-hsn" name="hsn_sac" type="text" inputmode="numeric" class="field-input"
                               autocomplete="off" placeholder="4 to 8 digits">
                        <p class="field-error hidden" data-error-for="hsn_sac"></p>
                    </div>

                    <div>
                        <label for="quick-gst" class="field-label">GST rate</label>
                        <div class="relative">
                            {{-- See the note on the same field in modules/items.blade.php:
                                 a greyed numeral in this box reads as a filled-in
                                 value and saved 0%. --}}
                            <input id="quick-gst" name="gst_rate" type="text" inputmode="decimal"
                                   class="field-input pr-8 text-right font-mono" placeholder="Rate in %">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="quick-gst-hint">A percentage — 18, not 0.18.</p>
                        <p class="field-error hidden" data-error-for="gst_rate"></p>
                    </div>

                    <div class="flex items-start gap-2.5 sm:col-span-2">
                        <input id="quick-stock" name="is_stock" type="checkbox"
                               class="mt-0.5 size-4 rounded border-border" checked>
                        <label for="quick-stock" class="text-sm text-secondary-foreground">
                            Keep stock of this
                            <span class="mt-0.5 block text-xs text-muted-foreground" id="quick-stock-hint"></span>
                        </label>
                    </div>
                </div>

                <div class="border-t border-border pt-4">
                    <h3 class="text-sm font-semibold text-foreground">Which one</h3>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="quick-variant-hint"></p>

                    {{-- Built from GET /items/meta rather than written here: which
                         fields describe a motor is the server's answer, and a copy
                         in the markup is a copy that drifts. --}}
                    <div id="quick-item-attributes" class="mt-3 grid gap-4 sm:grid-cols-2"></div>

                    <p class="field-error hidden" data-error-for="attributes"></p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="quick-sku" class="field-label">
                                SKU <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <input id="quick-sku" name="sku" type="text" class="field-input" autocomplete="off">
                            <p class="field-error hidden" data-error-for="sku"></p>
                        </div>

                        <div>
                            <label for="quick-price" class="field-label">
                                Selling price <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <input id="quick-price" name="sell_price" type="text" inputmode="decimal"
                                   class="field-input text-right font-mono" placeholder="0.00">
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Fills the rate on the line. Leave blank if you quote per job.
                            </p>
                            <p class="field-error hidden" data-error-for="sell_price"></p>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-quick-cancel>Cancel</button>
                <button type="submit" class="btn btn-primary">Save item</button>
            </footer>
        </form>
    </div>
</div>
