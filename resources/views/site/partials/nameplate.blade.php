{{--
    The nameplate, and what is on it.
    ======================================================================

    The most useful block on the site. A caller who cannot say what their motor
    is cannot be quoted, and the entire conversation goes "bring it in" — which
    for somebody with a 20 HP mill motor on a trolley is the whole problem. Get
    them to photograph the plate instead and the shop can answer before anyone
    loads anything.

    The plate is drawn in HTML rather than being a photograph or an SVG for one
    reason: its fields have to be individually highlightable, because pointing
    at a row in the list beside it and lighting up the field on the plate is
    what teaches somebody where to look. A photograph could not do that, would
    be a picture of somebody else's motor, and would not survive a phone screen.

    The plate's own labels stay in English in both languages. That is not an
    untranslated string — Indian motor nameplates are printed in English, and a
    guide to reading one has to show the words that are actually stamped on it.
--}}
@php
    use App\Support\Site;

    $rows = Site::items('nameplate.rows');

    /*
    | The plate's layout: one row per line of a real three-phase plate, and the
    | `key` on each cell ties it to its explanation in the list. Values are a
    | consistent worked example — a 7.5 HP 4-pole motor, the same one the test
    | report further down the page reports on.
    */
    $plate = [
        [['key' => 'output', 'label' => 'OUTPUT', 'value' => '5.5 kW / 7.5 HP', 'span' => 2]],
        [
            ['key' => 'volts', 'label' => 'VOLTS', 'value' => '415 V'],
            ['key' => 'amps', 'label' => 'AMPS', 'value' => '11.5 A'],
        ],
        [
            ['key' => 'rpm', 'label' => 'R.P.M.', 'value' => '1440'],
            ['key' => 'phase', 'label' => 'PH / HZ', 'value' => '3 PH / 50'],
        ],
        [
            ['key' => 'frame', 'label' => 'FRAME', 'value' => '132 S'],
            ['key' => 'insulation', 'label' => 'INS. CL', 'value' => 'F'],
        ],
        [
            ['key' => 'connection', 'label' => 'CONN.', 'value' => 'DELTA'],
            ['key' => 'ip', 'label' => 'ENCL.', 'value' => 'IP 55'],
        ],
        [['key' => 'serial', 'label' => 'SR. NO.', 'value' => 'XXXXXXX / 2019', 'span' => 2]],
    ];
@endphp

<div class="grid gap-10 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:gap-14">

    {{-- The plate ---------------------------------------------------- --}}
    <div data-reveal style="--rx: -1.5rem" class="lg:sticky lg:top-28 lg:self-start">
        <div class="s-plate p-1.5" data-plate>
            <div class="rounded-[4px] border border-ink-400/40 p-3.5">

                {{-- The maker's line. Deliberately not a real manufacturer:
                     the plate is a diagram of a plate, and stamping somebody's
                     brand on it would be putting words in their mouth. --}}
                <div class="flex items-end justify-between border-b border-ink-500/40 pb-2">
                    <p class="font-mono text-[0.625rem] font-bold tracking-[0.18em] text-ink-700">
                        3~ INDUCTION MOTOR
                    </p>
                    <p class="font-mono text-[0.5625rem] tracking-[0.12em] text-ink-500">IS 12615</p>
                </div>

                <dl class="mt-2 grid grid-cols-2 gap-1">
                    @foreach ($plate as $line)
                        @foreach ($line as $cell)
                            <div class="s-plate-field rounded-[3px] px-1.5 py-1
                                        {{ ($cell['span'] ?? 1) === 2 ? 'col-span-2' : '' }}"
                                 data-plate-field="{{ $cell['key'] }}">
                                <dt class="font-mono text-[0.5625rem] font-semibold tracking-[0.12em] text-ink-500">
                                    {{ $cell['label'] }}
                                </dt>
                                <dd class="font-mono text-[0.8125rem] font-bold leading-tight text-ink-900">
                                    {{ $cell['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    @endforeach
                </dl>

                {{-- The rivets. A nameplate is riveted, not glued, and the
                     four dots are what make a rectangle read as one. --}}
                <div class="pointer-events-none mt-2 flex justify-between px-0.5">
                    @foreach (range(1, 2) as $i)
                        <span class="size-1.5 rounded-full bg-ink-400/70 shadow-inner"></span>
                    @endforeach
                </div>
            </div>
        </div>

        <p class="mt-4 text-[0.8125rem] leading-relaxed text-ink-500">
            {{ Site::text('nameplate.plate_caption') }}
        </p>

        <a href="{{ Site::whatsappUrl() }}" target="_blank" rel="noopener"
           class="mt-5 inline-flex h-11 items-center gap-2 rounded-[11px] bg-ink-900 px-5 text-[0.875rem]
                  font-semibold text-white transition hover:bg-ink-800">
            <x-icon name="camera" :size="16" />
            {{ Site::text('actions.whatsapp_long') }}
        </a>
    </div>

    {{-- What each field means ---------------------------------------- --}}
    <div>
        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach ($rows as $key => $row)
                <li data-reveal style="--rd: {{ $loop->index * 0.04 }}s; --ry: 1rem"
                    class="s-plate-row rounded-[12px] border border-ink-100 bg-white p-4"
                    data-plate-row="{{ $key }}" tabindex="0">

                    <div class="flex items-center justify-between gap-3">
                        <p class="font-mono text-[0.75rem] font-bold tracking-wide text-ink-900">
                            {{ $row['field'] }}
                        </p>
                        <span class="rounded-full bg-copper-50 px-2 py-0.5 text-[0.625rem] font-semibold
                                     uppercase tracking-wide text-copper-700">
                            {{ $row['why'] }}
                        </span>
                    </div>

                    <p class="mt-2 text-[0.8125rem] leading-relaxed text-ink-600">{{ $row['means'] }}</p>
                </li>
            @endforeach
        </ul>

        {{-- The case everybody with an old motor is about to ask about. --}}
        <div data-reveal class="mt-6 flex items-start gap-3.5 rounded-[14px] border border-copper-200
                                bg-copper-50 p-5">
            <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-[10px] bg-copper-500 text-white">
                <x-icon name="info" :size="16" />
            </span>
            <div>
                <p class="text-[0.9375rem] font-semibold text-ink-900">
                    {{ Site::text('nameplate.unreadable_title') }}
                </p>
                <p class="mt-1.5 text-[0.875rem] leading-relaxed text-ink-700">
                    {{ Site::text('nameplate.unreadable_body') }}
                </p>
            </div>
        </div>
    </div>
</div>
