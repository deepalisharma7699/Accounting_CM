{{--
    Purchase — what the workshop bought in, and what is owed for it.

    A module of its own rather than a mode inside Bills, and the reason is §2A.
    A module opens on its create form. One combined "Bills" module would have to
    open by asking "sale or purchase?" — a screen organised around the ledger
    rather than around the work, which is the very thing the registry's own note
    objects to. One card per document kind lands straight on the right form, with
    the right counterparty and nothing to choose first.

    The form is not written here. It is `partials/bill-document.blade.php`, the
    same document the counter at /bills/new raises, and it must never be copied:
    the fields, the error slots and the `data-` hooks are a contract with
    `components/bill-document.js`, and a second copy is a second place for that
    contract to go stale.

    Filters are `data-` hooks rather than ids, deliberately. Modules mount into
    one page, and `#filter-search` is already spoken for by Items and by Stock —
    which is harmless only for as long as the shell keeps exactly one module root
    attached. Scoping to the root does not depend on that staying true.
--}}
<div class="mx-auto max-w-[1280px]">

    {{--
        Level 1, form mode — where the module lands (§2A.1).

        No `jobs` variable: a workshop job is billed to a customer, so it has no
        business on a purchase, and the partial paints no banner for one.
    --}}
    <div data-ws-form>

        {{--
            Correcting a bill that is already in the books.

            Here rather than in the shared partial, because it is the one thing
            about this form that is the Purchase module's own: the counter at
            /bills/new raises new documents and has nothing to correct. The
            partial stays exactly the document both screens share.

            It is loud on purpose. Somebody who walked away mid-correction and
            came back has to be able to tell at a glance that pressing "Review &
            post" will reverse PUR/26-27/1002 rather than write a new bill —
            which is a materially different act on the same button.
        --}}
        <div data-revise-banner
             class="mb-4 hidden items-start gap-3 rounded-[10px] border border-amber-200 bg-amber-50 px-4 py-3">
            <span class="mt-0.5 shrink-0 text-amber-600"><x-icon name="alert-triangle" :size="16" /></span>

            <div class="flex-1 text-[0.8125rem] text-amber-900">
                <p class="font-semibold" data-revise-title></p>
                <p class="mt-1">
                    Posting this reverses the original and issues the corrected bill in its place. Both
                    documents stay on the record — nothing is erased, and the stock moves by the difference
                    between them.
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
                       placeholder="Bill number, supplier or note…" aria-label="Search purchases">
            </div>

            {{-- Debit notes are listed with the bills they came off rather than
                 on a screen of their own: "what did we buy from them" and "what
                 did we send back" are one question asked of one supplier, and
                 splitting them would mean reconciling two lists by hand. --}}
            <select data-filter-kind class="field-input w-auto min-w-44" aria-label="Filter by kind">
                <option value="">Bills and debit notes</option>
                <option value="purchase">Purchase bills</option>
                <option value="purchase_return">Debit notes</option>
            </select>

            {{-- Derived on the fly from the total, the document's own payments
                 and the payments allocated to it — see M16 — which is why it is
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
                Only what we still owe
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
                            <th class="px-4 py-3 font-semibold">Supplier</th>
                            <th class="px-4 py-3 font-semibold">Date</th>
                            <th class="px-4 py-3 text-right font-semibold">Lines</th>
                            <th class="px-4 py-3 text-right font-semibold">Total</th>
                            <th class="px-4 py-3 text-right font-semibold">Paid</th>
                            <th class="px-4 py-3 text-right font-semibold">Due</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody data-purchase-body></tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <p data-purchase-summary class="text-[0.8125rem] text-muted-foreground"></p>

                <div class="flex gap-2">
                    <button type="button" data-page-prev class="btn btn-secondary btn-sm" disabled>Previous</button>
                    <button type="button" data-page-next class="btn btn-secondary btn-sm" disabled>Next</button>
                </div>
            </div>
        </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One purchase document, read and acted on without leaving the list — level 2.

    A drawer rather than a page, for the reason every drawer in this application
    is one: what was on this bill is read while thinking about the row above it,
    and losing the list to look is what makes people stop looking.

    ## Why paying and returning happen *in* here rather than over it

    Both are forms, and a form stacked on a drawer would be level 3 doing level
    2's job — which §2.2 says to redesign as a step-based flow instead. So the
    body has three states and the footer changes with them: read the bill, pay
    it, or send some of it back. Nothing opens over this except the confirmation
    for the two acts that cannot be undone, which is exactly what level 3 is for.
--}}
<div id="purchase-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="purchase-drawer-title">
    <div class="drawer-panel max-w-[620px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="mb-1 flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600">
                        <x-icon name="shopping-cart" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="purchase-drawer-title"
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
