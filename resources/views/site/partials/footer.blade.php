{{--
    The footer.
    ======================================================================

    Also the second sitemap: the service pages are linked from here as well as
    from their cards, because a visitor who has read to the bottom of a service
    page has no card in front of them any more.

    The staff sign-in lives here rather than in the header on purpose. It is the
    one control on this site that is not for the visitor, and putting it beside
    "Call us" in the header would have every third customer tapping it.
--}}
@php
    use App\Support\Site;

    $home = Site::url('home');
@endphp

<footer class="border-t border-white/10 bg-ink-950 pt-14">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <div class="grid gap-10 pb-12 sm:grid-cols-2 lg:grid-cols-4">

            <div class="lg:col-span-2">
                <a href="{{ $home }}" class="flex items-center gap-2.5">
                    <span class="grid size-9 place-items-center rounded-[11px] bg-copper-500 text-ink-950">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor"
                             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/>
                            <circle cx="12" cy="12" r="4"/>
                            <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>
                        </svg>
                    </span>
                    <span class="text-[0.9375rem] font-bold tracking-tight text-white">
                        {{ Site::shop('name') }}
                    </span>
                </a>

                <p class="mt-4 max-w-sm text-[0.875rem] leading-relaxed text-ink-400">
                    {{ Site::text('footer.tagline') }}
                </p>

                <div class="mt-6 flex flex-wrap gap-2.5">
                    <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener"
                       class="inline-flex h-10 items-center gap-2 rounded-[11px] bg-copper-500 px-4 text-[0.8125rem]
                              font-semibold text-ink-950 transition hover:bg-copper-400">
                        <x-icon name="phone" :size="15" />
                        {{ Site::text('actions.whatsapp') }}
                    </a>
                    <a href="{{ Site::shop('map_url') }}" target="_blank" rel="noopener"
                       class="inline-flex h-10 items-center gap-2 rounded-[11px] border border-white/15 px-4
                              text-[0.8125rem] font-semibold text-white transition hover:bg-white/10">
                        <x-icon name="map-pin" :size="15" />
                        {{ Site::text('actions.directions') }}
                    </a>
                </div>
            </div>

            <div>
                <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-ink-500">
                    {{ Site::text('footer.services_title') }}
                </p>
                <ul class="mt-4 space-y-2.5">
                    @foreach ($services as $service)
                        <li>
                            <a href="{{ $service['url'] ?? $home.'#services' }}"
                               class="text-[0.875rem] text-ink-300 transition hover:text-copper-300">
                                {{ $service['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-ink-500">
                    {{ Site::text('footer.shop_title') }}
                </p>

                <address class="mt-4 space-y-3 text-[0.875rem] not-italic text-ink-300">
                    <p class="leading-relaxed">
                        {{ Site::shop('street') }}<br>
                        {{ Site::shop('city') }}, {{ Site::shop('state') }} {{ Site::shop('postcode') }}
                    </p>
                    <p>
                        <a href="{{ Site::telUrl() }}" class="transition hover:text-copper-300">
                            {{ Site::shop('phone') }}
                        </a>
                    </p>
                    {{-- Dropped entirely while the address on file is somebody's
                         personal inbox — Site::email() is the one gate. --}}
                    @if ($email = Site::email())
                        <p>
                            <a href="mailto:{{ $email }}" class="transition hover:text-copper-300">{{ $email }}</a>
                        </p>
                    @endif
                    <p class="text-ink-400">{{ Site::hoursLabel() }}</p>
                    @if ($gstin = Site::shop('gstin'))
                        <p class="font-mono text-[0.75rem] text-ink-500">
                            {{ Site::text('contact.labels.gstin') }} {{ $gstin }}
                        </p>
                    @endif
                </address>
            </div>
        </div>

        <div class="flex flex-col gap-4 border-t border-white/10 py-6 md:flex-row md:items-center md:justify-between">
            <p class="text-[0.8125rem] text-ink-500">{{ Site::text('footer.rights') }}</p>

            <div class="flex items-center gap-3">
                <span class="hidden text-[0.75rem] text-ink-600 sm:inline">
                    {{ Site::text('footer.staff_note') }}
                </span>
                <button type="button" data-login-open
                        class="inline-flex items-center gap-2 self-start rounded-[10px] border border-white/15 px-3.5
                               py-2 text-[0.8125rem] font-semibold text-ink-300 transition hover:bg-white/10
                               hover:text-white md:self-auto">
                    <x-icon name="lock" :size="14" />
                    {{ Site::text('actions.staff_login') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Clearance for the sticky action bar, which is fixed over the page on a
         phone and would otherwise sit on top of the last line of the footer. --}}
    <div class="h-16 lg:hidden" aria-hidden="true"></div>
</footer>
