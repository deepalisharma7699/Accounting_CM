{{--
| The document a bill is written on — the brief's §2, §4, §5, §12, §25, §26, §27.
|
| The shell for `components/bill-document.js`, and the markup half of the same
| extraction: who, when, what, how it was settled, what it comes to, and the last
| look before anything reaches the ledger. Everything inside is a mount point —
| the pickers, the payment rows and the totals footer are all written by the
| engine, because the job screen and the journal want the same three.
|
| Included by the counter at `/bills/new` and by the Purchase module's level-1
| create form. Neither owns it, and neither may copy it: the fields, the error
| slots and the `data-` hooks are a contract with one JavaScript file, and a
| second copy of the markup is a second place for that contract to go stale.
|
| The whole of it sits in one `data-bill-document` element, which is the root the
| engine scopes every query to — including the dialogs, so a level-1 surface that
| gets detached takes its own dialogs with it rather than leaving them behind.
|
| ## Variables
|
|   $jobs  true where the host can bill a workshop job, which paints the banner
|          the job's name lands in. Absent everywhere else, so a module that
|          never bills one carries no markup for it.
--}}

<div data-bill-document>

{{-- §26. Restored from localStorage on load, and cleared the moment a bill
     posts. A counter is interrupted constantly, and losing six lines to a closed
     tab is the failure people remember. --}}
<p class="mb-4 hidden rounded-[10px] border border-amber-200 bg-amber-50/60 px-3.5 py-2.5 text-[0.8125rem]
          text-amber-900" data-restored role="status">
    You had an unfinished bill — it has been put back.
    <button type="button" class="ml-2 font-semibold underline" data-discard-draft>Start again</button>
</p>

<div class="grid gap-4 lg:grid-cols-[1fr_22rem] lg:items-start">

    <div class="space-y-4">
        {{-- Who. A type-ahead against the server, with a drawer for somebody who
             is not on the books yet. --}}
        <div class="surface p-4">
            <div class="grid gap-4 sm:grid-cols-[1fr_11rem]">
                <div data-party-host></div>

                <label class="field">
                    <span class="field-label">Date</span>
                    <input type="date" class="field-input" data-bill-date>
                    <span class="field-error hidden" data-error-for="date"></span>
                </label>
            </div>

            @if ($jobs ?? false)
                {{-- Shown only for a workshop bill. --}}
                <div class="mt-3 hidden rounded-[10px] border border-amber-200 bg-amber-50/60 px-3.5 py-2.5
                            text-[0.8125rem] text-amber-900" data-job-banner></div>
            @endif

            {{-- Who did the work — M22, and a sale only.
                 A mount point rather than markup: which trades this workshop
                 asks about is data, published by GET /transactions/meta, and
                 writing "Fitter" and "Winder" here would be the hard-coded
                 vocabulary the catalogue module was rebuilt to remove. Rendered
                 unconditionally because the counter at /bills/new flips its
                 direction at runtime — the engine decides whether it paints. --}}
            <div class="mt-3 hidden" data-staff-host></div>

            <label class="field mt-3">
                <span class="field-label">Notes <span class="font-normal text-muted-foreground">(optional)</span></span>
                <input type="text" class="field-input" maxlength="500" data-bill-notes
                       placeholder="Rewind for pump motor, site 4">
                <span class="field-error hidden" data-error-for="notes"></span>
            </label>
        </div>

        {{-- What. One search box; every result carries live stock. --}}
        <div class="surface p-4">
            <div data-item-host></div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[700px] border-collapse">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide
                                   text-muted-foreground">
                            <th class="px-2 py-2 font-semibold">Item</th>
                            <th class="px-2 py-2 text-right font-semibold">Qty</th>
                            <th class="px-2 py-2 text-right font-semibold">Rate</th>
                            <th class="px-2 py-2 text-right font-semibold">Discount</th>
                            <th class="px-2 py-2 text-right font-semibold">Amount</th>
                            <th class="px-2 py-2"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody data-lines></tbody>
                </table>
            </div>

            <p class="field-error hidden" data-error-for="items"></p>

            {{-- Money off the whole bill, on top of anything taken off a line.
                 Outside `data-totals-host` on purpose: that panel is repainted
                 on every keystroke, and a box that is rebuilt while somebody is
                 typing in it loses the caret and then the focus.

                 No `id` — three hosts include this partial, and an id would be
                 three of them. The engine finds it by `data-` attribute inside
                 its own root, like everything else here. --}}
            <div class="mt-4 border-t border-border pt-3">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <span class="text-[0.8125rem] font-medium text-foreground">Discount on the whole bill</span>

                    <div class="flex items-center gap-1">
                        <input type="text" class="field-input w-24 text-right font-mono" inputmode="decimal"
                               data-bill-discount placeholder="0"
                               aria-label="Discount on the whole bill, in rupees">

                        <button type="button" data-bill-discount-mode
                                class="h-[2.625rem] w-9 shrink-0 rounded-[10px] border border-border bg-card
                                       text-sm font-semibold text-muted-foreground
                                       hover:bg-secondary hover:text-foreground"
                                aria-label="Bill discount is in rupees — switch to a percentage">&#8377;</button>
                    </div>
                </div>

                <p class="mt-1.5 text-right text-xs text-muted-foreground">
                    Spread across the lines before GST, so each line&rsquo;s tax falls with it.
                </p>

                {{-- Two slots, because the server names one field or the other
                     and the error painter matches on the name it is given. --}}
                <p class="field-error hidden text-right" data-error-for="bill_discount"></p>
                <p class="field-error hidden text-right" data-error-for="bill_discount_percent"></p>
            </div>
        </div>

        {{-- How it was settled. --}}
        <div class="surface p-4" data-payments-host></div>
    </div>

    {{-- The running total and the commit, in a column that stays put while the
         lines scroll — §12's confirmation is the panel, not a second screen. --}}
    <aside class="lg:sticky lg:top-4">
        <div class="surface p-4" data-totals-host>
            <p class="text-sm text-muted-foreground">Add a line to see what this comes to.</p>
        </div>

        <div class="mt-3 space-y-2">
            <button type="button" class="btn btn-primary w-full" data-post disabled>
                Review &amp; post
            </button>

            <button type="button" class="btn btn-secondary w-full" data-draft>
                Save as a draft
            </button>

            <p class="text-center text-xs text-muted-foreground">
                Figures are worked out on the server, so the invoice and the books can never differ.
            </p>
        </div>
    </aside>
</div>

{{-- §12. The last look before anything reaches the ledger. It renders the
     server's own numbers — POST /transactions/preview — rather than the
     browser's, so what is confirmed is exactly what is posted.

     One per page rather than one per document, like the two quick-add dialogs
     below it — which is why there is never more than one document mounted. --}}
<div id="confirm-bill-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="confirm-bill-title">
    <div class="modal-panel max-w-2xl">
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="confirm-bill-title">Post this bill?</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" data-confirm-subtitle></p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="max-h-[60vh] overflow-y-auto px-5 py-4" data-confirm-body></div>

        <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
            <button type="button" class="btn btn-ghost" data-modal-close>Back</button>
            <button type="button" class="btn btn-primary" data-confirm-post>Post the bill</button>
        </footer>
    </div>
</div>

{{-- Adding to the catalogue, and adding a counterparty, without losing the bill —
     §5 and §4. Included here rather than by each host, so that including the
     document is enough to have a working one. --}}
@include('partials.quick-item-modal')
@include('partials.quick-party-modal')

</div>{{-- /data-bill-document --}}
