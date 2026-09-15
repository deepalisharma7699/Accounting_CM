{{--
    The bar at the bottom of a phone.
    ======================================================================

    Call and WhatsApp, fixed, for the whole scroll. This site's visitor is
    standing in a shed next to a motor that has stopped, holding a phone in one
    hand, and the two things they might actually do should never be more than a
    thumb away.

    Hidden while the hero is still on screen — the hero has these same two
    buttons at full size, and two calls to action at once is neither of them.
    resources/js/pages/site.js watches the hero and drops the class.
--}}
@php use App\Support\Site; @endphp

<div data-site-actionbar
     class="s-actionbar is-hidden fixed inset-x-0 bottom-0 z-30 border-t border-white/10 bg-ink-950/95
            px-3 pt-2.5 backdrop-blur lg:hidden">
    <div class="flex items-center gap-2.5">
        <a href="{{ Site::telUrl() }}"
           class="flex h-12 flex-1 items-center justify-center gap-2 rounded-[12px] border border-white/15
                  text-sm font-semibold text-white transition active:bg-white/10">
            <x-icon name="phone" :size="17" />
            {{ Site::text('actions.call_short') }}
        </a>

        <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener"
           class="flex h-12 flex-[1.4] items-center justify-center gap-2 rounded-[12px] bg-copper-500 text-sm
                  font-semibold text-ink-950 transition active:bg-copper-400">
            {{ Site::text('actions.whatsapp') }}
            <x-icon name="arrow-right" :size="17" />
        </a>
    </div>
</div>
