/**
 * Changing what is on the shelf — the one act, wherever it is reached from.
 *
 * Lifted out of `pages/stock.js` in P5, because the Items drawer needs the same
 * act against a variant it already knows about, and a second copy of a form that
 * writes to the stock ledger is the one thing this codebase refuses everywhere
 * else (§5.1). Everything it does still goes through
 * `POST /transactions/stock-adjustment`: there is no way to write a position
 * directly, here or anywhere, and every guarantee M8 makes rests on that.
 *
 * ## Two modes over one form
 *
 * **`count`** — Stock's stock-take, unchanged. Several lines, a variant chosen
 * per line, and the *difference* the count found typed in, signed.
 *
 * **`variant`** — Items, against one known variant. The operator types **what is
 * on the shelf** and this works out the difference from the position the books
 * already hold.
 *
 * That subtraction is the reason the two are one component. "Two fewer than the
 * books say" and "two on the shelf" are different numbers that post different
 * documents, and the conversion between them is arithmetic on a quantity — which
 * this application keeps in one place on principle, and which is done here in
 * integer thousandths rather than with `-` on two parsed floats.
 *
 * The markup is `resources/views/partials/stock-adjust.blade.php`, included by
 * whichever module wants it. This module attaches to whatever is on the page and
 * does nothing at all when the partial is absent.
 */

import auth from '../auth-client';
import { formatQuantity } from './badge';
import {
    $, $$, clearFormErrors, esc, formatMoney, hideModal, setSubmitting, showFormErrors, showModal, toast,
} from '../ui';

/* -------------------------------------------------------------------------
 | Quantities, subtracted without floats
 | ---------------------------------------------------------------------- */

/** The column is DECIMAL(15, 3), so this is the scale everything works in. */
const PLACES = 3;
const SCALE = 1000;

/**
 * A decimal string as an integer count of thousandths — "1.5" is 1500.
 *
 * `Number` rather than `BigInt` and that is safe rather than lucky: the widest
 * quantity the column can hold is under 10^12, which scaled is under 10^15 and
 * so inside `Number.MAX_SAFE_INTEGER`. Every value in between is an integer, so
 * every subtraction of two of them is exact — which `12.3 - 4.1` is not.
 *
 * @returns {number|null} Null when the text is not a quantity at all.
 */
function thousandths(value) {
    const text = String(value ?? '').trim();

    if (text === '' || !/^[-+]?\d*\.?\d*$/.test(text) || !/\d/.test(text)) return null;

    const negative = text.startsWith('-');
    const [whole = '', fraction = ''] = text.replace(/^[-+]/, '').split('.');

    // Truncated rather than rounded past the third place, and then refused by
    // `decimal:0,3` on the way in — a quantity somebody typed is not a figure to
    // quietly improve.
    if (fraction.length > PLACES) return null;

    const digits = Number(`${whole || '0'}${`${fraction}${'0'.repeat(PLACES)}`.slice(0, PLACES)}`);

    return negative ? -digits : digits;
}

/** Integer thousandths back to the decimal string the API is sent. */
function fromThousandths(units) {
    const sign = units < 0 ? '-' : '';
    const absolute = Math.abs(units);
    const fraction = String(absolute % SCALE).padStart(PLACES, '0').replace(/0+$/, '');

    return `${sign}${Math.trunc(absolute / SCALE)}${fraction ? `.${fraction}` : ''}`;
}

/* -------------------------------------------------------------------------
 | State
 | ---------------------------------------------------------------------- */

const state = {
    mode: 'count',

    /** `count`: what the line's picker offers, as `{ id, label }`. */
    variants: [],

    /** `variant`: the one this is against, and what the books say about it. */
    variant: null,
    unit: '',
    current: 0,          // thousandths
    averageCost: null,   // null when the books hold nothing to value found stock at

    /*
    | One `client_ref` per document, minted when the dialog opens and reused on
    | every retry — the rule C2 set for every write form in this application. A
    | stock adjustment is the case it matters most for: a request that times out
    | after the server has posted would, without this, move the same shelf twice
    | and leave a workshop's books disagreeing with its own count.
    */
    clientRef: null,

    onPosted: async () => {},
};

