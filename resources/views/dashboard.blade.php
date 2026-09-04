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
    <section id="view-home" class="view">

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
            <section class="mb-8" aria-labelledby="group-{{ $group }}">
                <h2 id="group-{{ $group }}" class="section-label mb-3">
                    {{ $group === 'primary' ? 'Modules' : 'Administration' }}
                </h2>

                <div class="card-grid">
                    @foreach ($modules as $key => $module)
                        <div class="hidden"
                             @if ($module['permission']) data-requires-permission="{{ $module['permission'] }}" @endif
                             @if ($module['workspace']) data-requires-workspace @endif
                             data-module-card>
                            <button type="button" class="module-card" data-open="{{ $key }}">
                                <span class="card-icon {{ $module['tone'] }}">
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
