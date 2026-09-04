
<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Transactions</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            Sales, purchases, expenses and the drafts nobody has authorised yet. A posted entry can
            never be edited — a mistake is corrected with a reversing entry, so both stay on the record.
        </p>
    </div>

    {{-- Three separate actions rather than one "new transaction" that then asks
         what kind. Collecting money from a customer, paying a supplier and
         writing a correcting voucher are different jobs done by different people
         at different moments, and a receipt is by far the commonest — it should
         be one click, not two. --}}
    <div class="flex flex-wrap gap-2">
        <button type="button" id="new-receipt" class="btn btn-primary hidden"
                data-requires-permission="WRITE:TRANSACTIONS">
            <x-icon name="plus" :size="17" />
            Record receipt
        </button>

        <button type="button" id="new-payment" class="btn btn-secondary hidden"
                data-requires-permission="WRITE:TRANSACTIONS">
            <x-icon name="plus" :size="17" />
            Record payment
        </button>

        <button type="button" id="new-journal" class="btn btn-secondary hidden"
                data-requires-permission="WRITE:TRANSACTIONS">
            <x-icon name="plus" :size="17" />
            Journal entry
        </button>
    </div>
</header>

{{--
    Four views of one list, from the design.

    Each tab is a *set* of types rather than a single one, and that is the whole
    reason the grouping lives here instead of in the API. A customer receipt is
    not a different subject from the invoice it settles — it is the next thing
    that happened to it — so putting the two on one tab is how somebody chasing
    a payment actually reads the page. Splitting them by enum case would be
    organising the screen around the posting engine.

    Counts come from GET /transactions/counts, which publishes the raw breakdown
    and lets this file decide what a tab means.
--}}
<div class="tab-strip mb-5" id="txn-tabs" role="tablist" aria-label="Transaction views">
    @foreach ([
        'sales'     => 'Sales',
        'purchases' => 'Purchase Bills',
        'expenses'  => 'Expenses',
        'drafts'    => 'Drafts',
    ] as $tab => $label)
        <button type="button" class="tab" role="tab" data-tab="{{ $tab }}"
                aria-selected="{{ $tab === 'sales' ? 'true' : 'false' }}"
                aria-controls="journal-rows">
            {{ $label }}
            {{-- Blank until the counts arrive. A zero here would be a claim
                 about an empty workshop that nothing has checked yet. --}}
            <span data-tab-count></span>
        </button>
    @endforeach
</div>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search notes…" aria-label="Search transactions">
    </div>

    {{-- Types come from the enum, so the filter cannot drift from what the
         engine can actually post.

         This is also the only way to reach the types no tab covers — a manual
         journal, a stock adjustment, an opening balance. Choosing one of those
         *overrides* the open tab rather than being ignored inside it, and the
         chip below says so: a filter that silently returned nothing would look
         like an empty workshop. --}}
    <select id="filter-type" class="field-input w-auto min-w-36" aria-label="Filter by type">
        <option value="">All types on this tab</option>
        @foreach (\App\Enums\TransactionType::cases() as $type)
            <option value="{{ $type->value }}">{{ $type->label() }}</option>
        @endforeach
    </select>

    {{-- Absent from the Drafts tab, which *is* a status: the JS disables it
         there rather than offering a choice that contradicts the tab. --}}
    <select id="filter-status" class="field-input w-auto min-w-36" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach (\App\Enums\TransactionStatus::cases() as $status)
            <option value="{{ $status->value }}">{{ $status->label() }}</option>
        @endforeach
    </select>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        From
        <input type="date" id="filter-from" class="field-input w-auto" aria-label="From date">
    </label>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        To
        <input type="date" id="filter-to" class="field-input w-auto" aria-label="To date">
    </label>
</div>

{{-- Shown only while a type filter is overriding the open tab. --}}
<p id="txn-override" class="mb-4 hidden items-center gap-2 rounded-[10px] border border-border bg-secondary/40
                            px-3.5 py-2.5 text-[0.8125rem] text-secondary-foreground">
    <span data-override-label></span>
    <button type="button" class="btn btn-ghost btn-sm" data-override-clear>Show the whole tab</button>
