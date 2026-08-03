@extends('layouts.app')

@section('title', 'Stock')
@section('page', 'stock')

@section('content')

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Stock</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            What is on the shelf and what it is worth. Every figure here is a sum of stock movements,
            worked out as you ask for it — there is no quantity column anywhere to go out of step.
        </p>
    </div>

    {{-- The only way stock changes, and it is a transaction like any other.
         There is deliberately no "edit quantity" control: a screen that could
         change a position without writing a transaction would be a second write
         path, and the whole module is built on there not being one. --}}
    <button type="button" id="new-adjustment" class="btn btn-primary hidden"
            data-requires-permission="WRITE:TRANSACTIONS">
        <x-icon name="clipboard-list" :size="17" />
        Record a count
    </button>
</header>

{{-- The three headline figures. Negative stock is given its own tile rather
     than being folded into "low", because they are different problems: low
     stock is a purchasing decision, negative stock means a sale was recorded
     before the purchase that supplied it. --}}
<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="surface px-4 py-3">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">Stock value</span>
        <span class="mt-1 block font-mono text-xl font-semibold text-foreground" id="stat-value">—</span>
    </div>
    <div class="surface px-4 py-3">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">Variants tracked</span>
        <span class="mt-1 block text-xl font-semibold text-foreground" id="stat-variants">—</span>
    </div>
    <button type="button" class="surface px-4 py-3 text-left transition hover:bg-secondary/50" data-status="low">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">At or below reorder</span>
        <span class="mt-1 block text-xl font-semibold text-amber-700" id="stat-low">—</span>
    </button>
    <button type="button" class="surface px-4 py-3 text-left transition hover:bg-secondary/50" data-status="negative">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">Negative</span>
        <span class="mt-1 block text-xl font-semibold text-rose-700" id="stat-negative">—</span>
    </button>
</div>

{{-- Shown only to somebody who can read the books, and only when the two sides
     differ. They are written in the same database transaction from the same
     figure, so a difference means something reached the Inventory account
     without a stock movement — almost always a manual journal, which M4
     deliberately allows because it is the correction mechanism for everything
     else. --}}
<div id="reconciliation" class="mb-4 hidden"></div>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search by item, code, SKU or rating…" aria-label="Search stock">
    </div>

    {{-- Types come from the enum, so the filter cannot drift from the kinds of
         thing the catalogue actually supports. A service is absent from the
         list below whatever is chosen: it can never hold stock. --}}
    <select id="filter-type" class="field-input w-auto min-w-40" aria-label="Filter by type">
        <option value="">All types</option>
        @foreach (\App\Enums\ItemType::cases() as $type)
            @if ($type->canHoldStock())
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endif
        @endforeach
    </select>

    <select id="filter-status" class="field-input w-auto min-w-48" aria-label="Filter by position">
        <option value="">Every variant</option>
        <option value="in_stock">In stock</option>
        <option value="low">At or below reorder level</option>
        <option value="out">Out of stock</option>
        <option value="negative">Negative</option>
    </select>

    <select id="filter-sort" class="field-input w-auto min-w-44" aria-label="Sort">
        <option value="name">Sort by name</option>
        <option value="quantity">Sort by quantity</option>
        <option value="value">Sort by value</option>
        <option value="cost">Sort by average cost</option>
    </select>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        <input type="checkbox" id="filter-archived" class="size-4 rounded border-border">
        Include archived
    </label>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[840px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">Variant</th>
                    <th class="px-4 py-3 font-semibold">Item</th>
                    <th class="px-4 py-3 text-right font-semibold">On hand</th>
                    <th class="px-4 py-3 text-right font-semibold">Average cost</th>
                    <th class="px-4 py-3 text-right font-semibold">Value</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                </tr>
            </thead>
            <tbody id="stock-body"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="stock-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

{{-- The stock card, opened over the list rather than navigating away — the same
     choice the party statement makes, and for the same reason: checking why a
     figure is what it is should not lose the list you were reading. --}}
<div id="stock-card-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="stock-card-title">
    <div class="modal-panel max-w-4xl">
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="stock-card-title">Stock card</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="stock-card-subtitle"></p>
            </div>
            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="max-h-[65vh] overflow-y-auto">
            <table class="w-full min-w-[640px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="px-4 py-3 font-semibold">Date</th>
                        <th class="px-4 py-3 font-semibold">Movement</th>
                        <th class="px-4 py-3 text-right font-semibold">Quantity</th>
                        <th class="px-4 py-3 text-right font-semibold">Rate</th>
                        <th class="px-4 py-3 text-right font-semibold">Value</th>
                        <th class="px-4 py-3 text-right font-semibold">On hand</th>
                    </tr>
                </thead>
                <tbody id="stock-card-body"></tbody>
            </table>
        </div>
    </div>
</div>

{{-- Recording a count. Quantities here are the *difference* the count found,
     signed — which is why the field is labelled "difference" and not "quantity
     on hand": typing what is on the shelf and typing how far out the books were
     are different numbers, and confusing them would post the wrong one. --}}
<div id="adjustment-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="adjustment-title">
    <div class="modal-panel max-w-3xl">
        <form id="adjustment-form" novalidate>
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold text-foreground" id="adjustment-title">Record a count</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    Enter the difference the count found — <span class="font-medium">−2</span> for two fewer than
                    the books say, <span class="font-medium">+1</span> for one more. A shortage is written off at
                    what the books were carrying it at; found stock needs a cost.
                </p>
            </header>

            <div class="max-h-[55vh] space-y-4 overflow-y-auto px-5 py-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        <span class="field-label">Date</span>
                        <input type="date" name="date" class="field-input" required>
                        <span class="field-error" data-error="date"></span>
                    </label>

                    <label class="field">
                        <span class="field-label">Note</span>
                        <input type="text" name="notes" class="field-input" maxlength="500"
                               placeholder="Stock-take, March">
                        <span class="field-error" data-error="notes"></span>
                    </label>
                </div>

                <div id="adjustment-lines" class="space-y-3"></div>

                <button type="button" id="add-adjustment-line" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" :size="15" />
                    Add a line
                </button>

                <div class="field-error" data-error="adjustments"></div>
            </div>

            <footer class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Post the correction</button>
            </footer>
        </form>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
