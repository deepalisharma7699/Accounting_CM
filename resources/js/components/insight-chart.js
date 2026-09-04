import { esc, formatMoney } from '../ui';

/**
 * The insight module's visual vocabulary — M23.
 *
 * Every chart in the module is drawn from here, so a bar means the same thing on
 * the overview as it does on the stock panel and a colour never changes meaning
 * between two tabs (§4.4, §7.4). Add a reader, never a second copy.
 *
 * ## Why there is no charting library
 *
 * §7.1: no new dependency without a reason. What this module actually needs is
 * columns, one stacked bar and one filled area — perhaps two hundred lines — and
 * the alternative is shipping a general-purpose plotting engine to a screen that
 * draws three shapes. It would also arrive with its own colours, its own fonts
 * and its own idea of a tooltip, none of which match the rest of the
 * application.
 *
 * ## Why the columns are HTML and only the area is SVG
 *
 * An SVG with a `viewBox` scales its *text* along with its geometry, so a chart
 * that fits a laptop renders microscopic axis labels on a phone and vice versa.
 * Columns are therefore flex children with percentage heights: they reflow at any
 * width, the labels stay at the document's font size, and there is no
 * arithmetic to get wrong. The area chart genuinely needs a path, so it uses the
 * standard trick — the SVG is stretched with `preserveAspectRatio="none"` and
 * carries no text at all, and the labels underneath it are ordinary HTML.
 *
 * ## Everything is a string
 *
 * These return markup rather than nodes, which is what every other renderer in
 * the application does. Amounts arrive as decimal strings from the API and go
 * through `formatMoney`; nothing here does arithmetic on money beyond finding
 * the scale, and that is deliberately done in floats because a bar's *height* is
 * a pixel count, not an amount.
 */

/**
 * The module's colours, by meaning rather than by hue.
 *
 * Named for what they say — revenue, margin, cost — so a panel asks for
 * `SERIES.margin` and cannot accidentally paint two different things the same
 * green. The values are the Tailwind palette the rest of the application uses.
 */
export const SERIES = {
    revenue: { fill: '#2563eb', label: 'Revenue' },
    margin: { fill: '#059669', label: 'Margin' },
    cost: { fill: '#94a3b8', label: 'Cost' },
    loss: { fill: '#e11d48', label: 'Loss' },
    stock: { fill: '#7c3aed', label: 'Stock value' },
    wages: { fill: '#d97706', label: 'Wages' },
    goods: { fill: '#2563eb', label: 'Parts' },
    service: { fill: '#7c3aed', label: 'Labour' },
};

/**
 * The ageing ramp — one colour per bucket, in the order they are read.
 *
 * Green through red rather than five shades of one hue, because the buckets are
 * not more and less of the same thing: "not yet due" is fine and "over 90 days"
 * is a problem, and a reader should be able to see that without reading the
 * labels.
 */
export const AGEING = ['#64748b', '#059669', '#d97706', '#ea580c', '#e11d48'];

const number = (value) => {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
};

/* -------------------------------------------------------------------------
 | Columns
 | ---------------------------------------------------------------------- */

/**
 * A column chart, one group of bars per bucket.
 *
 * Handles negative values properly rather than clamping them: a month with a
 * negative margin draws *below* a zero line, because a bar of height zero would
 * say the workshop broke even when it actually lost money. The baseline only
 * appears when something is negative, so an ordinary chart is not cluttered by
 * an axis it does not need.
 *
 * Every bar carries a `title`, which is the browser's own tooltip. That is a
 * deliberate non-feature: a custom hover card would need positioning, focus
 * handling and a mobile story, and the native one already works with a keyboard
 * and a screen reader.
 *
 * @param {object} options
 * @param {Array<object>} options.buckets  rows from the API, each with a `label`
 * @param {Array<{key: string, colour: object, format?: Function}>} options.series
 * @param {number} [options.height]  the plot area, in pixels
 * @param {string} [options.empty]   what to say when there is nothing to draw
 */
