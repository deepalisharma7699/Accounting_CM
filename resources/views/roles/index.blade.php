@extends('layouts.app')

@section('title', 'Roles')
@section('page', 'roles')

@section('content')

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Roles &amp; Permissions</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            Group permissions into roles, then assign a role to each user. System roles cannot be edited.
        </p>
    </div>

    <button type="button" id="new-role" class="btn btn-primary hidden"
            data-requires-permission="WRITE:ROLES">
        <x-icon name="plus" :size="17" />
        New role
    </button>
</header>

<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search roles…" aria-label="Search roles">
    </div>
</div>

<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead>
                <tr>
                    <th class="table-head">Role</th>
                    <th class="table-head">Description</th>
                    <th class="table-head">Permissions</th>
                    <th class="table-head">Users</th>
                    <th class="table-head text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="roles-body"></tbody>
        </table>
    </div>

    <div id="roles-pagination" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3"></div>
</div>

{{-- Create / edit --}}
<div id="role-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="role-modal-title">
    <div class="modal-panel max-w-2xl">
        <form id="role-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h2 id="role-modal-title" class="text-base font-bold text-foreground">New role</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[65vh] space-y-4 overflow-y-auto px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="role-name" class="field-label">Role name</label>
                        <input id="role-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="Branch Accountant">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div>
                        <span class="field-label">Identifier</span>
                        <div class="flex h-[2.625rem] items-center rounded-[10px] border border-border bg-muted px-3
                                    font-mono text-[0.8125rem] text-muted-foreground">
                            <span id="role-slug-preview">—</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">Derived from the name.</p>
                    </div>
                </div>

                <div>
                    <label for="role-description" class="field-label">Description</label>
                    <textarea id="role-description" name="description" rows="2" class="field-input !h-auto py-2"
                              placeholder="What is this role allowed to do?"></textarea>
                    <p class="field-error hidden" data-error-for="description"></p>
                </div>

                <div>
                    <span class="field-label">Permissions</span>
                    <div id="permission-matrix" class="space-y-3"></div>
                    <p class="field-error hidden" data-error-for="permission_ids"></p>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save role</button>
            </div>
        </form>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
