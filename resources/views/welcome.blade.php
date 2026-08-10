<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choudhary Motors · Motor rewinding & pump repairs, Charkhi Dadri</title>
    <meta name="description"
          content="Choudhary Motors, Charkhi Dadri — electric motor rewinding, submersible pump repairs, new motors, bearings and copper winding wire. Load-tested work with a written warranty.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

@php
    /*
    |--------------------------------------------------------------------------
    | The shop
    |--------------------------------------------------------------------------
    |
    | PLACEHOLDERS — replace before this page goes public. Every phone,
    | WhatsApp, email and address link on the page reads from this one array, so
    | the real details are a single edit here rather than a search through the
    | markup. `phone_dial` is what tel: and wa.me use and must stay digits-only
    | with the country code; `phone` is only what the visitor reads.
    */
    $shop = [
        'name' => 'Choudhary Motors',
        'town' => 'Charkhi Dadri',
        'phone' => '+91 98137 07087',
        'phone_dial' => '919813707087',
        'email' => 'sunil.achievers@gmail.com',
        'street' => 'Near Indian Oil Petrol Pump Rohtak Road, ByPass, Rawaldhi',
        'city' => 'Charkhi Dadri, Haryana 127306',
        'hours' => 'Mon – Sun · 5:30 am – 8:00 pm',
        'closed' => '',
        // A search link rather than an embedded map: no third-party script runs
        // on this page, which is the same reason the fonts are self-hosted.
        'map' => 'https://www.google.com/maps/search/?api=1&query=Choudhary+Motors+Charkhi+Dadri',
    ];

    /*
    |--------------------------------------------------------------------------
    | The hero's exploded motor
    |--------------------------------------------------------------------------
    |
    | Each part is a row: where it rests (`at`), where it flies in from (`from`
    | — a translate pair and a rotation), when (`delay`), and how it behaves once
    | it has landed. The cogs and the fan turn; everything else drifts.
    |
    | The distances are in viewport units on purpose. A part starting 62vw to the
    | left is off-screen on every size of screen, where a pixel offset would be
    | off-screen on a phone and a twitch on a desktop.
    */
    $parts = [
        ['art' => 'cog-lg',   'at' => 'left-[-2%] top-[6%]',      'from' => '-62vw, -28vh, -300deg', 'delay' => '0.05s', 'spin' => ['turn', '26s']],
        ['art' => 'bearing',  'at' => 'right-[2%] top-[0%]',      'from' => '34vw, -46vh, 260deg',   'delay' => '0.18s', 'spin' => null],
        ['art' => 'coil',     'at' => 'left-[4%] bottom-[8%]',    'from' => '-48vw, 42vh, -180deg',  'delay' => '0.30s', 'spin' => null],
        ['art' => 'fan',      'at' => 'right-[-3%] bottom-[16%]', 'from' => '56vw, 30vh, 240deg',    'delay' => '0.12s', 'spin' => ['turn-back', '9s']],
        ['art' => 'bolt',     'at' => 'left-[26%] top-[-4%]',     'from' => '-14vw, -52vh, 420deg',  'delay' => '0.42s', 'spin' => null],
        ['art' => 'cog-sm',   'at' => 'right-[20%] bottom-[-2%]', 'from' => '20vw, 48vh, 300deg',    'delay' => '0.36s', 'spin' => ['turn', '14s']],
        ['art' => 'terminal', 'at' => 'right-[-1%] top-[42%]',    'from' => '58vw, 4vh, 120deg',     'delay' => '0.24s', 'spin' => null],
        ['art' => 'spanner',  'at' => 'left-[10%] top-[46%]',     'from' => '-58vw, 16vh, -140deg',  'delay' => '0.48s', 'spin' => null],
    ];

    /*
    | The parts themselves. Drawn on the same 24x24 grid as the icon set so they
    | sit together, but kept here rather than in <x-icon> because none of them is
    | an icon — nothing else in the application will ever draw a rotor.
    */
    $art = [
        'cog-lg' => '<svg viewBox="0 0 24 24" class="size-24 sm:size-28 text-slate-500/70" fill="none" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.2 13.6 4a8 8 0 0 1 2 .8l2.3-.6 1.6 2.8-1.6 1.8a8 8 0 0 1 0 2.2l1.6 1.8-1.6 2.8-2.3-.6a8 8 0 0 1-2 .8L12 21.8 10.4 20a8 8 0 0 1-2-.8l-2.3.6-1.6-2.8 1.6-1.8a8 8 0 0 1 0-2.2L4.5 11.2 6.1 8.4l2.3.6a8 8 0 0 1 2-.8Z"/><circle cx="12" cy="12" r="4.4"/><circle cx="12" cy="12" r="1.6"/></svg>',
        'cog-sm' => '<svg viewBox="0 0 24 24" class="size-14 sm:size-16 text-blue-400/70" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.4 13.2 5a7 7 0 0 1 1.7.7l1.9-.5 1.2 2.1-1.2 1.5a7 7 0 0 1 0 1.8l1.2 1.5-1.2 2.1-1.9-.5a7 7 0 0 1-1.7.7L12 20.6 10.8 19a7 7 0 0 1-1.7-.7l-1.9.5-1.2-2.1 1.2-1.5a7 7 0 0 1 0-1.8L5.2 11.9l1.2-2.1 1.9.5A7 7 0 0 1 10 9.6Z"/><circle cx="12" cy="12" r="3.4"/></svg>',
        'bearing' => '<svg viewBox="0 0 24 24" class="size-16 sm:size-20 text-amber-300/80" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="12" cy="12" r="9.2"/><circle cx="12" cy="12" r="4.6"/><circle cx="12" cy="4.6" r="1.35"/><circle cx="17.2" cy="6.8" r="1.35"/><circle cx="19.4" cy="12" r="1.35"/><circle cx="17.2" cy="17.2" r="1.35"/><circle cx="12" cy="19.4" r="1.35"/><circle cx="6.8" cy="17.2" r="1.35"/><circle cx="4.6" cy="12" r="1.35"/><circle cx="6.8" cy="6.8" r="1.35"/></svg>',
        'coil' => '<svg viewBox="0 0 24 24" class="size-20 sm:size-24 text-amber-400/85" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M3 8c0-1.7 4-3 9-3s9 1.3 9 3-4 3-9 3-9-1.3-9-3Z"/><path d="M3 8v8c0 1.7 4 3 9 3s9-1.3 9-3V8"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/><path d="M8 5.4v13.2M16 5.4v13.2"/></svg>',
        'fan' => '<svg viewBox="0 0 24 24" class="size-20 sm:size-24 text-slate-400/70" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"><circle cx="12" cy="12" r="2.4"/><path d="M12 9.6c0-3.4.9-5.6 2.6-6.6 1.4-.8 2.8.3 2.5 1.9-.4 2.2-2.1 3.9-5.1 4.7Z"/><path d="M14.4 12c3.4 0 5.6.9 6.6 2.6.8 1.4-.3 2.8-1.9 2.5-2.2-.4-3.9-2.1-4.7-5.1Z"/><path d="M12 14.4c0 3.4-.9 5.6-2.6 6.6-1.4.8-2.8-.3-2.5-1.9.4-2.2 2.1-3.9 5.1-4.7Z"/><path d="M9.6 12c-3.4 0-5.6-.9-6.6-2.6-.8-1.4.3-2.8 1.9-2.5 2.2.4 3.9 2.1 4.7 5.1Z"/></svg>',
        'bolt' => '<svg viewBox="0 0 24 24" class="size-10 sm:size-12 text-slate-400/70" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><path d="M9 3.6h6l3 1.8v3.2l-3 1.8H9l-3-1.8V5.4Z"/><path d="M10.6 10.4h2.8v9.2l-1.4 1.2-1.4-1.2Z"/><path d="M10.6 13h2.8M10.6 15.4h2.8M10.6 17.8h2.8"/></svg>',
        'terminal' => '<svg viewBox="0 0 24 24" class="size-12 sm:size-14 text-blue-300/70" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3.2" y="6.4" width="17.6" height="11.2" rx="2"/><path d="M7 6.4V4.6M17 6.4V4.6"/><circle cx="8.2" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="15.8" cy="12" r="1.3"/></svg>',
        'spanner' => '<svg viewBox="0 0 24 24" class="size-12 sm:size-14 text-slate-400/60" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
    ];

    /* What the shop does, in the order people ask for it. */
    $services = [
        [
            'icon' => 'wrench',
            'title' => 'Motor rewinding',
            'body' => 'Single- and three-phase motors rewound to the original winding data — new copper, fresh varnish, oven-cured and balanced.',
            'meta' => '0.5 HP – 100 HP',
            'tone' => 'blue',
        ],
        [
            'icon' => 'droplet',
            'title' => 'Submersible pump repairs',
            'body' => 'Borewell and openwell pumps stripped, rewound and resealed, then run against head and discharge before they leave.',
            'meta' => 'Same day on most sets',
            'tone' => 'sky',
        ],
        [
            'icon' => 'zap',
            'title' => 'New motors & pumps',
            'body' => 'Branded induction motors, monoblocks and submersibles in stock, sized against your load rather than against a catalogue.',
            'meta' => 'Sized on site',
            'tone' => 'amber',
        ],
        [
            'icon' => 'package',
            'title' => 'Spares & bearings',
            'body' => 'Bearings, capacitors, fans, terminal blocks, starters and shaft sleeves — the parts that decide whether a rewind lasts.',
            'meta' => 'Counter sales',
            'tone' => 'violet',
        ],
        [
            'icon' => 'layers',
            'title' => 'Copper winding wire',
            'body' => 'Enamelled copper by gauge, insulation paper, sleeving and varnish, weighed out for winders and workshops around the district.',
            'meta' => 'Sold by weight',
            'tone' => 'rose',
        ],
        [
            'icon' => 'truck',
            'title' => 'Site visits & AMC',
            'body' => 'Breakdown call-outs to farms, flour mills and workshops, plus annual contracts that catch a failing bearing before it seizes.',
            'meta' => 'Across the district',
            'tone' => 'emerald',
        ],
    ];

    /* Tone -> classes for the service tiles, in one place so a new tone is a row. */
    $tones = [
        'blue' => 'bg-blue-50 text-blue-600 group-hover:bg-blue-600',
        'sky' => 'bg-sky-50 text-sky-600 group-hover:bg-sky-600',
        'amber' => 'bg-amber-50 text-amber-600 group-hover:bg-amber-600',
        'violet' => 'bg-violet-50 text-violet-600 group-hover:bg-violet-600',
        'rose' => 'bg-rose-50 text-rose-600 group-hover:bg-rose-600',
        'emerald' => 'bg-emerald-50 text-emerald-600 group-hover:bg-emerald-600',
    ];

    /* What happens between dropping a motor off and collecting it. */
    $steps = [
        ['n' => '01', 'icon' => 'clipboard-list', 'title' => 'Booked in', 'body' => 'Nameplate, winding data and the fault are written down against your name before a single bolt comes off.'],
        ['n' => '02', 'icon' => 'gauge', 'title' => 'Tested & quoted', 'body' => 'Insulation resistance, continuity and bearing play checked. You get a figure before any work starts, not after.'],
        ['n' => '03', 'icon' => 'wrench', 'title' => 'Rewound', 'body' => 'New copper to the original turns and gauge, varnished, oven-cured, bearings replaced and the rotor balanced.'],
        ['n' => '04', 'icon' => 'check-circle', 'title' => 'Load tested', 'body' => 'Run on load, current drawn on all three phases recorded, and handed back with the readings and a written warranty.'],
    ];

    /* The numbers on the band under the hero. */
    $stats = [
        ['to' => 32, 'suffix' => '+', 'label' => 'Years on the same road'],
        ['to' => 14, 'suffix' => 'k+', 'label' => 'Motors rewound'],
        ['to' => 48, 'suffix' => ' hrs', 'label' => 'Usual turnaround'],
        ['to' => 6, 'suffix' => ' mo', 'label' => 'Warranty on every rewind'],
    ];

    /*
    | PLACEHOLDER COPY. These are worked examples of the kind of review this
    | section is for — they are not real customers, and must be replaced with
    | genuine, attributable ones before the page is published.
    */
    $testimonials = [
        [
            'quote' => 'Our 25 HP flour mill motor burned out on a Tuesday and we were grinding again on Thursday morning. They showed me the current readings on all three phases before I paid.',
            'name' => 'Ramesh Kumar',
            'role' => 'Flour mill · Charkhi Dadri',
            'stars' => 5,
        ],
        [
            'quote' => 'Third season on the submersible they rewound and it has not been pulled out once. They sized the starter properly, which the last shop never bothered with.',
            'name' => 'Sunita Devi',
            'role' => 'Farm borewell · Baund Kalan',
            'stars' => 5,
        ],
        [
            'quote' => 'I buy winding wire from them by the kilo. The gauge is what the label says and the weight is honest — that is the whole reason I stopped driving to Bhiwani.',
            'name' => 'Anil Sharma',
            'role' => 'Rewinding workshop · Loharu',
            'stars' => 5,
        ],
    ];

    /* Why a visitor should pick this bench rather than the next one. */
    $reasons = [
        ['icon' => 'shield', 'title' => 'Written warranty', 'body' => 'Six months on every rewind, on paper, with the test readings attached.'],
        ['icon' => 'gauge', 'title' => 'Tested before quoting', 'body' => 'You are told what is wrong and what it costs before anything is opened up.'],
        ['icon' => 'award', 'title' => 'Original winding data', 'body' => 'Rewound to the nameplate turns and gauge, never to whatever copper is nearest.'],
        ['icon' => 'refresh-cw', 'title' => 'Standby motors', 'body' => 'A loan motor keeps the mill turning while yours is on the bench.'],
    ];

    /* The band that scrolls under the hero. Rendered twice, for the loop. */
    $trades = [
        'Motor rewinding', 'Submersible pumps', 'Copper winding wire', 'Bearings & spares',
        'Monoblock pumps', 'Starters & panels', 'On-site breakdown calls', 'Annual maintenance',
    ];

    /* The header and the footer offer the same five anchors. */
    $nav = ['Services' => 'services', 'How it works' => 'process', 'Why us' => 'why', 'Reviews' => 'reviews', 'Visit' => 'contact'];
