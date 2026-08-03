@extends('layouts.app')

@section('title', 'Uploads')
@section('page', 'uploads')

@section('content')

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Uploads</h2>
        <p class="mt-1.5 max-w-2xl text-[0.9375rem] text-muted-foreground">
            Photographs of invoices, recordings and documents. An upload finishes as soon as the file is
            away — it is then checked in the background, and you can carry on while that happens.
        </p>
    </div>

    <label class="btn btn-primary cursor-pointer" data-requires-permission="WRITE:ATTACHMENTS">
        <x-icon name="camera" :size="17" />
        <span>Upload a file</span>
        {{-- The `accept` attribute is filled in from GET /attachments/meta, so
             the browser offers exactly what the server will take. A list written
             here would be right until somebody changed the allow-list. --}}
        <input type="file" id="upload-input" class="sr-only" multiple>
    </label>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search by file name" aria-label="Search uploads">
    </div>

    <select id="filter-kind" class="field-input w-auto min-w-44" aria-label="Kind of file">
        <option value="">Every kind</option>
    </select>

    <select id="filter-status" class="field-input w-auto min-w-40" aria-label="Status">
        <option value="">Any status</option>
    </select>
</div>

{{-- What is uploading right now. Kept above the library rather than merged into
     it: a file that is still moving is not yet one of the workshop's records,
     and a row that appeared in the list and might then vanish would be worse
     than one that never claimed to be there. --}}
<div id="upload-queue" class="mb-4 hidden space-y-2"></div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">File</th>
                    <th class="px-4 py-3 font-semibold">Kind</th>
                    <th class="px-4 py-3 font-semibold">Size</th>
                    <th class="px-4 py-3 font-semibold">Stored</th>
                    <th class="px-4 py-3 font-semibold">Uploaded</th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody id="upload-rows"></tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p id="upload-summary" class="text-[0.8125rem] text-muted-foreground"></p>

        <div class="flex gap-2">
            <button type="button" id="page-prev" class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button type="button" id="page-next" class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
