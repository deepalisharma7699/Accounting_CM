{{--
    Money in & out — expenses, payments, receipts, advances, the journal voucher
    and the owner's personal diary. Key `journal`.

    ## Why one card

    It was two: **Transactions** (receipt, payment, voucher) and **Expenses**. A
    workshop does not think in document types — it thinks "money came in" and
    "money went out" — and two cards in the same band answering that question
    meant guessing which to open. So Expenses moved here as a tab, the staff
    advance gained a second way in beside the Staff card's own, and the owner's
    personal diary was added. The registry key stays `journal`, which is the
    card's address; `bills` is gone from config/modules.php and its URL redirects
    here (routes/web.php, shell.js).

    Nothing was forked to do it: the expense section is the Expenses card's own
    markup and code moved, Receipt and Payment are still one partial included
    twice, and the staff advance is one partial and one component mounted by both
    this card and Staff.

    ## Six sections, and the shared renderer used six times

    Each section is an ordinary §2A workspace mounted on its own root, so each
    inherits the form/list swap, the one switch control and the count badge with
    no per-module flow code. Sections mount lazily, on the first click of their
    tab (§2.5, §7.2), and two of them load their code only then.

    It opens on **Expense**, which is what most people come to this card for.

    ## Which tabs a person sees

    The card is READ:TRANSACTIONS. Staff advance additionally needs READ:STAFF —
    which a data-entry clerk deliberately does not hold, because it guards what
    people are paid — and Personal needs PERSONAL_EXPENSES, which only OWNER is
    given. A tab a person may not use is not drawn.

    ## Which invoices the money settled — level 2

    The allocation screen lives in the drawer of a posted receipt or payment, and
    nowhere else. A receipt with no allocations named is applied to the party's
    open bills oldest first — a default, not an answer — and only the operator can
    change it. **Nothing may guess which invoice a cheque was for.**

    ## What is deliberately not here

    **No transaction list.** Sales, Purchase and Insights' Day Book already draw
    it. **No draft.** Money that has moved is a fact, not a work in progress.
--}}
<div class="mx-auto max-w-[1080px]">

    {{--
        Six acts, named. Not one "new transaction" that then asks which: paying
        the electricity, paying a supplier, collecting from a customer, handing a
        fitter an advance and writing a correcting voucher are different jobs done
        by different people at different moments.

        Each section root below carries exactly one [data-ws-form] and one
        [data-ws-list], which is what `mountWorkspace()` looks for. The heading
        and the switch control above each pair are the workspace's, so there is
        no <h1> and no create button written out here (§2A.3).
    --}}
    <div class="tab-strip mb-5" role="tablist" aria-label="Money in and out" data-txn-tabs>
        <button type="button" class="tab" role="tab" data-txn-tab="expense" aria-selected="true">
            <x-icon name="receipt" :size="15" />
            Expense
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="payment" aria-selected="false">
            <x-icon name="arrow-up-right" :size="15" />
            Pay a vendor
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="receipt" aria-selected="false">
            <x-icon name="arrow-down-left" :size="15" />
            Receipt
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="advance" aria-selected="false"
                data-requires-permission="READ:STAFF">
            <x-icon name="credit-card" :size="15" />
            Staff advance
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="journal" aria-selected="false">
            <x-icon name="file-text" :size="15" />
            Journal voucher
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="personal" aria-selected="false"
                data-requires-permission="READ:PERSONAL_EXPENSES">
            <x-icon name="dollar-sign" :size="15" />
            Personal
        </button>
    </div>

    <div data-txn-section="expense">
        @include('partials.expense-section')
    </div>

    <div data-txn-section="payment" hidden>
        @include('partials.settlement-section', ['direction' => 'payment'])
    </div>

    <div data-txn-section="receipt" hidden>
        @include('partials.settlement-section', ['direction' => 'receipt'])
    </div>

    {{-- The same partial and component the Staff card's Advances tab uses. --}}
    <div data-txn-section="advance" hidden>
        @include('partials.staff-advance-section', ['prefix' => 'money'])
    </div>

    {{--
        The double-entry grid — the books' own correction mechanism.

        The only surface in the application that writes ledger lines directly,
        and the reason the Insights overview is allowed to disagree with the P&L:
        a journal straight to Sales is income the document lines cannot see. That
        is a real state of the books and the overview states it rather than
        repairing it.

        The accounts are fetched from `GET /accounts`, never rendered here — the
        catalogue's rule about vocabulary, applied to the chart. An expense head
        added from Accounting must appear in this picker without a deployment.
    --}}
    <div data-txn-section="journal" hidden>

        <div data-ws-form>

            <div class="mb-4 hidden rounded-[10px] border border-emerald-200 bg-emerald-50/60 px-4 py-3
                        text-[0.8125rem] text-emerald-900" data-voucher-outcome role="status"></div>

            <form id="voucher-form" novalidate class="space-y-4">

                <section class="surface form-card">
                    <h3 class="text-sm font-bold text-foreground">What this voucher is for</h3>

                    <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                        A depreciation charge, a write-off, a correction the reversing entry on a document
                        cannot express. Every other screen in this product writes its own entries; this is
                        the one that lets somebody write them directly, which is what makes it the
                        correction mechanism for all of them.
                    </p>

                    <div class="mt-4 form-grid">
                        <div>
                            <label for="voucher-date" class="field-label">Date</label>
                            <input id="voucher-date" name="date" type="date" class="field-input" required>
                            <p class="field-error hidden" data-error-for="date"></p>
                        </div>

                        {{-- Optional, and genuinely so: a depreciation entry and
                             a correcting journal have no counterparty. The
                             picker is mounted without a role, because a journal
                             does not decide one — see the note on `role` in
                             components/party-picker.js. --}}
                        <div data-voucher-party-host></div>
                    </div>

                    <div class="mt-4">
                        <label for="voucher-notes" class="field-label">Narration</label>
                        <input id="voucher-notes" name="notes" type="text" class="field-input" maxlength="500"
                               autocomplete="off" placeholder="Depreciation for the year on the lathe">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Why this entry exists, in the words somebody auditing it would look for.
                        </p>
                        <p class="field-error hidden" data-error-for="notes"></p>
                    </div>
                </section>

                <section class="surface overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] border-collapse">
                            <thead>
                                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                                           tracking-wide text-muted-foreground">
                                    <th class="px-3 py-3 font-semibold">Account</th>
                                    <th class="px-3 py-3 text-right font-semibold">Debit</th>
                                    <th class="px-3 py-3 text-right font-semibold">Credit</th>
                                    <th class="px-3 py-3 font-semibold">Memo</th>
                                    <th class="px-3 py-3"><span class="sr-only">Remove</span></th>
                                </tr>
                            </thead>

                            <tbody data-voucher-lines></tbody>

                            {{-- Live, and the whole reason it is here: the server
                                 refuses an unbalanced entry, but finding that out
                                 on submit means retyping a voucher. --}}
                            <tfoot>
                                <tr class="border-t border-border bg-secondary/30">
                                    <td class="px-3 py-2 text-right text-[0.8125rem] font-semibold
                                               text-muted-foreground">Total</td>
                                    <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-bold"
                                        data-total-debit>0.00</td>
                                    <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-bold"
                                        data-total-credit>0.00</td>
                                    <td class="px-3 py-2 text-[0.8125rem] font-semibold"
                                        data-balance-note colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="border-t border-border px-3 py-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-add-line>
                            <x-icon name="plus" :size="15" />
                            Add a line
                        </button>
                        <p class="field-error hidden mt-2" data-error-for="lines"></p>
                    </div>
                </section>

                <div class="flex items-center justify-end gap-3" data-requires-permission="WRITE:TRANSACTIONS">
                    <button type="submit" class="btn btn-primary">Post the voucher</button>
                </div>
            </form>
        </div>

        <div data-ws-list>

            <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
                <div class="search-pill min-w-56 flex-1">
                    <x-icon name="search" :size="16" />
                    <input type="search" data-filter-search class="w-full bg-transparent text-sm outline-none"
                           placeholder="Voucher number or narration…" aria-label="Search vouchers">
                </div>

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

                <button type="button" data-clear-filters class="btn btn-ghost btn-sm">Clear</button>
            </div>

            <div class="surface overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] border-collapse">
                        <thead>
                            <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                                       tracking-wide text-muted-foreground">
                                <th class="px-4 py-3 font-semibold">Voucher</th>
                                <th class="px-4 py-3 font-semibold">Date</th>
                                <th class="px-4 py-3 font-semibold">Narration</th>
                                <th class="px-4 py-3 font-semibold">Counterparty</th>
                                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                                <th class="px-4 py-3 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody data-txn-body></tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                    <p data-txn-summary class="text-[0.8125rem] text-muted-foreground"></p>

                    <div class="flex gap-2">
                        <button type="button" data-page-prev class="btn btn-secondary btn-sm" disabled>Previous</button>
                        <button type="button" data-page-next class="btn btn-secondary btn-sm" disabled>Next</button>
                    </div>
                </div>
            </div>

        </div>{{-- /data-ws-list --}}
    </div>

    <div data-txn-section="personal" hidden>
        @include('partials.personal-expense-section')
    </div>
</div>

{{--
    One document, read without leaving the list — level 2, and one drawer for
    Receipt, Payment and the voucher rather than one each (§5.1). Expense keeps
    its own drawer, below, because what it shows is the account it was booked to.

    A posted transaction is immutable and there is no edit here. What it offers
    is the two things a posted settlement or voucher still permits: **reverse**,
    which posts the mirror entry and leaves both documents on the record, and —
    for a receipt or a payment only — **which bills this money settles**, which
    writes no ledger entry at all and is therefore the one property of a posted
    document that can simply be corrected.

    A draft that predates the conversion is still openable, and gets the two acts
    a draft has: post it, or discard it. Nothing in the converted product creates
    one.
--}}
<div id="txn-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="txn-drawer-title">
    <div class="drawer-panel max-w-[620px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600"
                          data-drawer-icon>
                        <x-icon name="file-text" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="txn-drawer-title"
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
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" data-drawer-body></div>

        <div class="flex flex-wrap items-center gap-2 border-t border-muted px-6 py-4" data-drawer-actions></div>
    </div>
</div>

{{--
    One expense, read without leaving the list — level 2.

    A drawer rather than a modal, for the reason every drawer here is one: what
    this cost is read while thinking about the row above it, and losing the list
    to look is what makes people stop looking.

    There is no edit and no correct. A posted document is immutable, and an
    expense has no `revise` path — that is for bills, whose replacement has to be
    re-priced and re-taxed. What an expense has is **reverse**, which posts the
    mirror entry and leaves both documents on the record.
--}}
<div id="expense-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="expense-drawer-title">
    <div class="drawer-panel max-w-[560px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-violet-50 text-violet-600">
                        <x-icon name="credit-card" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="expense-drawer-title" data-drawer-title
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
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" data-drawer-body></div>

        <div class="flex flex-wrap gap-2 border-t border-muted px-6 py-4" data-drawer-actions></div>
    </div>
</div>

{{--
    One personal entry — level 2. Read, and as a *state* of the same drawer
    with the create form adopted into it, edited. Deleted through a confirm
    (level 3). Nothing here touches the books, so a diary line is simply
    corrected rather than reversed.
--}}
<div id="personal-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="personal-drawer-title">
    <div class="drawer-panel max-w-[520px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-rose-50 text-rose-600">
                        <x-icon name="dollar-sign" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="personal-drawer-title" data-drawer-title
                            class="truncate text-[15.5px] font-bold leading-tight text-foreground"></h3>
                        <p data-drawer-subtitle class="truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="16" />
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="px-6 py-5" data-drawer-body></div>

            {{-- Where the create form is adopted for an edit. Empty otherwise. --}}
            <div class="hidden px-6 py-5" data-personal-edit-slot></div>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-muted px-6 py-4" data-drawer-actions></div>
    </div>
</div>

{{--
    The personal categories — level 2, opened from the form or the list, never
    from the entry drawer (§2.2). Add, rename, archive, restore, and delete one
    nothing is filed under; a category in use is archived instead, so the entries
    keep the word that explains them.
--}}
<div id="personal-categories-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="personal-categories-title">
    <div class="drawer-panel max-w-[460px]">
        <div class="border-b border-muted px-6 py-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 id="personal-categories-title" class="text-[15.5px] font-bold leading-tight text-foreground">
                        Personal categories
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">What your own spending is filed under.</p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="16" />
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
            <form data-personal-category-form novalidate class="mb-5" data-requires-permission="WRITE:PERSONAL_EXPENSES">
                <label for="personal-category-name" class="field-label">Add a category</label>
                <div class="flex flex-wrap items-start gap-2">
                    <input id="personal-category-name" name="name" type="text" class="field-input min-w-0 flex-1"
                           maxlength="80" autocomplete="off" placeholder="Pet care">
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
                <p class="field-error hidden" data-error-for="name"></p>
            </form>

            <div data-personal-category-list></div>
        </div>

        <div class="flex justify-end border-t border-muted px-6 py-4">
            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Done</button>
        </div>
    </div>
</div>