@endphp

{{-- data-page selects which module resources/js/app.js boots. --}}
<body class="min-h-full bg-background antialiased" data-page="welcome">

{{-- =====================================================================
     Header — transparent over the hero, solid once the page has scrolled
     ===================================================================== --}}
<header data-lp-header
        class="fixed inset-x-0 top-0 z-40 border-b border-transparent transition-all duration-300">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6 lg:h-[4.5rem]">

        <a href="#top" class="flex items-center gap-2.5">
            <span class="grid size-9 place-items-center rounded-[11px] bg-primary text-primary-foreground
                         shadow-primary-glow">
                <x-icon name="zap" :size="19" />
            </span>
            <span class="leading-tight">
                <span class="block text-[0.9375rem] font-bold tracking-tight text-white">{{ $shop['name'] }}</span>
                <span class="block text-[0.6875rem] font-medium tracking-wide text-slate-400">
                    {{ $shop['town'] }} · Haryana
                </span>
            </span>
        </a>

        <nav class="ml-auto hidden items-center gap-1 lg:flex">
            @foreach ($nav as $label => $anchor)
                <a href="#{{ $anchor }}" data-lp-nav
                   class="rounded-lg px-3 py-2 text-[0.8125rem] font-medium text-slate-300 transition
                          hover:bg-white/10 hover:text-white">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-2 lg:ml-3">
            <a href="tel:+{{ $shop['phone_dial'] }}"
               class="hidden items-center gap-2 rounded-[10px] border border-white/15 px-3.5 py-2 text-[0.8125rem]
                      font-semibold text-white transition hover:bg-white/10 sm:inline-flex">
                <x-icon name="phone" :size="15" />
                {{ $shop['phone'] }}
            </a>

            {{-- The way in. Opens the modal at the foot of this file rather than
                 navigating anywhere. --}}
            <button type="button" data-login-open
                    class="inline-flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2 text-[0.8125rem]
                           font-semibold text-primary-foreground shadow-primary-glow transition
                           hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <x-icon name="lock" :size="15" />
                Login
            </button>

            <button type="button" data-lp-menu aria-expanded="false" aria-controls="lp-mobile-nav"
                    aria-label="Open menu"
                    class="grid size-9 place-items-center rounded-[10px] border border-white/15 text-white
                           transition hover:bg-white/10 lg:hidden">
                <x-icon name="menu" :size="18" data-lp-menu-open />
                <x-icon name="x" :size="18" class="hidden" data-lp-menu-close />
            </button>
        </div>
    </div>

    {{-- Hidden rather than absent, so the toggle has something to reveal without
         the header having to re-render itself. --}}
    <div id="lp-mobile-nav" class="hidden border-t border-white/10 bg-slate-950/95 backdrop-blur lg:hidden">
        <nav class="mx-auto grid max-w-6xl gap-1 px-4 py-4 sm:px-6">
            @foreach ($nav as $label => $anchor)
                <a href="#{{ $anchor }}" data-lp-nav
                   class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-200 hover:bg-white/10 hover:text-white">
                    {{ $label }}
                </a>
            @endforeach

            <a href="tel:+{{ $shop['phone_dial'] }}"
               class="mt-2 flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2.5 text-sm font-semibold text-white">
                <x-icon name="phone" :size="16" />
                {{ $shop['phone'] }}
            </a>
        </nav>
    </div>
