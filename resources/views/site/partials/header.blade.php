{{--
    The header.
    ======================================================================

    Transparent over a dark hero and solid once the page has moved under it —
    the state is toggled by resources/js/pages/site.js, which is also what
    stops white-on-white when the header reaches the paper sections.

    The anchors only mean anything on the home page, so a service page links
    back to them with an absolute URL rather than a bare `#fragment`. That is
    what `$anchor()` below is for: on the home page it is a fragment, elsewhere
    it is the home page's URL plus one.
--}}
@php
    use App\Support\Site;

    $onHome = ($route['name'] ?? 'home') === 'home';
    $home = Site::url('home');
    $anchor = fn (string $id): string => $onHome ? '#'.$id : $home.'#'.$id;

    // Deliberately not every section. A header that lists ten anchors is a
    // sitemap, and the visitor stops reading it at the third.
    $nav = [
        'services' => Site::text('nav.services'),
        'nameplate' => Site::text('nav.nameplate'),
        'process' => Site::text('nav.process'),
        'faq' => Site::text('nav.faq'),
        'contact' => Site::text('nav.contact'),
    ];
@endphp

<header data-site-header
        class="fixed inset-x-0 top-0 z-40 border-b border-transparent transition-all duration-300">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6 lg:h-[4.5rem]">

        {{-- The mark: a stator lamination, which is the shape a rewinding shop
             actually looks at all day. --}}
        <a href="{{ $home }}" class="flex shrink-0 items-center gap-2.5">
            <span class="grid size-9 place-items-center rounded-[11px] bg-copper-500 text-ink-950">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <circle cx="12" cy="12" r="4"/>
                    <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>
                </svg>
            </span>
            <span class="leading-tight">
                <span class="block text-[0.9375rem] font-bold tracking-tight text-white">
                    {{ Site::shop('name') }}
                </span>
                <span class="block text-[0.6875rem] font-medium tracking-wide text-ink-400">
                    {{ Site::shop('town') }} · {{ Site::shop('state') }}
                </span>
            </span>
        </a>

        <nav class="ml-auto hidden items-center gap-0.5 lg:flex">
            @foreach ($nav as $id => $label)
                <a href="{{ $anchor($id) }}" data-site-nav
                   class="rounded-lg px-3 py-2 text-[0.8125rem] font-medium text-ink-300 transition
                          hover:bg-white/10 hover:text-white">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-2 lg:ml-3">

            {{--
                The language switch.

                Two links, not a dropdown and not a toggle button: each language
                has a real URL, so this is navigation and should behave like it
                — middle-clickable, shareable, and visible to a crawler as a
                link to the translation.
            --}}
            <div class="hidden items-center rounded-[10px] border border-white/15 p-0.5 sm:flex"
                 role="group" aria-label="{{ Site::text('actions.language') }}">
                @foreach ($alternates as $alternate)
                    <a href="{{ $alternate['url'] }}"
                       hreflang="{{ $alternate['html'] }}"
                       lang="{{ $alternate['html'] }}"
                       @if ($alternate['current']) aria-current="true" @endif
                       class="rounded-[7px] px-2.5 py-1 text-[0.75rem] font-semibold transition
                              {{ $alternate['current']
                                  ? 'bg-white/15 text-white'
                                  : 'text-ink-400 hover:text-white' }}">
                        {{ $alternate['short'] }}
                    </a>
                @endforeach
            </div>

            {{--
                The way in for the shop's own staff.

                Secondary to the phone button on purpose: this header is read by
                customers, and the people who sign in are a handful who do it
                daily. It opens the modal rather than following a link, which is
                why it is a <button> — there is no sign-in page to navigate to
                (routes/web.php: /login is a redirect onto this modal).

                `data-login-open` is the hook initLoginModal() binds in
                resources/js/pages/site.js; the footer's button carries the same
                one. Below `lg` the hamburger is showing, so the panel below
                carries it instead and this is hidden rather than wrapped.
            --}}
            <button type="button" data-login-open
                    class="hidden h-10 items-center gap-2 rounded-[11px] border border-white/15 px-3.5
                           text-[0.8125rem] font-semibold text-white transition hover:bg-white/10 lg:inline-flex">
                <x-icon name="lock" :size="15" />
                {{ Site::text('actions.staff_login') }}
            </button>

            <a href="{{ Site::telUrl() }}"
               class="hidden h-10 items-center gap-2 rounded-[11px] bg-copper-500 px-4 text-[0.8125rem]
                      font-semibold text-ink-950 transition hover:bg-copper-400 sm:inline-flex">
                <x-icon name="phone" :size="16" />
                {{ Site::shop('phone') }}
            </a>

            <button type="button" data-site-menu aria-expanded="false"
                    aria-controls="site-mobile-nav"
                    aria-label="{{ Site::text('nav.menu_open') }}"
                    class="grid size-10 place-items-center rounded-[11px] border border-white/15 text-white
                           transition hover:bg-white/10 lg:hidden">
                <span data-site-menu-open><x-icon name="menu" :size="18" /></span>
                <span data-site-menu-close class="hidden"><x-icon name="x" :size="18" /></span>
            </button>
        </div>
    </div>

    {{-- The panel behind the hamburger. Always in the DOM so the script only
         toggles a class, and always dark: it only ever opens over the header,
         which is over one of the two dark bands or over a blurred page. --}}
    <div id="site-mobile-nav" class="hidden border-t border-white/10 bg-ink-950/95 backdrop-blur lg:hidden">
        <nav class="mx-auto grid max-w-6xl gap-0.5 px-4 py-3 sm:px-6">
            @foreach ($nav as $id => $label)
                <a href="{{ $anchor($id) }}" data-site-nav
                   class="rounded-lg px-3 py-2.5 text-sm font-medium text-ink-200 transition hover:bg-white/10 hover:text-white">
                    {{ $label }}
                </a>
            @endforeach

            {{-- Same button, for the widths where the one above is hidden. --}}
            <button type="button" data-login-open
                    class="mt-1 flex items-center gap-2 rounded-lg border border-white/15 px-3 py-2.5 text-sm
                           font-semibold text-white transition hover:bg-white/10">
                <x-icon name="lock" :size="15" />
                {{ Site::text('actions.staff_login') }}
            </button>

            <div class="mt-2 flex items-center gap-2 border-t border-white/10 pt-3">
                @foreach ($alternates as $alternate)
                    <a href="{{ $alternate['url'] }}"
                       hreflang="{{ $alternate['html'] }}"
                       lang="{{ $alternate['html'] }}"
                       class="rounded-lg px-3 py-2 text-sm font-semibold transition
                              {{ $alternate['current'] ? 'bg-white/15 text-white' : 'text-ink-400 hover:text-white' }}">
                        {{ $alternate['label'] }}
                    </a>
                @endforeach
            </div>
        </nav>
    </div>
</header>
