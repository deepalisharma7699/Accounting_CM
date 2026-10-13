/**
 * How the money moved — the brief's §13.
 *
 * Mode chips rather than a `<select>`, because at a counter the answer is
 * usually "cash" and a chip is one tap where a select is three. Several rows,
 * because "two thousand cash and the rest on UPI" is one payment in one visit,
 * and splitting it into two documents would put two receipts in a ledger that
 * only saw one customer.
 *
 * ## The settlement state is chosen, and the rows obey it
 *
 * On credit, part paid, paid in full. This used to work the other way round:
 * the state was *derived* from whatever the amount boxes added up to, and the
 * chips were only a shortcut for filling them in. That left the three choices
 * disconnected from the boxes under them, and each disconnection was a way to
 * post the wrong figure:
 *
 * - **On credit** still showed a mode and an amount box, asking how a payment
 *   that did not happen was made.
 * - **Paid in full** filled the box once and then left it editable — and left
 *   it behind when a line was added afterwards, so a "paid in full" bill
 *   quietly became a part-paid one with nothing on screen saying so.
 * - **Part paid** accepted the whole total, or nothing at all.
 *
 * So the state is held, and it decides what is on screen:
 *
 * - **credit** — no rows at all. Nothing moved, so there is nothing to ask.
 * - **full** — the last row's amount is not typed: it *is* the total, less any
 *   other rows of a split, and it follows the total as lines change. A split
 *   is "this much in cash, the rest on UPI": type the first, the rest fills.
 * - **part** — the amounts are typed, and must come to more than nothing and
 *   less than the total. Either end is a different state, and `problem()` says
 *   which.
 *
 * `problem()` is display-side only: the server checks the same arithmetic and
 * its refusal is the one that counts (§6.1). This is what stops somebody
 * pressing Post on a figure the screen could already have told them was wrong.
 *
 * ## Why "On credit" is opt-in
 *
 * It is offered where a document may legitimately go unsettled — a bill. It is
 * not offered where the split *is* the event: a receipt against an invoice and
 * an expense both record money that has already moved, and "on credit" there
 * would be a button for submitting nothing. Hence `canCredit`, off by default.
 *
 * ## When "Paid in full" is the only answer
 *
 * `mustSettle` is a host saying that, right now, nothing less will post — a
 * sale with no customer named (`TransactionType::admitsWalkIn()`). The other
 * chips are disabled with the reason beside them rather than hidden, so the
 * choice is visibly there and visibly unavailable, and naming a customer gives
 * it back.
 *
 * ## Paid in full, for less
 *
 * `settleShort` is a host saying that "paid in full" may be at a smaller figure
 * than the total — ₹1,200 handed over for a ₹1,250 bill and the counter calling
 * it square. The remainder box is then typed rather than locked, and a figure
 * below the total is reported to the host, which asks the server to take the
 * difference off as a discount. The box is never rewritten while it has the
 * caret, because the total it is measured against is about to move under it.
 */

import { $, $$, esc, formatMoney } from '../ui';

/** Money compared in paise, so 0.1 + 0.2 is never a part payment. */
const paise = (value) => Math.round((Number(value) || 0) * 100);
const rupees = (amount) => (amount / 100).toFixed(2);

/**
 * "Paid in full, for less" against a figure that is already fixed — what is
 * still owing on a *posted* invoice, collected from its drawer.
 *
 * Unlike the bill document there is nothing to re-price: the invoice and its GST
 * stand as issued, and what the party is let off is simply what was owing less
 * what they paid. The server writes that off after tax (Discount Allowed or
 * Discount Received) and refuses it if it is more than is owing. One helper for
 * both the Sales and the Purchase drawer, so the subtraction lives in one place.
 *
 * @param {string} due  What is still owing on the document, as a decimal string.
 * @returns {{ outstanding: () => string, settleShort: object, discount: () => string|null }}
 */
export function settleAgainst(due) {
    let settled = null;

    return {
        outstanding: () => settled ?? due,
        settleShort: {
            before: () => (settled === null ? null : due),
            onSettle: (amount) => {
                settled = amount;
            },
        },
        /** What the party is let off, or null where they paid what was owing. */
        discount: () => (settled === null ? null : rupees(paise(due) - paise(settled))),
    };
}

