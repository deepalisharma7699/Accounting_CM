{{--
    Users — who may sign in to this workshop, and as what.

    The §2A flow: the module opens on the create form, and the directory sits
    behind one switch control beside the heading (resources/js/workspace.js).

    ## One form, two frames

    `#user-form` is written once and *moved* — into the level-1 slot for a
    create, into `#user-modal` for an edit. Two copies of these fields would be
    two sets of ids, two submit handlers and two places for a password rule to
    be added to only one of (§4.4, §5.1). It starts life inside the modal panel
    so it is never visible before `adoptForm()` has decided where it belongs,
    and a caller without WRITE:USERS — whose module lands on the list — never
    sees it at all.

    ## What is not written here

    The roles. They are rows in `roles`, published by GET /api/v1/roles, and a
    copy of them in this markup would go stale the moment somebody adds one. The
    status options *are* rendered from `UserStatus`, because that is an enum in
    the application rather than a list anybody maintains — the same distinction
    the catalogue draws between a unit (data) and a transaction type (code).
--}}
<div class="mx-auto max-w-[1280px]">

    {{-- Level 1, form mode — where the module lands (§2A.1). --}}
    <div data-ws-form>
        <section class="surface form-card">
            <div class="form-head">
                <span class="tile-icon bg-blue-50 text-blue-600">
                    <x-icon name="user-cog" :size="17" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold text-foreground">Add a user</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                        Who may sign in, and what they are allowed to do once they are in.
                    </p>
                </div>
            </div>

            <div data-user-form-slot></div>

            <p class="hint mt-5">
                <x-icon name="info" :size="15" />
                <span>
                    A role is what somebody may do; a status is whether they may sign in at all.
                    Changing either revokes their sessions immediately, so an account taken away
                    is out on the next request rather than at the end of its token's life.
                </span>
            </p>
        </section>
    </div>

    {{-- Level 1, list mode. Exactly one of the two is in the DOM at a time — the
         other is held detached by the workspace, so its search, its filter and
         its fetched rows survive every trip to the form and back (§2A.2,
         §2A.6). --}}
    <div data-ws-list>

    {{-- No title and no "New user" button: the heading and the one control that
         swaps the two surfaces belong to the workspace, in the same slot in both
         modes. A second create button here is what §2A.3 forbids. --}}
    <header class="mb-5 flex flex-wrap items-center justify-end gap-2">
        <div class="search-pill w-60">
            <x-icon name="search" :size="15" />
            <input type="search" id="filter-search" class="w-full"
                   placeholder="Search by name or email…" aria-label="Search users">
        </div>

        {{-- Filling this needs the role catalogue, so it is gated on the grant
             that reads it: a caller holding READ:USERS alone gets the directory
             without a filter they could not populate. --}}
        <div data-requires-permission="READ:ROLES">
            <label for="filter-role" class="sr-only">Filter by role</label>
            <select id="filter-role" class="field-input h-[2.375rem] w-auto min-w-44 py-0">
                <option value="">Any role</option>
            </select>
        </div>
    </header>

    {{-- The four figures. Three are also filters; the total is a count and
         nothing else, so it does not pretend to be clickable. --}}
    <div class="tile-row">
        <div class="stat-tile">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-blue-50 text-blue-600">
                <x-icon name="users" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-total">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Total Users</span>
            </span>
        </div>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="active">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-emerald-50 text-emerald-600">
                <x-icon name="check-circle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-active">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Active</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        {{-- Only an active account may authenticate — inactive, suspended and
             pending all mean "cannot sign in", so they are counted together and
             the row's badge says which of the three it is. --}}
        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="blocked">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-amber-50 text-amber-500">
                <x-icon name="alert-triangle" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-blocked">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Cannot Sign In</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>

        <button type="button" class="stat-tile stat-tile-action" data-stat-filter="never">
            <span class="grid size-9 shrink-0 place-items-center rounded-[9px] bg-violet-50 text-violet-600">
                <x-icon name="clock" :size="16" />
            </span>
            <span>
                <span class="block text-[22px] font-bold leading-none text-foreground" id="stat-never">—</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">Never Signed In</span>
            </span>
            <span class="ml-auto text-border" data-stat-chevron>
                <x-icon name="chevron-right" :size="14" />
            </span>
        </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2" id="filter-pills">
        <button type="button" class="pill" data-pill="all" aria-pressed="true">All Users</button>
        <button type="button" class="pill" data-pill="active" aria-pressed="false">Active</button>
        <button type="button" class="pill" data-pill="blocked" aria-pressed="false">Cannot Sign In</button>
        <button type="button" class="pill" data-pill="never" aria-pressed="false">Never Signed In</button>

        <button type="button" id="clear-filters"
                class="ml-1 hidden items-center gap-1 px-3 py-1.5 text-xs text-muted-foreground transition hover:text-secondary-foreground">
            <x-icon name="x" :size="12" />
            Clear filters
        </button>
    </div>

    <div class="surface overflow-hidden rounded-[14px]">
        <div class="overflow-x-auto rounded-t-[14px]">
            <table class="w-full min-w-[760px] border-collapse">
                <thead>
                    <tr class="border-b border-border bg-background text-left" id="users-head">
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="name" scope="col">User</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="role" scope="col">Role</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="status" scope="col">Status</th>
                        <th class="th-sort px-4 py-3 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground"
                            data-sort="last_login" scope="col">Last Sign-in</th>
                        {{-- `relative`, and it matters: `sr-only` is absolutely
                             positioned, so without a positioned ancestor its
                             containing block is the document — it is laid out at
                             the far right of an 820px table, escapes the
                             scroller's clipping, and scrolls the whole page
                             sideways on a phone (§7.3). --}}
                        <th class="relative px-4 py-3" scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="users-body" class="divide-y divide-muted"></tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-muted px-4 py-3">
            <p id="users-summary" class="text-[0.78125rem] text-muted-foreground"></p>

            <div class="flex items-center gap-1" id="users-pager"></div>
        </div>
    </div>

    </div>{{-- /data-ws-list --}}
</div>

{{--
    One user, read without leaving the directory — level 2.

    What it is for is the last block in it: the grants this person actually
    holds, resolved server-side from their role. A role's name is not an answer
    to "may they void a bill", and the effective list is the only place that
    question is answered truthfully.
--}}
<div id="user-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="user-drawer-title">
    <div class="drawer-panel max-w-[480px]">
        <div class="border-b border-muted px-6 py-5">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary
                                 text-sm font-bold text-primary-foreground" id="user-drawer-initials"></span>
                    <div class="min-w-0">
                        <h3 id="user-drawer-title" class="truncate text-base font-bold leading-tight text-foreground"></h3>
                        <p id="user-drawer-email" class="mt-0.5 truncate text-xs text-muted-foreground"></p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <span id="user-drawer-status"></span>
                    <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                        <x-icon name="x" :size="16" />
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Role</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="user-drawer-role">—</p>
                </div>
                <div class="rounded-[10px] bg-background px-3 py-2.5">
                    <p class="mb-0.5 text-[11px] text-muted-foreground">Last sign-in</p>
                    <p class="truncate text-[15px] font-bold text-foreground" id="user-drawer-seen">—</p>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5" id="user-drawer-body"></div>

        <div class="drawer-foot">
            <button type="button" id="user-drawer-edit" class="btn btn-secondary btn-sm hidden"
                    data-requires-permission="UPDATE:USERS">
                <x-icon name="pencil" :size="13" />
                Edit
            </button>
            <button type="button" id="user-drawer-delete" class="btn btn-ghost btn-sm hidden !text-rose-600"
                    data-requires-permission="DELETE:USERS">
                <x-icon name="trash" :size="13" />
                Delete
            </button>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{--
    Editing one user — level 2.

    The form starts in here and is moved into the level-1 slot on mount, then
    back again whenever a row is edited. The parts that only make sense in a
    dialog — the title bar, the close button, Cancel — are marked
    `data-form-chrome="modal"`; the level-1 alternatives `="inline"`.
--}}
<div id="user-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="user-modal-title">
    <div class="modal-panel max-w-lg" data-user-modal-slot>

        <form id="user-form" novalidate>
            <input type="hidden" name="id">

            <div class="hidden items-start justify-between border-b border-muted px-6 py-4"
                 data-form-chrome="modal">
                <div>
                    <h2 id="user-modal-title" class="text-base font-bold text-foreground">Edit user</h2>
                    <p class="mt-0.5 text-[0.78125rem] text-muted-foreground" id="user-modal-subtitle"></p>
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
                        <label for="user-name" class="field-label">Full name <span class="req">*</span></label>
                        <input id="user-name" name="name" type="text" class="field-input" required
                               autocomplete="off" placeholder="e.g. Ravi Sharma">
                        <p class="field-error hidden" data-error-for="name"></p>
                    </div>

                    <div>
                        <label for="user-email" class="field-label">Email address <span class="req">*</span></label>
                        <input id="user-email" name="email" type="email" class="field-input" required
                               autocomplete="off" placeholder="ravi@workshop.in">
                        <p class="mt-1.5 text-xs text-muted-foreground">This is what they sign in with.</p>
                        <p class="field-error hidden" data-error-for="email"></p>
                    </div>
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

                    {{-- Filled from GET /api/v1/roles. Never listed here: roles
                         are rows somebody maintains, and a copy in the markup
                         goes stale the moment one is added. --}}
                    <div>
                        <label for="user-role" class="field-label">Role</label>
                        <select id="user-role" name="custom_role_id" class="field-input">
                            <option value="">No role</option>
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Somebody with no role can sign in and do nothing.
                        </p>
                        <p class="field-error hidden" data-error-for="custom_role_id"></p>
                    </div>
                </div>
            </div>

            {{-- The dialog's footer. --}}
            <div class="hidden gap-2 border-t border-muted px-6 py-4" data-form-chrome="modal">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1">Save user</button>
            </div>

            {{-- The level-1 footer. "Clear" rather than "Cancel": there is
                 nothing to cancel out of — the form is where the module lives,
                 and leaving it is what the switch control above is for. --}}
            <div class="form-foot" data-form-chrome="inline">
                <button type="submit" class="btn btn-primary" data-requires-permission="WRITE:USERS">
                    <x-icon name="plus" :size="15" />
                    Create user
                </button>
                <button type="button" class="btn btn-ghost" data-user-clear>Clear</button>
            </div>
        </form>

    </div>
</div>
