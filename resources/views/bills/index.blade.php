@extends('layouts.app')

@section('title', 'Bills')
@section('page', 'bills')

@section('content')

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Bills</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            What was sold, what was bought and what it cost to keep the doors open. Tax follows the item's
            HSN code and the two state codes; cost follows the shelf at the moment of sale.
        </p>
    </div>

    {{-- One button rather than the three it replaced. Choosing between a sale, a
         purchase and an expense is the chooser's first step now — three buttons
         made the header widen with every kind of document the workshop learns to
         write, and put the least-used one beside the most-used at equal weight. --}}
    <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary hidden" data-new-transaction
                data-requires-permission="WRITE:TRANSACTIONS">
            <x-icon name="plus" :size="17" />
            New
        </button>
    </div>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <select id="filter-type" class="field-input w-auto min-w-44" aria-label="Filter by kind">
        <option value="">Sales, purchases and expenses</option>
        <option value="sale">Sales</option>
        <option value="purchase">Purchases</option>
        <option value="expense">Expenses</option>
    </select>

    <select id="filter-status" class="field-input w-auto min-w-40" aria-label="Filter by status">
        <option value="">Any status</option>
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

    <button type="button" id="clear-filters" class="btn btn-ghost btn-sm">Clear</button>

    {{-- The design's second entry point, sitting with the list rather than with
         the page title. Same chooser: somebody who has scrolled the table and
         then wants to write one should not have to scroll back up for it. --}}
    <button type="button" class="btn btn-primary btn-sm ml-auto hidden" data-new-transaction
            data-requires-permission="WRITE:TRANSACTIONS">
        <x-icon name="plus" :size="15" />
        New
    </button>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">Date</th>
                    <th class="px-4 py-3 font-semibold">Kind</th>
                    <th class="px-4 py-3 font-semibold">Party</th>
                    <th class="px-4 py-3 font-semibold">Notes</th>
                    <th class="px-4 py-3 text-right font-semibold">Total</th>
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

{{-- The chooser behind both "New" buttons — the design's two questions, asked in
     one panel rather than across two screens.
     |
     | Two steps rather than one list of six, because the two questions are not
     | the same question: *what* is being recorded is a fact about the business,
     | and *how* it gets typed in is a preference. Flattening them would offer
     | nine cards, most of which nobody wants.
     |
     | Nothing here posts anything. It picks which of the forms below to open,
     | which is why it holds no fields and no submit. --}}
@php
    // Step one. `kind` is the value the API's route already uses, so the choice
    // made here is the choice passed on — nothing translates it in between.
    $transactionKinds = [
        ['kind' => 'sale',     'label' => 'Sale',          'icon' => 'receipt',       'desc' => 'Customer invoices & payments', 'tone' => 'bg-emerald-50 text-emerald-600'],
        ['kind' => 'purchase', 'label' => 'Purchase bill', 'icon' => 'shopping-cart', 'desc' => 'Vendor bills & purchases',     'tone' => 'bg-blue-50 text-blue-600'],
        ['kind' => 'expense',  'label' => 'Expense',       'icon' => 'credit-card',   'desc' => 'Workshop operating costs',     'tone' => 'bg-purple-50 text-purple-600'],
    ];

    /*
    | Step two. Two of the three are M15's — reading a photographed invoice and
    | taking dictation — and they are shown disabled rather than hidden: a
    | workshop that has been told the product will do this should be able to see
    | where it will appear, and a card that looked live and then did nothing
    | would be the worse of the two failures. `available` is what decides
    | whether a card can be picked at all, so there is one flag to flip per
    | method when its endpoint lands.
    */
    $inputMethods = [
        ['method' => 'ai',     'label' => 'AI Assistant',  'icon' => 'sparkles', 'desc' => 'Describe in plain language',       'available' => false],
        ['method' => 'upload', 'label' => 'Upload invoice', 'icon' => 'camera',  'desc' => 'Read the photographed bill for you', 'available' => false],
        ['method' => 'manual', 'label' => 'Manual entry',  'icon' => 'pencil',   'desc' => 'Fill in the form yourself',        'available' => true],
    ];
@endphp

