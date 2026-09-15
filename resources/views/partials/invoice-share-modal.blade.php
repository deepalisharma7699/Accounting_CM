{{--
| Publishing an invoice outside the workshop — level 3, over whichever surface
| asked for it.
|
| ## Why it is in the layout and not in the Sales fragment
|
| It was `#sales-share-modal`, declared in `modules/sales.blade.php`, for as long
| as Sales was the only screen that handed a customer a document. Jobs is the
| second, and the shell caches a module's root **detached** — so a dialog
| declared inside Sales is not in the page at all while the Jobs card is open.
|
| Up here it is a body child for the whole life of the session, exactly like
| `#invoice-print` and `#invoice-preview` beside it, and
| `components/invoice-delivery.js` finds all three by id from `document`. The
| next module that hands over a document borrows this one; it does not write a
| second.
|
| ## Why it stacks above the preview
|
| A drawer is z-50 and this opens over one: the preview is level 2 — one record
| being read — and publishing it is the short decision level 3 exists for (§2.2).
| Nothing opens over this.
|
| The body and the footer are written by `components/invoice-delivery.js`,
| because what can be done depends on whether a link is already live.
--}}
<div id="invoice-share-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="invoice-share-title" style="z-index: 55">
    <div class="modal-panel max-w-lg">

        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="invoice-share-title">Share this invoice</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" data-share-subtitle></p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="px-5 py-4" data-share-body></div>

        <footer class="flex flex-wrap gap-2 border-t border-border px-5 py-4" data-share-actions></footer>

    </div>
</div>
