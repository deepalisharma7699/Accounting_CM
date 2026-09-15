@extends('site.layout')

{{--
    The home page.
    ======================================================================

    One long page, in bands that alternate ink and paper: the shop speaks on
    the dark ones and explains on the light ones, so somebody scrolling fast
    can tell which is which without reading a word.

    Section order is the order the questions arrive in. What is it → what do
    you do → how do I tell you what I have → what will you do to it → what do I
    get back → what can I buy → the numbers I came to look up → do you come to
    me → the things I was going to ask → where are you. Nothing here is
    arranged for the shop's convenience; "about us" is not a section because
    nobody has ever searched for it.
--}}

@section('content')
@php
    use App\Support\Site;

    /* Tone → the classes a service card's icon carries. One row per tone, so a
       new accent is a row in config/shop.php plus a row here. */
    $tones = [
        'copper' => 'bg-copper-50 text-copper-600 group-hover:bg-copper-500',
        'blue' => 'bg-blue-50 text-blue-600 group-hover:bg-blue-600',
        'amber' => 'bg-amber-50 text-amber-600 group-hover:bg-amber-500',
        'sky' => 'bg-sky-50 text-sky-600 group-hover:bg-sky-600',
        'slate' => 'bg-ink-100 text-ink-700 group-hover:bg-ink-800',
        'emerald' => 'bg-emerald-50 text-emerald-600 group-hover:bg-emerald-600',
    ];
@endphp


{{-- =====================================================================
     Hero
     ===================================================================== --}}
