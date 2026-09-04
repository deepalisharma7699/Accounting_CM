/**
 * How the money moved — the brief's §13.
 *
 * Mode chips rather than a `<select>`, because at a counter the answer is
 * usually "cash" and a chip is one tap where a select is three. Several rows,
 * because "two thousand cash and the rest on UPI" is one payment in one visit,
 * and splitting it into two documents would put two receipts in a ledger that
 * only saw one customer.
 *
 * ## The settlement state is named, and it is *derived*
 *
 * On credit, part paid, paid in full. Those three were always what a split
 * meant, but they were left implicit — an empty section meant credit, and you
 * found out which of the other two you had by reading the summary line. So the
 * three are chips now, and pressing one sets the rows.
 *
 * **What is not stored is which chip is pressed.** The state is recomputed from
 * the amounts on every repaint, so typing over an auto-filled total moves the
 * selection to "Part paid" on its own. Holding it in a variable as well would
 * give the label and the split two ways to disagree, and the label is the half
 * somebody would believe.
 *
 * An overpayment lights *no* chip: it is not a state a document can be in, and
 * the summary says so in red. The server refuses it either way.
 *
 * ## "Paid in full" is one click and is not a default
 *
 * The chip fills the first row with whatever is outstanding. It is not applied
 * automatically, and that is deliberate: a bill on thirty-day terms is the
 * normal case for a workshop's regular customers, and a form that pre-filled the
 * total would have somebody recording money they had not been handed. The
 * server refuses a split larger than the document either way — this only decides
 * what the box says before anybody looks at it.
 *
 * ## Why "On credit" is opt-in
 *
 * It is offered where a document may legitimately go unsettled — a bill. It is
 * not offered where the split *is* the event: a receipt against an invoice and
 * an expense both record money that has already moved, and "on credit" there
 * would be a button for submitting nothing. Hence `canCredit`, off by default.
 */

import { $, $$, esc, formatMoney } from '../ui';