</p>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        {{-- Both the head and the body are rendered from JS, because the columns
             are what differ between the tabs: an expense has a payment mode and
             no counterparty, an invoice has a balance and no mode. One table
             carrying the union of every tab's columns would be mostly empty
             cells on every tab. --}}
        <table class="w-full min-w-[820px] border-collapse">
            <thead id="journal-head"></thead>
            <tbody id="journal-rows"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="journal-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

{{-- Write a journal entry --}}
<div id="journal-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="journal-modal-title">
    <div class="modal-panel max-w-4xl">
        <form id="journal-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h2 id="journal-modal-title" class="text-base font-bold text-foreground">New journal entry</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="journal-date" class="field-label">Date</label>
                        <input id="journal-date" name="date" type="date" class="field-input" required>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            The date the event happened, not the date you are entering it.
                        </p>
                        <p class="field-error hidden" data-error-for="date"></p>
                    </div>

                    <div>
                        <label for="journal-notes" class="field-label">Narration</label>
                        <input id="journal-notes" name="notes" type="text" class="field-input"
                               autocomplete="off" placeholder="Why this entry exists">
                        <p class="field-error hidden" data-error-for="notes"></p>
                    </div>

                    {{-- Optional, and genuinely so: a depreciation entry or a
                         correcting journal has no counterparty. When it is set,
                         the entry appears on that party's statement. --}}
                    <div>
                        <label for="journal-party" class="field-label">
                            Party <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <select id="journal-party" name="party_id" class="field-input">
                            <option value="">No counterparty</option>
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Puts this entry on their statement.
                        </p>
                        <p class="field-error hidden" data-error-for="party_id"></p>
                    </div>
                </div>

                {{-- The double-entry grid. Every line is one account and one
                     side; the totals below are the balance check the engine
                     will apply server-side. --}}
                <div class="overflow-hidden rounded-[10px] border border-border">
                    <table class="w-full min-w-[640px] border-collapse">
                        <thead>
                            <tr class="bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <th class="px-3 py-2 font-semibold">Account</th>
                                <th class="w-36 px-3 py-2 text-right font-semibold">Debit</th>
                                <th class="w-36 px-3 py-2 text-right font-semibold">Credit</th>
                                <th class="px-3 py-2 font-semibold">Memo</th>
                                <th class="w-10 px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="journal-lines"></tbody>
                        <tfoot>
                            <tr class="border-t border-border bg-secondary/30 text-sm font-semibold">
                                <td class="px-3 py-2 text-right text-muted-foreground">Totals</td>
                                <td class="px-3 py-2 text-right font-mono" id="total-debit">0.00</td>
                                <td class="px-3 py-2 text-right font-mono" id="total-credit">0.00</td>
                                <td class="px-3 py-2 text-[0.8125rem] font-medium" id="balance-note" colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <button type="button" id="add-line" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" :size="15" />
                    Add line
                </button>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-border px-6 py-4">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                {{-- Two explicit actions. Committing to the ledger is the
                     consequential one and must never happen by omission. --}}
                <button type="button" class="btn btn-secondary" id="save-draft">Save as draft</button>
                <button type="submit" class="btn btn-primary">Post entry</button>
            </div>
        </form>
    </div>
</div>

{{-- Record a payment or a receipt. One form for both: the payload is identical
     and the direction is the endpoint, not a field the user could get the wrong
     way round without noticing. The heading and the wording say which. --}}