let lineSeq = 0;

/* -------------------------------------------------------------------------
 | Mode: count
 | ---------------------------------------------------------------------- */

function adjustmentLine() {
    const id = ++lineSeq;

    const options = state.variants
        .map((row) => `<option value="${esc(String(row.id))}">${esc(row.label)}</option>`)
        .join('');

    return `
        <div class="grid gap-2 rounded-[10px] border border-border p-3 sm:grid-cols-[2fr_1fr_1fr_auto]" data-line="${id}">
            <label class="field">
                <span class="field-label">Variant</span>
                <select name="variant_id" class="field-input" required>
                    <option value="">Choose…</option>
                    ${options}
                </select>
            </label>

            <label class="field">
                <span class="field-label">Difference</span>
                <input type="text" class="field-input font-mono" inputmode="decimal"
                       placeholder="-2" autocomplete="off" data-line-quantity required>
            </label>

            <label class="field">
                <span class="field-label">Cost, if found</span>
                <input type="text" class="field-input font-mono" inputmode="decimal"
                       placeholder="Leave blank" autocomplete="off" data-line-cost>
            </label>

            <button type="button" class="btn btn-ghost btn-icon self-end" data-remove-line
                    aria-label="Remove this line">×</button>

            <!-- Stamped with its payload index at submit, so a refusal about the
                 fourth line lands on the fourth line. -->
            <div class="field-error hidden sm:col-span-4" data-line-error></div>
        </div>`;
}

/* -------------------------------------------------------------------------
 | Mode: variant
 | ---------------------------------------------------------------------- */

/** What the books say, said plainly, above the box that disagrees with it. */
function paintVariantHeader() {
    const form = $('#stock-adjust-form');

    $('#stock-adjust-variant-label', form).textContent = state.variant?.label ?? '';

    const held = formatQuantity(fromThousandths(state.current), state.unit);

    $('#stock-adjust-variant-position', form).textContent = state.current === 0
        ? 'The books say there is none of this on the shelf.'
        : `The books say ${held}${
            state.averageCost === null ? '' : `, at ${formatMoney(state.averageCost)} average`}.`;
}

/**
 * The difference the count implies, in words, before it is posted.
 *
 * This is the number that actually reaches the ledger, and the one thing this
 * mode never asks anybody to work out — so it is shown rather than trusted to
 * arithmetic done in somebody's head against a figure on the line above.
 */
