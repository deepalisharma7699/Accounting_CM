@extends('site.layout')

{{--
    One service, in depth.
    ======================================================================

    Three of the six services have a page: rewinding, pump repairs and winding
    wire. Those are the three somebody types into a search box by name, and a
    card on the home page cannot answer "what actually happens to my motor" in
    forty words.

    The other three do not have one, and should not be given one to make the
    set tidy. A page that exists only to repeat its own card is a page a visitor
    clicks once and never trusts again.

    Which services have a page is decided in config/shop.php, and the route's
    whitelist is built from the same list — so a page cannot exist without the
    copy to fill it, and copy cannot be written for a page nobody can reach.
--}}

@section('content')
@php use App\Support\Site; @endphp

{{-- =====================================================================
     Head
     ===================================================================== --}}
<section class="s-grid relative overflow-hidden bg-ink-950 pb-16 pt-28 sm:pt-32 lg:pb-20 lg:pt-40">
    <div class="pointer-events-none absolute -left-24 top-10 size-[28rem] rounded-full bg-copper-500/15
                blur-[120px]" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-4xl px-4 sm:px-6">

        {{-- The way back. A visitor who arrived here from a search has never
             seen the home page, so this is navigation and not a nicety. --}}
        <a href="{{ Site::url('home') }}" data-reveal
           class="inline-flex items-center gap-1.5 text-[0.8125rem] font-medium text-ink-400 transition
                  hover:text-copper-300">
            <x-icon name="chevron-left" :size="15" />
            {{ Site::text('actions.back_home') }}
        </a>

        <span data-reveal style="--rd: .06s"
              class="mt-8 inline-flex items-center gap-2.5 rounded-full border border-copper-500/25
                     bg-copper-500/10 px-3.5 py-1.5 font-mono text-[0.6875rem] font-medium tracking-wide
                     text-copper-200">
            <x-icon name="{{ $service['icon'] }}" :size="14" />
            {{ $service['tag'] }}
        </span>

        <h1 data-reveal style="--rd: .12s"
            class="mt-5 text-[2.25rem] font-bold leading-[1.08] tracking-tight text-white sm:text-5xl">
            {{ $service['title'] }}
        </h1>

        <p data-reveal style="--rd: .18s" class="mt-6 max-w-2xl text-[1.125rem] leading-relaxed text-ink-300">
            {{ $service['lead'] }}
        </p>

        <div data-reveal style="--rd: .24s" class="mt-8 flex flex-wrap gap-3">
            <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener" data-site-hero-cta
               class="inline-flex h-12 items-center gap-2 rounded-[12px] bg-copper-500 px-6 text-sm
                      font-semibold text-ink-950 transition hover:bg-copper-400">
                <x-icon name="camera" :size="17" />
                {{ Site::text('actions.whatsapp') }}
            </a>

            <a href="{{ Site::telUrl() }}"
               class="inline-flex h-12 items-center gap-2 rounded-[12px] border border-white/15 px-6 text-sm
                      font-semibold text-white transition hover:bg-white/10">
                <x-icon name="phone" :size="16" />
                {{ Site::text('actions.call') }}
            </a>
        </div>
    </div>
</section>


{{-- =====================================================================
     The body, and the specification beside it
     ===================================================================== --}}
<section class="bg-paper py-16 sm:py-20">
    <div class="mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-[1.5fr_1fr] lg:gap-16">

        <article class="max-w-2xl">
            @foreach ($service['sections'] as $i => $block)
                <div data-reveal style="--rd: {{ $i * 0.05 }}s" class="@if (! $loop->first) mt-10 @endif">
                    <h2 class="text-xl font-bold tracking-tight text-ink-950 sm:text-2xl">
                        {{ $block['title'] }}
                    </h2>
                    <p class="mt-3.5 text-[1rem] leading-[1.75] text-ink-700">{{ $block['body'] }}</p>
                </div>
            @endforeach
        </article>

        {{-- The specification. Monospaced and sticky: it is the part somebody
             scrolls back up to check while reading the prose. --}}
        <aside data-reveal style="--rx: 1.5rem" class="lg:sticky lg:top-28 lg:self-start">
            <div class="rounded-[16px] border border-ink-100 bg-white p-6">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-ink-500">
                    {{ $service['specs_title'] }}
                </p>

                <dl class="mt-4 divide-y divide-ink-100">
                    @foreach ($service['specs'] as $spec)
                        <div class="py-3 first:pt-0 last:pb-0">
                            <dt class="text-[0.75rem] font-medium text-ink-500">{{ $spec['label'] }}</dt>
                            <dd class="mt-1 font-mono text-[0.8125rem] font-semibold leading-snug text-ink-900">
                                {{ $spec['value'] }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="mt-4 rounded-[16px] border border-copper-200 bg-copper-50 p-6">
                <p class="text-[0.9375rem] font-semibold text-ink-950">
                    {{ Site::text('contact.cta_title') }}
                </p>
                <p class="mt-2 text-[0.8125rem] leading-relaxed text-ink-700">
                    {{ Site::text('contact.cta_body') }}
                </p>
                <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener"
                   class="mt-4 flex h-11 items-center justify-center gap-2 rounded-[11px] bg-ink-950 text-[0.875rem]
                          font-semibold text-white transition hover:bg-ink-800">
                    <x-icon name="camera" :size="16" />
                    {{ Site::text('actions.whatsapp') }}
                </a>
            </div>
        </aside>
    </div>
</section>


{{-- =====================================================================
     The nameplate guide, again
     ---------------------------------------------------------------------
     Not a duplicate: a visitor who landed here from a search has not seen the
     home page, and this is the one thing the page wants them to do next. It is
     one include, so there is still only one nameplate guide on the site.
     ===================================================================== --}}
<section id="nameplate" class="s-grid-light relative scroll-mt-24 overflow-hidden border-y border-ink-100
                               bg-paper-2 py-16 sm:py-20">
    <div class="relative mx-auto max-w-6xl px-4 sm:px-6">
        <div class="max-w-2xl">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('nameplate.heading') }}</p>
            <h2 data-reveal style="--rd: .06s"
                class="mt-4 text-2xl font-bold tracking-tight text-ink-950 sm:text-3xl">
                {{ Site::text('nameplate.title') }}
            </h2>
        </div>

        <div class="mt-10">
            @include('site.partials.nameplate')
        </div>
    </div>
