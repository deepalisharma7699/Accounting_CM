{{--
    Transactions — settlement and the journal voucher. C3.

    ## What this module stopped being

    It was four tabs over the whole transaction list — sales, purchases,
    expenses and drafts — with the receipt, the payment and the journal grid
    behind three buttons on top of it. Every part of that list now has a better
    home: Sales lists invoices and credit notes, Purchase lists bills and debit
    notes, Expenses lists expenses, and Insights' Day Book lists every posted
    document including the journals none of those show. A fifth copy here would
    have been four screens answering one question (§5.1).

    What was only ever here is the two structural holes, and they are the whole
    of the module now:

    - **Money that arrives or leaves without a document.** A customer clearing
      three invoices with one cheque, or paying on account before anything is
      raised, had nowhere to go. Settling on the *way in* was never the gap —
      Sales collects against the invoice its drawer is open on and Purchase pays
      a bill the same way.
    - **The manual journal voucher.** CLAUDE.md names it as the correction
      mechanism for everything else in the books; it is why the Insights overview
      is allowed to disagree with the P&L at all. Without it the only correction
      available anywhere was reversing a whole document.

    ## Three sections, and the shared renderer used three times

    Receipt, Payment and Journal voucher are three write acts on one card, so
    this follows **Staff** rather than inventing a shape: each section is an
    ordinary §2A workspace mounted on its own root, so all three inherit the
    form/list swap, the one switch control and the count badge with no per-module
    flow code. Sections mount lazily, on the first click of their tab (§2.5,
    §7.2) — a workshop that only ever takes receipts never pays for the voucher
    grid's chart of accounts.

    It opens on **Receipt**, which is the one done most, rather than asking which
    of the three.

    ## Which invoices the money settled — level 2

    The allocation screen lives in the drawer of a posted receipt or payment, and
    nowhere else. `POST /transactions/{id}/allocate` and
    `GET /transactions/{id}/open-bills` have existed and been tested since M16
    with no caller anywhere; this is the screen that answers them.

    It is *after* the fact on purpose. A receipt with no allocations named is
    applied to the party's open bills oldest first, which is what an accounts
    department does when nobody says otherwise — but it is still a decision about
    which invoice a cheque was for, and only the operator can make it. Insights
    reports an unallocated receipt as a worklist and refuses to net it away for
    the same reason: **nothing may guess which invoice a cheque was for.** What
    was missing was somewhere to answer.

    ## What is deliberately not here

    **No transaction list.** See above; three enabled cards already draw it.

    **No draft.** Every converted module posts outright, and this was the last
    screen in the product that parked a transaction.
--}}
<div class="mx-auto max-w-[1080px]">

    {{--
        Three write acts, named. Not one "new transaction" that then asks which:
        collecting from a customer, paying a supplier and writing a correcting
        voucher are different jobs done by different people at different moments,
        and a receipt is much the commonest — it should be one click.

        Each section root below carries exactly one [data-ws-form] and one
        [data-ws-list], which is what `mountWorkspace()` looks for. The heading
        and the switch control above each pair are the workspace's, so there is
        no <h1> and no create button written out here (§2A.3).
    --}}
    <div class="tab-strip mb-5" role="tablist" aria-label="Transactions" data-txn-tabs>
        <button type="button" class="tab" role="tab" data-txn-tab="receipt" aria-selected="true">
            <x-icon name="arrow-down-left" :size="15" />
            Receipt
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="payment" aria-selected="false">
            <x-icon name="arrow-up-right" :size="15" />
            Payment
        </button>

        <button type="button" class="tab" role="tab" data-txn-tab="journal" aria-selected="false">
            <x-icon name="file-text" :size="15" />
            Journal voucher
        </button>
    </div>

    <div data-txn-section="receipt">
        @include('partials.settlement-section', ['direction' => 'receipt'])
    </div>

    <div data-txn-section="payment" hidden>
        @include('partials.settlement-section', ['direction' => 'payment'])
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
</div>

{{--
    One document, read without leaving the list — level 2, and one drawer for all
    three sections rather than one each (§5.1).

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
