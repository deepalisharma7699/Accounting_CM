@extends('layouts.app')

@section('title', 'New bill')
@section('page', 'bill-counter')

@section('content')

{{-- The counter — the brief's §2, §4, §5, §12, §25, §26 and §27.
     |
     | A full page rather than the modal it replaces (decision D8). A modal cannot
     | host a search-first item picker, a running total, a keyboard flow and a
     | confirmation step without becoming a scroll trap — and the bill is the
     | thing a workshop spends its day writing, so it earns a screen.
     |
     | Everything below is a shell. The three pickers, the payment rows and the
     | totals footer are mounted from resources/js/components, because the job
     | screen and the journal want the same three. --}}

<header class="mb-5">
    <div>
        <div class="flex items-center gap-2">
            <a href="{{ route('bills.index') }}" class="btn btn-ghost btn-sm">← Bills</a>
            <h2 class="text-2xl font-bold tracking-tight text-foreground" data-counter-title>New sale</h2>
        </div>

        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground" data-counter-hint>
            Tax follows each item’s HSN rate and the two state codes. Cost of goods is the weighted average at
            the moment you post.
        </p>
    </div>
</header>

{{-- 1. What kind of document. Three buttons rather than a select: it is the
     first decision and it is made once. --}}
<div class="surface mb-4 p-3">
    <div class="grid gap-2 sm:grid-cols-3" role="group" aria-label="What kind of bill">
        <button type="button" class="selection-card" data-kind="sale" aria-pressed="true">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                <x-icon name="receipt" :size="16" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-foreground">Sale</span>
                <span class="block text-xs text-muted-foreground">Goods, labour or both</span>
            </span>
            <x-icon name="check-circle" :size="15" class="ml-auto text-primary" data-check />
        </button>

        <button type="button" class="selection-card" data-kind="purchase" aria-pressed="false">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                <x-icon name="shopping-cart" :size="16" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-foreground">Purchase</span>
                <span class="block text-xs text-muted-foreground">Stock bought in</span>
            </span>
            <x-icon name="check-circle" :size="15" class="ml-auto hidden text-primary" data-check />
        </button>

        {{-- Not a third transaction type: a workshop bill is a sale that came off
             a job card. It is here because it is how a rewinding shop thinks
             about the document, and it lands on the same `sale` endpoint. --}}
        <button type="button" class="selection-card" data-kind="workshop" aria-pressed="false"
                data-requires-permission="READ:WORKSHOP_JOBS">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-600">
                <x-icon name="zap" :size="16" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-foreground">Workshop bill</span>
                <span class="block text-xs text-muted-foreground">Off a job card</span>
            </span>
            <x-icon name="check-circle" :size="15" class="ml-auto hidden text-primary" data-check />
        </button>
    </div>
</div>

{{-- The document itself — partials/bill-document.blade.php, shared with the
     Purchase module. `jobs` paints the banner a chosen job card lands in, which
     only this screen can produce. --}}
@include('partials.bill-document', ['jobs' => true])

{{-- Choosing which job to bill — only reachable from the "Workshop bill" tab. --}}
<div id="job-picker-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="job-picker-title">
    <div class="modal-panel max-w-2xl">
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold text-foreground" id="job-picker-title">Which job?</h2>
                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    Only jobs that have had work done on them can be billed.
                </p>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="18" />
            </button>
        </header>

        <div class="border-b border-border px-5 py-3">
            <input type="text" class="field-input" placeholder="Job number, customer or serial number…"
                   data-job-search autocomplete="off">
        </div>

        <div class="max-h-[55vh] overflow-y-auto p-2" data-job-results></div>
    </div>
</div>

@endsection