</header>

<main id="top">

    {{-- =================================================================
         Hero
         ================================================================= --}}
    <section class="lp-grid relative overflow-hidden bg-slate-950 pb-20 pt-28 sm:pt-32 lg:pb-28 lg:pt-40">

        {{-- Two soft lights behind the grid: one under the copy, one behind the
             motor, so the eye is pulled across rather than into the middle. --}}
        <div class="pointer-events-none absolute -left-32 top-10 size-[34rem] rounded-full bg-blue-600/20 blur-[120px]"></div>
        <div class="lp-pulse pointer-events-none absolute -right-20 top-1/3 size-[30rem] rounded-full bg-amber-500/10 blur-[120px]"></div>

        <div class="relative mx-auto grid max-w-6xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:gap-8">

            {{-- Copy ------------------------------------------------------ --}}
            <div>
                <span data-reveal style="--ry:1rem"
                      class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5
                             text-[0.75rem] font-medium text-slate-200 backdrop-blur">
                    <span class="relative flex size-1.5">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-1.5 rounded-full bg-emerald-400"></span>
                    </span>
                    {{ $shop['hours'] }}
                </span>

                <h1 data-reveal style="--rd:.08s"
                    class="mt-6 text-4xl font-bold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-[3.5rem]">
                    Motors that keep
                    <span class="bg-gradient-to-r from-blue-400 via-sky-300 to-amber-300 bg-clip-text
                                 text-transparent">{{ $shop['town'] }}</span>
                    running.
                </h1>

                <p data-reveal style="--rd:.16s"
                   class="mt-6 max-w-xl text-[1.0625rem] leading-relaxed text-slate-300">
                    Rewinding, pump repairs and genuine spares from a bench that has been on the same road for
                    three decades. Every motor is load tested before it goes back on your trolley — and the
                    readings go home with it.
                </p>

                <div data-reveal style="--rd:.24s" class="mt-9 flex flex-wrap items-center gap-3">
                    <a href="https://wa.me/{{ $shop['phone_dial'] }}" target="_blank" rel="noopener"
                       class="inline-flex h-12 items-center gap-2 rounded-[12px] bg-primary px-6 text-sm font-semibold
                              text-primary-foreground shadow-primary-glow transition hover:brightness-110">
                        Get a quote
                        <x-icon name="arrow-right" :size="17" />
                    </a>

                    <a href="#services"
                       class="inline-flex h-12 items-center gap-2 rounded-[12px] border border-white/15 px-6 text-sm
                              font-semibold text-white transition hover:bg-white/10">
                        What we do
                    </a>
                </div>

                <dl data-reveal style="--rd:.32s"
                    class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-4 border-t border-white/10 pt-7">
                    @foreach ([['shield', 'Six-month warranty'], ['clock', '48-hour turnaround'], ['truck', 'Site call-outs']] as [$icon, $label])
                        <div class="flex items-center gap-2.5 text-slate-300">
                            <span class="grid size-8 place-items-center rounded-lg bg-white/5 text-blue-300">
                                <x-icon name="{{ $icon }}" :size="16" />
                            </span>
                            <dt class="text-[0.8125rem] font-medium">{{ $label }}</dt>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- The motor, arriving in pieces ----------------------------- --}}
            <div class="relative mx-auto aspect-square w-full max-w-[30rem]" aria-hidden="true">

                {{-- Rings behind everything, so the parts read as orbiting one
                     thing rather than as scattered clip art. --}}
                <div class="absolute inset-[12%] rounded-full border border-dashed border-white/10"></div>
                <div class="absolute inset-[24%] rounded-full border border-white/[0.07]"></div>

                {{-- The assembled motor, dead centre. --}}
                <div class="lp-core absolute inset-[22%] grid place-items-center">
                    <svg viewBox="0 0 120 88" class="w-full drop-shadow-[0_16px_40px_rgba(37,99,235,0.35)]"
                         fill="none" stroke-linecap="round" stroke-linejoin="round">
                        {{-- Body --}}
                        <rect x="24" y="18" width="62" height="50" rx="9"
                              class="fill-slate-800 stroke-slate-500" stroke-width="1.6"/>
                        {{-- Cooling fins --}}
                        @foreach ([32, 40, 48, 56, 64, 72] as $x)
                            <line x1="{{ $x }}" y1="22" x2="{{ $x }}" y2="64" class="stroke-slate-600" stroke-width="1.4"/>
                        @endforeach
                        {{-- Terminal box --}}
                        <rect x="44" y="8" width="22" height="11" rx="3"
                              class="fill-slate-700 stroke-slate-500" stroke-width="1.4"/>
                        {{-- Feet --}}
                        <rect x="28" y="68" width="16" height="6" rx="2" class="fill-slate-700 stroke-slate-500" stroke-width="1.3"/>
                        <rect x="66" y="68" width="16" height="6" rx="2" class="fill-slate-700 stroke-slate-500" stroke-width="1.3"/>
                        {{-- Drive-end housing, and the shaft that turns in it --}}
                        <circle cx="92" cy="43" r="13" class="fill-slate-800 stroke-slate-500" stroke-width="1.6"/>
                        <g class="lp-rotor">
                            <circle cx="92" cy="43" r="8" class="stroke-blue-400" stroke-width="1.6"/>
                            <line x1="92" y1="35" x2="92" y2="51" class="stroke-blue-400" stroke-width="1.6"/>
                            <line x1="84" y1="43" x2="100" y2="43" class="stroke-blue-400" stroke-width="1.6"/>
                        </g>
                        <rect x="103" y="40" width="12" height="6" rx="3" class="fill-slate-600"/>
                        {{-- Nameplate --}}
                        <rect x="36" y="34" width="24" height="14" rx="2.5"
                              class="fill-blue-500/20 stroke-blue-400/70" stroke-width="1.2"/>
                        <line x1="40" y1="39" x2="56" y2="39" class="stroke-blue-300/70" stroke-width="1.4"/>
                        <line x1="40" y1="43.5" x2="51" y2="43.5" class="stroke-blue-300/50" stroke-width="1.4"/>
                    </svg>
                </div>

                {{-- Everything else, each from its own direction. --}}
                @foreach ($parts as $part)
                    @php [$fx, $fy, $fr] = array_map('trim', explode(',', $part['from'])); @endphp

                    <span class="lp-part {{ $part['at'] }}"
                          style="--lp-fx:{{ $fx }}; --lp-fy:{{ $fy }}; --lp-fr:{{ $fr }}; --lp-delay:{{ $part['delay'] }}">
                        <span class="lp-part-inner {{ $part['spin'] ? 'lp-'.$part['spin'][0] : '' }}"
                              @if ($part['spin']) style="--lp-spin:{{ $part['spin'][1] }}" @endif>
                            {!! $art[$part['art']] !!}
                        </span>
                    </span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- =================================================================
         The trade band, and the numbers
         ================================================================= --}}
    <section class="overflow-hidden border-y border-white/10 bg-slate-900 py-4">
        <div class="lp-marquee items-center gap-10 text-[0.8125rem] font-semibold uppercase tracking-[0.14em]
                    text-slate-400">
            {{-- Twice over: the keyframe translates by half its own width to loop. --}}
            @for ($pass = 0; $pass < 2; $pass++)
                @foreach ($trades as $trade)
                    <span class="flex shrink-0 items-center gap-10">
                        {{ $trade }}
                        <span class="size-1.5 shrink-0 rounded-full bg-amber-400/70"></span>
                    </span>
                @endforeach
            @endfor
        </div>
    </section>

    <section class="bg-slate-950 py-12 sm:py-14">
        <div class="mx-auto grid max-w-6xl grid-cols-2 gap-x-6 gap-y-9 px-4 sm:px-6 lg:grid-cols-4">
            @foreach ($stats as $i => $stat)
                <div data-reveal style="--rd:{{ $i * 0.08 }}s" class="text-center lg:text-left">
                    <p class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        <span data-count-to="{{ $stat['to'] }}">0</span>{{ $stat['suffix'] }}
                    </p>
                    <p class="mt-1.5 text-[0.8125rem] text-slate-400">{{ $stat['label'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- =================================================================
         Services
         ================================================================= --}}
    <section id="services" class="scroll-mt-24 bg-background py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">

            <div class="max-w-2xl">
                <p data-reveal class="section-label">What we do</p>
                <h2 data-reveal style="--rd:.06s"
                    class="mt-3 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                    Six things, done properly
                </h2>
                <p data-reveal style="--rd:.12s" class="mt-4 text-[1.0625rem] text-muted-foreground">
                    Everything on this list happens on our own bench in {{ $shop['town'] }}. Nothing is sent
                    away, so nothing comes back a week later than promised.
                </p>
            </div>

            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($services as $i => $service)
                    {{-- The cards arrive from alternating sides, as the hero's
                         parts do — the page assembles rather than scrolls. --}}
                    <article data-reveal
                             style="--rd:{{ ($i % 3) * 0.09 }}s; --rx:{{ $i % 2 ? '1.5rem' : '-1.5rem' }}; --ry:1rem"
                             class="surface group p-6 transition duration-300 hover:-translate-y-1
                                    hover:border-primary/30 hover:shadow-primary">
                        <span class="grid size-11 place-items-center rounded-[13px] transition-colors duration-300
                                     group-hover:text-white {{ $tones[$service['tone']] }}">
                            <x-icon name="{{ $service['icon'] }}" :size="21" />
                        </span>

                        <h3 class="mt-5 text-base font-semibold text-foreground">{{ $service['title'] }}</h3>
                        <p class="mt-2 text-[0.875rem] leading-relaxed text-muted-foreground">{{ $service['body'] }}</p>

                        <p class="mt-5 flex items-center gap-1.5 border-t border-border pt-4 text-[0.75rem]
                                  font-semibold uppercase tracking-wide text-primary">
                            {{ $service['meta'] }}
                            <x-icon name="arrow-up-right" :size="13"
                                    class="transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- =================================================================
         How it works
         ================================================================= --}}
    <section id="process" class="scroll-mt-24 border-y border-border bg-card py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">

            <div class="max-w-2xl">
                <p data-reveal class="section-label">How it works</p>
                <h2 data-reveal style="--rd:.06s"
                    class="mt-3 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                    From the trolley to the load test
                </h2>
            </div>

            <ol class="mt-12 grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($steps as $i => $step)
                    <li data-reveal style="--rd:{{ $i * 0.1 }}s; --ry:2rem" class="relative">
                        {{-- The rule joining one step to the next. Dropped on the
                             last, and where the steps stack rather than run. --}}
                        @unless ($loop->last)
                            <span class="pointer-events-none absolute left-11 right-[-1.5rem] top-5 hidden h-px
                                         bg-gradient-to-r from-border to-transparent lg:block"></span>
                        @endunless

                        <div class="flex items-center gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                                <x-icon name="{{ $step['icon'] }}" :size="18" />
                            </span>
                            <span class="text-[0.6875rem] font-bold tracking-[0.14em] text-muted-foreground">
                                {{ $step['n'] }}
                            </span>
                        </div>

                        <h3 class="mt-4 text-base font-semibold text-foreground">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-[0.875rem] leading-relaxed text-muted-foreground">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- =================================================================
         Why us
         ================================================================= --}}
    <section id="why" class="scroll-mt-24 bg-background py-20 sm:py-24">
        <div class="mx-auto grid max-w-6xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-2">

            <div>
                <p data-reveal class="section-label">Why this bench</p>
                <h2 data-reveal style="--rd:.06s"
                    class="mt-3 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                    A rewind is only as good as the testing behind it
                </h2>
                <p data-reveal style="--rd:.12s" class="mt-4 text-[1.0625rem] text-muted-foreground">
                    Any shop can put copper into a stator. The difference shows up in month four — which is why
                    every motor leaving here has been run on load, and why the readings are written down.
                </p>

                <div class="mt-10 grid gap-6 sm:grid-cols-2">
                    @foreach ($reasons as $i => $reason)
                        <div data-reveal style="--rd:{{ $i * 0.08 }}s; --rx:-1.25rem">
                            <span class="grid size-10 place-items-center rounded-[12px] bg-primary/10 text-primary">
                                <x-icon name="{{ $reason['icon'] }}" :size="19" />
                            </span>
                            <h3 class="mt-3.5 text-[0.9375rem] font-semibold text-foreground">{{ $reason['title'] }}</h3>
                            <p class="mt-1.5 text-[0.8125rem] leading-relaxed text-muted-foreground">{{ $reason['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- The test-bench card: the readings, as they are handed over. --}}
            <div data-reveal style="--rx:2rem; --ry:1rem" class="relative">
                <div class="pointer-events-none absolute -inset-6 rounded-[28px] bg-primary/[0.07] blur-2xl"></div>

                <div class="surface relative overflow-hidden p-7">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="section-label">Load test report</p>
                            <p class="mt-1 text-lg font-bold tracking-tight text-foreground">7.5 HP · 3 ph · 1440 RPM</p>
                        </div>
                        <span class="badge bg-emerald-50 text-emerald-700">
                            <x-icon name="check-circle" :size="13" />
                            Passed
                        </span>
                    </div>

                    <dl class="mt-6 space-y-3">
                        @foreach ([
                            ['Insulation resistance', '> 200 MΩ'],
                            ['Current · R / Y / B', '10.4 / 10.6 / 10.5 A'],
                            ['No-load run', '30 min, no rise'],
                            ['Bearings', 'Both replaced · 6205 ZZ'],
                            ['Rotor balance', 'Corrected'],
                        ] as [$label, $value])
                            <div class="flex items-center justify-between gap-4 rounded-[10px] bg-background px-3.5 py-3">
                                <dt class="text-[0.8125rem] text-muted-foreground">{{ $label }}</dt>
                                <dd class="text-[0.8125rem] font-semibold text-foreground">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="mt-6 flex items-start gap-2 border-t border-border pt-5 text-[0.75rem] text-muted-foreground">
                        <x-icon name="info" :size="14" class="mt-px shrink-0 text-primary" />
                        A copy goes home with the motor and a copy stays with us, so a warranty claim never turns
                        into an argument about what was measured.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- =================================================================
         Reviews
         ================================================================= --}}
    <section id="reviews" class="scroll-mt-24 border-y border-border bg-card py-20 sm:py-24">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">

            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-xl">
                    <p data-reveal class="section-label">Reviews</p>
                    <h2 data-reveal style="--rd:.06s"
                        class="mt-3 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                        What the district says
                    </h2>
                </div>

                <a data-reveal style="--rd:.12s" href="{{ $shop['map'] }}" target="_blank" rel="noopener"
                   class="btn btn-secondary">
                    <x-icon name="map-pin" :size="16" />
                    Read them on Google
                </a>
            </div>

            <div class="mt-12 grid gap-5 lg:grid-cols-3">
                @foreach ($testimonials as $i => $testimonial)
                    <figure data-reveal
                            style="--rd:{{ $i * 0.1 }}s; --ry:{{ $i === 1 ? '2.75rem' : '1.5rem' }}"
                            class="surface flex flex-col p-6 transition duration-300 hover:-translate-y-1 hover:shadow-raised">

                        <x-icon name="quote" :size="24" class="text-primary/25" />

                        <blockquote class="mt-4 flex-1 text-[0.9375rem] leading-relaxed text-secondary-foreground">
                            {{ $testimonial['quote'] }}
                        </blockquote>

                        <div class="mt-6 flex items-center gap-1 text-amber-400"
                             aria-label="{{ $testimonial['stars'] }} out of 5">
                            @for ($star = 0; $star < $testimonial['stars']; $star++)
                                <x-icon name="star" :size="15" class="fill-current" />
                            @endfor
                        </div>

                        <figcaption class="mt-4 flex items-center gap-3 border-t border-border pt-4">
                            <span class="grid size-9 place-items-center rounded-full bg-primary/10 text-[0.8125rem]
                                         font-bold text-primary">
                                {{ mb_substr($testimonial['name'], 0, 1) }}
                            </span>
                            <span>
                                <span class="block text-[0.875rem] font-semibold text-foreground">{{ $testimonial['name'] }}</span>
                                <span class="block text-[0.75rem] text-muted-foreground">{{ $testimonial['role'] }}</span>
                            </span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>

    {{-- =================================================================
         Visit
         ================================================================= --}}
    <section id="contact" class="lp-grid relative scroll-mt-24 overflow-hidden bg-slate-950 py-20 sm:py-24">
        <div class="pointer-events-none absolute -right-24 bottom-0 size-[28rem] rounded-full bg-blue-600/15 blur-[120px]"></div>

        <div class="relative mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16">

            <div>
                <p data-reveal class="section-label">Visit the shop</p>
                <h2 data-reveal style="--rd:.06s"
                    class="mt-3 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                    Bring it in, or we will come to it
                </h2>
                <p data-reveal style="--rd:.12s" class="mt-4 max-w-lg text-[1.0625rem] text-slate-300">
                    Walk in with the motor, send a photo of the nameplate on WhatsApp, or call and we will come
                    out. Breakdowns are looked at the same day.
                </p>

                <div class="mt-10 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['map-pin', 'Address', $shop['street'].'<br>'.$shop['city'], $shop['map'], true],
                        ['phone', 'Phone', $shop['phone'], 'tel:+'.$shop['phone_dial'], false],
                        ['mail', 'Email', $shop['email'], 'mailto:'.$shop['email'], false],
                        ['clock', 'Hours', $shop['hours'].'<br>'.$shop['closed'], null, false],
                    ] as $i => [$icon, $label, $value, $href, $external])
                        {{-- A link where there is somewhere to go, a plain block
                             where there is not — the opening hours are not a
                             destination and must not look like one. --}}
                        @php $tag = $href ? 'a' : 'div'; @endphp

                        <{{ $tag }} data-reveal style="--rd:{{ $i * 0.07 }}s; --ry:1.25rem"
                            @if ($href) href="{{ $href }}" @endif
                            @if ($external) target="_blank" rel="noopener" @endif
                            class="rounded-[14px] border border-white/10 bg-white/5 p-5 transition
                                   {{ $href ? 'hover:border-white/25 hover:bg-white/10' : '' }}">
                            <span class="grid size-9 place-items-center rounded-[11px] bg-white/10 text-blue-300">
                                <x-icon name="{{ $icon }}" :size="17" />
                            </span>
                            <p class="mt-3.5 text-[0.6875rem] font-semibold uppercase tracking-[0.08em] text-slate-400">
                                {{ $label }}
                            </p>
                            <p class="mt-1 text-[0.875rem] font-medium leading-relaxed text-white">{!! $value !!}</p>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>

            {{-- The one thing this page wants a visitor to do. --}}
            <div data-reveal style="--rx:2rem" class="lg:pt-16">
                <div class="rounded-[20px] border border-white/10 bg-gradient-to-b from-white/[0.09] to-white/[0.03]
                            p-8 backdrop-blur">
                    <h3 class="text-xl font-bold tracking-tight text-white">Send us the nameplate</h3>
                    <p class="mt-2.5 text-[0.9375rem] leading-relaxed text-slate-300">
                        A photo of the plate — HP, phase and RPM — is enough for us to quote a rewind or price a
                        replacement before you load anything onto a trolley.
                    </p>

                    <div class="mt-7 grid gap-3">
                        <a href="https://wa.me/{{ $shop['phone_dial'] }}" target="_blank" rel="noopener"
                           class="flex h-12 items-center justify-center gap-2 rounded-[12px] bg-primary text-sm
                                  font-semibold text-primary-foreground shadow-primary-glow transition hover:brightness-110">
                            <x-icon name="phone" :size="17" />
                            Message on WhatsApp
                        </a>

                        <a href="tel:+{{ $shop['phone_dial'] }}"
                           class="flex h-12 items-center justify-center gap-2 rounded-[12px] border border-white/15
                                  text-sm font-semibold text-white transition hover:bg-white/10">
                            Call {{ $shop['phone'] }}
                        </a>
                    </div>

                    <p class="mt-6 flex items-start gap-2 border-t border-white/10 pt-5 text-[0.75rem] text-slate-400">
                        <x-icon name="check-circle" :size="14" class="mt-px shrink-0 text-emerald-400" />
                        Quotes are free, and nothing is opened up until you have agreed the figure.
                    </p>
                </div>
            </div>
        </div>
    </section>