<div id="new-transaction-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="new-transaction-title">
    <div class="modal-panel max-w-[440px]">
        <header class="flex items-start justify-between gap-4 border-b border-border px-6 py-5">
            <div>
                <h2 class="text-base font-bold text-foreground" id="new-transaction-title">
                    Create a new transaction
                </h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="new-transaction-subtitle">
                    What kind of transaction is this?
                </p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        {{-- Step one: what is being recorded. --}}
        <div class="space-y-2.5 p-6" data-step="kind">
            @foreach ($transactionKinds as $item)
                <button type="button" class="selection-card" data-kind="{{ $item['kind'] }}" aria-pressed="false">
                    <span class="grid size-9 shrink-0 place-items-center rounded-[9px] {{ $item['tone'] }}">
                        <x-icon :name="$item['icon']" :size="16" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-foreground">{{ $item['label'] }}</span>
                        <span class="block text-xs text-muted-foreground">{{ $item['desc'] }}</span>
                    </span>

                    <x-icon name="check-circle" :size="15" class="ml-auto hidden text-primary" data-check />
                </button>
            @endforeach

            <button type="button" class="btn btn-primary mt-1 w-full" data-choose-method disabled>
                Continue →
            </button>
        </div>

        {{-- Step two: how it gets typed in. --}}
        <div class="hidden space-y-2.5 p-6" data-step="method">
            @foreach ($inputMethods as $item)
                <button type="button" class="selection-card" data-method="{{ $item['method'] }}"
                        aria-pressed="false" @unless ($item['available']) disabled @endunless>
                    {{-- Neutral, unlike step one's three colours: the kinds are
                         different things and read faster for being coloured
                         apart; the methods are three routes to the same one. --}}
                    <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-muted text-muted-foreground"
                          data-method-icon>
                        <x-icon :name="$item['icon']" :size="16" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-foreground">{{ $item['label'] }}</span>

                            @unless ($item['available'])
                                <span class="badge bg-muted text-muted-foreground">Not ready yet</span>
                            @endunless
                        </span>

                        <span class="block text-xs text-muted-foreground">{{ $item['desc'] }}</span>
                    </span>

                    <x-icon name="check-circle" :size="15" class="ml-auto hidden text-primary" data-check />
                </button>
            @endforeach

            <div class="mt-1 flex gap-2">
                <button type="button" class="btn btn-secondary flex-1" data-choose-kind>← Back</button>
                <button type="button" class="btn btn-primary flex-1" data-start disabled>Start →</button>
            </div>
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

{{-- One form for a sale and a purchase: the payload is identical and the
     direction is the route, not a field — the same choice the API makes. --}}
<div id="bill-form-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="bill-form-title">
    <div class="modal-panel max-w-4xl">
        <form id="bill-form" novalidate>
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold text-foreground" id="bill-form-title">New sale</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="bill-form-hint"></p>
            </header>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="field">
                        <span class="field-label">Date</span>
                        <input type="date" name="date" class="field-input" required>
                        <span class="field-error" data-error-for="date"></span>
                    </label>

                    <label class="field sm:col-span-2">
                        <span class="field-label" id="bill-party-label">Customer</span>
                        <select name="party_id" class="field-input" required>
                            <option value="">Choose…</option>
                        </select>
                        <span class="field-error" data-error-for="party_id"></span>
                    </label>
                </div>

                <label class="field">
                    <span class="field-label">Notes</span>
                    <input type="text" name="notes" class="field-input" maxlength="500"
                           placeholder="Rewind for pump motor, site 4">
                    <span class="field-error" data-error-for="notes"></span>
                </label>

                <div id="bill-lines" class="space-y-3"></div>

                {{-- Shown only when there is nothing to pick. A catalogue can be
                     genuinely empty on the first day, and a select holding one
                     disabled placeholder explains nothing about why. --}}
                <p id="bill-catalogue-hint" class="hidden rounded-[10px] border border-amber-200 bg-amber-50/60
                          px-3.5 py-2.5 text-[0.8125rem] text-amber-900"></p>

                <button type="button" id="add-bill-line" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" :size="15" />
                    Add a line
                </button>

                <div class="field-error" data-error-for="items"></div>

                {{-- Optional, unlike a settlement's. A sale on thirty-day terms
                     carries none; a counter sale carries the whole invoice. --}}
                <div class="border-t border-border pt-4">
                    <h3 class="text-sm font-semibold text-foreground" id="bill-payment-heading">Collected now</h3>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        Leave empty for a bill on credit. It may not come to more than the invoice — the rest is
                        settled later with a receipt.
                    </p>

                    <div id="bill-payments" class="mt-3 space-y-3"></div>

                    <button type="button" id="add-bill-payment" class="btn btn-ghost btn-sm mt-2">
                        <x-icon name="plus" :size="15" />
                        Add a payment
                    </button>

                    <div class="field-error" data-error-for="payments"></div>
                </div>

                <div class="rounded-[10px] bg-secondary/40 px-4 py-3 text-sm" id="bill-totals"></div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-secondary" data-post="0">Save as draft</button>
                <button type="submit" class="btn btn-primary" data-post="1">Post the bill</button>
            </footer>
        </form>
    </div>
</div>

{{-- Add to the catalogue without leaving the bill.
     |
     | It posts to the same POST /api/v1/items and POST /api/v1/items/{id}/variants
     | the Items screen uses, so what is created here is a catalogue item like any
     | other and shows up on Items the next time that page is opened. There is no
     | second way to make an item — this is the same one, reached from where the
     | gap is noticed.
     |
     | An item *and* one variant, always. Stock is counted per variant, so a
     | stocked item with no variant cannot be put on a bill at all; creating the
     | family alone would leave somebody exactly where they started. --}}
