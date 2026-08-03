@php
    // Nav structure from the design. `match` is the route-name prefix used to
    // decide the active item.
    /*
    | `workspace => true` marks an entry as belonging to a single workshop's
    | books. Those are hidden from a platform super-admin, who holds every
    | permission but owns no books — authority is not membership, and showing
    | them a chart of accounts they cannot load is worse than showing nothing.
    */
    $primaryNav = [
        ['label' => 'Dashboard',    'icon' => 'home',      'route' => 'dashboard', 'badge' => true],
        // Capturing business events. Held by DATA_ENTRY as well as OWNER —
        // entering the day's transactions is the workshop's day job.
        /*
        | Bills — sales, purchases and expenses. A separate entry from
        | "Transactions" and not a filter on it, because they are what a workshop
        | spends its day writing: a screen that made somebody choose a
        | transaction type before offering an invoice form would be organised
        | around the ledger rather than around the work.
        */
        ['label' => 'Bills',        'icon' => 'receipt',   'route' => 'bills.index', 'permission' => 'READ:TRANSACTIONS', 'workspace' => true],
        ['label' => 'Transactions', 'icon' => 'file-text', 'route' => 'journal.index', 'permission' => 'READ:TRANSACTIONS', 'workspace' => true],
        /*
        | The catalogue — what the workshop deals in — and, since M8, what is
        | actually on the shelf. Still two entries rather than one "Inventory",
        | because they answer different questions and are gated on different
        | grants: ITEMS is the record, STOCK is the position. A data-entry user
        | holds both; the split matters because knowing the workshop deals in
        | 5 HP motors is not knowing four are in the corner.
        */
        ['label' => 'Items',        'icon' => 'package',   'route' => 'items.index', 'permission' => 'READ:ITEMS', 'workspace' => true],
        ['label' => 'Stock',        'icon' => 'layers',    'route' => 'stock.index', 'permission' => 'READ:STOCK', 'workspace' => true],
        /*
        | The design's two counterparty entries. They are two views of one
        | `parties` table, filtered on role *membership* — so the shop that buys
        | a rewound motor and sells you scrap copper is one record appearing on
        | both lists, marked as such, rather than two records whose halves of a
        | single balance never meet. That second outcome is the one the parties
        | table exists to prevent, and splitting the nav must not reintroduce
        | it: the statement either screen opens is always the combined ledger.
        |
        | Both are gated on the same grant, because they are the same records.
        */
        ['label' => 'Customers',    'icon' => 'users',      'route' => 'customers.index', 'permission' => 'READ:PARTIES', 'workspace' => true],
        ['label' => 'Vendors',      'icon' => 'building-2', 'route' => 'vendors.index',   'permission' => 'READ:PARTIES', 'workspace' => true],
        ['label' => 'Accounting',   'icon' => 'book-open', 'route' => 'accounts.index', 'permission' => 'READ:ACCOUNTS', 'workspace' => true],
        // Reading the whole financial position, which is a different authority
        // from capturing events — hence LEDGER rather than TRANSACTIONS.
        ['label' => 'Ledger',       'icon' => 'bar-chart', 'route' => 'ledger.index', 'permission' => 'READ:LEDGER', 'workspace' => true],
        /*
        | The day book, the P&L, the GST summary and the parked-draft worklist —
        | M12. Gated on LEDGER rather than TRANSACTIONS because three of the four
        | are the workshop's whole financial position; the worklist is the
        | exception and its endpoint says so, but an entry that opened on a
        | report a data-entry user cannot load would be worse than none.
        */
        ['label' => 'Reports',      'icon' => 'bar-chart', 'route' => 'reports.index', 'permission' => 'READ:LEDGER', 'workspace' => true],
        /*
        | Stored evidence — M14. In the primary nav rather than beside Settings,
        | because photographing an invoice is a thing somebody does at the
        | counter several times a day, not a thing they set up once. Gated on
        | ATTACHMENTS, which DATA_ENTRY holds: the person holding the paper is
        | the person who photographs it.
        */
        ['label' => 'Uploads',      'icon' => 'camera',    'route' => 'uploads.index', 'permission' => 'READ:ATTACHMENTS', 'workspace' => true],
        ['label' => 'AI Center',    'icon' => 'sparkles',  'route' => null, 'workspace' => true],
    ];

    /*
    | Administration group. Not in the original design, which has no screens for
    | user or role management — added below the divider so it reads as part of
    | the existing Settings group rather than competing with the primary nav.
    | Each entry is hidden unless the signed-in user holds the matching grant
    | (applied client-side in resources/js/app.js from GET /auth/me).
    */
    $adminNav = [
        // Platform surface: authority over every workshop. Only ADMIN holds it.
        ['label' => 'Workshops', 'icon' => 'building', 'route' => 'tenants.index', 'permission' => 'READ:TENANTS'],
        ['label' => 'Users',     'icon' => 'users',    'route' => 'users.index',   'permission' => 'READ:USERS'],
        ['label' => 'Roles',     'icon' => 'shield',   'route' => 'roles.index',   'permission' => 'READ:ROLES'],
        // The caller's own workshop. Needs membership as well as the grant —
        // a platform admin has no workshop to configure.
        ['label' => 'Settings',  'icon' => 'settings', 'route' => 'workspace.index', 'permission' => 'READ:WORKSPACE', 'workspace' => true],
        /*
        | Go-live — M11. Down here beside Settings rather than up in the primary
        | nav, because it is used once: an entry a workshop opens every day
        | should not sit beside one they open never. Gated on UPDATE:WORKSPACE
        | rather than on WRITE:TRANSACTIONS, which is the same distinction the
        | route makes — declaring what the business was worth at go-live is a
        | setup act, not the day job, and a data-entry user holds neither this
        | entry nor the endpoint behind it.
        */
        ['label' => 'Opening balances', 'icon' => 'clipboard-list', 'route' => 'opening.index', 'permission' => 'UPDATE:WORKSPACE', 'workspace' => true],
        /*
        | The trail — M13. Down here with the administration group rather than
        | up in the primary nav, because it is opened when something looks wrong
        | rather than as part of the day's work. Gated on AUDIT, which only OWNER
        | holds: the trail records what a data-entry user did, and reading it is
        | not part of doing it.
        */
        ['label' => 'History', 'icon' => 'clock', 'route' => 'audit.index', 'permission' => 'READ:AUDIT', 'workspace' => true],
    ];