/**
 * @param {HTMLElement} host
 * @param {object} options
 * @param {Array<{value: string, label: string}>} options.modes  From GET /transactions/meta.
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
    | Off by default, so the two hosts that record money which has already moved
    | — the receipt drawer and the expense form — keep exactly the controls they
    | had. A bill opts in.
    */
    canCredit = false,
    /*
    | Whether there is a *document* being settled at all.
    |
    | True everywhere it was first written: a bill, a receipt, an expense — each
    | has a total, and the split is measured against it. False for M22's staff
    | advance, where the split **is** the amount. There is no figure to compare
    | it with, so the three state chips have nothing to mean and the "left on
    | account" summary would be describing a document that does not exist.
    |
    | A flag rather than a second component, for the reason `noun` and `verb` are
    | already flags: the mode chips, the split rows, the reference rules and the
    | payload shape are identical, and a copy of them is where one of the two
    | eventually stops requiring a cheque number.
    */
    settlesADocument = true,
} = {}) {
    // Either may be a thunk: the bill counter switches between sale and purchase
    // without remounting, so the words have to be read when they are painted
    // rather than captured here.
    const say = (value) => (typeof value === 'function' ? value() : value);

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
            </div>` : ''}

            <div class="mt-3 space-y-3" data-pay-rows></div>

            <p class="mt-2 text-[0.8125rem]" data-pay-summary></p>
            <p class="field-error" data-error-for="payments"></p>
        </div>`;

    const rows = $('[data-pay-rows]', host);
    const summary = $('[data-pay-summary]', host);

    const chips = (selected) => modes.map((mode) => `
        <button type="button" class="pill" data-mode="${esc(mode.value)}"
                aria-pressed="${mode.value === selected}">${esc(mode.label)}</button>`).join('');

    const addRow = ({ mode = modes[0]?.value ?? 'cash', amount = '', reference = '' } = {}) => {
        rows.insertAdjacentHTML('beforeend', `
            <div class="rounded-[10px] border border-border p-3" data-payment>
                <div class="flex flex-wrap gap-1.5" data-mode-chips>${chips(mode)}</div>
                <input type="hidden" name="mode" value="${esc(mode)}">

                <div class="mt-2.5 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    <label class="field">
                        <span class="field-label">Amount</span>
                        <input type="text" name="amount" class="field-input text-right font-mono"
                               inputmode="decimal" value="${esc(amount)}" placeholder="0.00">
                    </label>

                    <label class="field">
                        <span class="field-label">Reference</span>
                        <input type="text" name="reference" class="field-input" maxlength="100"
                               value="${esc(reference)}" placeholder="Cheque or UPI ref">
                    </label>

                    <button type="button" class="btn btn-ghost btn-icon self-end" data-pay-remove
                            aria-label="Remove this payment">×</button>
                </div>
            </div>`);

        repaint();
    };

    const collect = () => $$('[data-payment]', rows)
        .map((row) => ({
            mode: $('[name=mode]', row).value,
            amount: $('[name=amount]', row).value.trim(),
            reference: $('[name=reference]', row).value.trim() || null,
        }))
        /*
        | Compared as a number, not as a string.
        |
        | It used to drop `''` and the literal `'0'`, which let the "Paid in
        | full" shortcut on a document worth nothing through as `'0.00'` — past
        | the filter, into the payload, and back as a 422 from `gt:0`. Anything
        | that is not a positive amount is somebody not having filled the row in.
        */
        .filter((split) => Number(split.amount) > 0);

    /**
     * Which of the three states the amounts currently add up to, or null.
     *
     * Null is an overpayment — not a state a document can be in, so no chip is
     * pressed and the summary explains it instead. A document worth nothing yet
     * reads as `credit`, which is honestly what it is: nothing has been settled.
     */
    const stateOf = (total, due) => {
        if (total <= 0.005) return 'credit';
        if (total > due + 0.005) return null;
        if (Math.abs(due - total) < 0.005) return 'full';

        return 'part';
    };

    /**
     * The one arithmetic this component does, and it is display only: the server
     * decides whether a split is acceptable, and it is the server's refusal that
     * a user sees if this and it disagree.
     */
    const repaint = () => {
        // Re-read, not captured: the counter switches direction under a mounted
        // component and "Collected now" over a vendor bill is the wrong act.
        $('[data-pay-heading]', host).textContent = say(heading);

        const total = collect().reduce((sum, split) => sum + (Number(split.amount) || 0), 0);

        /*
        | Nothing to compare the split against — it is the amount. So the summary
        | states it and stops, rather than reporting a balance against a document
        | that does not exist.
        */
        if (!settlesADocument) {
            summary.className = 'mt-2 text-[0.8125rem] text-muted-foreground';
            summary.textContent = total === 0
                ? `Enter how much is being ${say(verb)}.`
                : `${formatMoney(total.toFixed(2))} ${say(verb)}.`;

            onChange();

            return;
        }

        const due = Number(outstanding()) || 0;
        const balance = due - total;

        summary.className = `mt-2 text-[0.8125rem] ${balance < -0.005 ? 'text-rose-600 font-medium' : 'text-muted-foreground'}`;

        summary.textContent = total === 0
            /*
            | Nothing entered yet, and what that *means* depends on whether this
            | document may go unsettled.
            |
            | A bill may — the remainder goes on the counterparty's account, which
            | is what `canCredit` says. A receipt, an expense and a payroll run
            | may not: each one is money that has already moved, so there is no
            | account for a remainder to land on and saying there is would be
            | offering a state the server will refuse. The second line states
            | what is required instead, which is the thing somebody needs at that
            | moment anyway.
            */
            ? (canCredit
                ? `Nothing ${say(verb)} — this goes on the ${say(noun)}’s account.`
                : `Nothing entered yet — ${formatMoney(due.toFixed(2))} to settle.`)
            : balance < -0.005
                ? `That is ${formatMoney(Math.abs(balance).toFixed(2))} more than the document. `
                    + 'An overpayment is not a credit balance — it is a typo.'
                : balance < 0.005
                    ? 'Settled in full.'
                    : `${formatMoney(total.toFixed(2))} ${say(verb)}, `
                        + `${formatMoney(balance.toFixed(2))} left on account.`;

        /*
        | Recomputed, never remembered — see the note at the top. Typing over an
        | auto-filled total moves the selection here, on the same keystroke that
        | moves the summary, so the two cannot say different things.
        */
        const current = stateOf(total, due);

        $$('[data-pay-state]', host).forEach((chip) => {
            chip.setAttribute('aria-pressed', String(chip.dataset.payState === current));
        });

        // "Paid in full" has nothing to fill in until the document is worth
        // something. Disabled rather than hidden, so the row of choices does not
        // reshuffle under the cursor as lines are added.
        const full = $('[data-pay-state="full"]', host);

        if (full) full.disabled = due <= 0;

        onChange();
    };

    host.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-mode]');

        if (chip) {
            const row = chip.closest('[data-payment]');
            $$('[data-mode]', row).forEach((sibling) => sibling.setAttribute('aria-pressed', String(sibling === chip)));
            $('[name=mode]', row).value = chip.dataset.mode;

            return;
        }

        if (event.target.closest('[data-pay-add]')) {
            addRow();

            return;
        }

        if (event.target.closest('[data-pay-remove]')) {
            event.target.closest('[data-payment]').remove();

            // Never zero rows: an empty section with no visible way back to a
            // payment box is worse than a row somebody leaves blank, and a blank
            // row is dropped on submit anyway.
            if ($$('[data-payment]', rows).length === 0) addRow();
            else repaint();

            return;
        }

        const chosen = event.target.closest('[data-pay-state]');

        if (chosen) applyState(chosen.dataset.payState);
    });

    /**
     * Put the rows into the state somebody just named.
     *
     * A shortcut for typing, and nothing more: what is actually true is whatever
     * the amounts say afterwards, which is why `repaint` derives the selection
     * again rather than being told what it now is.
     */
    function applyState(next) {
        if (next === 'credit') {
            /*
            | Everything cleared, back to one empty row. "On credit" is a
            | statement that no money moved, so leaving a filled box behind it
            | — or a second split row — would have the form contradicting the
            | chip somebody just pressed.
            */
            rows.innerHTML = '';
            addRow();

            return;
        }

        const first = $('[data-payment]', rows);
        const amount = $('[name=amount]', first);

        if (next === 'full') {
            // Whatever is still outstanding after the other rows, so "paid in
            // full" after a part-payment fills the remainder rather than the
            // total twice.
            const others = $$('[data-payment]', rows)
                .filter((row) => row !== first)
                .reduce((sum, row) => sum + (Number($('[name=amount]', row).value) || 0), 0);

            amount.value = Math.max(Number(outstanding()) - others, 0).toFixed(2);
            repaint();

            return;
        }

        /*
        | "Part paid" — the operator is about to type a figure, so the box is
        | handed to them ready for it.
        |
        | A total that is there because "Paid in full" put it there is cleared
        | first; a figure somebody typed is left alone. The difference matters:
        | clearing what they typed would punish them for pressing the chip that
        | describes what they had already done.
        */
        const total = collect().reduce((sum, split) => sum + (Number(split.amount) || 0), 0);

        if (stateOf(total, Number(outstanding()) || 0) === 'full') amount.value = '';

        amount.focus();
        amount.select();
        repaint();
    }

    host.addEventListener('input', (event) => {
        if (event.target.name === 'amount') repaint();
    });

    addRow();

    return {
        /** The split, blank rows dropped — the shape the API's `payments` takes. */
        value: collect,
        /**
         * What the rows add up to, as a decimal string.
         *
         * Display and confirmation only — the server totals the split itself and
         * its answer is the one that posts. Exposed because both M22 hosts state
         * the figure back to the user before they commit ("Pay 5,000.00 to
         * Ravi?"), and re-summing the rows in each of them would be the same
         * arithmetic written a third and fourth time.
         */
        total: () => collect()
            .reduce((sum, split) => sum + (Number(split.amount) || 0), 0)
            .toFixed(2),
        /** Put a saved split back, for autosave restore. */
        set: (splits) => {
            rows.innerHTML = '';
            (splits ?? []).forEach((split) => addRow(split));

            if ($$('[data-payment]', rows).length === 0) addRow();
        },
        reset: () => {
            rows.innerHTML = '';
            addRow();
        },
        repaint,
    };
}
