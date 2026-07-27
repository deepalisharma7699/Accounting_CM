@php $heading ??= 'Home'; @endphp

<header class="sticky top-0 z-20 flex h-topbar items-center gap-3 border-b border-border bg-card px-4 sm:px-8">

    {{-- Mobile sidebar toggle --}}
    <button type="button"
            class="grid size-9 place-items-center rounded-lg text-secondary-foreground transition
                   hover:bg-muted lg:hidden"
            data-sidebar-open aria-label="Open navigation">
        <x-icon name="chevron-left" :size="18" class="rotate-180" />
    </button>

    <h1 class="text-lg font-bold tracking-tight text-foreground">{{ $heading }}</h1>

    {{-- Search --}}
    <div class="ml-2 hidden max-w-lg flex-1 sm:block">
        <div class="relative">
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                <x-icon name="search" :size="17" />
            </span>

            <input type="search"
                   placeholder="Search transactions, customers, vendors..."
                   class="h-9 w-full rounded-[10px] border border-border bg-secondary pl-9 pr-14 text-sm
                          text-foreground placeholder:text-muted-foreground focus:border-primary
                          focus:bg-card focus:outline-none focus:ring-2 focus:ring-ring/60"
                   data-search>

            <kbd class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md border
                        border-border bg-card px-1.5 py-0.5 font-sans text-[0.6875rem] font-medium
                        text-muted-foreground">
                ⌘K
            </kbd>
        </div>
    </div>

    <div class="ml-auto flex items-center gap-2">
        <button type="button"
                class="relative grid size-9 place-items-center rounded-lg text-secondary-foreground
                       transition hover:bg-muted"
                aria-label="Notifications">
            <x-icon name="bell" :size="19" />
            <span class="absolute right-2 top-2 size-2 rounded-full bg-primary ring-2 ring-card"></span>
        </button>

        <span class="grid size-9 place-items-center rounded-full bg-primary text-sm font-semibold
                     text-primary-foreground"
              data-user-initial>&nbsp;</span>
    </div>
</header>
