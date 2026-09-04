{{--
    Sales — what the workshop sold, and what is still owed for it.

    A module of its own rather than a mode inside Bills, and the reason is §2A,
    exactly as it is for Purchase. A module opens on its create form; one
    combined "Bills" module would have to open by asking "sale or purchase?" —
    a screen organised around the ledger rather than around the work. One card
    per document kind lands straight on the right form.

    The form is not written here. It is `partials/bill-document.blade.php`, the
    same document the counter at /bills/new and the Purchase module raise, and it
    must never be copied: the fields, the error slots and the `data-` hooks are a
    contract with `components/bill-document.js`, and a second copy is a second
    place for that contract to go stale.

    Filters are `data-` hooks rather than ids, deliberately. Modules mount into
    one page, and `#filter-search` is already spoken for by Items, Stock and
    Bills — which is harmless only for as long as the shell keeps exactly one
    module root attached. Scoping to the root does not depend on that staying
    true.
--}}
<div class="mx-auto max-w-[1280px]">

    {{--
        Level 1, form mode — where the module lands (§2A.1).

        No `jobs` variable. A workshop bill *is* a sale, but it has to post
        through the job so the invoice is stamped with it and its parts are
        marked billed — see pages/bill-counter.js. Painting the banner here
        would offer a job picker this module cannot honour.
    --}}
    <div data-ws-form>

        {{--
            Correcting an invoice that is already in the books.

            Here rather than in the shared partial, for the same reason it is in
            purchase.blade.php: the counter at /bills/new raises new documents
            and has nothing to correct. The partial stays exactly the document
            all three screens share.

            It is loud on purpose. Somebody who walked away mid-correction and
            came back has to be able to tell at a glance that pressing "Review &
            post" will reverse INV/26-27/1002 rather than write a new invoice —
            which is a materially different act on the same button.
        --}}
        <div data-revise-banner
             class="mb-4 hidden items-start gap-3 rounded-[10px] border border-amber-200 bg-amber-50 px-4 py-3">
            <span class="mt-0.5 shrink-0 text-amber-600"><x-icon name="alert-triangle" :size="16" /></span>

            <div class="flex-1 text-[0.8125rem] text-amber-900">
                <p class="font-semibold" data-revise-title></p>
                <p class="mt-1">
                    Posting this reverses the original and issues the corrected invoice in its place. Both
                    documents stay on the record — nothing is erased, the customer's copy of the old one
                    stops opening, and the stock moves by the difference between them.
                </p>
            </div>

            <button type="button" data-revise-cancel class="btn btn-secondary btn-sm shrink-0">
                Cancel the correction
            </button>
        </div>

        @include('partials.bill-document')
    </div>

    {{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
         other is held detached by the workspace, so its filters and its fetched
         rows survive every trip to the form and back (§2A.2, §2A.6). --}}
    <div data-ws-list>

        <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
            <div class="search-pill min-w-56 flex-1">
                <x-icon name="search" :size="16" />
                <input type="search" data-filter-search class="w-full bg-transparent text-sm outline-none"
                       placeholder="Invoice number, customer or note…" aria-label="Search sales">
            </div>

            {{-- Credit notes are listed with the invoices they came off rather
                 than on a screen of their own: "what did we sell them" and "what
                 came back" are one question asked of one customer, and splitting
                 them would mean reconciling two lists by hand. --}}
            <select data-filter-kind class="field-input w-auto min-w-44" aria-label="Filter by kind">
                <option value="">Invoices and credit notes</option>
                <option value="sale">Invoices</option>
                <option value="sales_return">Credit notes</option>
            </select>

            {{-- Derived on the fly from the total, the document's own payments
                 and the receipts allocated to it — see M16 — which is why it is
                 a server-side filter and not something applied to the page after
                 it arrives. --}}
            <select data-filter-payment class="field-input w-auto min-w-40" aria-label="Filter by payment">
                <option value="">Any payment status</option>
                @foreach (\App\Enums\PaymentStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>

            <select data-filter-status class="field-input w-auto min-w-36" aria-label="Filter by state">
                <option value="">Any state</option>
                @foreach (\App\Enums\TransactionStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>

            <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
                From
                <input type="date" data-filter-from class="field-input w-auto" aria-label="From date">
            </label>

            <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
                To
                <input type="date" data-filter-to class="field-input w-auto" aria-label="To date">
            </label>

            <button type="button" data-filter-outstanding class="pill" aria-pressed="false">
                Only what is still owed
            </button>

            <button type="button" data-clear-filters class="btn btn-ghost btn-sm">Clear</button>
        </div>

        <div class="surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                                   tracking-wide text-muted-foreground">
                            <th class="px-4 py-3 font-semibold">Document</th>
                            <th class="px-4 py-3 font-semibold">Customer</th>
                            <th class="px-4 py-3 font-semibold">Date</th>
                            <th class="px-4 py-3 text-right font-semibold">Lines</th>
                            <th class="px-4 py-3 text-right font-semibold">Total</th>
                            <th class="px-4 py-3 text-right font-semibold">Paid</th>
                            <th class="px-4 py-3 text-right font-semibold">Due</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody data-sales-body></tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <p data-sales-summary class="text-[0.8125rem] text-muted-foreground"></p>

                <div class="flex gap-2">
                    <button type="button" data-page-prev class="btn btn-secondary btn-sm" disabled>Previous</button>
                    <button type="button" data-page-next class="btn btn-secondary btn-sm" disabled>Next</button>
                </div>
            </div>
        </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One sale document, read without leaving the list — level 2.

    A drawer rather than a page, for the reason every drawer in this application
    is one: what was on this invoice is read while thinking about the row above
    it, and losing the list to look is what makes people stop looking.

    Declared after both level-1 surfaces rather than inside either, so the
    workspace's swap between the form and the list cannot detach it with one of
    them.

    The body and the footer are empty in the markup and written by
    `pages/sales.js`. Collecting a payment and taking goods back will be *states
    of this surface* rather than forms stacked over it — §2.2 allows nothing
    above level 3, and a form on a drawer would be level 3 doing level 2's job.
    Nothing opens over this except the confirmation for the acts that cannot be
    undone, which is exactly what level 3 is for.
--}}
<div id="sales-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="sales-drawer-title">
    <div class="drawer-panel max-w-[620px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="mb-1 flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-emerald-50
                                 text-emerald-600">
                        <x-icon name="receipt" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="sales-drawer-title"
                            class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p data-drawer-subtitle class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span data-drawer-status></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            {{-- Only when there is something to act on. A banner that is always
                 there is a banner nobody reads. --}}
            <div data-drawer-alert
                 class="mt-3 hidden items-start gap-2.5 rounded-[10px] border border-amber-100 bg-amber-50 px-3 py-2.5">
                <span class="mt-0.5 shrink-0 text-amber-500"><x-icon name="alert-triangle" :size="14" /></span>
                <div class="flex-1 space-y-1 text-[0.78125rem] font-medium text-amber-700"
                     data-drawer-alert-text></div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" data-drawer-body></div>

        <div class="flex flex-wrap gap-2 border-t border-muted px-6 py-4" data-drawer-actions></div>
    </div>
