{{--
    The hero's drawing: an induction motor, cut open.
    ======================================================================

    A longitudinal half-section of the thing this shop takes apart every day —
    frame and fins, stator core and winding overhang, rotor and shaft, both
    bearings, the fan under its cowl, the terminal box and the nameplate.

    It is drawn rather than photographed for three reasons. A photograph would
    be of somebody else's motor. A drawing scales to a phone without turning to
    mush. And a drawing can be labelled: the callouts around it name the parts,
    which is the first useful thing the page says and the reason a visitor
    scrolls at all.

    Everything is one SVG on a 420 × 250 grid, so the whole drawing scales as a
    unit and the callouts — HTML, positioned in percentages over it — stay
    attached to what they point at.
--}}
@php use App\Support\Site; @endphp

<div class="relative mx-auto w-full max-w-[34rem]">

    {{-- The light behind it. Two, so the drawing is lit from the copper side
         and cooled on the other, which is what stops a flat SVG looking flat. --}}
    <div class="s-pulse pointer-events-none absolute left-1/2 top-1/2 size-[26rem] -translate-x-1/2
                -translate-y-1/2 rounded-full bg-copper-500/20 blur-[110px]" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-10 top-0 size-[18rem] rounded-full bg-sky-500/10
                blur-[90px]" aria-hidden="true"></div>

    <svg viewBox="0 0 420 250" class="relative w-full" fill="none"
         stroke-linecap="round" stroke-linejoin="round"
         role="img" aria-label="{{ Site::text('hero.diagram_title') }}">

        {{-- Hatching for the cut faces of the stator core, as a section is
             drawn on paper. Defined once and referenced, so the angle and
             pitch cannot drift between the two halves. --}}
        <defs>
            <pattern id="s-hatch" width="6" height="6" patternTransform="rotate(45)"
                     patternUnits="userSpaceOnUse">
                <line x1="0" y1="0" x2="0" y2="6" stroke="#8b93a5" stroke-width="1" opacity="0.55"/>
            </pattern>
            <linearGradient id="s-frame" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#262b36"/>
                <stop offset="0.5" stop-color="#1a1e27"/>
                <stop offset="1" stop-color="#11141b"/>
            </linearGradient>
        </defs>

        {{-- The centre line, and the trace of the shaft axis. Dashed, thin and
             copper: on a real drawing it is the datum everything is measured
             from, and here it is what makes the picture read as a section. --}}
        <line x1="24" y1="132" x2="400" y2="132" stroke="#b87333" stroke-width="1"
              stroke-dasharray="14 5 2 5" opacity="0.5"/>

        {{-- Feet ---------------------------------------------------------- --}}
        <path d="M134 186h44v12h-44z" fill="#262b36" stroke="#646d80" stroke-width="1.4"/>
        <path d="M246 186h44v12h-44z" fill="#262b36" stroke="#646d80" stroke-width="1.4"/>
        <circle cx="156" cy="192" r="2.6" fill="#0a0c10" stroke="#646d80" stroke-width="1"/>
        <circle cx="268" cy="192" r="2.6" fill="#0a0c10" stroke="#646d80" stroke-width="1"/>

        {{-- The frame, and the fins cast into it ------------------------- --}}
        <rect x="118" y="76" width="188" height="110" rx="8"
              fill="url(#s-frame)" stroke="#8b93a5" stroke-width="1.6"/>
        @foreach (range(0, 10) as $i)
            <line x1="{{ 128 + $i * 17 }}" y1="80" x2="{{ 128 + $i * 17 }}" y2="106"
                  stroke="#3a4150" stroke-width="2"/>
        @endforeach

        {{-- Stator core, in section: hatched laminations top and bottom with
             the bore between them. --}}
        <path d="M132 92h160v22H132z" fill="url(#s-hatch)" stroke="#b3b9c6" stroke-width="1.3"/>
        <path d="M132 150h160v22H132z" fill="url(#s-hatch)" stroke="#b3b9c6" stroke-width="1.3"/>

        {{-- The winding, in the slots and overhanging each end of the core.
             Copper, because it is: this is the part being sold. --}}
        @foreach (range(0, 7) as $i)
            <rect x="{{ 140 + $i * 20 }}" y="114" width="12" height="9" rx="2"
                  fill="#b87333" opacity="0.9"/>
            <rect x="{{ 140 + $i * 20 }}" y="141" width="12" height="9" rx="2"
                  fill="#b87333" opacity="0.9"/>
        @endforeach
        <path d="M132 114c-10 0-14 5-14 10s4 10 14 10" stroke="#cf8146" stroke-width="3.2" opacity="0.95"/>
        <path d="M292 114c10 0 14 5 14 10s-4 10-14 10" stroke="#cf8146" stroke-width="3.2" opacity="0.95"/>

        {{-- Rotor: the cage between the two core halves, on the shaft ----- --}}
        <rect x="152" y="123" width="120" height="18" rx="3"
              fill="#3a4150" stroke="#b3b9c6" stroke-width="1.3"/>
        @foreach (range(0, 11) as $i)
            <line x1="{{ 158 + $i * 10 }}" y1="124" x2="{{ 158 + $i * 10 }}" y2="140"
                  stroke="#646d80" stroke-width="1.2"/>
        @endforeach

        {{-- The shaft, running the whole length and out to the drive end --}}
        <path d="M92 128h250v9H92z" fill="#8b93a5"/>
        <path d="M342 129h34v7h-34z" fill="#b3b9c6"/>
        {{-- The keyway on the extension, which is how it is coupled --}}
        <path d="M352 126h14v4h-14z" fill="#0a0c10" opacity="0.65"/>

        {{-- End shields, one each end of the frame --------------------- --}}
        <path d="M306 86c14 6 20 20 20 46s-6 40-20 46" fill="#1a1e27" stroke="#8b93a5" stroke-width="1.6"/>
        <path d="M118 86c-14 6-20 20-20 46s6 40 20 46" fill="#1a1e27" stroke="#8b93a5" stroke-width="1.6"/>

        {{-- Bearings, in section: outer race, inner race and the balls ---- --}}
        @foreach ([['x' => 314], ['x' => 108]] as $bearing)
            <rect x="{{ $bearing['x'] - 9 }}" y="112" width="18" height="14" rx="2"
                  fill="#0a0c10" stroke="#e09e6c" stroke-width="1.5"/>
            <rect x="{{ $bearing['x'] - 9 }}" y="139" width="18" height="14" rx="2"
                  fill="#0a0c10" stroke="#e09e6c" stroke-width="1.5"/>
            <circle cx="{{ $bearing['x'] }}" cy="119" r="3.4" fill="#e09e6c"/>
            <circle cx="{{ $bearing['x'] }}" cy="146" r="3.4" fill="#e09e6c"/>
        @endforeach

        {{-- The fan, and the cowl over it ------------------------------- --}}
        <path d="M64 88h34v88H64z" fill="#1a1e27" stroke="#8b93a5" stroke-width="1.5"/>
        @foreach (range(0, 6) as $i)
            <line x1="68" y1="{{ 96 + $i * 12 }}" x2="94" y2="{{ 96 + $i * 12 }}"
                  stroke="#3a4150" stroke-width="2"/>
        @endforeach
        <g class="s-rotor" style="--ox: 84px; --oy: 132px">
            <path d="M84 104c6 6 6 18 0 24M84 160c-6-6-6-18 0-24" stroke="#b3b9c6" stroke-width="2.4"/>
            <circle cx="84" cy="132" r="6" fill="#262b36" stroke="#b3b9c6" stroke-width="1.6"/>
        </g>

        {{-- Terminal box, where the supply lands ------------------------ --}}
        <rect x="184" y="50" width="60" height="26" rx="4"
              fill="#262b36" stroke="#8b93a5" stroke-width="1.5"/>
        @foreach ([200, 214, 228] as $x)
            <circle cx="{{ $x }}" cy="63" r="3" fill="#b87333"/>
        @endforeach
        <path d="M214 50v-9" stroke="#646d80" stroke-width="1.6"/>

        {{-- The nameplate, riveted to the frame. Small, and the section
             below the hero is entirely about it. --}}
        <rect x="196" y="158" width="46" height="22" rx="2.5"
              fill="#d4d8e0" stroke="#f6f7f9" stroke-width="1"/>
        @foreach ([164, 169, 174] as $i => $y)
            <line x1="201" y1="{{ $y }}" x2="{{ 237 - $i * 8 }}" y2="{{ $y }}"
                  stroke="#3a4150" stroke-width="1.6"/>
        @endforeach
    </svg>

    {{--
        The labels.

        HTML rather than SVG <text>: an SVG viewBox scales its own text, so a
        label sized to read on a desktop is illegible on a phone and one sized
        for a phone is enormous on a desktop. As HTML they stay at the page's
        own type size whatever the drawing does — and they can be hidden
        wholesale on the narrowest screens, where the drawing has to shrink far
        enough that nothing would fit beside it anyway.

        `--x` and `--y` place the label; `--len` is how far its leader line
        reaches back towards the part. See the `.s-callout` block in site.css.
    --}}
    <div class="pointer-events-none absolute inset-0 hidden sm:block" aria-hidden="true">
        @php
            /*
            | Where each label sits, and which part it names. The position is a
            | layout decision so it stays here; the words are translated and
            | come from `hero.callouts`, keyed by part.
            */
            $labels = Site::items('hero.callouts');

            /*
            | Every label, its leader line and its dot must finish inside this
            | box. A label hung off the left with `--x: -2%` overflows by its
            | own width plus the line, and in the hero's two-column grid that
            | lands it on top of the paragraph in the next column — which is
            | what the first draft of this did.
            |
            | So the two on the left sit at the edge pointing *inwards* over the
            | drawing's empty end, and the three on the right are placed far
            | enough from the edge to fit their own line.
            */
            $callouts = [
                ['key' => 'terminal_box', 'x' => '60%', 'y' => '5%', 'side' => 'l', 'len' => '1.5rem', 'from' => '1.25rem', 'd' => '.5s'],
                ['key' => 'stator', 'x' => '1%', 'y' => '27%', 'side' => 'r', 'len' => '1.5rem', 'from' => '-1.25rem', 'd' => '.65s'],
                ['key' => 'rotor', 'x' => '72%', 'y' => '45%', 'side' => 'l', 'len' => '2rem', 'from' => '1.25rem', 'd' => '.8s'],
                ['key' => 'bearings', 'x' => '1%', 'y' => '66%', 'side' => 'r', 'len' => '1.25rem', 'from' => '-1.25rem', 'd' => '.95s'],
                ['key' => 'nameplate', 'x' => '62%', 'y' => '86%', 'side' => 'l', 'len' => '1.5rem', 'from' => '1.25rem', 'd' => '1.1s'],
            ];
        @endphp

        @foreach ($callouts as $callout)
            <span class="s-callout s-callout-{{ $callout['side'] }} whitespace-nowrap rounded-md
                         border border-copper-500/30 bg-ink-950/80 px-2 py-1 font-mono text-[0.6875rem]
                         font-medium tracking-wide text-copper-200 backdrop-blur"
                  style="--x: {{ $callout['x'] }}; --y: {{ $callout['y'] }}; --len: {{ $callout['len'] }};
                         --from: {{ $callout['from'] }}; --d: {{ $callout['d'] }}">
                {{ $labels[$callout['key']] ?? '' }}
            </span>
        @endforeach
    </div>
</div>
