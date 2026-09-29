{{--
    Workshops — every workshop on the platform.

    The §2A flow: the module opens on the provisioning form, and the platform's
    list of workshops sits behind one switch control beside the heading
    (resources/js/workspace.js).

    ## One form, two frames

    `#tenant-form` is written once and *moved* — into the level-1 slot to
    provision a workshop, into `#tenant-modal` to edit one. It starts life inside
    the modal panel so it is never visible before `adoptForm()` has decided where
    it belongs. The owner block is the one part that is not shared: it exists only
    while provisioning, because an existing workshop's people are managed from
    inside it.

    ## What is not written here

    The status vocabulary is rendered from `TenantStatus`, which is an enum in the
    application rather than a list anybody maintains — the row badges take their
    words from the filter's options rather than from a second copy in the script.
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, form mode — where the module lands (§2A.1). --}}
    <div data-ws-form>
        <section class="surface form-card">
            <div class="form-head">
                <span class="tile-icon bg-blue-50 text-blue-600">
                    <x-icon name="building" :size="17" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-foreground">Provision a workshop</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        Each workshop keeps its own books, staff and chart of accounts.
                    </p>
                </div>
            </div>

            <div data-tenant-form-slot></div>

            <p class="hint mt-5">
                <x-icon name="info" :size="15" />
                <span>
                    A workshop and its owner are created together, or neither is. Suspending a workshop
                    later signs out everybody inside it and keeps its books intact.
                </span>
            </p>
        </section>
    </div>

    {{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
         other is held detached by the workspace, so its search, its filter and
         its fetched rows survive every trip to the form and back (§2A.2,
         §2A.6). --}}
    <div data-ws-list>

    {{-- No title and no "New workshop" button: the heading and the one control
         that swaps the two surfaces belong to the workspace (§2A.3). --}}
    <header class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <div class="search-pill w-64">
            <x-icon name="search" :size="15" />
            <input type="search" id="filter-search" class="w-full"
                   placeholder="Search by name, handle or GSTIN…" aria-label="Search workshops">
        </div>

        <div>
            <label for="filter-status" class="sr-only">Filter by status</label>
            <select id="filter-status" class="field-input w-auto min-w-40 py-0">
                <option value="">All statuses</option>
                @foreach (\App\Enums\TenantStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    </header>

    <div class="surface overflow-hidden rounded-[14px]">
        <div class="overflow-x-auto rounded-t-[14px]">
            <table class="w-full min-w-[820px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left" id="tenants-head">
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="name" scope="col">Workshop</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground" scope="col">GSTIN</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="status" scope="col">Status</th>
                        <th class="px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground" scope="col">Users</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="created_at" scope="col">Created</th>
                        {{-- `relative`: `sr-only` is absolutely positioned, and
                             without a positioned ancestor it escapes the
                             scroller and scrolls the page sideways (§7.3). --}}
                        <th class="relative px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="tenants-body" class="divide-y divide-muted"></tbody>
            </table>
        </div>

        <div id="tenants-pagination"
             class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3"></div>
    </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One workshop, read without leaving the list — level 2.

    The suspend and reactivate control lives here as well as on the row: the
    confirmation explains what it does to the people inside, and that is worth
    reading next to the workshop's details rather than over a table.
--}}
<div id="tenant-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="tenant-drawer-title">
    <div class="drawer-panel max-w-[680px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-[12px] bg-blue-50 text-blue-600">
                        <x-icon name="building" :size="20" />
                    </span>
                    <div class="min-w-0">
                        <h3 id="tenant-drawer-title" class="truncate text-base font-bold leading-tight text-foreground"></h3>
                        <p id="tenant-drawer-slug" class="mt-0.5 truncate font-mono text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="tenant-drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Users</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="tenant-drawer-users">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Created</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="tenant-drawer-created">—</p>
                </div>
            </div>
        </div>

        {{-- What the platform can look after inside this workshop: its people, the
             roles they may hold, and its settings. Not its books — sales, stock
             and the ledger stay the workshop's own. Each section is fetched the
             first time it is opened, never with the drawer. --}}
        <div class="tab-strip px-6" role="tablist" data-tenant-tabs>
            <button type="button" class="tab" role="tab" data-tenant-tab="details" aria-selected="true">Details</button>
            <button type="button" class="tab" role="tab" data-tenant-tab="users" aria-selected="false"
                    data-requires-permission="READ:USERS">
                Users <span data-tab-count="users"></span>
            </button>
            <button type="button" class="tab" role="tab" data-tenant-tab="roles" aria-selected="false"
                    data-requires-permission="READ:ROLES">
                Roles <span data-tab-count="roles"></span>
            </button>
            <button type="button" class="tab" role="tab" data-tenant-tab="settings" aria-selected="false">Settings</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
            <div data-tenant-panel="details" id="tenant-drawer-body"></div>

            <div data-tenant-panel="users" class="hidden">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[0.8125rem] text-muted-foreground">
                        Who may sign in to this workshop, and as what. Changing a role, a status or a
                        password signs that person out everywhere.
                    </p>
                    <button type="button" id="tenant-user-add" class="btn btn-primary btn-sm hidden"
                            data-requires-permission="WRITE:USERS">
                        <x-icon name="plus" :size="13" />
                        Add user
                    </button>
                </div>
                <div id="tenant-users-list"></div>
            </div>

            <div data-tenant-panel="roles" class="hidden">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[0.8125rem] text-muted-foreground">
                        The roles this workshop can give its people. They belong to this workshop alone — no other
                        workshop sees them, and changing one here changes nothing anywhere else.
                    </p>
                    <button type="button" id="tenant-role-add" class="btn btn-primary btn-sm hidden"
                            data-requires-permission="WRITE:ROLES">
                        <x-icon name="plus" :size="13" />
                        Add role
                    </button>
                </div>
                <div id="tenant-roles-list"></div>
            </div>

            <div data-tenant-panel="settings" class="hidden">
                <form id="tenant-settings-form" novalidate>
                    <p class="mb-4 text-[0.8125rem] text-muted-foreground">
                        The trading rules this workshop runs on. Its name, GSTIN and address are changed with
                        <strong>Edit</strong>. Each change is recorded in this workshop's history.
                    </p>

                    <div class="space-y-4">
                        <div class="form-grid">
                            <div>
                                <label for="ts-fy" class="field-label">Financial year starts</label>
                                <select id="ts-fy" name="financial_year_start_month" class="field-input">
                                    @foreach (range(1, 12) as $month)
                                        <option value="{{ $month }}">{{ date('F', mktime(0, 0, 0, $month, 1)) }}</option>
                                    @endforeach
                                </select>
                                <p class="field-error hidden" data-error-for="financial_year_start_month"></p>
                            </div>

                            <div>
                                <label for="ts-tz" class="field-label">Time zone</label>
                                <input id="ts-tz" name="timezone" type="text" class="field-input field-short font-mono"
                                       autocomplete="off" placeholder="Asia/Kolkata">
                                <p class="field-error hidden" data-error-for="timezone"></p>
                            </div>
                        
                            <div>
                                <label for="ts-books" class="field-label">Books start on</label>
                                <input id="ts-books" name="books_start_date" type="date" class="field-input field-date">
                                <p class="field-error hidden" data-error-for="books_start_date"></p>
                            </div>

                            <div>
                                <label for="ts-due" class="field-label">Payment terms (days)</label>
                                <input id="ts-due" name="payment_due_days" type="number" min="0" max="730"
                                       class="field-input" placeholder="Leave blank for none">
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    How long a bill may go unsettled before it counts as overdue.
                                </p>
                                <p class="field-error hidden" data-error-for="payment_due_days"></p>
                            </div>
                        </div>

                        <label class="flex items-start gap-2.5 text-[0.875rem] text-foreground">
                            <input type="checkbox" name="allow_negative_stock" class="mt-1">
                            <span>
                                Allow bills to take stock the shelf does not hold
                                <span class="block text-xs text-muted-foreground">Off by default.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2.5 text-[0.875rem] text-foreground">
                            <input type="checkbox" name="round_off_invoices" class="mt-1">
                            <span>
                                Round invoices to the nearest rupee
                                <span class="block text-xs text-muted-foreground">The paise are booked to Round Off.</span>
                            </span>
                        </label>
                    </div>

                    <div class="form-foot" data-requires-permission="UPDATE:TENANTS">
                        <button type="submit" class="btn btn-primary">Save settings</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="drawer-foot">
            <button type="button" id="tenant-drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:TENANTS">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" id="tenant-drawer-status-toggle" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:TENANTS"></button>
            <button type="button" id="tenant-drawer-delete" class="btn btn-ghost btn-sm hidden !text-rose-600"
                    data-requires-permission="DELETE:TENANTS">
                <x-icon name="trash" :size="13" />
                Delete
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{--
    Editing one workshop — level 2.

    The form starts in here and is moved into the level-1 slot on mount, then back
    again whenever a row is edited. The parts that only make sense in a dialog —
    the title bar, the close button, Cancel — are marked
    `data-form-chrome="modal"`; the level-1 alternatives `="inline"`.
--}}
<div id="tenant-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="tenant-modal-title">
    <div class="modal-panel max-w-2xl" data-tenant-modal-slot>

        <form id="tenant-form" novalidate>
            <input type="hidden" name="id">

            <div class="hidden items-start justify-between border-b border-muted px-6 py-4"
                 data-form-chrome="modal">
                <div>
                    <h2 id="tenant-modal-title" class="text-base font-bold text-foreground">Edit workshop</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="tenant-modal-subtitle"></p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4" data-form-body>
                <div class="form-grid">
                    <div>
                        <label for="tenant-name" class="field-label">Workshop name <span class="req">*</span></label>
                        <input id="tenant-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="Sharma Electricals">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div>
                        <span class="field-label">Handle</span>
                        <div class="flex h-[var(--control-h)] items-center rounded-[10px] border border-border bg-muted px-3
                                    font-mono text-[0.8125rem] text-muted-foreground">
                            <span id="tenant-slug-preview">—</span>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Set once, from the name. Renaming later leaves it unchanged.
                        </p>
                    </div>
                
                    <div>
                        <label for="tenant-gstin" class="field-label">GSTIN <span class="font-normal text-muted-foreground">(optional)</span></label>
                        <input id="tenant-gstin" name="gstin" type="text" maxlength="15"
                               class="field-input field-code font-mono uppercase" autocomplete="off" placeholder="27AAPFU0939F1ZV">
                        <p class="mt-1.5 text-xs text-muted-foreground">The first two digits set the state code.</p>
                        <p class="field-error hidden" data-error-for="gstin"></p>
                    </div>

                    <div>
                        <label for="tenant-state-code" class="field-label">State code</label>
                        <input id="tenant-state-code" name="state_code" type="text" maxlength="2" inputmode="numeric"
                               class="field-input field-num font-mono" autocomplete="off" placeholder="27">
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

                {{-- Owner block. Only offered when provisioning: an existing
                     workshop's people are managed from inside it. All or
                     nothing — a half-filled owner is a slip, not an intention. --}}
                <fieldset id="tenant-owner-block" class="rounded-[10px] border border-border p-4">
                    <legend class="px-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">
                        Owner account
                    </legend>

                    <p class="mb-3 text-[0.8125rem] text-muted-foreground">
                        A workshop with no owner cannot be signed into. Create one now, or leave blank and add a
                        user later.
                    </p>

                    <div class="space-y-3">
                        <div class="form-grid">
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

            {{-- The dialog's footer. --}}
            <div class="hidden gap-2 border-t border-muted px-6 py-4" data-form-chrome="modal">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save workshop</button>
            </div>

            {{-- The level-1 footer. "Clear" rather than "Cancel": the form is
                 where the module lives, and leaving it is what the switch
                 control above is for. --}}
            <div class="form-foot" data-form-chrome="inline">
                <button type="submit" class="btn btn-primary" data-requires-permission="WRITE:TENANTS">
                    <x-icon name="plus" :size="15" />
                    Create workshop
                </button>
                <button type="button" class="btn btn-ghost" data-tenant-clear>Clear</button>
            </div>
        </form>

    </div>
</div>

{{--
    One person in this workshop — level 3, over the drawer.

    A short form, and the drawer's own list is what shows the result, so it is a
    small dialog rather than a second drawer (§2.2). The roles in the picker are
    fetched from this workshop's own list, never written here: they are rows, and
    a copy in the markup would go stale the moment the owner adds one.
--}}
<div id="tenant-role-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="tenant-role-title">
    <div class="modal-panel max-w-2xl">
        <form id="tenant-role-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-start justify-between border-b border-muted px-6 py-4">
                <div>
                    <h2 id="tenant-role-title" class="text-base font-bold text-foreground">Add a role</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="tenant-role-subtitle"></p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-6 py-5">
                <div class="form-grid">
                    <div>
                        <label for="trole-name" class="field-label">Role name <span class="req">*</span></label>
                        <input id="trole-name" name="name" type="text" class="field-input" required
                               autocomplete="off" maxlength="64" placeholder="e.g. Counter Staff">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div>
                        <label for="trole-description" class="field-label">Description</label>
                        <input id="trole-description" name="description" type="text" class="field-input"
                               autocomplete="off" maxlength="255" placeholder="What this role is for">
                        <p class="field-error hidden" data-error-for="description"></p>
                    </div>
                </div>

                <div>
                    <span class="field-label">What this role may do</span>
                    <p class="mt-0.5 mb-2 text-xs text-muted-foreground">
                        Only grants that apply inside a workshop are offered, and never more than you hold
                        yourself.
                    </p>
                    {{-- Filled from the API. Never write the catalogue into this template. --}}
                    <div id="tenant-role-matrix" class="space-y-3"></div>
                    <p class="field-error hidden" data-error-for="permission_ids"></p>
                </div>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save role</button>
            </div>
        </form>
    </div>
</div>

<div id="tenant-user-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="tenant-user-title">
    <div class="modal-panel max-w-lg">
        <form id="tenant-user-form" novalidate>
            <input type="hidden" name="id">

            <div class="flex items-start justify-between border-b border-muted px-6 py-4">
                <div>
                    <h2 id="tenant-user-title" class="text-base font-bold text-foreground">Add a user</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="tenant-user-subtitle"></p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <div class="space-y-4 px-6 py-5">
                <div class="form-grid">
                    <div>
                        <label for="tuser-name" class="field-label">Full name <span class="req">*</span></label>
                        <input id="tuser-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="e.g. Ravi Sharma">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div>
                        <label for="tuser-email" class="field-label">Email address <span class="req">*</span></label>
                        <input id="tuser-email" name="email" type="email" class="field-input" required
                               autocomplete="off" placeholder="ravi@workshop.in">
                        <p class="field-error hidden" data-error-for="email"></p>
                    </div>
                </div>

                <div>
                    <label for="tuser-password" class="field-label">Password</label>
                    <input id="tuser-password" name="password" type="password" class="field-input"
                           autocomplete="new-password">
                    <p id="tenant-user-password-hint" class="mt-1.5 text-xs text-muted-foreground"></p>
                    <p class="field-error hidden" data-error-for="password"></p>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="tuser-status" class="field-label">Status</label>
                        <select id="tuser-status" name="status" class="field-input">
                            @foreach (\App\Enums\UserStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <p class="field-error hidden" data-error-for="status"></p>
                    </div>

                    <div>
                        <label for="tuser-role" class="field-label">Role</label>
                        <select id="tuser-role" name="custom_role_id" class="field-input">
                            <option value="">No role</option>
                        </select>
                        <p class="field-error hidden" data-error-for="custom_role_id"></p>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 border-t border-muted px-6 py-4">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save user</button>
            </div>
        </form>
    </div>
</div>
