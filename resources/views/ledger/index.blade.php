@extends('layouts.app')

@section('title', 'Ledger')
@section('page', 'ledger')

@section('content')

<header class="mb-6">
    <h2 class="text-2xl font-bold tracking-tight text-foreground">Ledger</h2>
    <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
        Every figure here is a sum of journal entries, worked out as you ask for it. Nothing is
        stored, so a ledger and the trial balance can never disagree.
    </p>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <select id="filter-account" class="field-input w-auto min-w-64" aria-label="Choose an account">
        <option value="">Trial balance — all accounts</option>
    </select>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        From
        <input type="date" id="filter-from" class="field-input w-auto" aria-label="From date">
    </label>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
        To
        <input type="date" id="filter-to" class="field-input w-auto" aria-label="To date">
    </label>

    <button type="button" id="clear-period" class="btn btn-ghost btn-sm">Clear dates</button>
</div>

{{-- Reconciliation banner. The single most important number on the page: if
     the two sides ever differ, everything else on it is suspect. --}}
<div id="reconciliation" class="mb-4 hidden"></div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] border-collapse">
            <thead id="ledger-head"></thead>
            <tbody id="ledger-rows"></tbody>
            <tfoot id="ledger-foot"></tfoot>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="ledger-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2" id="ledger-pager">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

@endsection
