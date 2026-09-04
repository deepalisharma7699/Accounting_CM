{{--
    The topbar — the whole of the application's global chrome, since the sidebar
    was removed (CLAUDE.md §1.2).

    It is mounted once and never unmounts. Opening a module swaps the region
    below it and repaints the breadcrumb; nothing here is re-rendered, which is
    what makes the swap feel like a panel changing rather than a page loading.

    The breadcrumb has two faces and shows exactly one of them:

        level 0   Choudhary Motors
        level 1   ◂ Home › Items

    Two separate elements rather than one whose text is rewritten, so
    `data-workspace-name` stays where resources/js/app.js paints it from
    /auth/me and the shell only has to toggle visibility. A single element would
    mean remembering the workshop's name in order to restore it, which is a copy
    of something the session already knows.
--}}

<header class="topbar">

    <nav class="crumb" aria-label="Breadcrumb">
        {{-- Level 1 only. Hidden rather than absent, because the shell toggles
             them on every open and close and creating nodes for that would be
             work done over and over for no gain. --}}
        <button type="button" class="crumb-back" data-crumb-back hidden aria-label="Back to dashboard">
            <x-icon name="chevron-left" :size="18" />
        </button>

        <button type="button" class="crumb-home" data-crumb-home hidden>Home</button>

        <span class="crumb-sep" data-crumb-sep hidden aria-hidden="true">
            <x-icon name="chevron-right" :size="14" />
        </span>

        {{-- Level 0: which workshop's books this session is looking at. Painted
             client-side — a name in the markup would make it impossible to tell
             which workshop, or whether any, the session belongs to. --}}
        <span class="crumb-current" data-crumb-workspace data-workspace-name>&nbsp;</span>

        {{-- Level 1: the open module. Filled by the shell from the registry. --}}
        <span class="crumb-current" data-crumb-module hidden></span>
    </nav>

    <div class="topbar-search">
        <div class="search-pill">
            <x-icon name="search" :size="16" />
            <input type="search"
                   class="w-full"
                   placeholder="Search bills, customers, items…"
                   aria-label="Search"
                   data-search>
            <kbd class="hidden rounded-md border border-border bg-card px-1.5 py-0.5 font-sans
                        text-[0.6875rem] font-medium text-muted-foreground sm:block">⌘K</kbd>
        </div>
    </div>

    {{-- No notification bell. It rang for nothing — no handler, no feed, and a
         permanent unread dot over an empty list is a control that teaches people
         to ignore it. It comes back when there is something to notify about. --}}
    <div class="ml-auto flex items-center gap-2">
        {{--
            The signed-in user, and the way out.

            Sign-out lived in the sidebar footer until the sidebar went. It is a
            menu rather than a bare button because the identity it belongs to —
            who you are signed in as, in which role, in which workshop — is what
            somebody checks just before signing out, and a topbar has no room to
            state all three.
        --}}
        <div class="relative" data-user-menu>
            <button type="button"
                    class="avatar"
                    data-user-menu-toggle
                    aria-haspopup="menu"
                    aria-expanded="false"
                    aria-label="Account">
                <span data-user-initial>&nbsp;</span>
            </button>

            <div class="row-menu hidden w-64 p-0" data-user-menu-panel role="menu">
                <div class="border-b border-border px-4 py-3">
                    <span class="block truncate text-sm font-semibold text-foreground" data-user-name>&nbsp;</span>
                    <span class="block truncate text-xs text-muted-foreground" data-user-role>&nbsp;</span>
                </div>

                <div class="border-b border-border px-4 py-3">
                    <span class="section-label block">Workshop</span>
                    <span class="mt-1 block truncate text-sm font-medium text-foreground"
                          data-workspace-name>&nbsp;</span>
                    <span class="block truncate text-xs text-muted-foreground" data-workspace-scope>&nbsp;</span>
                </div>

                <button type="button" class="row-menu-item" data-danger role="menuitem"
                        data-logout title="Sign out" aria-label="Sign out">
                    <x-icon name="log-out" :size="17" />
                    <span>Sign out</span>
                </button>
            </div>
        </div>
    </div>
</header>