@endphp

<aside id="sidebar"
       class="fixed inset-y-0 left-0 z-40 flex w-sidebar flex-col border-r border-border bg-card
              transition-transform duration-200 max-lg:-translate-x-full max-lg:shadow-raised
              lg:translate-x-0"
       data-sidebar>

    {{-- Workspace identity --}}
    <div class="flex items-center gap-3 px-4 py-4">
        <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-primary text-primary-foreground
                     shadow-primary-glow">
            <x-icon name="zap" :size="20" />
        </span>

        {{-- Populated by resources/js/app.js from GET /api/v1/auth/me. A
             hardcoded name here made it impossible to tell at a glance which
             workshop — or whether any workshop — the session belonged to. --}}
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[0.9375rem] font-semibold leading-tight text-foreground"
                  data-workspace-name>&nbsp;</span>
            <span class="block truncate text-xs text-muted-foreground" data-workspace-scope>&nbsp;</span>
        </span>

        <button type="button"
                class="grid size-7 place-items-center rounded-md text-muted-foreground
                       transition hover:bg-muted hover:text-foreground"
                data-sidebar-collapse aria-label="Collapse sidebar">
            <x-icon name="chevron-left" :size="18" />
        </button>
    </div>

    {{-- Primary navigation --}}
    <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Main">
        <ul class="space-y-1">
            @foreach ($primaryNav as $item)
                @php $active = $item['route'] && request()->routeIs($item['route']); @endphp

                @php $gated = ($item['permission'] ?? null) || ($item['workspace'] ?? false); @endphp

                {{-- Gated entries start hidden and are revealed client-side once
                     /auth/me confirms the grant and workshop membership — same
                     mechanism as the admin group below. --}}
                <li @if ($gated) class="hidden" @endif
                    @if ($item['permission'] ?? null) data-requires-permission="{{ $item['permission'] }}" @endif
                    @if ($item['workspace'] ?? false) data-requires-workspace @endif>
                    <a href="{{ $item['route'] ? route($item['route']) : '#' }}"
                       @class([
                           'group flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm transition',
                           'bg-accent font-semibold text-accent-foreground' => $active,
                           'font-medium text-secondary-foreground hover:bg-muted hover:text-foreground' => ! $active,
                       ])
                       @if ($active) aria-current="page" @endif>

                        <x-icon :name="$item['icon']" :size="19"
                                :class="$active
                                    ? 'text-primary'
                                    : 'text-muted-foreground group-hover:text-secondary-foreground'" />

                        <span class="flex-1">{{ $item['label'] }}</span>

                        @if ($item['badge'] ?? false)
                            <span class="size-1.5 rounded-full bg-primary"></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        <hr class="my-4 border-border">

        <ul class="space-y-1">
            @foreach ($adminNav as $item)
                @php $active = $item['route'] && request()->routeIs($item['route']); @endphp

                @php $gated = ($item['permission'] ?? null) || ($item['workspace'] ?? false); @endphp

                <li @if ($gated) class="hidden" @endif
                    @if ($item['permission'] ?? null) data-requires-permission="{{ $item['permission'] }}" @endif
                    @if ($item['workspace'] ?? false) data-requires-workspace @endif>
                    <a href="{{ $item['route'] ? route($item['route']) : '#' }}"
                       @class([
                           'group flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm transition',
                           'bg-accent font-semibold text-accent-foreground' => $active,
                           'font-medium text-secondary-foreground hover:bg-muted hover:text-foreground' => ! $active,
                       ])
                       @if ($active) aria-current="page" @endif>

                        <x-icon :name="$item['icon']" :size="19"
                                :class="$active
                                    ? 'text-primary'
                                    : 'text-muted-foreground group-hover:text-secondary-foreground'" />

                        <span>{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Signed-in user. Populated by resources/js/app.js from GET /api/v1/auth/me --}}
    <div class="border-t border-border px-4 py-4">
        <div class="flex items-center gap-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-sm font-semibold
                         text-primary-foreground"
                  data-user-initial>&nbsp;</span>

            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold leading-tight text-foreground"
                      data-user-name>&nbsp;</span>
                <span class="block truncate text-xs text-muted-foreground" data-user-role>&nbsp;</span>
            </span>

            <button type="button"
                    class="grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground transition
                           hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-ring disabled:opacity-50"
                    data-logout title="Sign out" aria-label="Sign out">
                <x-icon name="log-out" :size="17" />
            </button>
        </div>
    </div>
</aside>

{{-- Mobile scrim --}}
<div class="fixed inset-0 z-30 hidden bg-foreground/20 backdrop-blur-[1px] lg:hidden"
     data-sidebar-scrim></div>
