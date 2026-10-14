{{--
    Expense — what it costs the workshop to be open. One tab of Money in & out.

    This was the Expenses card; it moved under Money in & out because a workshop
    looks for "money went out" in one place. Mounted by `pages/journal-expense.js`
    on the first click of its tab.

    ## Why the account is a filter and not a column

    Which expense account a cost was booked to lives in the ledger entries, and a
    listing deliberately does not load them: a page of forty documents would pull
    every posting of every one. So the account narrows the list *server-side*
    (`account_id`) and is read on the document itself in the drawer. The column
    that is here instead is the note — "March electricity" — which is what
    somebody scanning for a cost actually recognises.
--}}
{{-- Level 1, form mode — where the module lands (§2A.1). --}}
<div data-ws-form>

    <form id="expense-form" novalidate class="space-y-4">

        <section class="surface form-card">
            <h3 class="text-sm font-bold text-foreground">What was spent</h3>
            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                Rent, electricity, a courier, the tea. Anything bought to sell or to fit is a purchase,
                and belongs on the Purchase card — that is the line a P&amp;L needs kept.
            </p>

            <div class="mt-4 form-grid">
                <div>
                    <label for="expense-date" class="field-label">Date</label>
                    <input id="expense-date" name="date" type="date" class="field-input" required>
                    <p class="field-error hidden" data-error-for="date"></p>
                </div>

                {{-- Defaulted to Misc Expense, and that is a real answer
                     rather than a placeholder: "we spent money and I do not
                     want to categorise it right now" is a state the template
                     accepts on purpose, because refusing the entry over it
                     would push somebody into not recording the spend. --}}
                <div>
                    <label for="expense-account" class="field-label">What it was for</label>
                    <select id="expense-account" name="account_id" class="field-input">
                        <option value="">Misc Expense</option>
                    </select>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Any expense account on your chart. Rent and Electricity are added from Accounting.
                    </p>
                    <p class="field-error hidden" data-error-for="account_id"></p>
                </div>

                <div>
                    <label for="expense-amount" class="field-label">Amount before tax</label>
                    <input id="expense-amount" name="amount" type="text" inputmode="decimal"
                           class="field-input font-mono" required autocomplete="off" placeholder="0.00">
                    <p class="field-error hidden" data-error-for="amount"></p>
                </div>

                {{-- An amount and not a rate, because that is what is printed
                     on the receipt in the person's hand. Blank is meaningful:
                     where none is claimable the whole amount is the expense,
                     which is the correct treatment — unclaimable tax really
                     is part of what the thing cost. --}}
                <div>
                    <label for="expense-gst" class="field-label">
                        Claimable GST <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="expense-gst" name="gst_amount" type="text" inputmode="decimal"
                           class="field-input font-mono" autocomplete="off" placeholder="0.00">
                    {{-- The sentence was the placeholder, which a field sized
                         for an amount cannot hold. It is a hint now, where it
                         survives the box being the width of the figure it
                         takes rather than the width of its own instructions. --}}
                    <p class="mt-1.5 text-xs text-muted-foreground">Blank where none is claimable.</p>
                    <p class="field-error hidden" data-error-for="gst_amount"></p>
                </div>
            </div>

            <div class="mt-4">
                <label for="expense-notes" class="field-label">Note</label>
                <input id="expense-notes" name="notes" type="text" class="field-input" maxlength="500"
                       autocomplete="off" placeholder="March electricity">
                <p class="mt-1.5 text-xs text-muted-foreground">
                    What this was, in the words you would use looking for it later. It is the column the
                    list is scanned by.
                </p>
                <p class="field-error hidden" data-error-for="notes"></p>
            </div>

            {{-- The same payment rows the counter uses. An expense *is* its
                 split — take the money away and there is no event left — so
                 "On credit" is not offered here, which is what the
                 component's `canCredit` default already decides. --}}
            <div class="mt-4 border-t border-border pt-4" data-expense-payments></div>

            <p class="field-error hidden" data-error-for="payments"></p>
        </section>

        {{-- Absent rather than blanked for a reader: a disabled Save asks
             somebody to work out for themselves why it will not press. The
             workspace additionally lands such a caller on the list, so this
             surface is never the one they are looking at. --}}
        <div class="flex items-center justify-end gap-3" data-requires-permission="WRITE:TRANSACTIONS">
            <button type="submit" class="btn btn-primary">Record the expense</button>
        </div>
    </form>
</div>

{{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
     other is held detached by the workspace, so a half-typed expense and the
     list's filters both survive every trip between them (§2A.2, §2A.6). --}}
<div data-ws-list>

    <div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
        <div class="search-pill min-w-56 flex-1">
            <x-icon name="search" :size="16" />
            <input type="search" data-filter-search class="w-full bg-transparent text-sm outline-none"
                   placeholder="Voucher number or note…" aria-label="Search expenses">
        </div>

        {{-- Server-side, against the ledger. See the note at the top of this
             file for why this is a filter and not a column. --}}
        <select data-filter-account class="field-input w-auto min-w-48" aria-label="Filter by account">
            <option value="">Every expense account</option>
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

        <button type="button" data-clear-filters class="btn btn-ghost btn-sm">Clear</button>
    </div>

    <div class="surface overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase
                               tracking-wide text-muted-foreground">
                        <th class="px-4 py-3 font-semibold">Voucher</th>
                        <th class="px-4 py-3 font-semibold">Date</th>
                        <th class="px-4 py-3 font-semibold">What it was for</th>
                        <th class="px-4 py-3 font-semibold">Paid by</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody data-expense-body></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
            <p data-expense-summary class="text-[0.8125rem] text-muted-foreground"></p>

            <div class="flex gap-2">
                <button type="button" data-page-prev class="btn btn-secondary btn-sm" disabled>Previous</button>
                <button type="button" data-page-next class="btn btn-secondary btn-sm" disabled>Next</button>
            </div>
        </div>
    </div>

</div>{{-- /data-ws-list --}}
