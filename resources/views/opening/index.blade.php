@extends('layouts.app')

@section('title', 'Opening Balances')
@section('page', 'opening')

@section('content')

<header class="mb-6">
    <h2 class="text-2xl font-bold tracking-tight text-foreground">Opening balances</h2>
    <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
        What the workshop already had on the day the books opened — stock on the shelf, money customers
        owed, money owed to suppliers, cash in the till. Declared once, before anything else is entered,
        because every figure this product reports is wrong by whatever was there already.
    </p>
</header>

{{-- Where the workshop stands. The owner's stake is the figure to lead on: it
     is what Opening Balance Equity holds once everything is declared, and an
     owner who knows their business is worth ₹6 lakh and sees ₹2 lakh here has
     found their own mistake before anybody had to point at it. --}}
<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="surface px-4 py-3">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">Owner's stake at go-live</span>
        <span class="mt-1 block font-mono text-xl font-semibold text-foreground" id="stat-stake">—</span>
        <span class="mt-0.5 block text-xs text-muted-foreground">Assets declared, less what was owed</span>
    </div>
    <div class="surface px-4 py-3">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">Books open on</span>
        <span class="mt-1 block text-xl font-semibold text-foreground" id="stat-books-start">—</span>
        <span class="mt-0.5 block text-xs text-muted-foreground">Set on the workshop settings screen</span>
    </div>
    <div class="surface px-4 py-3">
        <span class="block text-xs uppercase tracking-wide text-muted-foreground">Declarations posted</span>
        <span class="mt-1 block text-xl font-semibold text-foreground" id="stat-posted">—</span>
        <span class="mt-0.5 block text-xs text-muted-foreground">Opening transactions in the books</span>
    </div>
</div>

{{-- The trial balance, stated plainly. It always reconciles — every opening
     line is posted against Opening Balance Equity — and saying so is the point:
     an owner about to declare their whole financial history needs to know that
     getting a figure wrong cannot break the books, only misstate them. --}}
<div id="reconciliation" class="mb-4 hidden"></div>

<div class="surface mb-4 p-4" id="declare-panel" data-requires-permission="UPDATE:WORKSPACE">
    <h3 class="text-[0.9375rem] font-semibold text-foreground">Declare what you had</h3>

    <p class="mt-1 text-[0.8125rem] text-muted-foreground">
        Save your existing stock list, customer balances and supplier balances as a CSV and paste it
        below — or type the rows in by hand. Nothing is posted until you have seen exactly what will be.
    </p>

    <form id="opening-form" class="mt-4 space-y-4" novalidate>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="field-label">As at</span>
                <input type="date" name="date" id="opening-date" class="field-input">
                <span class="mt-1 block text-xs text-muted-foreground">
                    Defaults to the day the books open. Nothing can be dated before it.
                </span>
            </label>

            <label class="block">
                <span class="field-label">File name <span class="text-muted-foreground">(optional)</span></span>
                <input type="text" name="filename" id="opening-filename" class="field-input"
                       placeholder="opening-balances.csv" maxlength="255">
                <span class="mt-1 block text-xs text-muted-foreground">
                    Kept on the record, so you can tell which file a figure came from later.
                </span>
            </label>
        </div>

        <label class="block">
            <span class="field-label">Rows</span>
            <textarea name="csv" id="opening-csv" rows="10" spellcheck="false"
                      class="field-input font-mono text-[0.8125rem]"
                      placeholder="kind,name,variant,type,quantity,unit_cost,amount,account"></textarea>
        </label>

        {{-- The column guide is filled from GET /opening-balances/meta rather
             than written here, so the instructions cannot drift from the rules
             the parser and the resolver actually apply. --}}
        <div id="column-guide" class="rounded-[10px] border border-border bg-secondary/30 p-3 text-[0.8125rem]"></div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="load-sample" class="btn btn-ghost btn-sm">Show me an example</button>

            <span class="flex-1"></span>

            {{-- Two buttons and never one. Previewing writes nothing; importing
                 commits a workshop's whole financial history, and that must
                 never be something that happened by omission. --}}
            <button type="submit" id="preview-opening" class="btn btn-secondary">Check it</button>
            <button type="button" id="import-opening" class="btn btn-primary" disabled>Post these balances</button>
        </div>
    </form>
</div>

{{-- The preview. The same object the import commits, rendered — not a second
     reading of the file that could disagree with what lands. --}}
<div id="preview-panel" class="surface mb-4 hidden overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
        <h3 class="text-[0.9375rem] font-semibold text-foreground">What will be posted</h3>
        <p id="preview-summary" class="text-[0.8125rem] text-muted-foreground"></p>
    </div>

    <div id="preview-totals" class="grid gap-px bg-border sm:grid-cols-2 lg:grid-cols-5"></div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">Row</th>
                    <th class="px-4 py-3 font-semibold">Declares</th>
                    <th class="px-4 py-3 font-semibold">In the file</th>
                    <th class="px-4 py-3 font-semibold">Resolved to</th>
                    <th class="px-4 py-3 text-right font-semibold">Quantity</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 font-semibold">Outcome</th>
                </tr>
            </thead>
            <tbody id="preview-rows"></tbody>
        </table>
    </div>
</div>

{{-- Every import ever run. A receipt for a decision, not a position — the
     trial balance above is what says whether the position is right. --}}
<div class="surface overflow-hidden">
    <div class="border-b border-border px-4 py-3">
        <h3 class="text-[0.9375rem] font-semibold text-foreground">Imports</h3>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">When</th>
                    <th class="px-4 py-3 font-semibold">File</th>
                    <th class="px-4 py-3 text-right font-semibold">Posted</th>
                    <th class="px-4 py-3 text-right font-semibold">Already declared</th>
                    <th class="px-4 py-3 text-right font-semibold">Declared value</th>
                    <th class="px-4 py-3 font-semibold">By</th>
                </tr>
            </thead>
            <tbody id="history-rows"></tbody>
        </table>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