function paintDifference() {
    const form = $('#stock-adjust-form');
    const line = $('#stock-adjust-difference', form);
    const cost = $('[data-adjust-cost]', form);

    const counted = thousandths(form.elements.counted.value);
    const delta = counted === null ? null : counted - state.current;

    // A cost is only ever read for stock that was found, so the box appears only
    // when there is found stock to value.
    cost.classList.toggle('hidden', !(delta !== null && delta > 0));

    if (delta === null || delta === 0) {
        line.classList.add('hidden');
        line.textContent = '';

        return;
    }

    const moved = formatQuantity(fromThousandths(Math.abs(delta)), state.unit);

    line.className = 'rounded-[10px] px-3.5 py-2.5 text-[0.8125rem] '
        + (delta > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700');

    line.textContent = delta > 0
        ? `${moved} more than the books say. It is taken onto the shelf as found stock.`
        : `${moved} fewer than the books say. It is written off at what the books were carrying it at.`;

    /*
    | Said before it is submitted rather than come back as a refusal.
    |
    | With no position to average against, the service values found stock at
    | whatever the variant last cost — and a variant never bought has no such
    | rate, which makes the whole document worth nothing and
    | `STOCK_ADJUSTMENT_VALUELESS` refuses it. That refusal is the right one:
    | quantities moving with no accounting trace behind them is the drift M8
    | exists to prevent. It is just a poor way to find out.
    */
    if (delta > 0 && state.averageCost === null) {
        line.textContent += ' There is nothing on the shelf to average against, so say what the found'
            + ' stock was worth. Left blank it comes on at whatever it last cost, and if it has never'
            + ' been bought there is no such figure and this will be refused.';
    }
}

/* -------------------------------------------------------------------------
 | Opening
 | ---------------------------------------------------------------------- */

/**
 * Show the dialog in one of its two modes.
 *
 * @param {object}   options
 * @param {'count'|'variant'} options.mode
 * @param {Array<{id: number|string, label: string}>} [options.variants]  `count`: the picker's options.
 * @param {{id: number|string, label: string}} [options.variant]          `variant`: the one being counted.
 * @param {string}   [options.unit]         Its unit's symbol, for the figures on screen.
 * @param {string|number} [options.current] What the books say is on the shelf.
 * @param {string|number|null} [options.averageCost] What they are carrying it at, or null.
 * @param {() => Promise<void>} [options.onPosted]   Run after a successful post.
 */
export function openStockAdjust({
    mode = 'count',
    variants = [],
    variant = null,
    unit = '',
    current = 0,
    averageCost = null,
    onPosted = async () => {},
} = {}) {
    const form = $('#stock-adjust-form');
    if (!form) return;

    Object.assign(state, {
        mode,
        variants,
        variant,
        unit,
        current: thousandths(current) ?? 0,
        averageCost,
        clientRef: crypto.randomUUID(),
        onPosted,
    });

    clearFormErrors(form);
    form.reset();
    form.elements.date.value = new Date().toISOString().slice(0, 10);

    $$('[data-adjust-mode]', form).forEach((section) =>
        section.classList.toggle('hidden', section.dataset.adjustMode !== mode));

    $('#stock-adjust-title', form).textContent = mode === 'variant' ? 'Set what is on the shelf' : 'Record a count';

    $('#stock-adjust-hint', form).textContent = mode === 'variant'
        ? 'Type what the count actually found. The difference from what the books say is what gets posted, '
            + 'and it is shown before you commit to it.'
        : 'Enter the difference the count found — −2 for two fewer than the books say, +1 for one more. '
            + 'A shortage is written off at what the books were carrying it at; found stock needs a cost.';

    if (mode === 'variant') {
        paintVariantHeader();
        paintDifference();
    } else {
        lineSeq = 0;
        $('#stock-adjust-lines', form).innerHTML = adjustmentLine();
    }

    showModal('#stock-adjust-modal');
}

/* -------------------------------------------------------------------------
 | Posting
 | ---------------------------------------------------------------------- */

/**
 * The lines to post, each paired with the block it came from.
 *
 * The pairing is what makes a per-line refusal land on the right line: the
 * server indexes `adjustments.*` by position in what it was sent, and this drops
 * the blank rows somebody left behind, so the fourth block on screen is very
 * often not the fourth line in the payload.
 *
 * @returns {Array<{row: object, block: Element|null}>}
 */
function collect(form) {
    if (state.mode === 'variant') {
        const counted = thousandths(form.elements.counted.value);
        const cost = form.elements.unit_cost.value.trim();
        const delta = counted === null ? 0 : counted - state.current;

        if (delta === 0) return [];

        return [{
            row: {
                variant_id: state.variant.id,
                quantity: fromThousandths(delta),
                unit_cost: delta > 0 && cost !== '' ? cost : null,
            },
            block: null,
        }];
    }

    return $$('#stock-adjust-lines [data-line]', form)
        .map((block) => ({
            row: {
                variant_id: Number($('[name=variant_id]', block).value),
                quantity: $('[data-line-quantity]', block).value.trim(),
                unit_cost: $('[data-line-cost]', block).value.trim() || null,
            },
            block,
        }))
        .filter(({ row }) => row.variant_id && row.quantity !== '');
}

/**
 * Say why there is nothing to post, in the words of whichever mode asked.
 *
 * Both are things the server would also refuse, and neither would come back
 * naming a field this form shows — `adjustments` is empty in one and a zero
 * `quantity` in the other, which is a difference the operator never typed.
 */
function refuseEmpty(form) {
    showFormErrors(form, state.mode === 'variant'
        ? {
            fields: {
                counted: [thousandths(form.elements.counted.value) === null
                    ? 'Say what the count found.'
                    : 'That is what the books already say. Nothing needs correcting.'],
            },
        }
        : { fields: { adjustments: ['Say what the count found — at least one variant and the difference.'] } });
}

/**
 * A refusal about the one line this mode built, put back on the box it was typed
 * into — the mirror of the scoping `collect()` did on the way out.
 */
function rekey(error) {
    if (state.mode === 'variant' && error?.fields?.['adjustments.0.quantity']) {
        error.fields.counted = error.fields['adjustments.0.quantity'];
    }

    return error;
}

async function submit(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);

    const lines = collect(form);

    if (!lines.length) {
        refuseEmpty(form);

        return;
    }

    /*
    | Each block learns its position in the payload, so `showFormErrors` can walk
    | `adjustments.3.quantity` down onto the block that actually holds it.
    |
    | Every stamp is dropped first, including from the blocks this attempt left
    | out. A block emptied since the last try would otherwise still answer to an
    | index that now belongs to a different line, and `errorSlot` takes the first
    | match in the document — so the message would appear on the wrong one.
    */
    $$('[data-line-error]', form).forEach((slot) => slot.removeAttribute('data-error-for'));

    lines.forEach(({ block }, index) => {
        if (block) $('[data-line-error]', block).setAttribute('data-error-for', `adjustments.${index}`);
    });

    setSubmitting(form, true, 'Posting…');

    try {
        await auth.call('/transactions/stock-adjustment', {
            method: 'POST',
            body: {
                date: form.elements.date.value,
                notes: form.elements.notes.value.trim() || null,

                // Never defaulted. Committing to the ledger is the consequential
                // act, and this screen is explicit about doing it.
                post: true,
                client_ref: state.clientRef,
                adjustments: lines.map(({ row }) => row),
            },
        });

        hideModal('#stock-adjust-modal');
        toast('The count is recorded and the books are updated.');

        await state.onPosted();
    } catch (error) {
        showFormErrors(form, rekey(error));
    } finally {
        setSubmitting(form, false, 'Post the correction');
    }
}