<section class="s-grid relative overflow-hidden bg-ink-950 pb-16 pt-28 sm:pt-32 lg:pb-24 lg:pt-40">
    <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-[1.02fr_1fr] lg:gap-10">

        <div>
            <span data-reveal style="--ry: 1rem"
                  class="inline-flex items-center gap-2.5 rounded-full border border-copper-500/25 bg-copper-500/10
                         px-3.5 py-1.5 text-[0.75rem] font-medium text-copper-200">
                <span class="relative flex size-1.5">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-copper-400 opacity-75"></span>
                    <span class="relative inline-flex size-1.5 rounded-full bg-copper-400"></span>
                </span>
                {{ Site::text('hero.eyebrow') }}
            </span>

            <h1 data-reveal style="--rd: .08s"
                class="mt-6 text-[2.5rem] font-bold leading-[1.06] tracking-tight text-white sm:text-5xl lg:text-[3.4rem]">
                {{ Site::text('hero.title_lead') }}
                <span class="block bg-gradient-to-r from-copper-300 via-copper-400 to-amber-300 bg-clip-text
                             text-transparent">
                    {{ Site::text('hero.title_accent') }}
                </span>
            </h1>

            <p data-reveal style="--rd: .16s"
               class="mt-6 max-w-xl text-[1.0625rem] leading-relaxed text-ink-300">
                {{ Site::text('hero.body') }}
            </p>

            <div data-reveal style="--rd: .24s" class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener" data-site-hero-cta
                   class="inline-flex h-12 items-center gap-2 rounded-[12px] bg-copper-500 px-6 text-sm
                          font-semibold text-ink-950 transition hover:bg-copper-400">
                    <x-icon name="camera" :size="17" />
                    {{ Site::text('actions.whatsapp') }}
                </a>

                <a href="#services"
                   class="inline-flex h-12 items-center gap-2 rounded-[12px] border border-white/15 px-6 text-sm
                          font-semibold text-white transition hover:bg-white/10">
                    {{ Site::text('actions.services') }}
                    <x-icon name="arrow-right" :size="16" />
                </a>
            </div>

            <p data-reveal style="--rd: .3s" class="mt-5 max-w-md text-[0.8125rem] leading-relaxed text-ink-500">
                {{ Site::text('hero.hint') }}
            </p>

            {{-- The confirmed figures, if there are any. An unverified fact is
                 dropped along with its tile rather than shown as a dash — see
                 App\Support\Site::stats(). --}}
            @if ($stats)
                <dl data-reveal style="--rd: .38s"
                    class="mt-9 flex flex-wrap gap-x-10 gap-y-5 border-t border-white/10 pt-7">
                    @foreach ($stats as $stat)
                        <div>
                            <dt class="text-2xl font-bold tracking-tight text-white">{{ $stat['value'] }}</dt>
                            <dd class="mt-0.5 text-[0.75rem] text-ink-500">{{ $stat['label'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>

        <div data-reveal style="--rd: .2s; --rx: 1.5rem">
            @include('site.partials.motor-cutaway')
            <p class="mt-5 text-center text-[0.75rem] text-ink-500 sm:text-[0.8125rem]">
                {{ Site::text('hero.diagram_caption') }}
            </p>
        </div>
    </div>
</section>


{{-- =====================================================================
     The trade band
     ===================================================================== --}}
<section class="overflow-hidden border-y border-white/10 bg-ink-900 py-3.5" aria-hidden="true">
    <div class="s-marquee items-center gap-8 font-mono text-[0.75rem] font-medium uppercase tracking-[0.16em]
                text-ink-500">
        {{-- Rendered twice: the keyframe translates by half its own width. --}}
        @for ($pass = 0; $pass < 2; $pass++)
            @foreach ($services as $service)
                <span class="flex shrink-0 items-center gap-8">
                    {{ $service['title'] }}
                    <span class="size-1 shrink-0 rounded-full bg-copper-500"></span>
                </span>
            @endforeach
        @endfor
    </div>
</section>


{{-- =====================================================================
     What the shop commits to
     ===================================================================== --}}
<section class="bg-ink-950 py-14">
    <div class="mx-auto grid max-w-6xl gap-x-8 gap-y-9 px-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        @foreach (Site::items('trust') as $i => $promise)
            <div data-reveal style="--rd: {{ $i * 0.07 }}s" class="border-t border-copper-500/40 pt-5">
                <h2 class="text-[0.9375rem] font-semibold text-white">{{ $promise['title'] }}</h2>
                <p class="mt-2 text-[0.8125rem] leading-relaxed text-ink-400">{{ $promise['body'] }}</p>
            </div>
        @endforeach
    </div>
</section>


{{-- =====================================================================
     What we do
     ===================================================================== --}}
<section id="services" class="scroll-mt-24 bg-paper py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <div class="max-w-2xl">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('services.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('services.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 text-[1.0625rem] leading-relaxed text-ink-600">
                {{ Site::text('services.intro') }}
            </p>
        </div>

        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($services as $i => $service)
                @php
                    // Three of the six lead somewhere. A card that links is an
                    // <a>; a card that does not must not look like one, so the
                    // tag itself changes rather than the cursor.
                    $tag = $service['url'] ? 'a' : 'div';
                @endphp

                <{{ $tag }} data-reveal
                    style="--rd: {{ ($i % 3) * 0.08 }}s; --ry: 1.25rem"
                    @if ($service['url']) href="{{ $service['url'] }}" @endif
                    class="group flex flex-col rounded-[14px] border border-ink-100 bg-white p-6 transition
                           duration-300 hover:-translate-y-1 hover:border-copper-300 hover:shadow-lg
                           hover:shadow-copper-500/10">

                    <span class="grid size-11 place-items-center rounded-[13px] transition-colors duration-300
                                 group-hover:text-white {{ $tones[$service['tone']] }}">
                        <x-icon name="{{ $service['icon'] }}" :size="21" />
                    </span>

                    <h3 class="mt-5 text-[1.0625rem] font-semibold text-ink-950">{{ $service['title'] }}</h3>
                    <p class="mt-2 flex-1 text-[0.875rem] leading-relaxed text-ink-600">{{ $service['summary'] }}</p>

                    <p class="mt-5 flex items-center justify-between gap-3 border-t border-ink-100 pt-4">
                        <span class="font-mono text-[0.6875rem] font-medium uppercase tracking-wide text-ink-500">
                            {{ $service['tag'] }}
                        </span>

                        @if ($service['url'])
                            <span class="inline-flex items-center gap-1 text-[0.75rem] font-semibold text-copper-600">
                                {{ Site::text('actions.read_more') }}
                                <x-icon name="arrow-up-right" :size="13"
                                        class="transition-transform duration-300 group-hover:-translate-y-0.5
                                               group-hover:translate-x-0.5" />
                            </span>
                        @endif
                    </p>
                </{{ $tag }}>
            @endforeach
        </div>
    </div>
</section>


{{-- =====================================================================
     The nameplate guide
     ===================================================================== --}}
<section id="nameplate" class="s-grid-light relative scroll-mt-24 overflow-hidden border-y border-ink-100
                               bg-paper-2 py-20 sm:py-24">
    <div class="relative mx-auto max-w-6xl px-4 sm:px-6">

        <div class="max-w-2xl">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('nameplate.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('nameplate.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 text-[1.0625rem] leading-relaxed text-ink-600">
                {{ Site::text('nameplate.intro') }}
            </p>
        </div>

        <div class="mt-12">
            @include('site.partials.nameplate')
        </div>
    </div>
</section>


{{-- =====================================================================
     How it works
     ===================================================================== --}}
<section id="process" class="scroll-mt-24 bg-paper py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <div class="max-w-2xl">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('process.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('process.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 text-[1.0625rem] text-ink-600">
                {{ Site::text('process.intro') }}
            </p>
        </div>

        <ol class="mt-12 grid gap-x-6 gap-y-9 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (Site::items('process.steps') as $i => $step)
                <li data-reveal style="--rd: {{ $i * 0.08 }}s; --ry: 1.5rem" class="relative">
                    {{-- The rule joining one step to the next. Dropped on the
                         last, and wherever the steps stack rather than run. --}}
                    @unless ($loop->last)
                        <span class="pointer-events-none absolute left-10 right-[-1.5rem] top-4 hidden h-px
                                     bg-gradient-to-r from-copper-300 to-transparent lg:block"></span>
                    @endunless

                    <span class="inline-flex items-center gap-2.5">
                        <span class="grid size-8 place-items-center rounded-full bg-ink-950 font-mono text-[0.6875rem]
                                     font-bold text-copper-300">
                            {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                        </span>
                    </span>

                    <h3 class="mt-4 text-[0.9375rem] font-semibold text-ink-950">{{ $step['title'] }}</h3>
                    <p class="mt-2 text-[0.8125rem] leading-relaxed text-ink-600">{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>


{{-- =====================================================================
     What you get back
     ===================================================================== --}}
<section class="s-grid relative overflow-hidden bg-ink-950 py-20 sm:py-24">
    <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16">

        <div>
            <p data-reveal class="s-eyebrow text-copper-400">{{ Site::text('report.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                {{ Site::text('report.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-5 text-[1.0625rem] leading-relaxed text-ink-300">
                {{ Site::text('report.body') }}
            </p>

            <p data-reveal style="--rd: .18s"
               class="mt-7 flex items-start gap-3 rounded-[12px] border border-white/10 bg-white/5 p-4
                      text-[0.875rem] leading-relaxed text-ink-300">
                <x-icon name="info" :size="16" class="mt-0.5 shrink-0 text-copper-400" />
                {{ Site::text('report.footnote') }}
            </p>
        </div>

        {{-- The sheet itself. Labelled a worked example in as many words: the
             figures are what a healthy 7.5 HP set reads, which teaches somebody
             what to look for, but they are not a claim about a real job. --}}
        <div data-reveal style="--rx: 1.5rem">
            <div class="rounded-[16px] border border-white/10 bg-ink-900 p-6 sm:p-7">

                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-white/10 pb-5">
                    <div>
                        <p class="font-mono text-[0.625rem] font-semibold uppercase tracking-[0.14em] text-copper-400">
                            {{ Site::text('report.example_label') }}
                        </p>
                        <p class="mt-1.5 font-mono text-[0.9375rem] font-bold text-white">
                            {{ Site::text('report.subject') }}
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1
                                 text-[0.6875rem] font-semibold text-emerald-300">
                        <x-icon name="check-circle" :size="13" />
                        {{ Site::text('report.passed') }}
                    </span>
                </div>

                <dl class="mt-5 space-y-2">
                    @foreach (Site::items('report.rows') as $row)
                        <div class="flex items-baseline justify-between gap-4 rounded-[9px] bg-white/[0.04] px-3.5 py-2.5">
                            <dt class="text-[0.8125rem] text-ink-400">{{ $row['label'] }}</dt>
                            <dd class="text-right font-mono text-[0.8125rem] font-semibold text-white">
                                {{ $row['value'] }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-5 border-t border-white/10 pt-4 text-[0.75rem] leading-relaxed text-ink-500">
                    {{ Site::text('report.example_note') }}
                </p>
            </div>
        </div>
    </div>
</section>


{{-- =====================================================================
     On the counter
     ===================================================================== --}}
<section id="counter" class="scroll-mt-24 bg-paper py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <div class="max-w-2xl">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('counter.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('counter.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 text-[1.0625rem] text-ink-600">
                {{ Site::text('counter.intro') }}
            </p>
        </div>

        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Keyed by group, not indexed: the stagger comes from the loop's
                 own counter rather than from the array key. --}}
            @foreach (Site::items('counter.groups') as $group)
                <div data-reveal style="--rd: {{ $loop->index * 0.07 }}s; --ry: 1.25rem"
                     class="rounded-[14px] border border-ink-100 bg-white p-5">
                    <h3 class="text-[0.9375rem] font-semibold text-ink-950">{{ $group['title'] }}</h3>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($group['items'] as $item)
                            <li class="flex items-start gap-2.5 text-[0.8125rem] leading-relaxed text-ink-600">
                                <span class="mt-1.5 size-1 shrink-0 rounded-full bg-copper-500"></span>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{--
            The makes.

            Named only once somebody has confirmed them: a brand on a shopfront
            reads as a dealership, which is a different claim and a legal one.
            Until then the block says what it can honestly say — ask, because
            stock moves — which is also more useful than a stale logo wall.
        --}}
        <div data-reveal class="mt-6 rounded-[14px] border border-ink-100 bg-white p-6">
            <h3 class="text-[0.9375rem] font-semibold text-ink-950">{{ Site::text('counter.brands_title') }}</h3>

            @if (Site::shop('counter.verified'))
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach (array_merge(Site::shop('counter.motors'), Site::shop('counter.pumps'), Site::shop('counter.bearings')) as $make)
                        <span class="rounded-lg border border-ink-100 bg-paper px-3 py-1.5 text-[0.8125rem]
                                     font-medium text-ink-700">{{ $make }}</span>
                    @endforeach
                </div>
            @else
                <p class="mt-2.5 max-w-3xl text-[0.875rem] leading-relaxed text-ink-600">
                    {{ Site::text('counter.brands_pending') }}
                </p>
            @endif
        </div>
    </div>
</section>


{{-- =====================================================================
     Reference
     ===================================================================== --}}
<section id="reference" class="scroll-mt-24 border-y border-ink-100 bg-paper-2 py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <div class="max-w-2xl">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('reference.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('reference.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 text-[1.0625rem] text-ink-600">
                {{ Site::text('reference.intro') }}
            </p>
        </div>

        {{--
            Three tables, one at a time.

            Tabs rather than three stacked tables: together they are about sixty
            rows, and a visitor who came to convert one SWG number should not
            have to scroll past the other two. Every panel is in the DOM and
            only `hidden` toggles, so all three are in the page for a crawler
            and for anybody without JavaScript — the script hides the two that
            are not selected on boot, not the markup.
        --}}
        <div class="mt-10" data-ref-tabs>
            <div class="flex flex-wrap gap-2" role="tablist">
                @foreach (['rating', 'swg', 'bearings'] as $i => $panel)
                    <button type="button" role="tab" data-ref-tab="{{ $panel }}"
                            aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                            aria-controls="ref-{{ $panel }}"
                            class="rounded-[10px] border px-4 py-2 text-[0.8125rem] font-semibold transition
                                   {{ $i === 0
                                       ? 'border-ink-950 bg-ink-950 text-white'
                                       : 'border-ink-200 bg-white text-ink-600 hover:border-ink-400' }}">
                        {{ Site::text('reference.'.$panel.'.title') }}
                    </button>
                @endforeach
            </div>

            @php
                /* Panel key → the rows and the cells to draw from each row.
                   One structure for three tables, so a fourth is a row here. */
                $panels = [
                    'rating' => ['rows' => $ratings, 'cells' => ['hp', 'kw', 'amps']],
                    'swg' => ['rows' => $gauges, 'cells' => ['swg', 'mm']],
                    'bearings' => ['rows' => $bearings, 'cells' => ['ref', 'bore', 'outer', 'width']],
                ];
            @endphp

            @foreach ($panels as $key => $panel)
                <div id="ref-{{ $key }}" role="tabpanel" data-ref-panel="{{ $key }}" class="mt-5">
                    <div class="overflow-hidden rounded-[14px] border border-ink-200 bg-white">
                        {{-- The scroll lives on this wrapper, never on the page:
                             these are the widest things on the site and they are
                             read on a phone at a bench. --}}
                        <div class="max-h-[26rem] overflow-auto">
                            <table class="s-table">
                                <thead>
                                    <tr>
                                        @foreach (Site::items('reference.'.$key.'.columns') as $column)
                                            <th scope="col">{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($panel['rows'] as $row)
                                        <tr>
                                            @foreach ($panel['cells'] as $cell)
                                                <td>{{ $row[$cell] }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <p class="mt-3 flex items-start gap-2 text-[0.75rem] leading-relaxed text-ink-500">
                        <x-icon name="info" :size="13" class="mt-0.5 shrink-0" />
                        {{ Site::text('reference.'.$key.'.note') }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</section>


{{-- =====================================================================
     Where we come out
     ===================================================================== --}}
<section id="area" class="scroll-mt-24 bg-paper py-20 sm:py-24">
    <div class="mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-[1fr_1.1fr] lg:gap-16">

        <div>
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('area.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('area.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 text-[1.0625rem] leading-relaxed text-ink-600">
                {{ Site::text('area.body') }}
            </p>

            <a data-reveal style="--rd: .18s" href="{{ Site::telUrl() }}"
               class="mt-7 inline-flex h-11 items-center gap-2 rounded-[11px] bg-ink-950 px-5 text-[0.875rem]
                      font-semibold text-white transition hover:bg-ink-800">
                <x-icon name="phone" :size="16" />
                {{ Site::text('actions.call') }}
            </a>
        </div>

        <div data-reveal style="--rx: 1.5rem" class="rounded-[16px] border border-ink-100 bg-white p-6 sm:p-7">
            <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-ink-500">
                {{ Site::text('area.towns_title') }}
            </p>

            <ul class="mt-4 flex flex-wrap gap-2">
                @foreach (Site::shop('area', []) as $place)
                    <li class="rounded-lg border border-ink-100 bg-paper px-3 py-1.5 text-[0.8125rem]
                               font-medium text-ink-700">
                        {{ $place }}
                    </li>
                @endforeach
            </ul>

            <p class="mt-5 border-t border-ink-100 pt-4 text-[0.8125rem] text-ink-500">
                {{ Site::text('area.towns_note') }}
            </p>
        </div>
    </div>
</section>


{{-- =====================================================================
     Questions
     ===================================================================== --}}
<section id="faq" class="scroll-mt-24 border-y border-ink-100 bg-paper-2 py-20 sm:py-24">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">

        <div>
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('faq.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-ink-950 sm:text-4xl">
                {{ Site::text('faq.title') }}
            </h2>
        </div>

        {{--
            <details>, not a script-driven accordion.

            Every answer is readable with JavaScript off and present in the
            markup for a crawler — which matters here, because these answers are
            also the FAQPage structured data and a search result may show one on
            its own. The script only adds "one open at a time" on top of
            something that already works.
        --}}
        <div class="mt-10 space-y-3" data-faq>
            @foreach (Site::items('faq.items') as $i => $item)
                <details data-reveal style="--rd: {{ $i * 0.04 }}s; --ry: 1rem"
                         class="s-faq group rounded-[14px] border border-ink-200 bg-white px-5
                                open:border-copper-300 open:shadow-sm">
                    <summary class="flex items-center justify-between gap-4 py-4">
                        <h3 class="text-[0.9375rem] font-semibold text-ink-950">{{ $item['q'] }}</h3>
                        <span class="s-faq-chevron grid size-7 shrink-0 place-items-center rounded-full
                                     bg-ink-100 text-ink-600">
                            <x-icon name="chevron-down" :size="15" />
                        </span>
                    </summary>
                    <p class="border-t border-ink-100 py-4 text-[0.875rem] leading-relaxed text-ink-600">
                        {{ $item['a'] }}
                    </p>
                </details>
            @endforeach
        </div>
    </div>
</section>


{{-- =====================================================================
     Visit
     ===================================================================== --}}
<section id="contact" class="s-grid relative scroll-mt-24 overflow-hidden bg-ink-950 py-20 sm:py-24">
    <div class="relative mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16">

        <div>
            <p data-reveal class="s-eyebrow text-copper-400">{{ Site::text('contact.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                {{ Site::text('contact.title') }}
            </h2>
            <p data-reveal style="--rd: .12s" class="mt-4 max-w-lg text-[1.0625rem] leading-relaxed text-ink-300">
                {{ Site::text('contact.body') }}
            </p>

            @php
                /*
                | The contact rows. A row with somewhere to go is a link and a
                | row without is a block: opening hours are not a destination
                | and must not be styled as one.
                |
                | E-mail is absent rather than empty while the address on file
                | is somebody's personal inbox — Site::email() is the gate.
                */
                $contact = [
                    ['icon' => 'map-pin', 'label' => Site::text('contact.labels.address'),
                     'value' => Site::shop('street').'<br>'.Site::shop('city').', '.Site::shop('state').' '.Site::shop('postcode'),
                     'href' => Site::shop('map_url'), 'external' => true],
                    ['icon' => 'phone', 'label' => Site::text('contact.labels.phone'),
                     'value' => Site::shop('phone'), 'href' => Site::telUrl(), 'external' => false],
                    ['icon' => 'clock', 'label' => Site::text('contact.labels.hours'),
                     'value' => Site::hoursLabel().'<br>'.Site::text('contact.hours_note'),
                     'href' => null, 'external' => false],
                ];

                if ($email = Site::email()) {
                    $contact[] = ['icon' => 'mail', 'label' => Site::text('contact.labels.email'),
                                  'value' => $email, 'href' => 'mailto:'.$email, 'external' => false];
                }
            @endphp

            {{-- `items-start` so a card is the height of what is in it. The
                 address runs to four lines and the phone to one; stretching
                 the short one to match leaves it looking like a card that
                 failed to load. --}}
            <div class="mt-9 grid items-start gap-4 sm:grid-cols-2">
                @foreach ($contact as $i => $row)
                    @php $tag = $row['href'] ? 'a' : 'div'; @endphp

                    <{{ $tag }} data-reveal style="--rd: {{ $i * 0.06 }}s; --ry: 1rem"
                        @if ($row['href']) href="{{ $row['href'] }}" @endif
                        @if ($row['external']) target="_blank" rel="noopener" @endif
                        class="rounded-[14px] border border-white/10 bg-white/5 p-5 transition
                               {{ $row['href'] ? 'hover:border-copper-500/40 hover:bg-white/10' : '' }}">
                        <span class="grid size-9 place-items-center rounded-[11px] bg-copper-500/15 text-copper-300">
                            <x-icon name="{{ $row['icon'] }}" :size="17" />
                        </span>
                        <p class="mt-3.5 text-[0.6875rem] font-semibold uppercase tracking-[0.08em] text-ink-500">
                            {{ $row['label'] }}
                        </p>
                        <p class="mt-1 text-[0.875rem] font-medium leading-relaxed text-white">{!! $row['value'] !!}</p>
                    </{{ $tag }}>
                @endforeach
            </div>
        </div>

        {{-- The one thing this page wants a visitor to do. --}}
        <div data-reveal style="--rx: 1.5rem" class="lg:pt-14">
            <div class="rounded-[20px] border border-white/10 bg-gradient-to-b from-white/[0.09] to-white/[0.03]
                        p-7 backdrop-blur sm:p-8">
                <h3 class="text-xl font-bold tracking-tight text-white">{{ Site::text('contact.cta_title') }}</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-ink-300">
                    {{ Site::text('contact.cta_body') }}
                </p>

                <div class="mt-7 grid gap-3">
                    <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener"
                       class="flex h-12 items-center justify-center gap-2 rounded-[12px] bg-copper-500 text-sm
                              font-semibold text-ink-950 transition hover:bg-copper-400">
                        <x-icon name="camera" :size="17" />
                        {{ Site::text('actions.whatsapp_long') }}
                    </a>

                    <a href="{{ Site::telUrl() }}"
                       class="flex h-12 items-center justify-center gap-2 rounded-[12px] border border-white/15
                              text-sm font-semibold text-white transition hover:bg-white/10">
                        {{ Site::text('actions.call') }}
                    </a>

                    <a href="{{ Site::shop('map_url') }}" target="_blank" rel="noopener"
                       class="flex h-12 items-center justify-center gap-2 rounded-[12px] border border-white/15
                              text-sm font-semibold text-white transition hover:bg-white/10">
                        <x-icon name="map-pin" :size="16" />
                        {{ Site::text('actions.directions') }}
                    </a>
                </div>

                <p class="mt-6 flex items-start gap-2 border-t border-white/10 pt-5 text-[0.75rem] text-ink-400">
                    <x-icon name="check-circle" :size="14" class="mt-px shrink-0 text-emerald-400" />
                    {{ Site::text('contact.cta_note') }}
                </p>
            </div>
        </div>
    </div>
</section>
@endsection