<div id="settlement-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="settlement-modal-title">
    <div class="modal-panel max-w-3xl">
        <form id="settlement-form" novalidate>
            <input type="hidden" name="id">
            <input type="hidden" name="kind">

            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h2 id="settlement-modal-title" class="text-base font-bold text-foreground">Record receipt</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <p id="settlement-explainer"
                   class="rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5 text-[0.8125rem] text-secondary-foreground"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {{-- Required here where a journal's counterparty is optional:
                         money moved to or from somebody, and a settlement
                         attributed to nobody sits in a control account no
                         statement could account for. --}}
                    <div>
                        <label for="settlement-party" class="field-label" id="settlement-party-label">Party</label>
                        <select id="settlement-party" name="party_id" class="field-input" required>
                            <option value="">Choose a party…</option>
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="settlement-party-hint"></p>
                        <p class="field-error hidden" data-error-for="party_id"></p>
                    </div>

                    <div>
                        <label for="settlement-date" class="field-label">Date</label>
                        <input id="settlement-date" name="date" type="date" class="field-input" required>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            The date the money moved.
                        </p>
                        <p class="field-error hidden" data-error-for="date"></p>
                    </div>

                    <div>
                        <label for="settlement-notes" class="field-label">Narration</label>
                        <input id="settlement-notes" name="notes" type="text" class="field-input"
                               autocomplete="off" placeholder="e.g. against invoice 118">
                        <p class="field-error hidden" data-error-for="notes"></p>
                    </div>
                </div>

                {{-- How the money moved. One row per tender, because ₹2,000 from
                     the till and ₹3,000 by UPI is one receipt that moves two
                     accounts — and each of those accounts is reconciled
                     separately against a cash box or a passbook. --}}
                <div class="overflow-hidden rounded-[10px] border border-border">
                    <table class="w-full min-w-[560px] border-collapse">
                        <thead>
                            <tr class="bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <th class="px-3 py-2 font-semibold">How</th>
                                <th class="w-40 px-3 py-2 text-right font-semibold">Amount</th>
                                <th class="px-3 py-2 font-semibold">Reference</th>
                                <th class="w-10 px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="settlement-rows"></tbody>
                        <tfoot>
                            <tr class="border-t border-border bg-secondary/30 text-sm font-semibold">
                                <td class="px-3 py-2 text-right text-muted-foreground">Total</td>
                                <td class="px-3 py-2 text-right font-mono" id="settlement-total">0.00</td>
                                <td class="px-3 py-2" colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="field-error hidden" data-error-for="payments"></p>

                <button type="button" id="add-payment-row" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" :size="15" />
                    Add another tender
                </button>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-border px-6 py-4">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                {{-- Two explicit actions, as everywhere else: committing to the
                     ledger must never happen as a side effect of saving. --}}
                <button type="button" class="btn btn-secondary" id="save-settlement-draft">Save as draft</button>
                <button type="submit" class="btn btn-primary" id="settlement-submit">Record receipt</button>
            </div>
        </form>
    </div>
</div>

{{--
    Read a voucher, in the side panel from the design.

    A drawer rather than the centred modal the forms use, because reading is not
    the same act as writing: the row you clicked stays visible behind it, so you
    can tell at a glance that you opened the one you meant. Its sub-tabs are
    rendered from JS, because which of them apply depends on the transaction —
    only a bill has items, only a stock-moving posting has movements.
--}}
<div id="voucher-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="voucher-drawer-title">
    <div class="drawer-panel">
        <div class="flex items-start justify-between gap-3 border-b border-border px-6 py-4">
            <div class="min-w-0">
                <p class="section-label" id="voucher-drawer-kind">&nbsp;</p>
                <h2 id="voucher-drawer-title" class="truncate text-base font-bold text-foreground">Transaction</h2>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <span id="voucher-drawer-status"></span>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>
        </div>

        {{-- Sub-tabs. Same underline treatment as the page's own strip, one
             level in, which is what the design does. --}}
        <div class="tab-strip px-4" id="voucher-tabs" role="tablist" aria-label="Voucher sections"></div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="voucher-body"></div>

        {{-- Whatever can still be done to this transaction, which depends on
             its status: a draft can be posted or discarded, a posted entry can
             only be reversed. Filled from JS for that reason. --}}
        <div class="flex flex-wrap justify-end gap-2 border-t border-border px-6 py-4" id="voucher-actions">
            <button type="button" class="btn btn-secondary" data-modal-close>Close</button>
        </div>
    </div>
</div>