/* -------------------------------------------------------------------------
 | Wiring
 | ---------------------------------------------------------------------- */

/**
 * Wire the shared dialog, once per module. Safe to call where the partial is not
 * included — it simply does nothing.
 *
 * No Escape handling of its own: `showModal` keeps a stack and the shell's
 * handler closes whichever dialog is on top, which is the whole reason this can
 * open over the Items drawer without taking the drawer with it. Cancelling has
 * no side effect to undo, so `data-modal-close` is the whole of the close path.
 */
export function initStockAdjust() {
    const form = $('#stock-adjust-form');

    if (!form) return;

    form.addEventListener('submit', submit);

    $('#stock-adjust-add-line', form).addEventListener('click', () => {
        $('#stock-adjust-lines', form).insertAdjacentHTML('beforeend', adjustmentLine());
    });

    $('#stock-adjust-lines', form).addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');

        // Never the last one: an empty form with no way to add a line back
        // without closing and reopening is worse than a line you can ignore.
        if (remove && $$('#stock-adjust-lines [data-line]', form).length > 1) {
            remove.closest('[data-line]').remove();
        }
    });

    // Live, because the difference is the number being decided and a figure that
    // only appears on blur is a figure somebody posts without having read.
    form.elements.counted.addEventListener('input', paintDifference);
}
