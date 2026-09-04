import { formatMoney, isZeroAmount } from '../ui';

/**
 * What a counterparty's position comes to, and which way it points.
 *
 * ## Why this is a component
 *
 * The Customers and Vendors screens worked this out first — a row's amount, a
 * badge, a drawer tile — and the arithmetic is three lines. The party picker on
 * every bill form needs the same answer at the moment somebody is picked, and
 * three lines is exactly the size of thing that gets retyped instead of
 * imported. §4.4: one place decides what an amount *means*, the way
 * `components/stock-position.js` decides what a shelf's quantity means.
 *
 * ## The sign is the whole meaning
 *
 * `outstanding` is signed towards the workshop, per side. A positive receivable
 * is a customer who owes; a **negative** one is a customer who has paid ahead,
 * and the workshop is holding their money. Those are opposite facts and they
 * must never share a colour: −₹5,000 shown in the amber that everywhere else
 * means "chase this" sends somebody after money they already have.
 *
 * ## Absent is not zero
 *
 * `party.outstanding` is `null` when nobody asked for it — the list endpoint
 * only computes it under `with_position=1`. Rendering that as "Nil" would tell a
 * reader the account is settled when in fact nothing was looked up, which is the
 * one wrong answer here that reads as reassurance. Every predicate below is
 * false for a null, and {@see describePosition} returns null so a caller paints
 * nothing at all.
 */

/** The side of the position this screen is about, or null when unfetched. */
export function positionFor(party, side) {
    return party?.outstanding?.[side] ?? null;
}

/** Something is owed on this side — a debt, not an advance and not nothing. */
export function isOwing(amount) {
    return amount !== null && amount !== undefined
        && !isZeroAmount(amount) && !String(amount).trim().startsWith('-');
}

/**
 * The position has gone the other way: a customer who has paid more than they
 * have been billed, or a supplier the workshop has overpaid.
 *
 * Its own case, never a kind of "outstanding".
 */
export function isAdvance(amount) {
    return amount !== null && amount !== undefined
        && !isZeroAmount(amount) && String(amount).trim().startsWith('-');
}

export function hasBalance(amount) {
    return isOwing(amount) || isAdvance(amount);
}

/** An advance is shown as a positive figure under a label that says which way. */
export function absolute(amount) {
    return String(amount ?? '0').trim().replace(/^-/, '');
}

/**
 * The position as one sentence, for a surface with room for a sentence and not
 * a tile — the party picker on a bill form, which is the only one so far.
 *
 * Returns null when there is nothing to say: the position was never fetched, or
 * it is genuinely nil. A picker that printed "Nothing outstanding" under every
 * walk-in would be noise on the field somebody is trying to leave.
 *
 * @param {object|null} party
 * @param {'customer'|'vendor'} role  which half of the relationship is being written
 * @returns {{tone: 'due'|'advance', amount: string, text: string}|null}
 */
export function describePosition(party, role) {
    const side = role === 'vendor' ? 'payable' : 'receivable';
    const amount = positionFor(party, side);

    if (isAdvance(amount)) {
        return {
            tone: 'advance',
            amount: absolute(amount),
            // Whose money it is, not merely which way the number leans. A
            // customer in credit is a reason to set the new invoice against
            // what is already held rather than to ask for cash.
            text: role === 'vendor'
                ? `₹${formatMoney(absolute(amount))} paid ahead — this bill can come off the advance.`
                : `₹${formatMoney(absolute(amount))} in credit — this invoice can come off what they have already paid.`,
        };
    }

    if (!isOwing(amount)) return null;

    return {
        tone: 'due',
        amount,
        text: role === 'vendor'
            ? `₹${formatMoney(amount)} already owed to them on earlier bills.`
            : `₹${formatMoney(amount)} already outstanding on earlier invoices.`,
    };
}
