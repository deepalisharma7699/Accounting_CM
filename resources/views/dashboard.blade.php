@extends('layouts.app')

@section('title', 'Dashboard')
@section('page', 'dashboard')

@section('content')

{{--
    The one page shell in the application — CLAUDE.md §1.3.

    Two regions live in here. `#view-home` is the module grid; `#view-module` is
    the level-1 workspace a card opens into. Exactly one of them is on screen,
    and the swap between them is the only thing that ever changes below the
    topbar. Nothing here navigates, and nothing reloads.

    ## Why home is only the cards

    It used to carry the day's figures as well — takings, an attention list, the
    bench, counts and a day book down the right-hand side. They are gone on
    purpose. Home is the way in to the work (§1.3, §1.4), and a screen that is
    also a report gives somebody a wall to read before they can reach the module
    they opened the tab for. The figures were never home's to own: each belongs
    beside the records it summarises, inside the module that holds them.

    Nothing is baked in here regardless. The shell is public, so a figure in this
    markup would be a workshop's takings in HTML anybody could fetch.
--}}

<div class="shell">

    {{-- ============================================================= --}}
    {{-- Level 0 — home                                                --}}
    {{-- ============================================================= --}}
    {{--
        `data-home-loading` is the document's own initial state rather than
        something JavaScript adds, because anything added after the markup has
        painted arrives too late to prevent the flash it is there to prevent.
        shell.js takes it off once the grants are in and the grid has settled.
    --}}
    <section id="view-home" class="view" data-home-loading aria-busy="true">

        @include('partials.home-skeleton')

        {{--
            Favourites — the cards this workshop uses every day, above the rest.

            Empty on purpose, and populated by resources/js/favourites.js, which
            *moves* the card nodes below into this grid. Three reasons it is a
            move rather than a second copy rendered here:

            - the grid has exactly one card per module, which is what
              PagesRenderTest holds shut. A card rendered twice is a card the
              owner sees twice.
            - the cards are gated client-side, so by the time anything is moved
              the permission pass has already hidden whatever this reader may not
              open. A favourite they have lost the grant for is simply not moved.
            - shell.js reads its label registry and its permission check off
              `[data-module-card]`. Two nodes per key would be two answers to one
              question (§4.4).

            Which keys are favourites is per *workshop*, not per user: it rides in
            the `tenant` block of /auth/me, so nothing user-specific is baked into
            this markup — the shell is public, and a figure or a name in here
            would be one anybody could fetch.
        --}}
        <section class="mb-5" data-favourites hidden aria-labelledby="group-favourites">
            {{-- The same heading the bands below get: one band of cards, so it
                 is labelled like one (§7.4). --}}
            <div class="group-head">
                <h2 id="group-favourites" class="section-label">Favourites</h2>
                <span class="group-rule" aria-hidden="true"></span>
            </div>

            <div class="card-grid" data-favourites-grid></div>

            {{--
                Shown only to somebody who can actually change it, and only while
                nothing is starred. It teaches the one control this feature has
                and then goes away for good — a permanent instruction above the
                cards would be a line every owner reads past every morning.
            --}}
            <p class="hint hidden"
               data-favourites-empty
               data-requires-permission="UPDATE:WORKSPACE"
               data-requires-workspace>
                <x-icon name="star" :size="15" />
                <span>Star the cards your workshop opens every day and they will sit up here, above the rest.</span>
            </p>
        </section>

        {{--
            The modules, as cards — §1.3 and §1.4.

            One per module that exists and is switched on, rather than a
            hand-picked few: a card opens its module in the mounted shell rather
            than pointing at a page.

            Both gates are declared on the wrapper rather than on the card, so
            `applyPermissionGates()` toggles `hidden` on an element whose display
            it fully owns. Gated wrappers start hidden and are revealed only once
            /auth/me confirms the grant — nothing flashes on the way in.
        --}}
        @foreach (\App\Support\Modules::groups() as $group => $modules)
            <section class="mb-5" data-module-group="{{ $group }}" aria-labelledby="group-{{ $group }}">
                {{--
                    The band's heading, and the rule that carries it to the right
                    edge. Five bands of three or four replaced two of twelve and
                    six, and at that size a bare label floating over a row is not
                    enough to read as a division — the rule is what makes the
                    grid look sorted rather than merely interrupted.

                    Both the label and the band's place in the order come from
                    App\Support\Modules, never from a ternary here: this loop
                    used to name two groups, which is exactly the kind of copy
                    that is forgotten when a third is added.
                --}}
                <div class="group-head">
                    <h2 id="group-{{ $group }}" class="section-label">
                        {{ \App\Support\Modules::groupLabel($group) }}
                    </h2>
                    <span class="group-rule" aria-hidden="true"></span>
                </div>

                <div class="card-grid">
                    @foreach ($modules as $key => $module)
                        {{-- `relative` so the star can sit over the card's corner. --}}
                        <div class="hidden relative"
                             @if ($module['permission']) data-requires-permission="{{ $module['permission'] }}" @endif
                             @if ($module['workspace']) data-requires-workspace @endif
                             data-module-card="{{ $key }}">
                            {{--
                                A sibling of the card, never a child of it: the
                                card is a <button>, and a button inside a button
                                is invalid markup that browsers resolve by
                                dropping the inner one. It is positioned over the
                                card's top-right corner instead, which is why the
                                wrapper is `relative`.

                                Gated on UPDATE:WORKSPACE — the favourites are the
                                workshop's, so the person who configures the
                                workshop arranges them. Everybody else sees the
                                row and no stars.
                            --}}
                            <button type="button"
                                    class="card-star hidden"
                                    data-star="{{ $key }}"
                                    data-requires-permission="UPDATE:WORKSPACE"
                                    data-requires-workspace
                                    aria-pressed="false"
                                    aria-label="Star {{ $module['label'] }}">
                                <x-icon name="star" :size="15" />
                            </button>

                            {{--
                                The tone is on the card, not on the chip.

                                It is one class declaring a `--tone-bg` /
                                `--tone-fg` pair, and the card spends it in three
                                places: the band across its head, the chip, and
                                the title. The two Tailwind classes this replaced
                                (`bg-emerald-50 text-emerald-600`) could only
                                paint the element they sat on.
                            --}}
                            <button type="button"
                                    class="module-card {{ $module['tone'] }}"
                                    data-open="{{ $key }}">
                                <span class="card-icon">
                                    <x-icon :name="$module['icon']" :size="20" />
                                </span>

                                <span class="card-title">{{ $module['label'] }}</span>
                                <span class="card-desc">{{ $module['description'] }}</span>

                                <span class="card-meta">
                                    <span class="card-count"></span>
                                    <x-icon name="arrow-up-right" :size="16" />
                                </span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        {{--
            Nothing to show at all.

            A platform super-admin holds every grant but belongs to no workshop,
            so every workshop-scoped card is stripped for them and the grid comes
            out empty. Revealed client-side by the same gating pass, because only
            it knows what survived. An empty page with no explanation reads as a
            broken one.
        --}}
        <p class="hint hidden" data-no-modules>
            <x-icon name="info" :size="15" />
            <span>
                There is nothing here for this account. Your user administers the platform
                rather than a single workshop, so it owns no books to open.
            </span>
        </p>
    </section>

    {{-- ============================================================= --}}
    {{-- Level 1 — the module workspace                                --}}
    {{-- ============================================================= --}}
    {{-- Empty on purpose. A module's markup, its code and its data all arrive on
         the first open and are kept from then on (§2.5, §7.2). --}}
    <section id="view-module" class="view" hidden></section>

</div>

@endsection