<div id="quick-item-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="quick-item-title" style="z-index: 55">
    <div class="modal-panel max-w-xl">
        <form id="quick-item-form" novalidate>
            <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <div>
                    <h2 class="text-base font-bold text-foreground" id="quick-item-title">New item</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="quick-item-subtitle"></p>
                </div>

                <button type="button" class="btn btn-ghost btn-icon" data-quick-cancel aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </header>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                {{-- Hidden when the family already exists and only its
                     specification is missing. --}}
                <div id="quick-item-fields" class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="quick-name" class="field-label">Item name</label>
                        <input id="quick-name" name="name" type="text" class="field-input"
                               autocomplete="off" placeholder="e.g. 3-Phase Induction Motor">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            The family, not the rating — the rating goes below.
                        </p>
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    {{-- The type decides the unit, whether stock is possible and
                         which specification fields appear, so it is asked first. --}}
                    <div>
                        <label for="quick-type" class="field-label">Category</label>
                        <select id="quick-type" name="type" class="field-input">
                            @foreach (\App\Enums\ItemType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground" id="quick-type-hint"></p>
                        <p class="field-error hidden" data-error-for="type"></p>
                    </div>

                    <div>
                        <label for="quick-uom" class="field-label">Counted in</label>
                        <select id="quick-uom" name="base_uom" class="field-input">
                            @foreach (\App\Enums\UnitOfMeasure::cases() as $unit)
                                <option value="{{ $unit->value }}">{{ $unit->label() }}</option>
                            @endforeach
                        </select>
                        <p class="field-error hidden" data-error-for="base_uom"></p>
                    </div>

                    <div>
                        <label for="quick-hsn" class="field-label" id="quick-hsn-label">HSN code</label>
                        <input id="quick-hsn" name="hsn_sac" type="text" inputmode="numeric" class="field-input"
                               autocomplete="off" placeholder="4 to 8 digits">
                        <p class="field-error hidden" data-error-for="hsn_sac"></p>
                    </div>

                    <div>
                        <label for="quick-gst" class="field-label">GST rate</label>
                        <div class="relative">
                            <input id="quick-gst" name="gst_rate" type="text" inputmode="decimal"
                                   class="field-input pr-8 text-right font-mono" placeholder="18">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">%</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">A percentage — 18, not 0.18.</p>
                        <p class="field-error hidden" data-error-for="gst_rate"></p>
                    </div>

                    <div class="flex items-start gap-2.5 sm:col-span-2">
                        <input id="quick-stock" name="is_stock" type="checkbox"
                               class="mt-0.5 size-4 rounded border-border" checked>
                        <label for="quick-stock" class="text-sm text-secondary-foreground">
                            Keep stock of this
                            <span class="mt-0.5 block text-xs text-muted-foreground" id="quick-stock-hint"></span>
                        </label>
                    </div>
                </div>

                <div class="border-t border-border pt-4">
                    <h3 class="text-sm font-semibold text-foreground">Which one</h3>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="quick-variant-hint"></p>

                    {{-- Built from GET /items/meta rather than written here: which
                         fields describe a motor is the server's answer, and a copy
                         in the markup is a copy that drifts. --}}
                    <div id="quick-item-attributes" class="mt-3 grid gap-4 sm:grid-cols-2"></div>

                    <p class="field-error hidden" data-error-for="attributes"></p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="quick-sku" class="field-label">
                                SKU <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <input id="quick-sku" name="sku" type="text" class="field-input" autocomplete="off">
                            <p class="field-error hidden" data-error-for="sku"></p>
                        </div>

                        <div>
                            <label for="quick-price" class="field-label">
                                Selling price <span class="font-normal text-muted-foreground">(optional)</span>
                            </label>
                            <input id="quick-price" name="sell_price" type="text" inputmode="decimal"
                                   class="field-input text-right font-mono" placeholder="0.00">
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Fills the rate on the line. Leave blank if you quote per job.
                            </p>
                            <p class="field-error hidden" data-error-for="sell_price"></p>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-quick-cancel>Cancel</button>
                <button type="submit" class="btn btn-primary">Save item</button>
            </footer>
        </form>
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

                <div class="border-t border-border pt-4">
                    <h3 class="text-sm font-semibold text-foreground">Paid by</h3>
                    <div id="expense-payments" class="mt-3 space-y-3"></div>

                    <button type="button" id="add-expense-payment" class="btn btn-ghost btn-sm mt-2">
                        <x-icon name="plus" :size="15" />
                        Add a payment
                    </button>

                    <div class="field-error" data-error-for="payments"></div>
                </div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Record the expense</button>
            </footer>
        </form>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