</div>

{{--
    Sending the customer their invoice — level 3, over the drawer.

    A modal rather than another drawer state, and §2 is explicit about which
    this is: "confirm, quick-create, one short decision". Publishing a document
    is one short decision, and the invoice it is about stays visible behind it —
    which is the point. Collecting a payment and taking goods back are forms and
    are therefore drawer *modes*; this is not a form.

    Two states in one panel, swapped by `pages/sales.js`: before there is a link,
    and after. They are not two dialogs, because a workshop reaching for Share
    does not know or care which state it is in — it wants the link, and whether
    one already existed is the product's problem.

    z-index above the drawer's 50 and below the confirmation's 60, so revoking
    can put a confirm over this without either disappearing.
--}}
{{--
    Correcting who did the work — M22.

    The one edit this application offers against a document that is already in
    the books, and it is offered because it moves no figure on one: no ledger
    entry, no stock movement, no total, and nothing on the customer's copy. The
    alternative was a name that stayed wrong for ever — correcting a sale by
    reversing and reissuing it is refused outright once the weighted average has
    moved, so `Correct` is not a way out of a mis-picked fitter.

    Level 3 over the drawer's level 2 (§2.2), and nothing opens over it. Same
    z-index as the share dialog, and for the same reason.

    The boxes themselves are written by `components/staff-attribution.js`, which
    is also what the sale form mounts — the trades are data, and a second copy of
    the picker here would be a second place for the departed-employee case to be
    got wrong.
--}}
<div id="sales-staff-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="sales-staff-title" style="z-index: 55">
    <div class="modal-panel max-w-lg">

        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="sales-staff-title">Who did this work?</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" data-staff-subtitle></p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="px-5 py-4">
            <div data-staff-edit-host></div>

            <p class="mt-3 text-xs text-muted-foreground">
                The invoice itself does not change &mdash; nothing here touches the ledger, the stock or the
                customer&rsquo;s copy. The change is recorded on the audit trail.
            </p>
        </div>

        <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary" data-staff-save>Save</button>
        </footer>

    </div>
</div>

<div id="sales-share-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="sales-share-title" style="z-index: 55">
    <div class="modal-panel max-w-lg">

        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="sales-share-title">Share this invoice</h2>
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