/**
 * @param {HTMLElement} host
 * @param {object} options
 * @param {Array<{value: string, label: string, reference_label?: string, requires_reference?: boolean}>} options.modes
 *        From GET /transactions/meta.
 * @param {() => string} options.outstanding   The document total, as a decimal string.
 * @param {() => void} [options.onChange]
 * @param {string} [options.heading]
 */
export function mountPaymentRows(host, {
    modes = [],
    outstanding = () => '0.00',
    onChange = () => {},
    heading = 'Collected now',
    /*
    | Whose account the unpaid remainder lands on, and what the act is called.
    |
    | Parameterised rather than written, because this component is mounted on
    | both directions of the same document and the sale's words were showing on
    | the purchase: a vendor bill telling somebody it "goes on the customer's
    | account" names the wrong party in the one sentence about who owes whom.
    */
    noun = 'customer',
    verb = 'collected',
    /*
    | Whether this document may be left unsettled.
    |
    | Off by default, so the hosts that record money which has already moved —
    | the receipt drawer, the expense form, payroll — are never offered it. A
    | bill opts in.
    */
    canCredit = false,
    /*
    | Whether there is a *document* being settled at all.
    |
    | True everywhere it was first written: a bill, a receipt, an expense — each
    | has a total, and the split is measured against it. False for M22's staff
    | advance and M16's settlement, where the split **is** the amount. There is no
    | figure to compare it with, so the three state chips have nothing to mean
    | and the rows are simply typed.
    */
    settlesADocument = true,
    /*
    | The state a fresh document starts in — a value or a thunk, read on every
    | reset, because the bill document changes direction under a mounted
    | component. A purchase starts on credit; money that has already moved
    | starts paid in full, which is what the expense form and payroll require
    | and what a receipt usually is.
    */
    initial = canCredit ? 'credit' : 'full',
    /** A thunk: true while nothing short of "Paid in full" will post. */
    mustSettle = () => false,
    /** Why, said beside the disabled chips. A value or a thunk. */
    mustSettleReason = '',
    /*
    | Whether "Paid in full" may be at less than the total — see above. Null, or
    | `{ before, onSettle }`: `before()` is what the document came to before it
    | was settled short (null while it was not), and `onSettle(amount)` is told
    | the figure paid, or null when it is the whole of the bill again.
    */
    settleShort = null,
} = {}) {
    // Either may be a thunk: the bill document switches between sale and
    // purchase without remounting, so the words have to be read when they are
    // painted rather than captured here.
    const say = (value) => (typeof value === 'function' ? value() : value);

    const startState = () => {
        const wanted = say(initial);

        return wanted === 'credit' && !canCredit ? 'full' : wanted;
    };

    let settlement = settlesADocument ? startState() : null;

    host.innerHTML = `
        <div data-payments>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-foreground" data-pay-heading>${esc(say(heading))}</h3>

                <button type="button" class="btn btn-ghost btn-sm" data-pay-add>+ Split</button>
            </div>

            ${settlesADocument ? `
            <div class="mt-2 flex flex-wrap gap-1.5" role="group"
                 aria-label="How this document is being settled">
                ${canCredit
                    ? '<button type="button" class="pill" data-pay-state="credit" aria-pressed="false">'
                        + 'On credit</button>'
                    : ''}
                <button type="button" class="pill" data-pay-state="part" aria-pressed="false">Part paid</button>
                <button type="button" class="pill" data-pay-state="full" aria-pressed="false">Paid in full</button>
            </div>
            <p class="mt-1.5 hidden text-[0.8125rem] text-muted-foreground" data-pay-locked></p>` : ''}

            <div class="mt-3 space-y-3" data-pay-rows></div>

            <p class="mt-2 text-[0.8125rem]" data-pay-summary></p>
            <p class="field-error" data-error-for="payments"></p>
        </div>`;

    const rows = $('[data-pay-rows]', host);
    const summary = $('[data-pay-summary]', host);
    const addButton = $('[data-pay-add]', host);
    const lockedLine = $('[data-pay-locked]', host);

    const modeOf = (value) => modes.find((mode) => mode.value === value) ?? null;

    const chips = (selected) => modes.map((mode) => `
        <button type="button" class="pill" data-mode="${esc(mode.value)}"
                aria-pressed="${mode.value === selected}">${esc(mode.label)}</button>`).join('');

    const allRows = () => $$('[data-payment]', rows);

    /**
     * Cash has nothing to reference, so it asks for nothing. Every other mode
     * asks for the thing somebody is actually holding — "Cheque number", not
     * "Reference" — and says when it is required rather than merely useful.
     */
    const paintReference = (row) => {
        const mode = modeOf($('[name=mode]', row).value);
        const field = $('[data-pay-reference]', row);
        const shown = mode !== null && mode.value !== 'cash';

        field.classList.toggle('hidden', !shown);

        if (!shown) return;

        const label = mode.reference_label ?? 'Reference';

        $('[data-pay-reference-label]', row).textContent = mode.requires_reference ? label : `${label} (optional)`;
        $('[name=reference]', row).placeholder = mode.requires_reference ? 'Required' : '';
    };

    const rowMarkup = ({ mode, amount, reference }) => `
        <div class="rounded-[10px] border border-border p-3" data-payment>
            <div class="flex flex-wrap gap-1.5" data-mode-chips>${chips(mode)}</div>
            <input type="hidden" name="mode" value="${esc(mode)}">

            <div class="mt-2.5 flex flex-wrap items-end gap-2">
                <label class="field w-full sm:w-44">
                    <span class="field-label" data-pay-amount-label>Amount</span>
                    <input type="text" name="amount" class="field-input text-right font-mono"
                           inputmode="decimal" value="${esc(amount)}" placeholder="0.00">
                </label>

                <label class="field min-w-0 flex-1" data-pay-reference>
                    <span class="field-label" data-pay-reference-label>Reference</span>
                    <input type="text" name="reference" class="field-input" maxlength="100"
                           value="${esc(reference)}">
                </label>

                <button type="button" class="btn btn-ghost btn-icon ml-auto" data-pay-remove
                        aria-label="Remove this payment">×</button>
            </div>
        </div>`;

    /**
     * A new row. In "Paid in full" it goes in *above* the last one, because the
     * last row is the one that takes whatever is left — "+ Split" there means
     * "some of it was paid another way", and the new box is where that is typed.
     */
    const addRow = ({ mode = modes[0]?.value ?? 'cash', amount = '', reference = '' } = {}, { focus = false, append = false } = {}) => {
        const markup = rowMarkup({ mode, amount, reference });
        const last = allRows().at(-1);
        const above = settlement === 'full' && last && !append;

        if (above) last.insertAdjacentHTML('beforebegin', markup);
        else rows.insertAdjacentHTML('beforeend', markup);

        const added = above ? last.previousElementSibling : allRows().at(-1);

        paintReference(added);
        repaint();

        if (focus) $('[name=amount]', added).focus();
    };

    const sumOf = (list) => list.reduce((sum, row) => sum + paise($('[name=amount]', row).value), 0);

    const collect = () => {
        if (settlement === 'credit') return [];

        return allRows()
            .map((row) => {
                const mode = $('[name=mode]', row).value;

                return {
                    mode,
                    amount: $('[name=amount]', row).value.trim(),
                    // A cash row shows no reference box, so whatever a previous
                    // mode left in it is not sent.
                    reference: mode === 'cash' ? null : ($('[name=reference]', row).value.trim() || null),
                };
            })
            /*
            | Compared as a number, not as a string. Anything that is not a
            | positive amount is somebody not having filled the row in — and a
            | "Paid in full" on a document worth nothing yet is 0.00, which the
            | server would refuse with `gt:0`.
            */
            .filter((split) => Number(split.amount) > 0);
    };

    const totalPaise = () => collect().reduce((sum, split) => sum + paise(split.amount), 0);

    /**
     * What is wrong with the split as it stands, in a sentence — or null.
     *
     * The same arithmetic the server does, said before anybody presses the
     * button rather than after.
     */
    const problem = () => {
        const split = collect();
        const missing = split.find((line) => modeOf(line.mode)?.requires_reference && !line.reference);

        if (missing) {
            const mode = modeOf(missing.mode);

            return `A ${mode.label.toLowerCase()} needs its ${(mode.reference_label ?? 'reference').toLowerCase()}.`;
        }

        if (!settlesADocument || settlement === 'credit') return null;

        const due = paise(outstanding());
        const total = totalPaise();

        if (settlement === 'full') {
            const others = sumOf(allRows().slice(0, -1));
            const whole = settleShort ? paise(settleShort.before() ?? outstanding()) : due;

            if (settleShort && total > whole) {
                return `${formatMoney(rupees(total))} is more than the ${formatMoney(rupees(whole))} due. `
                    + 'An overpayment is not a credit balance — it is a typo.';
            }

            return others > due
                ? `The other payments come to ${formatMoney(rupees(others))}, more than the `
                    + `${formatMoney(rupees(due))} due. Reduce them, or remove a row.`
                : null;
        }

        if (total === 0) {
            return `Enter how much was ${say(verb)} now`
                + (canCredit ? ' — or choose On credit if nothing was.' : '.');
        }

        if (total > due) {
            return `${formatMoney(rupees(total))} is more than the ${formatMoney(rupees(due))} due. `
                + 'An overpayment is not a credit balance — it is a typo.';
        }

        if (total === due) return 'That is the whole amount — choose Paid in full.';

        return null;
    };

    /**
     * Bring the screen into line with the state and the total.
     *
     * Called by the host whenever the total may have moved, which is what keeps
     * "Paid in full" true as lines are added: the last row is rewritten here,
     * not once when the chip was pressed.
     */
    const repaint = () => {
        // Re-read, not captured: the document switches direction under a mounted
        // component and "Collected now" over a vendor bill is the wrong act.
        $('[data-pay-heading]', host).textContent = say(heading);

        const list = allRows();

        list.forEach((row) => {
            $('[data-pay-remove]', row).classList.toggle('hidden', list.length < 2);
        });

        if (!settlesADocument) {
            const total = totalPaise();

            summary.className = 'mt-2 text-[0.8125rem] text-muted-foreground';
            summary.textContent = total === 0
                ? `Enter how much is being ${say(verb)}.`
                : `${formatMoney(rupees(total))} ${say(verb)}.`;

            onChange();

            return;
        }

        // Nothing less than paid in full will post — so nothing less is offered.
        const forced = Boolean(mustSettle());

        if (forced && settlement !== 'full') {
            switchTo('full');

            return;
        }

        $$('[data-pay-state]', host).forEach((chip) => {
            chip.setAttribute('aria-pressed', String(chip.dataset.payState === settlement));
            chip.disabled = forced && chip.dataset.payState !== 'full';
        });

        lockedLine.classList.toggle('hidden', !forced);
        lockedLine.textContent = forced ? say(mustSettleReason) : '';

        const due = paise(outstanding());

        rows.classList.toggle('hidden', settlement === 'credit');
        addButton.classList.toggle('hidden', settlement === 'credit');

        list.forEach((row, index) => {
            const amount = $('[name=amount]', row);
            const isRemainder = settlement === 'full' && index === list.length - 1;
            const locked = isRemainder && !settleShort;

            amount.readOnly = locked;
            amount.tabIndex = locked ? -1 : 0;
            amount.classList.toggle('bg-muted', locked);

            $('[data-pay-amount-label]', row).textContent = !isRemainder
                ? 'Amount'
                : list.length > 1 ? 'The rest' : 'Amount — the whole bill';

            // Never under the caret: somebody typing "1200" is mid-way through
            // a figure the total has not caught up with yet.
            if (isRemainder && document.activeElement !== amount) {
                amount.value = rupees(Math.max(due - sumOf(list.slice(0, -1)), 0));
            }
        });

        const total = totalPaise();
        const wrong = problem();

        summary.className = 'mt-2 text-[0.8125rem] text-muted-foreground';

        if (settlement === 'credit') {
            summary.textContent = due > 0
                ? `Nothing ${say(verb)} now — ${formatMoney(rupees(due))} goes on the ${say(noun)}’s account.`
                : `Nothing ${say(verb)} now — the whole amount goes on the ${say(noun)}’s account.`;
        } else if (wrong && !(settlement === 'part' && total === 0)) {
            // Nothing typed yet is not an error until somebody tries to post —
            // it is just the next thing to do, said in grey below.
            summary.className = 'mt-2 text-[0.8125rem] font-medium text-rose-600';
            summary.textContent = wrong;
        } else if (settlement === 'full' && settleShort?.before()) {
            const before = paise(settleShort.before());

            summary.textContent = `${formatMoney(rupees(due))} ${say(verb)} — settled in full. `
                + `${formatMoney(rupees(before - due))} of the ${formatMoney(rupees(before))} is given as a `
                + 'discount.';
        } else if (settlement === 'full') {
            summary.textContent = due > 0
                ? `${formatMoney(rupees(due))} ${say(verb)} — settled in full.`
                    + (settleShort && list.length === 1 ? ' Type a smaller figure if less was paid.' : '')
                    + (list.length > 1 ? ' Type the other payments; the last line takes the rest.' : '')
                : 'Fills in with the total, and follows it if the total changes.';
        } else {
            summary.textContent = total === 0
                ? `Enter the amount ${say(verb)} now. The rest goes on the ${say(noun)}’s account.`
                : `${formatMoney(rupees(total))} ${say(verb)}, `
                    + `${formatMoney(rupees(due - total))} left on the ${say(noun)}’s account.`;
        }

        onChange();
    };

    /**
     * Change state, and leave the rows in the shape that state means.
     *
     * Never clears a figure somebody typed for a reason that still holds — only
     * the ones the old state wrote itself.
     */
    function switchTo(next, { focus = false } = {}) {
        const previous = settlement;

        settlement = next;

        // A smaller figure only ever meant "paid in full at this".
        if (previous === 'full' && next !== 'full') settleShort?.onSettle(null);

        if (next === 'credit') {
            // Nothing moved, so nothing is held over to be sent by mistake if
            // somebody switches back.
            rows.innerHTML = '';
            addRow();

            return;
        }

        if (allRows().length === 0) addRow();

        const last = allRows().at(-1);
        const amount = $('[name=amount]', last);

        // The figure the remainder row was showing was the state's, not theirs.
        if (previous === 'full' && next === 'part') amount.value = '';

        repaint();

        if (focus && next === 'part') {
            amount.focus();
            amount.select();
        }
    }

    host.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-mode]');

        if (chip) {
            const row = chip.closest('[data-payment]');
            $$('[data-mode]', row).forEach((sibling) => sibling.setAttribute('aria-pressed', String(sibling === chip)));
            $('[name=mode]', row).value = chip.dataset.mode;
            paintReference(row);
            repaint();

            return;
        }

        if (event.target.closest('[data-pay-add]')) {
            addRow(undefined, { focus: true });

            return;
        }

        if (event.target.closest('[data-pay-remove]')) {
            event.target.closest('[data-payment]').remove();

            // Never zero rows: an empty section with no visible way back to a
            // payment box is worse than a row somebody leaves blank.
            if (allRows().length === 0) addRow();
            else repaint();

            return;
        }

        const chosen = event.target.closest('[data-pay-state]');

        if (chosen && !chosen.disabled && chosen.dataset.payState !== settlement) {
            switchTo(chosen.dataset.payState, { focus: true });
        }
    });

    host.addEventListener('input', (event) => {
        if (event.target.name === 'amount' && settleShort && settlement === 'full') reportSettled();

        if (event.target.name === 'amount' || event.target.name === 'reference') repaint();
    });

    /**
     * Tell the host what "paid in full" now means: the figure typed, where it is
     * below the bill, or null where it is the bill — or more than it, which is a
     * typo `problem()` names rather than a settlement.
     */
    function reportSettled() {
        const paid = totalPaise();
        const whole = paise(settleShort.before() ?? outstanding());

        if (paid === 0) return;

        settleShort.onSettle(paid < whole ? rupees(paid) : null);
    }

    addRow();

    return {
        /** The split, blank rows dropped — the shape the API's `payments` takes. */
        value: collect,
        /**
         * What the rows add up to, as a decimal string.
         *
         * Display and confirmation only — the server totals the split itself and
         * its answer is the one that posts.
         */
        total: () => rupees(totalPaise()),
        /** Which of credit / part / full is chosen, or null where none applies. */
        state: () => settlement,
        problem,
        /** Put the cursor where the problem is — the first box that can answer it. */
        focus: () => {
            const target = allRows().find((row) => !$('[name=amount]', row).readOnly) ?? allRows()[0];

            $('[name=amount]', target)?.focus();
        },
        /**
         * Put a saved split back, for autosave restore.
         *
         * A draft written before the state was saved carries only the rows, and
         * is read the way it would have been: nothing is credit, something is
         * part paid until the total is known to say otherwise.
         */
        set: (splits, saved = null) => {
            const list = splits ?? [];

            settlement = settlesADocument
                ? (saved ?? (list.length === 0 ? startState() : 'part'))
                : null;

            if (settlement === 'credit' && !canCredit) settlement = 'full';

            rows.innerHTML = '';
            // Appended in order: "above the last" is for a person pressing
            // "+ Split", and would reverse a restored split.
            list.forEach((split) => addRow(split, { append: true }));

            if (allRows().length === 0) addRow();
            else repaint();
        },
        reset: () => {
            settlement = settlesADocument ? startState() : null;
            rows.innerHTML = '';
            addRow();
        },
        repaint,
    };
}
