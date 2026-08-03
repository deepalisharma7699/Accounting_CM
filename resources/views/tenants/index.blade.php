@extends('layouts.app')

@section('title', 'Workshops')
@section('page', 'tenants')

@section('content')

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Workshops</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            Every workshop on the platform. Each one keeps its own books, staff and chart of accounts —
            suspending a workshop signs out everybody inside it.
        </p>
    </div>

    <button type="button" id="new-tenant" class="btn btn-primary hidden"
            data-requires-permission="WRITE:TENANTS">
        <x-icon name="plus" :size="17" />
        New workshop
    </button>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search by name, handle or GSTIN…" aria-label="Search workshops">
    </div>

    <select id="filter-status" class="field-input w-auto min-w-40" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach (\App\Enums\TenantStatus::cases() as $status)
            <option value="{{ $status->value }}">{{ $status->label() }}</option>
        @endforeach
    </select>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] border-collapse">
            <thead>
                <tr>
                    <th class="table-head">Workshop</th>
                    <th class="table-head">GSTIN</th>
                    <th class="table-head">Status</th>
                    <th class="table-head">Users</th>
                    <th class="table-head">Created</th>
                    <th class="table-head text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="tenants-body"></tbody>
        </table>
    </div>

    <div id="tenants-pagination" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3"></div>
</div>

{{-- Create / edit --}}
<div id="tenant-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="tenant-modal-title">
    <div class="modal-panel max-w-2xl">
        <form id="tenant-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h2 id="tenant-modal-title" class="text-base font-bold text-foreground">New workshop</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[65vh] space-y-4 overflow-y-auto px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="tenant-name" class="field-label">Workshop name</label>
                        <input id="tenant-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="Sharma Electricals">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div>
                        <span class="field-label">Handle</span>
                        <div class="flex h-[2.625rem] items-center rounded-[10px] border border-border bg-muted px-3
                                    font-mono text-[0.8125rem] text-muted-foreground">
                            <span id="tenant-slug-preview">—</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Set once, from the name. Renaming later leaves it unchanged.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="tenant-gstin" class="field-label">GSTIN <span class="font-normal text-muted-foreground">(optional)</span></label>
                        <input id="tenant-gstin" name="gstin" type="text" maxlength="15"
                               class="field-input font-mono uppercase" autocomplete="off" placeholder="27AAPFU0939F1ZV">
                        <p class="mt-1.5 text-xs text-muted-foreground" id="tenant-state-hint">
                            The first two digits set the state code.
                        </p>
                        <p class="field-error hidden" data-error-for="gstin"></p>
                    </div>

                    <div>
                        <label for="tenant-state-code" class="field-label">State code</label>
                        <input id="tenant-state-code" name="state_code" type="text" maxlength="2" inputmode="numeric"
                               class="field-input font-mono" autocomplete="off" placeholder="27">
                        <p class="mt-1.5 text-xs text-muted-foreground">Ignored when a GSTIN is given.</p>
                        <p class="field-error hidden" data-error-for="state_code"></p>
                    </div>
                </div>

                <div>
                    <label for="tenant-address" class="field-label">Address</label>
                    <textarea id="tenant-address" name="address" rows="2" class="field-input !h-auto py-2"
                              placeholder="Shop address as it should appear on documents"></textarea>
                    <p class="field-error hidden" data-error-for="address"></p>
                </div>

                {{-- Owner block. Only offered when creating: an existing
                     workshop's people are managed from inside it. --}}
                <fieldset id="tenant-owner-block" class="rounded-[10px] border border-border p-4">
                    <legend class="px-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">
                        Owner account
                    </legend>

                    <p class="mb-3 text-[0.8125rem] text-muted-foreground">
                        A workshop with no owner cannot be signed into. Create one now, or leave blank and add a
                        user later.
                    </p>

                    <div class="space-y-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="owner-name" class="field-label">Name</label>
                                <input id="owner-name" name="owner_name" type="text" class="field-input"
                                       autocomplete="off" placeholder="Ravi Sharma">
                                <p class="field-error hidden" data-error-for="owner_name"></p>
                            </div>

                            <div>
                                <label for="owner-email" class="field-label">Email</label>
                                <input id="owner-email" name="owner_email" type="email" class="field-input"
                                       autocomplete="off" placeholder="ravi@sharma.test">
                                <p class="field-error hidden" data-error-for="owner_email"></p>
                            </div>
                        </div>

                        <div>
                            <label for="owner-password" class="field-label">Temporary password</label>
                            <input id="owner-password" name="owner_password" type="text" class="field-input font-mono"
                                   autocomplete="off" placeholder="At least 12 characters, mixed case, number, symbol">
                            <p class="field-error hidden" data-error-for="owner_password"></p>
                        </div>
                    </div>
                </fieldset>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save workshop</button>
            </div>
        </form>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