export function columnChart({ buckets = [], series = [], height = 168, empty = 'Nothing in this period.' }) {
    const values = buckets.flatMap((bucket) => series.map((s) => number(bucket[s.key])));
    const peak = Math.max(0, ...values);
    const trough = Math.min(0, ...values);
    const span = peak - trough;

    if (!buckets.length || span === 0) {
        return `<div class="chart-empty" style="height:${height}px">${esc(empty)}</div>`;
    }

    // Where the zero line sits, as a share of the plot from the top. With no
    // negative values it is the floor and no line is drawn.
    const zeroAt = (peak / span) * 100;
    const hasNegative = trough < 0;

    /*
    | One label in every N, chosen from the bucket count rather than fixed.
    |
    | Fifty-two weekly bars cannot each carry a date without the labels
    | overlapping into an unreadable smear. Thinning them keeps the first and
    | last — which is what a reader actually uses to orient a trend — and drops
    | the ones in between.
    */
    const step = Math.ceil(buckets.length / 12);

    const columns = buckets.map((bucket, index) => {
        const bars = series.map((s) => {
            const value = number(bucket[s.key]);

            /*
            | A bucket with nothing in it draws nothing at all.
            |
            | `.chart-bar` carries a 2px floor so a genuinely tiny value is still
            | visible, and that floor turns every empty week into a dash along
            | the axis — which reads as a steady trickle when what happened was
            | one busy Monday and a fortnight of nothing. A gap has to look like
            | a gap, which is the same reason the empty buckets are emitted in
            | the first place.
            */
            if (value === 0) return '';

            const magnitude = (Math.abs(value) / span) * 100;
            const colour = value < 0 ? SERIES.loss : s.colour;
            const format = s.format ?? formatMoney;

            // Grown from the zero line: upwards for a positive value, downwards
            // for a negative one.
            const position = value < 0
                ? `top:${zeroAt}%;height:${magnitude}%`
                : `bottom:${100 - zeroAt}%;height:${magnitude}%`;

            return `<span class="chart-bar" style="${position};background:${colour.fill}"
                          title="${esc(bucket.label)} · ${esc(s.colour.label)} ${esc(format(bucket[s.key]))}"></span>`;
        }).join('');

        const label = index % step === 0 || index === buckets.length - 1 ? bucket.label : '';

        return `
            <div class="chart-col">
                <span class="chart-stack" style="height:${height}px">${bars}</span>
                <span class="chart-tick">${esc(label)}</span>
            </div>`;
    }).join('');

    return `
        <div class="chart">
            <div class="chart-plot" style="height:${height}px">
                ${hasNegative ? `<span class="chart-zero" style="top:${zeroAt}%"></span>` : ''}
            </div>
            <div class="chart-cols" style="margin-top:-${height}px">${columns}</div>
            ${legend(series)}
        </div>`;
}

function legend(series) {
    if (series.length < 2) return '';

    return `<div class="chart-legend">${series.map((s) => `
        <span class="chart-key">
            <span class="chart-swatch" style="background:${s.colour.fill}"></span>
            ${esc(s.colour.label)}
        </span>`).join('')}</div>`;
}

/* -------------------------------------------------------------------------
 | The area
 | ---------------------------------------------------------------------- */

/**
 * A filled area over time — used for a *balance* rather than a flow.
 *
 * Stock value is the case it exists for. Drawn as an area rather than as columns
 * because the eye reads a continuous shape as "this is one quantity changing"
 * and separate bars as "these are separate events", and a closing balance is the
 * first of those.
 *
 * The SVG is stretched with `preserveAspectRatio="none"`, so it carries no text
 * — the labels underneath are HTML and stay crisp at any width.
 *
 * @param {object} options
 * @param {Array<{label: string, value: string}>} options.buckets
 */
export function areaChart({ buckets = [], colour = SERIES.stock, height = 150, empty = 'Nothing to plot yet.' }) {
    if (buckets.length < 2) {
        return `<div class="chart-empty" style="height:${height}px">${esc(empty)}</div>`;
    }

    const values = buckets.map((bucket) => number(bucket.value));
    const peak = Math.max(...values);
    // Floored at zero so the shape reads as a quantity above nothing rather than
    // as a wobble around whatever the smallest month happened to be — the
    // classic truncated-axis exaggeration.
    const trough = Math.min(0, ...values);
    const span = peak - trough || 1;

    const points = values.map((value, index) => {
        const x = (index / (values.length - 1)) * 100;
        const y = 100 - ((value - trough) / span) * 100;

        return `${x.toFixed(3)},${y.toFixed(3)}`;
    });

    const id = `area-${Math.random().toString(36).slice(2, 8)}`;
    const step = Math.ceil(buckets.length / 8);

    return `
        <div class="chart">
            <svg class="chart-area" style="height:${height}px" viewBox="0 0 100 100"
                 preserveAspectRatio="none" role="img" aria-label="Value over time">
                <defs>
                    <linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="${colour.fill}" stop-opacity="0.28"/>
                        <stop offset="100%" stop-color="${colour.fill}" stop-opacity="0.02"/>
                    </linearGradient>
                </defs>
                <polygon points="0,100 ${points.join(' ')} 100,100" fill="url(#${id})"/>
                <polyline points="${points.join(' ')}" fill="none" stroke="${colour.fill}"
                          stroke-width="2" vector-effect="non-scaling-stroke"
                          stroke-linejoin="round" stroke-linecap="round"/>
            </svg>
            <div class="chart-cols">
                ${buckets.map((bucket, index) => `
                    <div class="chart-col" title="${esc(bucket.label)} · ${esc(formatMoney(bucket.value))}">
                        <span class="chart-tick">${esc(index % step === 0 || index === buckets.length - 1 ? bucket.label : '')}</span>
                    </div>`).join('')}
            </div>
        </div>`;
}

/* -------------------------------------------------------------------------
 | One bar, several parts
 | ---------------------------------------------------------------------- */

