/**
 * One status badge for the whole application — the brief's §38.
 *
 * Before this there were four copies of "which colour is a draft" scattered
 * across the pages, and they had already drifted: a reversed transaction was
 * rose on one screen and slate on another. The fix is not a fifth copy that
 * happens to be right, it is one function — and, more importantly, the *tone*
 * coming from the server.
 *
 * ## Why the tone is the API's answer
 *
 * `payment_status_tone` and `status_tone` are sent on the resources that have a
 * status, from `PaymentStatus::tone()` and `WorkshopJobStatus::tone()`. So the
 * decision "overdue is alarming, partial is merely amber" is made once, in the
 * enum that owns the concept, and every screen inherits it. A client-side map
 * from status value to colour would be a second copy of that judgement, and the
 * copy is what drifts the day a status is added.
 *
 * The map below is the *palette* — tone to Tailwind classes — which is a
 * presentation decision and legitimately lives here. {@see fallbackTone} covers
 * the statuses that predate the tone field (a transaction's draft / posted /
 * reversed), so nothing has to be rewritten server-side before this is useful.
 */

import { esc } from '../ui';

/**
 * Four words, named for what they mean rather than for a colour, so a change of
 * palette is one change here rather than a search across the pages.
 */
const TONES = {
    neutral: 'bg-muted text-muted-foreground',
    info: 'bg-sky-100 text-sky-800',
    success: 'bg-emerald-100 text-emerald-800',
    warning: 'bg-amber-100 text-amber-800',
    danger: 'bg-rose-100 text-rose-700',
};

export function toneClass(tone) {
    return TONES[tone] ?? TONES.neutral;
}

/**
 * A badge, as HTML.
 *
 * Everything interpolated goes through `esc`, because a status label can come
 * from the server and a description on a warning can come from a user.
 */
export function badge(label, tone = 'neutral', { title = null } = {}) {
    if (label === null || label === undefined || label === '') return '';

    return `<span class="badge ${toneClass(tone)}"${title ? ` title="${esc(title)}"` : ''}>${esc(label)}</span>`;
}

/**
 * The tone for the statuses that do not carry one yet — a transaction's
 * lifecycle, and the document kinds a bills list colours by.
 *
 * A lookup rather than a `tone` field on TransactionResource, and the reason is
 * that these are not really statuses in the same sense: `draft` versus `posted`
 * is about whether a record exists in the ledger, not about whether anybody
 * needs to do something. Giving them server-side tones would invite them to be
 * read as urgency.
 */
const LIFECYCLE = {
    // Amber, because a draft is unfinished work and somebody has to come back to
    // it — the same sense in which a partly-paid invoice is amber. This is what
    // the journal and accounts screens already showed; what they disagreed about
    // was `reversed`, which was neutral on one and rose on the other.
    draft: 'warning',
    posted: 'success',
    reversed: 'danger',
};

export function lifecycleTone(status) {
    return LIFECYCLE[status] ?? 'neutral';
}

/**
 * Where a transaction came from — typed, imported, or read by a machine.
 *
 * Categories, like {@see kindBadge}, and not from the status vocabulary: an
 * AI-captured document is not more urgent than a typed one. It is worth a badge
 * at all only where it changes what somebody does next — a draft an OCR pass
 * produced needs checking against the paper in a way one somebody typed does
 * not.
 */
const SOURCES = {
    manual: 'bg-muted text-muted-foreground',
    import: 'bg-violet-50 text-violet-700',
    ai: 'bg-sky-50 text-sky-700',
};

export function sourceBadge(source, label) {
    return `<span class="badge ${SOURCES[source] ?? 'bg-muted text-muted-foreground'}">${esc(label ?? source)}</span>`;
}

/**
 * What kind of document a row is — sale, purchase, expense, credit note.
 *
 * Deliberately *not* from the tone vocabulary above. These are categories rather
 * than states: nothing is more urgent about a purchase than about a sale, and
 * using the status palette for them would make a bills list look as though half
 * of it needed attention. They are told apart by hue alone.
 */
const KINDS = {
    sale: 'bg-emerald-50 text-emerald-700',
    purchase: 'bg-sky-50 text-sky-700',
    expense: 'bg-purple-50 text-purple-700',
    sales_return: 'bg-orange-50 text-orange-700',
    purchase_return: 'bg-orange-50 text-orange-700',
    receipt: 'bg-teal-50 text-teal-700',
    payment: 'bg-indigo-50 text-indigo-700',
    journal: 'bg-slate-100 text-slate-700',
    stock_adjustment: 'bg-slate-100 text-slate-700',
    opening: 'bg-slate-100 text-slate-700',
};

export function kindBadge(type, label) {
    return `<span class="badge ${KINDS[type] ?? 'bg-muted text-muted-foreground'}">${esc(label ?? type)}</span>`;
}

/**
 * A quantity with its unit, always together — §38's other consistency rule.
 *
 * "4" on a stock screen is a number somebody has to guess the meaning of; "4 PCS"
 * is a fact. Trailing zeros are trimmed, because `4.000` reads as a measurement
 * that was taken to the gram when it was counted on a shelf.
 */
export function formatQuantity(quantity, unitSymbol = '') {
    if (quantity === null || quantity === undefined || quantity === '') return '—';

    const number = Number(quantity);

    /*
    | One rule for every quantity on the screen. The API sends three decimals
    | because the column has three, and `toLocaleString` drops the ones that say
    | nothing — "3" for three bearings, "2.5" for two and a half kilograms — while
    | grouping the thousands, so 12000 kg of scrap reads as 12,000 rather than as
    | a number somebody has to count the digits of.
    |
    | Anything that is not a number is shown as it arrived. A NaN on a stock
    | screen is worse than an odd-looking string, because it looks like zero.
    */
    const text = Number.isFinite(number)
        ? number.toLocaleString('en-IN', { maximumFractionDigits: 3 })
        : String(quantity);

    return unitSymbol ? `${text} ${unitSymbol}` : text;
}
