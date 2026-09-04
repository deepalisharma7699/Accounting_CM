
<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Bills</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            What was sold, what was bought and what is still owed on each. Tax follows the item's HSN code and
            the two state codes; cost follows the shelf at the moment of sale.
        </p>
    </div>

    {{-- Straight to the counter — M20. The chooser this replaced asked two
         questions before offering a form, and the second one ("how would you
         like to enter it?") had exactly one live answer. The kind is now the
         first thing on the counter itself, where it can be changed without
         starting again. --}}
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('bills.create') }}" class="btn btn-primary hidden" data-new-bill
           data-requires-permission="WRITE:TRANSACTIONS">
            <x-icon name="plus" :size="17" />
            New bill
        </a>

        {{-- An expense is a different kind of money — what it costs to be open
             rather than what was bought to sell — so it keeps its own small
             form rather than a place on the counter. --}}
        <button type="button" class="btn btn-secondary hidden" data-new-expense
                data-requires-permission="WRITE:TRANSACTIONS">
            <x-icon name="credit-card" :size="17" />
            Expense
        </button>
    </div>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="search-pill min-w-56 flex-1">
        <x-icon name="search" :size="16" />
        <input type="search" id="filter-search" class="w-full bg-transparent text-sm outline-none"
               placeholder="Invoice number, customer or note…" aria-label="Search bills">
    </div>

    <select id="filter-type" class="field-input w-auto min-w-44" aria-label="Filter by kind">
        <option value="">Sales, purchases and expenses</option>
        <option value="sale">Sales</option>
        <option value="purchase">Purchases</option>
        <option value="expense">Expenses</option>
        <option value="sales_return">Credit notes</option>
        <option value="purchase_return">Debit notes</option>
    </select>

    {{-- §23's status column, as a filter. Derived on the fly from the total, the
         document's own payments and the receipts allocated to it — see M16 —
         which is why it is a server-side filter and not something applied to the
         page after it arrives. --}}
    <select id="filter-payment" class="field-input w-auto min-w-40" aria-label="Filter by payment">
        <option value="">Any payment status</option>
        @foreach (\App\Enums\PaymentStatus::cases() as $status)
            <option value="{{ $status->value }}">{{ $status->label() }}</option>
        @endforeach
    </select>

    <select id="filter-status" class="field-input w-auto min-w-36" aria-label="Filter by state">
        <option value="">Any state</option>
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

    <button type="button" id="filter-outstanding" class="pill" aria-pressed="false">
        Only what is owed
    </button>

    <button type="button" id="clear-filters" class="btn btn-ghost btn-sm">Clear</button>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        {{-- The brief's §23 columns. Total, Paid and Due were impossible before
             M16 gave a receipt a link to the invoice it settled — the ledger
             could say what a customer owed in total but not which bill it was
             left on. --}}
        <table class="w-full min-w-[900px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide
                           text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">Invoice</th>
                    <th class="px-4 py-3 font-semibold">Party</th>
                    <th class="px-4 py-3 font-semibold">Date</th>
                    <th class="px-4 py-3 text-right font-semibold">Items</th>
                    <th class="px-4 py-3 text-right font-semibold">Total</th>
                    <th class="px-4 py-3 text-right font-semibold">Paid</th>
                    <th class="px-4 py-3 text-right font-semibold">Due</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                </tr>
            </thead>
            <tbody id="bills-body"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="bills-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

{{-- The bill, opened over the list. Shows the document — items, tax, totals —
     and, for a sale, what it made. The margin is derived on read from the stock
     movement behind each line, so it is answerable long after the bill was
     posted, which is when "why is this month's margin down" is actually asked. --}}
<div id="bill-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="bill-modal-title">
    <div class="modal-panel max-w-4xl">
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="bill-modal-title">Bill</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="bill-modal-subtitle"></p>
            </div>
            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="max-h-[70vh] overflow-y-auto" id="bill-modal-body"></div>
    </div>
</div>

{{-- An expense is a different kind of money and gets a different form: it costs
     the workshop to be open, and nothing on it is bought to sell. --}}
<div id="expense-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="expense-title">
    <div class="modal-panel max-w-2xl">
        <form id="expense-form" novalidate>
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold text-foreground" id="expense-title">New expense</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    Rent, electricity, a courier, the tea. Anything bought to sell or to fit is a purchase.
                </p>
            </header>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        <span class="field-label">Date</span>
                        <input type="date" name="date" class="field-input" required>
                        <span class="field-error" data-error-for="date"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Account</span>
                        <select name="account_id" class="field-input">
                            <option value="">Misc Expense</option>
                        </select>
                        <span class="field-error" data-error-for="account_id"></span>
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        <span class="field-label">Amount before tax</span>
                        <input type="text" name="amount" class="field-input font-mono" inputmode="decimal" required>
                        <span class="field-error" data-error-for="amount"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Claimable GST</span>
                        <input type="text" name="gst_amount" class="field-input font-mono" inputmode="decimal"
                               placeholder="Leave blank where none is claimable">
                        <span class="field-error" data-error-for="gst_amount"></span>
                    </label>
                </div>

                <label class="field">
                    <span class="field-label">Notes</span>
                    <input type="text" name="notes" class="field-input" maxlength="500"
                           placeholder="March electricity">
                    <span class="field-error" data-error-for="notes"></span>
                </label>

                <div class="border-t border-border pt-4" id="expense-payments-host"></div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Record the expense</button>
            </footer>
        </form>
    </div>
</div>