/**
 * A single horizontal bar split into segments — the mix, and the ageing.
 *
 * Preferred to a pie for the reason every data-visualisation guide gives and
 * this application's tables already assume: people compare lengths accurately
 * and angles badly. It also lays out at any width, which a pie does not.
 *
 * A segment below 2% is still drawn, at 2%, and its label moves to the key
 * underneath. Dropping it would make the bar add up to less than the total it
 * claims to show.
 *
 * `display` overrides the money formatting, for the one caller whose segments
 * are not amounts: the attendance register counts *days*, and "₹12.00 present"
 * would be nonsense.
 *
 * @param {Array<{label: string, amount: string, share: string, colour: string, display?: string}>} segments
 */
export function stackedBar(segments = [], { empty = 'Nothing to show.' } = {}) {
    const drawable = segments.filter((segment) => number(segment.share) > 0);

    if (!drawable.length) {
        return `<p class="chart-empty" style="height:auto;padding:1rem 0">${esc(empty)}</p>`;
    }

    const bar = drawable.map((segment) => `
        <span class="chart-seg" style="flex:${Math.max(number(segment.share), 2)};background:${segment.colour}"
              title="${esc(segment.label)} · ${esc(segment.display ?? formatMoney(segment.amount))} (${esc(segment.share)}%)"></span>`).join('');

    const keys = segments.map((segment) => `
        <li class="chart-key">
            <span class="chart-swatch" style="background:${segment.colour}"></span>
            <span class="chart-key-label">${esc(segment.label)}</span>
            <span class="chart-key-value">${esc(segment.display ?? formatMoney(segment.amount))}</span>
            <span class="chart-key-share">${esc(segment.share)}%</span>
        </li>`).join('');

    return `
        <div class="chart-split">
            <div class="chart-track">${bar}</div>
            <ul class="chart-keys">${keys}</ul>
        </div>`;
}

/* -------------------------------------------------------------------------
 | In a table row
 | ---------------------------------------------------------------------- */

/**
 * A proportion bar sized to fit inside a table cell.
 *
 * What turns a top-ten list from a column of numbers into something readable at
 * a glance: the row that is half the revenue looks like half the revenue.
 */
export function shareBar(share, colour = SERIES.revenue.fill) {
    const width = Math.max(0, Math.min(100, number(share)));

    return `
        <span class="chart-mini" role="presentation">
            <span class="chart-mini-fill" style="width:${width.toFixed(2)}%;background:${colour}"></span>
        </span>`;
}

/* -------------------------------------------------------------------------
 | The headline tiles
 | ---------------------------------------------------------------------- */

const TONE_CLASS = {
    good: 'text-emerald-700',
    bad: 'text-rose-700',
    warn: 'text-amber-700',
    neutral: 'text-foreground',
};

/**
 * One headline figure, its caption, and how it compares with the window before.
 *
 * The delta is the reason this module exists rather than being four more report
 * tabs, so it is part of the tile rather than an optional extra. **A null delta
 * paints nothing at all** — no arrow, no dash, no "0%". There is no honest
 * comparison for an all-time window or for a workshop's first month, and a tile
 * that filled the gap with a zero would be making a claim.
 *
 * `formatted: false` opts out of the money formatter, for the tiles whose value
 * is a count or a ratio rather than an amount.
 *
 * `signed` is opt-in and off by default. A leading "+" is a *claim* that the
 * figure has a direction — right on a margin, which can go either way, and noise
 * on revenue, stock value or what a customer owes, none of which is ever
 * negative in a way the sign would explain.
 */
export function statTile({
    label, value, note, delta = null, tone = 'neutral', formatted = true, signed = false,
}) {
    return `
        <div class="stat-tile flex-col items-start gap-1">
            <span class="text-[0.6875rem] font-semibold uppercase tracking-wide text-muted-foreground">${esc(label)}</span>
            <span class="block font-mono text-[1.375rem] font-bold leading-tight ${TONE_CLASS[tone] ?? TONE_CLASS.neutral}">
                ${esc(formatted ? formatMoney(value, { sign: signed }) : String(value))}
            </span>
            <span class="flex flex-wrap items-center gap-1.5">
                ${deltaPill(delta)}
                ${note ? `<span class="text-[0.75rem] text-muted-foreground">${esc(note)}</span>` : ''}
            </span>
        </div>`;
}

/**
 * "up 18%" against the window before.
 *
 * Direction is stated in words as well as by colour and by the arrow, because
 * colour alone fails for a red-green colour-blind reader and an arrow alone
 * fails in a screen reader.
 */
export function deltaPill(delta) {
    if (!delta) return '';

    const glyph = { up: '▲', down: '▼', flat: '■' }[delta.direction] ?? '■';

    /*
    | Up is not automatically good.
    |
    | Rising revenue is good and rising discount is not, and this component
    | cannot tell which it is looking at — so it colours by *direction* only and
    | leaves the judgement to the caption beside it. Painting "discount up 40%"
    | green would be worse than painting it nothing at all.
    */
    const tone = { up: 'text-emerald-700 bg-emerald-50', down: 'text-rose-700 bg-rose-50', flat: 'text-muted-foreground bg-muted' }[delta.direction];

    return `
        <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-semibold ${tone}">
            <span aria-hidden="true">${glyph}</span>
            ${delta.direction === 'flat' ? 'level' : `${esc(delta.percent)}%`}
            <span class="sr-only">${delta.direction} on the previous period</span>
        </span>`;
}