</section>


{{-- =====================================================================
     The other two
     ===================================================================== --}}
@if ($others)
    <section class="bg-paper py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p data-reveal class="s-eyebrow text-copper-600">{{ Site::text('services.heading') }}</p>

            <div class="mt-8 grid gap-5 sm:grid-cols-2">
                @foreach ($others as $i => $other)
                    <a data-reveal style="--rd: {{ $i * 0.07 }}s" href="{{ $other['url'] }}"
                       class="group rounded-[14px] border border-ink-100 bg-white p-6 transition duration-300
                              hover:-translate-y-1 hover:border-copper-300 hover:shadow-lg hover:shadow-copper-500/10">
                        <span class="grid size-11 place-items-center rounded-[13px] bg-copper-50 text-copper-600
                                     transition-colors duration-300 group-hover:bg-copper-500 group-hover:text-white">
                            <x-icon name="{{ $other['icon'] }}" :size="21" />
                        </span>
                        <h3 class="mt-5 text-[1.0625rem] font-semibold text-ink-950">{{ $other['title'] }}</h3>
                        <p class="mt-2 text-[0.875rem] leading-relaxed text-ink-600">{{ $other['summary'] }}</p>
                        <p class="mt-5 inline-flex items-center gap-1.5 border-t border-ink-100 pt-4 text-[0.75rem]
                                  font-semibold text-copper-600">
                            {{ Site::text('actions.read_more') }}
                            <x-icon name="arrow-up-right" :size="13"
                                    class="transition-transform duration-300 group-hover:-translate-y-0.5
                                           group-hover:translate-x-0.5" />
                        </p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif


{{-- =====================================================================
     Visit
     ===================================================================== --}}
<section id="contact" class="s-grid relative scroll-mt-24 overflow-hidden bg-ink-950 py-16 sm:py-20">
    <div class="relative mx-auto max-w-4xl px-4 text-center sm:px-6">
        <h2 data-reveal class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {{ Site::text('contact.title') }}
        </h2>
        <p data-reveal style="--rd: .08s" class="mx-auto mt-4 max-w-xl text-[1.0625rem] leading-relaxed text-ink-300">
            {{ Site::text('contact.body') }}
        </p>

        <div data-reveal style="--rd: .14s" class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener"
               class="inline-flex h-12 items-center gap-2 rounded-[12px] bg-copper-500 px-6 text-sm
                      font-semibold text-ink-950 transition hover:bg-copper-400">
                <x-icon name="camera" :size="17" />
                {{ Site::text('actions.whatsapp') }}
            </a>
            <a href="{{ Site::telUrl() }}"
               class="inline-flex h-12 items-center gap-2 rounded-[12px] border border-white/15 px-6 text-sm
                      font-semibold text-white transition hover:bg-white/10">
                <x-icon name="phone" :size="16" />
                {{ Site::text('actions.call') }}
            </a>
            <a href="{{ Site::shop('map_url') }}" target="_blank" rel="noopener"
               class="inline-flex h-12 items-center gap-2 rounded-[12px] border border-white/15 px-6 text-sm
                      font-semibold text-white transition hover:bg-white/10">
                <x-icon name="map-pin" :size="16" />
                {{ Site::text('actions.directions') }}
            </a>
        </div>

        <p data-reveal style="--rd: .2s" class="mt-8 font-mono text-[0.8125rem] text-ink-500">
            {{ Site::shop('street') }} · {{ Site::shop('city') }}, {{ Site::shop('state') }} {{ Site::shop('postcode') }}
        </p>
    </div>
</section>
@endsection
