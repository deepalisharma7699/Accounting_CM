{{--
    Roles — what each role is allowed to do.

    The §2A flow: the module opens on the create form, and the roles sit behind
    one switch control beside the heading (resources/js/workspace.js). A caller
    who may read roles but not write them — the workshop OWNER, who holds
    READ:ROLES and nothing more — lands on the list instead and is offered no
    switch to a surface they cannot use.

    ## The matrix is not written here

    `#permission-matrix` is empty in the markup and filled from
    GET /api/v1/permissions?grouped=1. The catalogue is seeded from
    `PermissionResource` and grows with every module that gets built, so a copy
    of it in this template would be a list of grants that silently stops
    matching the ones the middleware actually checks — a role would be given
    permissions that no longer exist and refused ones that do.

    ## System roles

    ADMIN is seeded, flagged `is_system_role` and refused by the API for edit
    and delete: if the superuser role could be rewritten through this screen, one
    compromised admin session could lock everybody else out. Its controls are
    disabled here rather than hidden, so the reason stays visible.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, form mode — where the module lands for a caller who may write
         roles (§2A.1). --}}
    <div data-ws-form>
        <section class="surface form-card">
            <div class="form-head">
                <span class="tile-icon bg-violet-50 text-violet-600">
                    <x-icon name="shield" :size="17" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-foreground">Create a role</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        Group the grants a job needs, then hand the role to the people who do it.
                    </p>
                </div>
            </div>

            <div data-role-form-slot></div>

            <p class="hint mt-5">
                <x-icon name="info" :size="15" />
                <span>
                    Every grant is checked again on the server, on every endpoint. Taking one away
                    here takes it away everywhere, on the holder's next request — not at the end of
                    their session.
                </span>
            </p>
        </section>
    </div>

    {{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
         other is held detached by the workspace, so its search and its fetched
         rows survive every trip to the form and back (§2A.2, §2A.6). --}}
    <div data-ws-list>

    <header class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <div class="search-pill w-60">
            <x-icon name="search" :size="15" />
            <input type="search" id="filter-search" class="w-full"
                   placeholder="Search roles…" aria-label="Search roles">
        </div>
    </header>

    {{-- Three of the four are also filters. "Unassigned" is the one worth
         having: a role nobody holds is either about to be needed or was left
         behind, and neither is visible on a list sorted by name. --}}
    <div class="tile-row">
        <div class="stat-tile">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-violet-50 text-violet-600">
                <x-icon name="shield" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-total">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Total Roles</span>
            </span>
        </div>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="custom">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                <x-icon name="users" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-custom">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Editable</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="system">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                <x-icon name="lock" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-system">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">System</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="unassigned">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                <x-icon name="alert-triangle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-unassigned">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Held By Nobody</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2" id="filter-pills">
        <button type="button" class="pill" data-pill="all" aria-pressed="true">All Roles</button>
        <button type="button" class="pill" data-pill="custom" aria-pressed="false">Editable</button>
        <button type="button" class="pill" data-pill="system" aria-pressed="false">System</button>
        <button type="button" class="pill" data-pill="unassigned" aria-pressed="false">Held By Nobody</button>

        <button type="button" id="clear-filters"
                class="ml-1 hidden items-center gap-1 px-3 py-1.5 text-xs text-muted-foreground transition hover:text-secondary-foreground">
            <x-icon name="x" :size="12" />
            Clear filters
        </button>
    </div>

    <div class="surface overflow-hidden rounded-[14px]">
        <div class="overflow-x-auto rounded-t-[14px]">
            <table class="w-full min-w-[820px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left" id="roles-head">
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="name" scope="col">Role</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            scope="col">Description</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="grants" scope="col">Permissions</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="users" scope="col">Users</th>
                        {{-- `relative`, and it matters: `sr-only` is absolutely
                             positioned, so without a positioned ancestor its
                             containing block is the document — it is laid out at
                             the far right of an 820px table, escapes the
                             scroller's clipping, and scrolls the whole page
                             sideways on a phone (§7.3). --}}
                        <th class="relative px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="roles-body" class="divide-y divide-muted"></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
            <p id="roles-summary" class="text-[0.78125rem] text-muted-foreground"></p>

            <div class="flex items-center gap-1" id="roles-pager"></div>
        </div>
    </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One role, read without leaving the list — level 2.

    The grants in full, grouped by resource. The table can only show the first
    few, and "+14 more" is exactly the part somebody opens a role to check.
--}}
<div id="role-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="role-drawer-title">
    <div class="drawer-panel max-w-[480px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-accent
                                 text-accent-foreground" id="role-drawer-icon">
                        <x-icon name="shield" :size="18" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="role-drawer-title" class="truncate text-base font-bold leading-tight text-foreground"></h3>
                        <p id="role-drawer-slug" class="mt-0.5 truncate font-mono text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="role-drawer-badge"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Permissions</p>
                    <p class="text-[17px] font-bold text-foreground" id="role-drawer-grants">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Users holding it</p>
                    <p class="text-[17px] font-bold text-foreground" id="role-drawer-users">—</p>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="role-drawer-body"></div>

        <div class="drawer-foot">
            <button type="button" id="role-drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:ROLES">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" id="role-drawer-delete" class="btn btn-ghost btn-sm hidden !text-rose-600"
                    data-requires-permission="DELETE:ROLES">
                <x-icon name="trash" :size="13" />
                Delete
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{--
    Editing one role — level 2.

    The form starts in here and is moved into the level-1 slot on mount, then
    back again whenever a role is edited. One node, one set of ids, one submit
    handler — see the note at the top of resources/js/workspace.js.
--}}
<div id="role-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="role-modal-title">
    <div class="modal-panel max-w-2xl" data-role-modal-slot>

        <form id="role-form" novalidate>
            <input type="hidden" name="id">

            <div class="hidden items-start justify-between border-b border-muted px-6 py-4"
                 data-form-chrome="modal">
                <div>
                    <h2 id="role-modal-title" class="text-base font-bold text-foreground">Edit role</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="role-modal-subtitle"></p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4" data-form-body>
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
                   data-form-banner role="alert"></p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="role-name" class="field-label">Role name <span class="req">*</span></label>
                        <input id="role-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="e.g. Branch Accountant">
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
                    <label for="role-description" class="field-label">
                        Description <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <textarea id="role-description" name="description" rows="2" maxlength="255"
                              class="field-input !h-auto py-2"
                              placeholder="What is somebody holding this role here to do?"></textarea>
                    <p class="field-error hidden" data-error-for="description"></p>
                </div>

                {{-- Filled from GET /api/v1/permissions?grouped=1 — see the note
                     at the top of this file. --}}
                <div>
                    <span class="field-label">Permissions</span>
                    <div id="permission-matrix" class="space-y-3"></div>
                    <p class="field-error hidden" data-error-for="permission_ids"></p>
                </div>
            </div>

            {{-- The dialog's footer. --}}
            <div class="hidden gap-2 border-t border-muted px-6 py-4" data-form-chrome="modal">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save role</button>
            </div>

            {{-- The level-1 footer. --}}
            <div class="form-foot" data-form-chrome="inline">
                <button type="submit" class="btn btn-primary" data-requires-permission="WRITE:ROLES">
                    <x-icon name="plus" :size="15" />
                    Create role
                </button>
                <button type="button" class="btn btn-ghost" data-role-clear>Clear</button>
            </div>
        </form>

    </div>
</div>
