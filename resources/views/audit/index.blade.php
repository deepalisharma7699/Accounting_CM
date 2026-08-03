@extends('layouts.app')

@section('title', 'History')
@section('page', 'audit')

@section('content')

<header class="mb-6">
    <h2 class="text-2xl font-bold tracking-tight text-foreground">History</h2>
    <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
        Who changed what, and when. This covers the records underneath the figures — your accounts,
        parties, catalogue, people and settings — because those are the ones that can change quietly.
        A posted transaction cannot be edited or deleted at all, so it needs no entry here.
    </p>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search by record or person" aria-label="Search the history">
    </div>

    {{-- Options come from GET /audit-logs/meta rather than being written here:
         the list of things that can be changed grows with every module, and a
         copy in the browser would silently stop offering the newest one. --}}
    <select id="filter-resource" class="field-input w-auto min-w-44" aria-label="Kind of record">
        <option value="">Everything</option>
    </select>

    <select id="filter-action" class="field-input w-auto min-w-40" aria-label="What happened">
        <option value="">Any change</option>
    </select>

    {{-- Built from the trail, not from the user list. Somebody who has left the
         workshop still has a history, and a filter built from current users
         could not select them. --}}
    <select id="filter-actor" class="field-input w-auto min-w-44" aria-label="Who made the change">
        <option value="">Anyone</option>
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
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">When</th>
                    <th class="px-4 py-3 font-semibold">Record</th>
                    <th class="px-4 py-3 font-semibold">Change</th>
                    <th class="px-4 py-3 font-semibold">Who</th>
                </tr>
            </thead>
            <tbody id="audit-rows"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="audit-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

@endsection
