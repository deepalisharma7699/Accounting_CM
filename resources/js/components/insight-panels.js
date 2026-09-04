import {
    AGEING, SERIES, areaChart, columnChart, shareBar, stackedBar, statTile,
} from './insight-chart';
import { esc, formatDate, formatMoney } from '../ui';

/**
 * The five insight panels — M23.
 *
 * Each is a pure function of the payload its endpoint returned: given the same
 * data it produces the same markup, holds no state and touches no element
 * outside the string it builds. The module controller
 * ([pages/insights.js](../pages/insights.js)) owns the period, the tab and the
 * cache; this file owns nothing but what a panel looks like.
 *
 * That split is what lets a panel be re-rendered after a period change without
 * any of them needing to know a period exists.
 *
 * ## Two rules run through all of them
 *
 * **A figure that could not be computed is absent, not zero.** A turnover ratio
 * on a workshop holding no stock, a margin percentage on a labour-only month, a
 * delta with no previous window — each comes back as `null` from the API and is
 * rendered as "—" with a caption, never as `0.00`. A zero is a claim; a dash is
 * an admission.
 *
 * **Every list says what it is ordered by.** These are worklists, not tables
 * somebody sorts: below-cost is worst first, the ageing is oldest first, top
 * items are by margin rather than by revenue. A reader who assumes alphabetical
 * would draw the wrong conclusion from the first three rows.
 */

/* -------------------------------------------------------------------------
 | Shared furniture
 | ---------------------------------------------------------------------- */

const money = (value, sign = false) => esc(formatMoney(value, { sign }));

/** A dash, for a figure that genuinely has no value — never a zero. */
const dash = '<span class="text-muted-foreground">—</span>';

/**
 * "1 variant", "4 variants".
 *
 * Trivial, and worth having in one place: these captions are the sentences a
 * reader actually reads, and "1 lines adjusted" is the kind of thing that makes
 * somebody trust the arithmetic a little less than they should.
 */
const plural = (count, noun) => `${count ?? 0} ${noun}${Number(count) === 1 ? '' : 's'}`;

function card(title, body, { note = '' } = {}) {
    return `
        <section class="surface mb-4 p-4">
            <header class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-[0.9375rem] font-bold text-foreground">${esc(title)}</h3>
                ${note ? `<p class="text-[0.75rem] text-muted-foreground">${esc(note)}</p>` : ''}
            </header>
            ${body}
        </section>`;
}

function tiles(html) {
    return `<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">${html}</div>`;
}

/**
 * A table, or a sentence saying why there is not one.
 *
 * The empty state is a full sentence rather than "No data": on these panels an
 * empty table is usually *good news* — nothing sold below cost, nothing overdue
 * — and a reader has to be able to tell that from a failed fetch.
 */
