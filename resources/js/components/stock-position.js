/**
 * Rolling variant positions up to the thing they are read as.
 *
 * Two screens ask the same question of the same rows. The Items catalogue reads
 * by *family* — "have we got 5 HP motors" — and the Stock module reads by family
 * and then by variant underneath it. Both have to turn a handful of variant
 * positions into one quantity, one value, one average cost and one status, and
 * both have to answer it the same way or the two screens disagree about the same
 * shelf.
 *
 * So the rule lives here once (§4.4). Three parts of it are worth stating,
 * because each is a decision rather than an obvious sum:
 *
 * **Average cost is total value over total quantity**, never the mean of the
 * variants' averages — that would weight a single spare bearing the same as
 * forty of them.
 *
 * **Status is worst-wins**, because the question the column answers is "is there
 * anything here I need to do something about". A family with forty bearings and
 * no capacitors is not three-quarters fine.
 *
 * **Negative is not a kind of low.** A low position is a purchasing decision; a
 * negative one means a sale was recorded before the purchase that supplied it,
 * which is a data problem with a different fix. They are separate statuses with
 * separate colours, because a screen that showed them alike would train people
 * to ignore the second.
 */

/**
 * @typedef {object} Roll
 * @property {number} quantity   summed across the variants
 * @property {number} value      ditto
 * @property {number} variants   how many were rolled up
 * @property {number} low        how many are at or below their reorder level
 * @property {number} negative   how many are below zero
 * @property {number} out        how many are empty
 * @property {Array<object>} positions  the variant rows themselves
 */

/** An empty roll — the shape callers can rely on before any row is added. */
function emptyRoll() {
    return { quantity: 0, value: 0, variants: 0, low: 0, negative: 0, out: 0, positions: [] };
}

/**
 * Sum a set of variant positions into one.
 *
 * Quantities and values are summed as numbers rather than kept as the decimal
 * strings the API sends. That is safe here and nowhere else: these figures are
 * displayed and compared, never posted back, and the API remains the only thing
 * that computes a position.
 *
 * @param {Array<object>} rows  StockPositionResource rows
 * @returns {Roll}
 */
export function rollUpPositions(rows) {
    return (rows ?? []).reduce((roll, row) => {
        roll.quantity += Number(row.quantity ?? 0);
        roll.value += Number(row.value ?? 0);
        roll.variants += 1;

        // Counted as one thing each, in the order that matters: a variant that
        // is negative is not also counted as out, or the three tallies would sum
        // to more than the variants they describe.
        if (row.is_negative) roll.negative += 1;
        else if (row.is_low) roll.low += 1;
        else if (!row.has_stock) roll.out += 1;

        roll.positions.push(row);

        return roll;
    }, emptyRoll());
}

/**
 * Total value over total quantity, as a decimal string — or null where there is
 * nothing on the shelf to have a cost.
 *
 * @param {Roll|null|undefined} roll
 * @returns {string|null}
 */
export function averageCostOf(roll) {
    if (!roll || roll.quantity <= 0) return null;

    return (roll.value / roll.quantity).toFixed(2);
}

/**
 * The worst thing true of any variant in the roll.
 *
 * @param {Roll|null|undefined} roll
 * @returns {'negative'|'out'|'low'|'in_stock'}
 */
export function positionStatus(roll) {
    if (!roll || roll.variants === 0) return 'out';
    if (roll.negative > 0) return 'negative';
    if (roll.quantity <= 0) return 'out';
    if (roll.low > 0) return 'low';

    return 'in_stock';
}

/**
 * The palette. A presentation decision, and legitimately client-side — unlike a
 * status *value*, which is always the server's answer.
 */
export const STOCK_STATUS = {
    in_stock: { label: 'In Stock', chip: 'border-emerald-100 bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    low: { label: 'Low Stock', chip: 'border-amber-100 bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    out: { label: 'Out of Stock', chip: 'border-rose-100 bg-rose-50 text-rose-600', dot: 'bg-rose-500' },
    // Its own badge, never folded into "low". The fix for a negative position is
    // to find the missing purchase, not to order more.
    negative: { label: 'Negative', chip: 'border-rose-200 bg-rose-100 text-rose-700', dot: 'bg-rose-600' },
    // The catalogue's answer for an item that was never on a shelf — labour, a
    // service. Only the Items screen can produce it; Stock never lists one.
    untracked: { label: 'Not stocked', chip: 'border-border bg-muted text-muted-foreground', dot: 'bg-muted-foreground' },
};

/**
 * One status chip. Returns an empty string for an unknown status, so a caller
 * that has nothing to say says nothing rather than showing a blank badge.
 *
 * @param {string|null} status
 * @returns {string} HTML
 */
export function stockStatusBadge(status) {
    const badge = STOCK_STATUS[status];

    if (!badge) return '';

    return `
        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5
                     text-[11.5px] font-semibold ${badge.chip}">
            <span class="size-1.5 rounded-full ${badge.dot}"></span>
            ${badge.label}
        </span>`;
}

/**
 * The sentence a refused reversal, correction or return is explained with.
 *
 * Here rather than in each module for the reason the roll-up above is: there is
 * one way this shelf talks about a shortfall, and two copies of it drift. It was
 * written twice — identically — in the Purchase and Sales drawers before the
 * third caller made that obvious.
 *
 * The shape comes from the server: `ReversalWouldGoNegativeException` and
 * `InvalidReturnException` both send `{ variant, available, unit, requested,
 * shortfall }`, in units rather than money, because somebody standing at a
 * counter with bearings in their hand needs to know how many of them the system
 * will take.
 *
 * @param {Array<{variant: string, available: string, unit: string, requested: string, shortfall: string}>} shortfalls
 */
export function describeShortfalls(shortfalls) {
    if (!shortfalls?.length) return 'More would come off the shelf than is still on it.';

    return shortfalls
        .map((row) => `${row.shortfall} of ${row.variant} has already gone — only ${row.available} `
            + `${row.unit} is left against ${row.requested} to take back.`)
        .join(' ');
}