</main>

{{-- =====================================================================
     Footer
     ===================================================================== --}}
<footer class="border-t border-white/10 bg-slate-950 py-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 sm:px-6 md:flex-row md:items-center md:justify-between">

        <div class="flex items-center gap-2.5">
            <span class="grid size-9 place-items-center rounded-[11px] bg-primary text-primary-foreground">
                <x-icon name="zap" :size="19" />
            </span>
            <span class="leading-tight">
                <span class="block text-[0.9375rem] font-bold tracking-tight text-white">{{ $shop['name'] }}</span>
                <span class="block text-[0.6875rem] text-slate-400">{{ $shop['city'] }}</span>
            </span>
        </div>

        <p class="text-[0.8125rem] text-slate-400">
            &copy; {{ date('Y') }} {{ $shop['name'] }}. All rights reserved.
        </p>

        {{-- The second way in, for anyone who scrolled past the header. --}}
        <button type="button" data-login-open
                class="inline-flex items-center gap-2 self-start rounded-[10px] border border-white/15 px-4 py-2
                       text-[0.8125rem] font-semibold text-white transition hover:bg-white/10 md:self-auto">
            <x-icon name="lock" :size="15" />
            Staff login
        </button>
    </div>
</footer>

{{-- =====================================================================
     Sign in
     ---------------------------------------------------------------------
     The form the standalone /login page used to carry, moved into a modal on
     the shopfront. The ids and data- hooks are exactly what initLogin() in
     resources/js/app.js binds to, so the credential flow behind it — field
     errors, the 422 envelope, the busy state and the redirect to /dashboard —
     is the one that was already there and is unchanged.
     ===================================================================== --}}
