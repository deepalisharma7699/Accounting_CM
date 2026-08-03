@extends('layouts.app')

@section('title', 'Reports')
@section('page', 'reports')

@section('content')

<header class="mb-6">
    <h2 class="text-2xl font-bold tracking-tight text-foreground">Reports</h2>
    <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
        Every figure here is worked out from the journal entries, stock movements and bill lines as you
        ask for it. Nothing is stored and nothing is rolled up overnight, so no two reports can disagree
        about the same money.
    </p>
</header>

{{-- The period. Presets come from GET /reports/meta rather than being written
     here, because "this financial year" depends on the workshop's own year-start
     setting — a copy in the client would be right until somebody changed it. --}}
<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="flex gap-1 rounded-[10px] bg-secondary/60 p-1" id="report-tabs" role="tablist">
        <button type="button" class="btn btn-sm btn-ghost" data-report="day-book" role="tab">Day book</button>
        <button type="button" class="btn btn-sm btn-ghost" data-report="profit-and-loss" role="tab">Profit &amp; loss</button>
        <button type="button" class="btn btn-sm btn-ghost" data-report="gst" role="tab">GST</button>
        <button type="button" class="btn btn-sm btn-ghost" data-report="drafts" role="tab">Parked drafts</button>
    </div>

    <span class="flex-1"></span>

    <select id="filter-period" class="field-input w-auto min-w-48" aria-label="Period"></select>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground" data-custom-dates>
        From
        <input type="date" id="filter-from" class="field-input w-auto" aria-label="From date">
    </label>

    <label class="flex items-center gap-2 text-[0.8125rem] text-muted-foreground" data-custom-dates>
        To
        <input type="date" id="filter-to" class="field-input w-auto" aria-label="To date">
    </label>
</div>

{{-- What the report is actually covering, stated rather than left to be
     inferred from a dropdown the reader may not have looked at. --}}
<p id="period-label" class="mb-4 text-[0.8125rem] text-muted-foreground"></p>

{{-- Headline figures. Rebuilt per report, because the three or four numbers
     that matter are different for each one. --}}
<div id="report-stats" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"></div>

{{-- Reconciliation. The day book states whether the books balance; the GST
     summary states whether the bill lines and the tax accounts agree. Both are
     the number that makes everything else on the page trustworthy or not. --}}
<div id="reconciliation" class="mb-4 hidden"></div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead id="report-head"></thead>
            <tbody id="report-rows"></tbody>
            <tfoot id="report-foot"></tfoot>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="report-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2" id="report-pager">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

@endsection
