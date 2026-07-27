@php
    // Nav structure from the design. `match` is the route-name prefix used to
    // decide the active item.
    $primaryNav = [
        ['label' => 'Dashboard',    'icon' => 'home',           'route' => 'dashboard', 'badge' => true],
        ['label' => 'Transactions', 'icon' => 'file-text',      'route' => null],
        ['label' => 'Inventory',    'icon' => 'package',        'route' => null],
        ['label' => 'Customers',    'icon' => 'users',          'route' => null],
        ['label' => 'Vendors',      'icon' => 'building',       'route' => null],
        ['label' => 'Accounting',   'icon' => 'book-open',      'route' => null],
        ['label' => 'Reports',      'icon' => 'bar-chart',      'route' => null],
        ['label' => 'AI Center',    'icon' => 'sparkles',       'route' => null],
    ];

    /*
    | Administration group. Not in the original design, which has no screens for
    | user or role management — added below the divider so it reads as part of
    | the existing Settings group rather than competing with the primary nav.
    | Each entry is hidden unless the signed-in user holds the matching grant
    | (applied client-side in resources/js/app.js from GET /auth/me).
    */
    $adminNav = [
        ['label' => 'Users',    'icon' => 'users',    'route' => 'users.index', 'permission' => 'READ:USERS'],
        ['label' => 'Roles',    'icon' => 'shield',   'route' => 'roles.index', 'permission' => 'READ:ROLES'],
        ['label' => 'Settings', 'icon' => 'settings', 'route' => null,          'permission' => null],
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

        <span class="min-w-0 flex-1">
            <span class="block truncate text-[0.9375rem] font-semibold leading-tight text-foreground">
                XYZ Workshop
            </span>
            <span class="block truncate text-xs text-muted-foreground">Pro Plan</span>
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

                <li>
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

                <li @if ($item['permission']) data-requires-permission="{{ $item['permission'] }}" class="hidden" @endif>
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
