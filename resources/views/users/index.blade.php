@extends('layouts.app')

@section('title', 'Users')
@section('page', 'users')

@section('content')

<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Users</h2>
        <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
            Manage who can sign in, what role they hold and whether their account is active.
        </p>
    </div>

    <button type="button" id="new-user" class="btn btn-primary hidden"
            data-requires-permission="WRITE:USERS">
        <x-icon name="plus" :size="17" />
        New user
    </button>
</header>

{{-- Filters --}}
<div class="surface mb-4 flex flex-wrap items-center gap-3 p-3">
    <div class="relative min-w-56 flex-1">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            <x-icon name="search" :size="17" />
        </span>
        <input type="search" id="filter-search" class="field-input pl-9"
               placeholder="Search by name or email…" aria-label="Search users">
    </div>

    <select id="filter-status" class="field-input w-auto min-w-40" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach (\App\Enums\UserStatus::cases() as $status)
            <option value="{{ $status->value }}">{{ $status->label() }}</option>
        @endforeach
    </select>

    <div data-role-filter class="hidden">
        <select id="filter-role" class="field-input w-auto min-w-40" aria-label="Filter by role">
            <option value="">All roles</option>
        </select>
    </div>
</div>

{{-- Table --}}
<div class="surface overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] border-collapse">
            <thead>
                <tr>
                    <th class="table-head">User</th>
                    <th class="table-head">Role</th>
                    <th class="table-head">Status</th>
                    <th class="table-head">Last sign-in</th>
                    <th class="table-head text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="users-body"></tbody>
        </table>
    </div>

    <div id="users-pagination" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3"></div>
</div>

{{-- Create / edit --}}
<div id="user-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="user-modal-title">
    <div class="modal-panel max-w-lg">
        <form id="user-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h2 id="user-modal-title" class="text-base font-bold text-foreground">New user</h2>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4 px-6 py-5">
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div>
                    <label for="user-name" class="field-label">Full name</label>
                    <input id="user-name" name="name" type="text" class="field-input" required
                           autocomplete="off" placeholder="Jane Cooper">
                    <p class="field-error hidden" data-error-for="name"></p>
                </div>

                <div>
                    <label for="user-email" class="field-label">Email address</label>
                    <input id="user-email" name="email" type="email" class="field-input" required
                           autocomplete="off" placeholder="jane@company.com">
                    <p class="field-error hidden" data-error-for="email"></p>
                </div>

                <div>
                    <label for="user-password" class="field-label">Password</label>
                    <input id="user-password" name="password" type="password" class="field-input"
                           autocomplete="new-password">
                    <p id="password-hint" class="mt-1.5 text-xs text-muted-foreground"></p>
                    <p class="field-error hidden" data-error-for="password"></p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="user-status" class="field-label">Status</label>
                        <select id="user-status" name="status" class="field-input">
                            @foreach (\App\Enums\UserStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <p class="field-error hidden" data-error-for="status"></p>
                    </div>

                    <div>
                        <label for="user-role" class="field-label">Role</label>
                        <select id="user-role" name="custom_role_id" class="field-input">
                            <option value="">No role</option>
                        </select>
                        <p class="field-error hidden" data-error-for="custom_role_id"></p>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Changing a role or setting a non-active status revokes that user's sessions immediately.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save user</button>
            </div>
        </form>
    </div>
</div>

@include('partials.confirm-modal')

@endsection
