{{--
    The topbar — the whole of the application's global chrome, since the sidebar
    was removed (CLAUDE.md §1.2).

    It is mounted once and never unmounts. Opening a module swaps the region
    below it and repaints the breadcrumb; nothing here is re-rendered, which is
    what makes the swap feel like a panel changing rather than a page loading.

    The breadcrumb has two faces and shows exactly one of them:

        level 0   Choudhary Motors
        level 1   ◂ ⌂ › Items

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

        {{-- The root of the crumb is a glyph, not the word "Home": beside a module
             name set at 1.125rem/700 the word read as a footnote to it, which is
             the wrong way round — it is the one step back and the only other place
             to go. The icon is sized to that title rather than to the word it
             replaced, and carries the label for anyone not reading the glyph. --}}
        <button type="button" class="crumb-home" data-crumb-home hidden
                aria-label="Home" title="Home">
            <x-icon name="home" :size="20" />
        </button>

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

    {{--
        The one search box in the application that is not over a list.

        Every module has its own, and each of them can only be asked from inside
        that module. This one is asked from anywhere and answers with the record
        rather than with a filtered table: picking a result opens the module that
        owns it, in the mounted shell, with a deep link that opens its drawer
        (§1.4). The behaviour is resources/js/search.js.

        `relative` so the results panel can hang off the pill, and the whole
        thing is one `data-search-root` because a click outside it is what closes
        the panel.
    --}}
    <div class="topbar-search">
        <div class="relative" data-search-root>
            <div class="search-pill">
                <x-icon name="search" :size="16" />
                <input type="search"
                       class="w-full"
                       placeholder="Search bills, customers, items…"
                       aria-label="Search"
                       autocomplete="off"
                       role="combobox"
                       aria-expanded="false"
                       aria-autocomplete="list"
                       aria-controls="search-results"
                       data-search>
                {{-- The glyph is corrected to "Ctrl K" off a Mac by search.js:
                     a shortcut hint naming a key the keyboard does not have is
                     worse than no hint. --}}
                <kbd class="hidden whitespace-nowrap rounded-md border border-border bg-card px-1.5 py-0.5
                            font-sans text-[0.6875rem] font-medium text-muted-foreground sm:block"
                     data-search-hint>⌘K</kbd>
            </div>

            <div id="search-results"
                 class="search-panel hidden"
                 role="listbox"
                 aria-label="Search results"
                 data-search-panel></div>
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

                {{-- Above sign-out, and separated from it: they are the two
                     things you do to your own session, but one of them ends it
                     and a mis-tap there costs somebody their unsaved work. --}}
                <button type="button" class="row-menu-item" role="menuitem"
                        data-security-open title="Sign-in security">
                    <x-icon name="fingerprint" :size="17" />
                    <span>Sign-in security</span>
                </button>

                <button type="button" class="row-menu-item" data-danger role="menuitem"
                        data-logout title="Sign out" aria-label="Sign out">
                    <x-icon name="log-out" :size="17" />
                    <span>Sign out</span>
                </button>
            </div>
        </div>
    </div>
</header>
