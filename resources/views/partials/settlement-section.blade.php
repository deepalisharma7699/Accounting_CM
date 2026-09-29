{{--
    Money that arrives or leaves without a document — C3.

    The Receipt and the Payment sections of the Transactions module are the same
    section twice: a counterparty, a date, a note and a split, over a list of
    what has been taken or paid. Only the direction differs, and the direction is
    stated by the route the JS posts to — exactly as it is on the server, where
    `/transactions/receipt` and `/transactions/payment` are two routes over one
    `StoreSettlementRequest`. So the markup lives here once and each section
    passes `$direction`.

    Two near-identical templates is how a rule gets added to the receipt and left
    off the payment — which on this form would mean a cheque number demanded of a
    customer and not of a supplier (§5.1). `pages/journal.js` mirrors it: one
    factory, called twice, closing over its own state.

    $direction: 'receipt' | 'payment'.

    ## What is not on it

    **No allocation picker.** Which invoices a receipt settles is decided on the
    posted document, in the drawer — see the note in modules/journal.blade.php.
    Settling on the way *in* already exists and is better placed: Sales collects
    against the invoice its drawer is open on, and Purchase pays a bill the same
    way, both sending an explicit `allocations` entry. What was missing, and what
    C3 adds, is deciding *afterwards*.

    **No "save as draft".** Money that has moved is a fact, not a work in
    progress — the same judgement M22 makes about a payroll run. Every converted
    module posts outright; this was the last screen in the product that parked a
    transaction, and a parked receipt is a cash box that disagrees with the books
    for as long as nobody authorises it.
--}}
@php
    $receipt = $direction === 'receipt';
    $party = $receipt ? 'customer' : 'supplier';
@endphp

{{-- Level 1, form mode — where the section lands (§2A.1). --}}
<div data-ws-form>

    {{--
        Where the money went, said after it has gone.

        A receipt is applied to the party's open bills oldest first unless
        somebody says otherwise, and that default is right far more often than
        not — but it is still a decision about which invoice a cheque was for,
        and §2A.8 clears the form the moment it posts. So it is stated here, with
        the way to change it one click away. Not a toast: a toast is gone before
        somebody has finished reading the amount.
    --}}
    <div class="mb-4 hidden rounded-[10px] border border-emerald-200 bg-emerald-50/60 px-4 py-3
                text-[0.8125rem] text-emerald-900" data-settlement-outcome role="status"></div>

    <form id="{{ $direction }}-form" novalidate class="space-y-4" data-settlement-form>

        <section class="surface form-card">
            <h3 class="text-sm font-bold text-foreground">
                {{ $receipt ? 'Money collected' : 'Money paid out' }}
            </h3>

            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                @if ($receipt)
                    Cash, a cheque or a transfer from a customer — including one that pays nothing in
                    particular. It reduces what they owe and raises the account the money arrived in. GST
                    is untouched: the tax was recorded when the invoice was raised.
                @else
                    Cash, a cheque or a transfer to a supplier. It reduces what the workshop owes them and
                    lowers the account the money left. GST is untouched: the tax was recorded when the
                    bill was entered.
                @endif
            </p>

            {{-- The shared type-ahead, which fetches what they already owe on
                 the pick and says so under the box. Never a select of every
                 party: a workshop with three hundred silently loses the last
                 hundred, with nothing on screen saying so. --}}
            <div class="mt-4 form-grid">
                <div data-party-host></div>

                <div>
                    <label for="{{ $direction }}-date" class="field-label">Date the money moved</label>
                    <input id="{{ $direction }}-date" name="date" type="date" class="field-input field-date" required>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        The day it was {{ $receipt ? 'taken' : 'paid' }}, not the day it is being entered.
                    </p>
                    <p class="field-error hidden" data-error-for="date"></p>
                </div>
            </div>

            <div class="mt-4">
                <label for="{{ $direction }}-notes" class="field-label">
                    Note <span class="font-normal text-muted-foreground">(optional)</span>
                </label>
                <input id="{{ $direction }}-notes" name="notes" type="text" class="field-input" maxlength="500"
                       autocomplete="off"
                       placeholder="{{ $receipt ? 'Part payment against March invoices' : 'Against the February statement' }}">
                <p class="field-error hidden" data-error-for="notes"></p>
            </div>

            {{-- The same split the counter and the expense form use. There is no
                 document total to measure it against here — the split *is* the
                 amount — which is what `settlesADocument: false` says. --}}
            <div class="mt-4 border-t border-border pt-4" data-settlement-payments></div>
        </section>

        {{-- Absent rather than disabled for a caller without the grant: a greyed
             button asks somebody to work out for themselves why it will not
             press. The workspace additionally lands them on the list. --}}
        <div class="flex items-center justify-end gap-3" data-requires-permission="WRITE:TRANSACTIONS">
            <button type="submit" class="btn btn-primary">
                {{ $receipt ? 'Record the receipt' : 'Record the payment' }}
            </button>
        </div>
    </form>
</div>

{{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
     other is held detached by the workspace, so the half-typed receipt and the
     list's filters both survive every trip between them (§2A.2, §2A.6). --}}
<div data-ws-list>

    <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
        <div class="search-pill min-w-56 flex-1">
            <x-icon name="search" :size="16" />
            <input type="search" data-filter-search class="w-full bg-transparent text-sm outline-none"
                   placeholder="Voucher number or note…" aria-label="Search {{ $direction }}s">
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
            <table class="w-full min-w-[820px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                               tracking-wide text-muted-foreground">
                        <th class="px-4 py-3 font-semibold">Voucher</th>
                        <th class="px-4 py-3 font-semibold">Date</th>
                        <th class="px-4 py-3 font-semibold">{{ ucfirst($party) }}</th>
                        <th class="px-4 py-3 font-semibold">Note</th>
                        <th class="px-4 py-3 font-semibold">How</th>
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