<div id="login-modal" data-modal class="modal-backdrop hidden">
    <div class="modal-panel lp-modal-in max-w-[420px] p-6 sm:p-7">

        <div class="flex items-start justify-between gap-4">
            <div>
                <span class="grid size-11 place-items-center rounded-[13px] bg-primary text-primary-foreground
                             shadow-primary-glow">
                    <x-icon name="zap" :size="22" />
                </span>
                <h2 class="mt-4 text-xl font-bold tracking-tight text-foreground">Welcome back</h2>
                <p class="mt-1 text-[0.875rem] text-muted-foreground">
                    Sign in to your AI Accounting Back Office
                </p>
            </div>

            <button type="button" data-modal-close aria-label="Close"
                    class="btn btn-ghost btn-icon -mr-1 -mt-1">
                <x-icon name="x" :size="18" />
            </button>
        </div>

        <form id="login-form" class="mt-6" novalidate>

            {{-- Populated from the API's error envelope: error.error.message --}}
            <div id="login-error"
                 class="mb-5 hidden items-start gap-2.5 rounded-[10px] border border-rose-200 bg-rose-50
                        px-3.5 py-3 text-[0.8125rem] text-rose-700"
                 role="alert" aria-live="polite">
                <x-icon name="alert-triangle" :size="16" class="mt-px shrink-0" />
                <span data-error-message></span>
            </div>

            {{-- Email --}}
            <div class="mb-4">
                <label for="email" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                    Email address
                </label>

                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <x-icon name="mail" :size="17" />
                    </span>

                    <input id="email" name="email" type="email" autocomplete="email" required
                           placeholder="you@company.com"
                           class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-3.5 text-sm
                                  text-foreground placeholder:text-muted-foreground focus:border-primary
                                  focus:outline-none focus:ring-2 focus:ring-ring/60">
                </div>

                <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="email"></p>
            </div>

            {{-- Password --}}
            <div class="mb-5">
                <div class="mb-1.5 flex items-baseline justify-between">
                    <label for="password" class="text-[0.8125rem] font-semibold text-secondary-foreground">
                        Password
                    </label>
                    <a href="#" class="text-[0.8125rem] font-medium text-primary hover:underline">
                        Forgot password?
                    </a>
                </div>

                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <x-icon name="lock" :size="17" />
                    </span>

                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           placeholder="••••••••••••"
                           class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-11 text-sm
                                  text-foreground placeholder:text-muted-foreground focus:border-primary
                                  focus:outline-none focus:ring-2 focus:ring-ring/60">

                    <button type="button" data-toggle-password aria-label="Show password"
                            class="absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center
                                   rounded-md text-muted-foreground transition hover:bg-muted
                                   hover:text-secondary-foreground">
                        <x-icon name="eye" :size="17" data-icon-show />
                        <x-icon name="eye-off" :size="17" class="hidden" data-icon-hide />
                    </button>
                </div>

                <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="password"></p>
            </div>

            <button type="submit" data-submit
                    class="flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-primary
                           text-sm font-semibold text-primary-foreground shadow-primary-glow transition
                           hover:brightness-110 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60">
                <x-icon name="loader" :size="16" class="hidden animate-spin" data-spinner />
                <span data-submit-label>Sign in</span>
            </button>
        </form>

        @if (config('tenancy.allow_public_signup', true))
            <p class="mt-5 text-center text-[0.8125rem] text-muted-foreground">
                New here?
                <a href="{{ route('register') }}" class="font-medium text-primary hover:underline">
                    Create your workshop
                </a>
            </p>
        @endif

        <p class="mt-2.5 text-center text-[0.75rem] text-muted-foreground">
            Protected by rate limiting and account lockout.
        </p>
    </div>
</div>

</body>
</html>