function table(columns, rows, empty) {
    if (!rows.length) {
        return `<p class="rounded-[10px] border border-dashed border-border px-4 py-6 text-center text-[0.8125rem] text-muted-foreground">${esc(empty)}</p>`;
    }

    return `
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] border-collapse">
                <thead>
                    <tr class="border-b border-border text-left">
                        ${columns.map(([label, extra = '']) => `
                            <th class="px-3 py-2 text-[11.5px] font-semibold whitespace-nowrap text-muted-foreground ${extra}"
                                scope="col">${esc(label)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>${rows.join('')}</tbody>
            </table>
        </div>`;
}

const cell = (content, extra = '') => `<td class="px-3 py-2 text-[0.8125rem] ${extra}">${content}</td>`;
const num = (content) => cell(content, 'text-right font-mono whitespace-nowrap');

/**
 * A margin percentage, coloured by whether it is a margin at all.
 *
 * Negative is rose, because a line sold below what it cost is the one thing on
 * these panels that is unambiguously wrong rather than merely worth noticing.
 */
function marginCell(amount, percent) {
    const negative = String(amount).startsWith('-');

    return num(`
        <span class="${negative ? 'text-rose-700' : 'text-foreground'}">${money(amount, true)}</span>
        <span class="ml-1.5 text-[0.6875rem] text-muted-foreground">${esc(percent)}%</span>`);
}

/* -------------------------------------------------------------------------
 | Overview
 | ---------------------------------------------------------------------- */

const ATTENTION_TONE = {
    bad: 'border-rose-200 bg-rose-50 text-rose-900',
    warn: 'border-amber-200 bg-amber-50 text-amber-900',
    info: 'border-blue-200 bg-blue-50 text-blue-900',
};

export function overviewPanel(data) {
    const headlines = (data.headlines ?? []).map((tile) => statTile({
        label: tile.label,
        value: tile.value,
        // A caption that ends in a comparison gets the figure formatted the way
        // every other amount on the screen is, rather than the raw decimal
        // string the API sends.
        note: tile.compare === undefined ? tile.note : `${tile.note} ${formatMoney(tile.compare, { sign: true })}`,
        delta: tile.delta,
        tone: tile.tone,
        signed: Boolean(tile.signed),
    })).join('');

    return `
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">${headlines}</div>

        ${attentionFeed(data.attention ?? [])}

        ${card('Revenue and margin', columnChart({
        buckets: data.trend?.buckets ?? [],
        series: [
            { key: 'revenue', colour: SERIES.revenue },
            { key: 'margin', colour: SERIES.margin },
        ],
        empty: 'Nothing was billed in this period.',
    }), { note: bucketNote(data.trend) })}

        ${card('Parts against labour', stackedBar(mixSegments(data.mix ?? []), {
        empty: 'Nothing was billed in this period.',
    }), { note: 'Goods carry a cost and labour does not, so each side shows its own margin.' })}

        ${reconciliationBanner(data.reconciliation)}`;
}

/**
 * The exception feed — the part of the overview somebody acts on.
 *
 * Absent entirely when there is nothing in it, rather than rendered as an empty
 * box with a reassuring heading. A panel that says "all clear" every day is a
 * panel people stop reading, and the tiles above already say the month went
 * well.
 */
function attentionFeed(rows) {
    if (!rows.length) return '';

    return `
        <section class="mb-4 grid gap-2 md:grid-cols-2">
            ${rows.map((row) => `
                <button type="button" class="flex items-start gap-3 rounded-[12px] border px-4 py-3 text-left transition hover:opacity-90 ${ATTENTION_TONE[row.tone] ?? ATTENTION_TONE.info}"
                        data-attention="${esc(row.tab)}">
                    <span class="mt-0.5 shrink-0 text-base leading-none" aria-hidden="true">${row.tone === 'bad' ? '⚠' : row.tone === 'warn' ? '●' : 'ℹ'}</span>
                    <span class="min-w-0">
                        <span class="block text-[0.8125rem] font-semibold">${esc(row.title)}</span>
                        <span class="mt-0.5 block text-[0.75rem] opacity-80">
                            ${row.amount ? `<span class="font-mono font-semibold">${money(row.amount)}</span> ` : ''}${esc(row.detail)}
                        </span>
                    </span>
                </button>`).join('')}
        </section>`;
}

/**
 * Whether the bill lines add up to what the ledger says.
 *
 * Shown **even when it agrees**, quietly, because a reader has to be able to
 * tell "nothing to see" from "we did not check" — the same judgement the GST
 * summary's reconciliation makes. When it disagrees it says what that means,
 * because "your revenue is out by ₹1,04,600" with no explanation is alarming in
 * a way the underlying fact usually is not.
 */
function reconciliationBanner(reconciliation) {
    if (!reconciliation) return '';

    if (reconciliation.agrees) {
        return `
            <p class="flex items-center gap-2 px-1 text-[0.75rem] text-muted-foreground">
                <span class="text-emerald-600" aria-hidden="true">✓</span>
                Every rupee of income on the ledger has a bill line behind it, so these panels and the
                profit &amp; loss report the same revenue.
            </p>`;
    }

    return `
        <div class="surface border-amber-200 bg-amber-50 p-4 text-[0.8125rem] text-amber-900">
            <p>
                <span class="font-semibold">The ledger holds more income than the bill lines account for.</span>
                Profit &amp; loss reports ${money(reconciliation.ledger_revenue)} of income; the documents
                behind these panels add up to ${money(reconciliation.document_revenue)}, a difference of
                ${money(reconciliation.difference, true)}.
            </p>
            <p class="mt-1.5 opacity-80">
                Almost always a manual journal posted straight to an income account — which is allowed, because it
                is the correction mechanism for everything else. These panels read the documents, so anything without
                one is invisible to them. Nothing here changes it; it is shown so it stays a decision.
            </p>
        </div>`;
}

function mixSegments(mix) {
    return mix.map((side) => ({
        label: `${side.label} · ${side.margin_percent}% margin`,
        amount: side.revenue,
        share: side.share,
        colour: SERIES[side.key]?.fill ?? SERIES.revenue.fill,
    }));
}

function bucketNote(trend) {
    const granularity = { day: 'day by day', week: 'week by week', month: 'month by month' }[trend?.granularity];

    return granularity ? `Shown ${granularity}.` : '';
}

/* -------------------------------------------------------------------------
 | Sales, and Purchase — one renderer
 | ---------------------------------------------------------------------- */

/**
 * Sales and Purchase are one panel with a direction, exactly as the two modules
 * are one document engine with a direction (§5.1).
 *
 * The asymmetry is the same one the bill form makes: a purchase arrives at a
 * cost it states, so there is no margin to report and nothing can be "below
 * cost". Those two sections are simply absent on that side rather than rendered
 * empty — a "Sold below cost: none" heading on a purchase panel would be
 * answering a question nobody asked.
 */
export function salesPanel(data, { direction = 'sale' } = {}) {
    const isSale = direction === 'sale';
    const totals = data.totals ?? {};
    const noun = isSale ? 'customer' : 'supplier';

    const headline = tiles([
        statTile({
            label: isSale ? 'Revenue' : 'Purchases',
            value: totals.revenue,
            note: `${totals.documents ?? 0} document${totals.documents === 1 ? '' : 's'}, net of returns`,
        }),
        isSale
            ? statTile({
                label: 'Gross margin',
                value: totals.margin,
                note: `${totals.margin_percent ?? '0.00'}% on goods sold`,
                tone: String(totals.margin ?? '0').startsWith('-') ? 'bad' : 'good',
                // Can go either way, so it says which — unlike revenue, which
                // never needs a plus in front of it.
                signed: true,
            })
            : statTile({ label: 'Tax paid', value: totals.tax, note: 'Claimable as input credit' }),
        statTile({
            label: 'Returned',
            value: totals.returns,
            note: `${totals.returns_count ?? 0} credit note${totals.returns_count === 1 ? '' : 's'}`,
            tone: Number(totals.returns) > 0 ? 'warn' : 'neutral',
        }),
        statTile({
            label: isSale ? 'Discount given' : 'Discount received',
            value: totals.discount,
            note: `Average document ${formatMoney(totals.average_document ?? '0')}`,
        }),
    ].join(''));

    const sections = [
        card(isSale ? 'Revenue and margin' : 'What was bought', columnChart({
            buckets: data.trend?.buckets ?? [],
            series: isSale
                ? [{ key: 'revenue', colour: SERIES.revenue }, { key: 'margin', colour: SERIES.margin }]
                : [{ key: 'revenue', colour: SERIES.cost }],
            empty: 'Nothing in this period.',
        }), { note: bucketNote(data.trend) }),

        card('Parts against labour', stackedBar(mixSegments(data.mix ?? []), { empty: 'Nothing in this period.' })),

        card(`Top ${noun}s`, table(
            [['Name'], ['Share'], ['Documents', 'text-right'], [isSale ? 'Revenue' : 'Spend', 'text-right'], ...(isSale ? [['Margin', 'text-right']] : [])],
            (data.top_parties ?? []).map((row) => `
                <tr class="border-b border-border/60">
                    ${cell(`<span class="font-medium">${esc(row.name)}</span>`)}
                    ${cell(`<span class="flex items-center gap-2">${shareBar(row.share)}<span class="w-11 shrink-0 text-right font-mono text-[0.6875rem] text-muted-foreground">${esc(row.share)}%</span></span>`, 'w-48')}
                    ${num(row.documents)}
                    ${num(money(row.revenue))}
                    ${isSale ? marginCell(row.margin, row.margin_percent) : ''}
                </tr>`),
            `No ${noun} has been billed in this period.`,
        ), {
            note: isSale
                ? 'Ordered by margin. A share above about a third is a concentration risk.'
                : 'Ordered by spend.',
        }),

        card('Items', table(
            [['Item'], ['Qty', 'text-right'], [isSale ? 'Revenue' : 'Spend', 'text-right'], ['Cost', 'text-right'], ...(isSale ? [['Margin', 'text-right']] : [])],
            (data.top_items ?? []).map((row) => `
                <tr class="border-b border-border/60">
                    ${cell(`<span class="font-medium">${esc(row.name)}</span>`)}
                    ${num(esc(row.quantity ?? ''))}
                    ${num(money(row.revenue))}
                    ${num(Number(row.cost) === 0 ? dash : money(row.cost))}
                    ${isSale ? marginCell(row.margin, row.margin_percent) : ''}
                </tr>`),
            'Nothing itemised in this period.',
        ), {
            note: isSale
                ? 'Ordered by margin, not revenue — the biggest seller is often not the one paying the rent.'
                : 'Ordered by value.',
        }),
    ];

    if (isSale) {
        sections.push(card('Sold below cost', table(
            [['Date'], ['Document'], ['Customer'], ['Item'], ['Sold for', 'text-right'], ['Cost', 'text-right'], ['Short by', 'text-right']],
            (data.below_cost ?? []).map((row) => `
                <tr class="border-b border-border/60 bg-rose-50/40">
                    ${cell(esc(formatDate(row.date)), 'whitespace-nowrap')}
                    ${cell(esc(row.doc_no ?? `#${row.transaction_id}`), 'font-mono text-[0.75rem]')}
                    ${cell(esc(row.party ?? '—'))}
                    ${cell(esc(row.item ?? row.description ?? '—'))}
                    ${num(money(row.revenue))}
                    ${num(money(row.cost))}
                    ${num(`<span class="font-semibold text-rose-700">${money(row.shortfall)}</span>`)}
                </tr>`),
            'Nothing went out below what it cost. ',
        ), {
            note: 'Worst first. Selling below cost is a decision, not a fault — but it should be one somebody made.',
        }));
    }

    return headline + sections.join('');
}

/* -------------------------------------------------------------------------
 | Stock
 | ---------------------------------------------------------------------- */

export function stockPanel(data) {
    const position = data.position ?? {};
    const turnover = data.turnover ?? {};
    const dead = data.dead ?? {};
    const shrinkage = data.shrinkage ?? {};

    const headline = tiles([
        statTile({ label: 'Stock value', value: position.value, note: plural(position.variants, 'variant') }),
        statTile({
            label: 'Turned over',
            // Null on a workshop holding no stock — a ratio of zero would read
            // as "your stock is not moving" when the truth is there is none.
            value: turnover.ratio === null || turnover.ratio === undefined ? '—' : `${turnover.ratio}×`,
            note: turnover.holding_days == null
                ? 'Not enough stock held to measure'
                : `About ${turnover.holding_days} days on the shelf`,
            formatted: false,
        }),
        statTile({
            label: 'Not moving',
            value: dead.value,
            note: `${plural(dead.variants, 'variant')} idle over ${dead.threshold_days ?? 90} days`,
            tone: Number(dead.value) > 0 ? 'warn' : 'neutral',
        }),
        statTile({
            label: 'Written off',
            value: shrinkage.written_off,
            note: (shrinkage.counts ?? 0) === 0 ? 'No stock-take in this period' : `${shrinkage.counts} stock-take${shrinkage.counts === 1 ? '' : 's'}`,
            tone: Number(shrinkage.written_off) > 0 ? 'warn' : 'neutral',
        }),
    ].join(''));

    return headline
        + card('What the shelf has been worth', areaChart({
            buckets: data.value_trend?.buckets ?? [],
            colour: SERIES.stock,
            empty: 'Not enough movement to plot a trend yet.',
        }), {
            note: 'The closing value at the end of each period, not what moved during it.',
        })

        + card('Needs reordering', table(
            [['Item'], ['Variant'], ['State'], ['On hand', 'text-right'], ['Reorder at', 'text-right'], ['Short by', 'text-right']],
            (data.reorder ?? []).map((row) => `
                <tr class="border-b border-border/60">
                    ${cell(`<span class="font-medium">${esc(row.item ?? '—')}</span>`)}
                    ${cell(esc(row.label ?? row.sku ?? '—'), 'text-muted-foreground')}
                    ${cell(stockStateBadge(row.status))}
                    ${num(esc(row.quantity))}
                    ${num(row.reorder_level === null ? dash : esc(row.reorder_level))}
                    ${num(row.shortfall === null ? dash : esc(row.shortfall))}
                </tr>`),
            'Nothing is at or below its reorder level.',
        ), {
            note: 'Gone and negative first — a part that is out stops a job today.',
        })

        + card('Money sitting still', table(
            [['Item'], ['Variant'], ['On hand', 'text-right'], ['Avg cost', 'text-right'], ['Value', 'text-right'], ['Last sold']],
            (dead.rows ?? []).map((row) => `
                <tr class="border-b border-border/60">
                    ${cell(`<span class="font-medium">${esc(row.item ?? '—')}</span>`)}
                    ${cell(esc(row.label ?? row.sku ?? '—'), 'text-muted-foreground')}
                    ${num(esc(row.quantity))}
                    ${num(money(row.unit_cost))}
                    ${num(`<span class="font-semibold">${money(row.value)}</span>`)}
                    ${cell(row.never_issued
        ? '<span class="text-rose-700">never sold</span>'
        : `${esc(formatDate(row.last_issue))} <span class="text-muted-foreground">· ${row.days_idle}d</span>`, 'whitespace-nowrap')}
                </tr>`),
            'Everything on the shelf has moved recently.',
        ), {
            note: `Nothing issued in ${dead.threshold_days ?? 90} days, most valuable first. Not filtered by the period — money that has not moved since March is not less stuck in August.`,
        })

        + card('Stock-takes', `
            <div class="grid gap-3 sm:grid-cols-3">
                ${statTile({ label: 'Written off', value: shrinkage.written_off, note: 'Counted short', tone: Number(shrinkage.written_off) > 0 ? 'bad' : 'neutral' })}
                ${statTile({ label: 'Found', value: shrinkage.found, note: 'Counted over', tone: 'neutral' })}
                ${statTile({ label: 'Net', value: shrinkage.net, note: `${shrinkage.lines ?? 0} line${shrinkage.lines === 1 ? '' : 's'} adjusted`, tone: String(shrinkage.net ?? '0').startsWith('-') ? 'bad' : 'neutral', signed: true })}
            </div>
            <p class="mt-3 text-[0.75rem] text-muted-foreground">
                Shown apart rather than netted: a workshop that lost ₹40,000 and found ₹38,000 has a counting
                problem, not a ₹2,000 one. A write-off posts to cost of sales, so it does not appear
                separately on the profit &amp; loss — this is the only place it is visible.
            </p>`);
}

const STOCK_STATE = {
    negative: ['bg-rose-50 text-rose-700', 'Negative'],
    out: ['bg-slate-100 text-slate-600', 'Out of stock'],
    low: ['bg-amber-50 text-amber-700', 'Low'],
};

function stockStateBadge(status) {
    const [classes, label] = STOCK_STATE[status] ?? STOCK_STATE.low;

    return `<span class="badge ${classes}">${esc(label)}</span>`;
}

/* -------------------------------------------------------------------------
 | Money owed
 | ---------------------------------------------------------------------- */

export function creditPanel(data) {
    const receivable = data.receivable ?? {};
    const payable = data.payable ?? {};
    const collection = data.collection ?? {};
    const terms = data.terms ?? {};

    const headline = tiles([
        statTile({
            label: 'Owed to the workshop',
            value: receivable.total,
            note: `${receivable.bills ?? 0} open invoice${receivable.bills === 1 ? '' : 's'}`,
            tone: (receivable.oldest_days ?? 0) > 60 ? 'bad' : 'neutral',
        }),
        statTile({
            label: 'The workshop owes',
            value: payable.total,
            note: `${payable.bills ?? 0} open bill${payable.bills === 1 ? '' : 's'}`,
        }),
        statTile({
            label: 'Net position',
            value: data.net,
            note: String(data.net ?? '0').startsWith('-') ? 'Owing more than owed' : 'Owed more than owing',
            tone: String(data.net ?? '0').startsWith('-') ? 'warn' : 'good',
            // Receivable less payable — the other figure with a real direction.
            signed: true,
        }),
        statTile({
            label: 'Collected',
            value: collection.received,
            note: `${collection.efficiency ?? '0.00'}% of ${formatMoney(collection.billed ?? '0')} billed`,
        }),
    ].join(''));

    return headline
        + termsNote(terms, data.as_at)
        + card('How old the money is', ageingChart(receivable), {
            note: 'Not filtered by the period. An unpaid invoice from March is a position, not an event.',
        })
        + card('Who to ring', table(
            [['Customer'], ['Invoices', 'text-right'], ['Oldest'], ['Owed', 'text-right']],
            (receivable.parties ?? []).map((row) => `
                <tr class="border-b border-border/60">
                    ${cell(`<span class="font-medium">${esc(row.name)}</span>`)}
                    ${num(row.count)}
                    ${cell(`${esc(formatDate(row.oldest_date))} <span class="${row.oldest_days > 60 ? 'text-rose-700' : 'text-muted-foreground'}">· ${row.oldest_days}d</span>`, 'whitespace-nowrap')}
                    ${num(`<span class="font-semibold">${money(row.amount)}</span>`)}
                </tr>`),
            'Nothing is outstanding. Every invoice has been settled.',
        ), { note: 'Largest first.' })
        + card('What the workshop owes', ageingChart(payable), {
            note: 'The same arithmetic, the other way round.',
        })
        + unallocatedNote(data.unallocated)
        + creditHeld(data.credit_held);
}

function ageingChart(side) {
    return stackedBar((side.buckets ?? []).map((bucket, index) => ({
        label: `${bucket.label} (${bucket.count})`,
        amount: bucket.amount,
        share: bucket.share,
        colour: AGEING[index] ?? AGEING[AGEING.length - 1],
    })), { empty: 'Nothing outstanding.' });
}

/**
 * What the buckets are measured from.
 *
 * Load-bearing rather than decorative: `payment_due_days` is nullable because a
 * counter trade settles on the spot and has no terms, and an ageing measured
 * from the invoice date means something different from one measured against
 * agreed terms. Leaving it unsaid would have somebody chasing customers who are
 * not late by any agreement anybody made.
 */
function termsNote(terms, asAt) {
    const measured = terms.basis === 'due_date'
        ? `against the workshop's own ${terms.payment_due_days}-day terms`
        : 'from the invoice date, because no payment terms are set on this workshop';

    return `
        <p class="mb-4 px-1 text-[0.75rem] text-muted-foreground">
            Ages measured ${esc(measured)}, as at ${esc(formatDate(asAt))}.
            ${terms.basis === 'due_date' ? '' : 'Set a term on the workshop settings screen and every bucket below moves with it.'}
        </p>`;
}

/**
 * Money received or paid out and never pointed at a document.
 *
 * The figure that reconciles this panel against the Customers module, and the
 * reason the two can differ: the ageing counts **open documents**, a party's
 * balance counts the **ledger**. Bank a cheque without saying which invoice it
 * settles and the customer's balance is already nil while the invoice is still
 * open — both true, about different questions.
 *
 * Absent when there is none, like every other row on these panels. It is a
 * worklist rather than a fault: allocating is a deliberate act, because nothing
 * in this application may guess which invoice somebody meant.
 */
function unallocatedNote(unallocated) {
    if (!unallocated || Number(unallocated.amount) <= 0) return '';

    return `
        <div class="surface mb-4 border-amber-200 bg-amber-50 p-4 text-[0.8125rem] text-amber-900">
            <span class="font-semibold">${money(unallocated.amount)} has been received or paid without being
            allocated to a document.</span>
            Until it is, the invoices it settles stay in the buckets above while the counterparty's balance
            already reflects it — the ageing counts open documents and a balance counts the ledger. Point each
            one at an invoice from its own transaction to clear the difference.
        </div>`;
}

/**
 * Customers who have paid ahead.
 *
 * Deliberately blue rather than amber, and outside the ageing entirely. A
 * negative receivable is money the workshop is *holding*, not a small debt —
 * showing it in the colour that means "chase this" everywhere else would send
 * somebody after money that is already theirs. Absent when there is none.
 */
function creditHeld(held) {
    if (!held || Number(held.parties) === 0) return '';

    return `
        <div class="surface border-blue-200 bg-blue-50 p-4 text-[0.8125rem] text-blue-900">
            <span class="font-semibold">${money(held.amount)} is held on account.</span>
            ${held.parties} customer${Number(held.parties) === 1 ? ' has' : 's have'} paid ahead of what they have
            been billed. That is money the workshop is holding, not a debt — it is outside the buckets above
            because an over-payment is a balance with no invoice behind it.
        </div>`;
}

/* -------------------------------------------------------------------------
 | People
 | ---------------------------------------------------------------------- */

export function peoplePanel(data) {
    const cost = data.cost ?? {};
    const advances = data.advances ?? {};
    const attendance = data.attendance ?? {};

    const headline = tiles([
        statTile({
            label: 'Wages',
            value: cost.gross,
            note: cost.share_of_revenue === null || cost.share_of_revenue === undefined
                ? plural(cost.payslips, 'payslip')
                : `${cost.share_of_revenue}% of revenue`,
        }),
        statTile({
            label: 'Cost per head',
            value: cost.cost_per_head,
            note: `${cost.people ?? 0} paid across ${cost.runs ?? 0} run${cost.runs === 1 ? '' : 's'}`,
        }),
        statTile({
            label: 'Advances out',
            value: advances.outstanding,
            note: `With ${advances.people ?? 0} ${advances.people === 1 ? 'person' : 'people'}`,
            tone: Number(advances.outstanding) > 0 ? 'warn' : 'neutral',
        }),
        statTile({
            label: 'Turned up',
            value: attendance.present_rate === null || attendance.present_rate === undefined
                ? '—'
                : `${attendance.present_rate}%`,
            note: attendance.working_days
                ? `${attendance.working_days} working days marked`
                : 'Nothing marked in this period',
            formatted: false,
        }),
    ].join(''));

    return headline
        + card('The wage bill', columnChart({
            buckets: data.trend?.buckets ?? [],
            series: [{ key: 'value', colour: SERIES.wages }],
            empty: 'No payroll has been posted in this period.',
        }), {
            note: 'Always monthly — payroll is. A run belongs to the month it is for, not the day it was paid.',
        })

        + card('Advances outstanding', table(
            [['Name'], ['Designation'], ['Paid', 'text-right'], ['Recovered', 'text-right'], ['Still out', 'text-right'], ['Of a month', 'text-right']],
            (advances.rows ?? []).map((row) => `
                <tr class="border-b border-border/60">
                    ${cell(`<span class="font-medium">${esc(row.name)}</span>`)}
                    ${cell(esc(row.designation ?? '—'), 'text-muted-foreground')}
                    ${num(money(row.paid))}
                    ${num(money(row.recovered))}
                    ${num(`<span class="font-semibold">${money(row.outstanding)}</span>`)}
                    ${num(row.share_of_pay === null
        ? dash
        : `<span class="${Number(row.share_of_pay) > 100 ? 'text-rose-700' : ''}">${esc(row.share_of_pay)}%</span>`)}
                </tr>`),
            'Nothing is out with anybody.',
        ), {
            note: 'An advance is an asset, recovered from a payslip. Over 100% of a month cannot come out of one.',
        })

        + card('Person by person', table(
            [['Name'], ['Designation'], ['Payslips', 'text-right'], ['Cost', 'text-right'], ['Jobs billed', 'text-right'], ['Work billed', 'text-right']],
            (data.people ?? []).map((row) => `
                <tr class="border-b border-border/60 ${row.is_active ? '' : 'opacity-60'}">
                    ${cell(`<span class="font-medium">${esc(row.name)}</span>${row.is_active ? '' : ' <span class="badge bg-muted text-secondary-foreground">left</span>'}`)}
                    ${cell(esc(row.designation ?? '—'), 'text-muted-foreground')}
                    ${num(row.payslips)}
                    ${num(money(row.cost))}
                    ${num(row.work_jobs || dash)}
                    ${num(Number(row.work_value) === 0 ? dash : money(row.work_value))}
                </tr>`),
            'Nobody is on the payroll yet.',
        ), {
            note: 'Cost and work are side by side and never divided into one another — see the caption below.',
        })

        + `<p class="px-1 pb-2 text-[0.75rem] text-muted-foreground">
                Work billed is what a person was <em>credited with</em> on an invoice, and it is not an input to
                pay. An invoice names at most one person per trade, many of the workshop's people never appear on
                a document at all, and nothing here records hours. A winder with no invoices against them is
                usually the person doing the stripping — which is why these two columns sit beside each other
                rather than being turned into a ratio.
           </p>`

        + card('Attendance', attendanceBars(attendance), {
            note: 'An unmarked day is left unmarked. What silence is worth depends on how somebody is paid, and that is decided once, in the payroll calculator.',
        });
}

function attendanceBars(attendance) {
    const rows = (attendance.counts ?? []).filter((row) => row.days > 0);

    if (!rows.length) {
        return '<p class="rounded-[10px] border border-dashed border-border px-4 py-6 text-center text-[0.8125rem] text-muted-foreground">Nothing has been marked in this period.</p>';
    }

    const total = rows.reduce((sum, row) => sum + row.days, 0);

    return stackedBar(rows.map((row, index) => ({
        label: row.label,
        amount: String(row.days),
        // Days, not rupees — the one caller whose segments are a count.
        display: `${row.days} day${row.days === 1 ? '' : 's'}`,
        share: ((row.days / total) * 100).toFixed(2),
        colour: AGEING[index % AGEING.length],
    })), { empty: 'Nothing marked.' });
}
