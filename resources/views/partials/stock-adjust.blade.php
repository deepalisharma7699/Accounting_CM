{{-- Changing what is on the shelf — the one act, wherever it is reached from.
     |
     | It posts `POST /api/v1/transactions/stock-adjustment`, which is the only
     | way a position ever moves outside a bill. There is deliberately no "edit
     | quantity" anywhere in this application: a control that wrote a position
     | directly would be a second write path, and everything M8 guarantees rests
     | on there not being one (§4.3).
     |
     | ## Two modes over one form
     |
     | **`count`** — the Stock screen's stock-take. Several lines, a variant
     | chosen per line, and the *difference* the count found typed in, signed.
     |
     | **`variant`** — the Items drawer, against one variant that is already
     | known. The operator types **what is on the shelf**, and the component
     | subtracts what the books say to get the difference. That subtraction is
     | the reason this is one component and not two: the same act, entered two
     | ways, and the arithmetic between them lives in exactly one place (§4.4).
     |
     | Which way round the number is entered is not a detail. "Two fewer than the
     | books say" and "two on the shelf" are different numbers, and a form that
     | let them be confused would post the wrong one — so each mode labels its
     | own field, and neither offers the other's.
     |
     | The behaviour is resources/js/components/stock-adjust.js. One markup, one
     | module, however many screens reach for it. --}}
<div id="stock-adjust-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="stock-adjust-title" style="z-index: 55">
    <div class="modal-panel max-w-3xl">
        <form id="stock-adjust-form" novalidate>
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold text-foreground" id="stock-adjust-title">Record a count</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="stock-adjust-hint"></p>
            </header>

            <div class="max-h-[55vh] space-y-4 overflow-y-auto px-5 py-4">

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        <span class="field-label">Date</span>
                        <input type="date" name="date" class="field-input" required>
                        <span class="field-error hidden" data-error-for="date"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Note</span>
                        <input type="text" name="notes" class="field-input" maxlength="500"
                               placeholder="Stock-take, March">
                        <span class="field-error hidden" data-error-for="notes"></span>
                    </label>
                </div>

                {{-- ----------------------------------------------------------
                     Mode: count — several variants, the difference each way.
                     ---------------------------------------------------------- --}}
                <div class="hidden space-y-3" data-adjust-mode="count">
                    <div id="stock-adjust-lines" class="space-y-3"></div>

                    <button type="button" id="stock-adjust-add-line" class="btn btn-secondary btn-sm">
                        <x-icon name="plus" :size="15" />
                        Add a line
                    </button>
                </div>

                {{-- ----------------------------------------------------------
                     Mode: variant — one known variant, counted rather than
                     differenced.
                     ---------------------------------------------------------- --}}
                <div class="hidden space-y-3" data-adjust-mode="variant">
                    <div class="rounded-[10px] border border-border bg-secondary/40 px-3.5 py-3">
                        <p class="text-[0.8125rem] font-semibold text-secondary-foreground"
                           id="stock-adjust-variant-label"></p>
                        <p class="mt-0.5 text-xs text-muted-foreground" id="stock-adjust-variant-position"></p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="field">
                            <span class="field-label">Count on the shelf</span>
                            <input type="text" name="counted" class="field-input font-mono" inputmode="decimal"
                                   placeholder="0" autocomplete="off">
                            <span class="field-error hidden" data-error-for="counted"></span>
                        </label>

                        {{-- Only ever read for stock that was *found*: a shortage
                             is written off at what the books were carrying it at,
                             which is not the counter's number to choose. Hidden
                             until the difference is positive, so it is never a
                             box somebody fills in for a shortage. --}}
                        <label class="field hidden" data-adjust-cost>
                            <span class="field-label">What the found stock cost</span>
                            <input type="text" name="unit_cost" class="field-input font-mono" inputmode="decimal"
                                   placeholder="Leave blank" autocomplete="off">
                            <span class="field-error hidden" data-error-for="adjustments.0.unit_cost"></span>
                        </label>
                    </div>

                    {{-- What is about to be posted, in words, before it is.
                         Painted by the component as the count is typed — the
                         difference is what reaches the ledger, and it is the one
                         number this mode never asks anybody to work out. --}}
                    <p class="hidden rounded-[10px] px-3.5 py-2.5 text-[0.8125rem]" id="stock-adjust-difference"></p>
                </div>

                {{-- A refusal about the set of lines rather than any one of them. --}}
                <div class="field-error hidden" data-error-for="adjustments"></div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Post the correction</button>
            </footer>
        </form>
    </div>
</div>
