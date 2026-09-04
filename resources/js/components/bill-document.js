/**
 * Writing a bill — the document engine behind every screen that raises one.
 *
 * This is the counter's machinery, lifted out of `pages/bill-counter.js` whole:
 * the lines, the server-priced running total, the confirmation, the payment
 * split, the autosaved draft and the post. What is left in the counter is the
 * part that is genuinely the counter's — the sale/purchase/workshop chooser and
 * everything about billing a job card.
 *
 * It moved because a second caller arrived. The Purchase module raises exactly
 * this document with the direction fixed, and the alternative was a copy —
 * which, on the evidence of the two counterparty forms this codebase carried
 * until last week, would have drifted before anyone noticed.
 *
 * ## The four decisions it inherits, unchanged
 *
 * **The total comes from the server.** Every change re-prices the basket through
 * `POST /transactions/preview`, which runs the same `BillLine` the posting would
 * build. The old modal form estimated in JavaScript and said so in small print;
 * the result was a confirmation showing ₹11,800 against an invoice of ₹11,799.99,
 * because JavaScript numbers are floats and the ledger's are not. There is one
 * arithmetic, and the confirmation is literally the server's answer.
 *
 * **Nothing is preloaded.** Parties and items are searched as they are typed,
 * against endpoints that already have the indexes for it.
 *
 * **The work survives the tab.** Everything is written to localStorage on every
 * change and restored with a banner (§26). A counter is interrupted constantly,
 * and losing six lines is the failure people remember about a product.
 *
 * **One `client_ref` per document, generated once.** Not per request — a value
 * regenerated on retry would make the second attempt look like a different bill,
 * which is exactly what §28 exists to prevent.
 *
 * ## What the host supplies
 *
 * As little as possible, and nothing the engine could work out for itself. The
 * direction, because it decides the endpoint, the party's role and two lines of
 * copy. A key, so two screens do not share one saved draft. What to do after a
 * post, because "go to the bills list" and "stay on the form and flag the new
 * row" are the difference between a page and a §2A module. And two optional
 * hooks, which today only the counter uses, for the extra state a workshop bill
 * carries and the different endpoint it posts to.
 *
 * The engine knows nothing about workshop jobs, and must not learn: a module
 * that never bills one should not ship the code that does (§7.2).
 */

import auth from '../auth-client';
import { formatQuantity } from './badge';
import { mountItemPicker } from './item-picker';
import { mountPartyPicker } from './party-picker';
import { mountPaymentRows } from './payment-rows';
import { mountStaffAttribution } from './staff-attribution';
import { initQuickItem, openQuickItem } from './quick-item';
import { initQuickParty, openQuickParty } from './quick-party';
import { $, $$, debounce, esc, formatMoney, hideModal, isZeroAmount, showModal, toast } from '../ui';

/**
 * Mount the document form into a host that carries the markup from
 * `partials/bill-document.blade.php`.
 *
 * @param {HTMLElement} root
 * @param {object} config
 * @param {string} config.key         Namespaces the saved draft. One per screen.
 * @param {'sale'|'purchase'} [config.direction]
 * @param {(response: object) => void} [config.onPosted]
 * @param {() => object} [config.extraState]   Merged into the saved draft.
 * @param {boolean} [config.restoreDraft]  Whether to look for an unfinished
 *        document. False where the host was opened with a deep link, which asks
 *        for something specific and outranks what somebody was doing yesterday.
 * @param {(saved: object) => Promise<void>} [config.onRestore]  Run before the
 *        engine puts a restored draft back, so a host can settle its own mode first.
 * @param {(payload: object, meta: {post: boolean}) => Promise<object|null>} [config.submitWith]
 *        Post this document somewhere other than its type endpoint. Returning
 *        null means "the ordinary way".
 */
export async function mountBillDocument(root, {
    key,
    direction = 'sale',
    onPosted = () => {},
    extraState = () => ({}),
    restoreDraft = true,
    onRestore = async () => {},
    submitWith = async () => null,
} = {}) {
    const draftKey = `${key}:draft:v1`;

    /*
    | Nothing is written to the saved draft until the document is actually under
    | somebody's hand, and this flag is the whole of what enforces it.
    |
    | Mounting fires `save` several times over — `mountPaymentRows` paints an
    | empty split and calls `onChange`, and a host settling its own mode moves
    | the direction — all of it while `lines` is still `[]`. Without this, boot
    | overwrites the saved draft with an empty one and the restore that runs a
    | moment later finds nothing to put back.
    |
    | That is not hypothetical: it is exactly what the counter did before this
    | was extracted, which meant §26 had never once restored a bill.
    */
    let live = false;

    const state = {
        direction,
        lines: [],
        lineSeq: 0,
        paymentModes: [],
        preview: null,
        // Why the last pricing attempt came back empty, or null. Kept apart from
        // `preview` because "not priced yet" and "cannot be priced" are different
        // things to put in front of somebody.
        previewError: null,
        /*
        | Whether `preview` is behind the boxes.
        |
        | Set the moment anything that changes a figure is touched, cleared only
        | when a response for the *current* edit lands. Without it the
        | confirmation opened on whatever the last completed request had said —
        | a rate typed and reviewed inside the debounce window showed 0.00 in
        | the dialog while the panel behind it already read ₹500.00.
        */
        previewStale: false,
        previewSeq: 0,
        // Somebody pressed the post control on an empty document. Held so the
        // panel can say what is missing instead of the press doing nothing.
        emptyAttempt: false,
        // Lines the operator has agreed are as large as they look — see
        // LARGE_LINE_AMOUNT.
        acknowledgedLarge: false,
        /*
        | Whether the bill-level discount box is rupees or a percentage.
        |
        | The typed figure lives in the input, not here — a mode is a mode, and
        | mirroring the value would make two places to keep in step. What is
        | never done in either place is *resolving* the percentage: the server
        | does that, against the same lines it is about to tax. See BillDiscount.
        */
        billDiscountMode: 'amount',
        /*
        | The trades this workshop records on a sale, from `/transactions/meta`.
        |
        | Held so that flipping the direction at the counter can paint the boxes
        | back without a second request. Empty for a workshop that records none,
        | and the section then never appears.
        */
        staffSlots: [],
        /*
        | What is currently picked, mirrored out of the component.
        |
        | Held here and not only in the DOM for the same reason `lines` is: the
        | component is torn down and rebuilt when the counter flips direction,
        | and the autosaved draft is written from state rather than read back off
        | the markup — which on a detached surface would find nothing.
        */
        staffPicked: [],
        // Generated once, when this document is started, and reused on every retry.
        clientRef: crypto.randomUUID(),
        posting: false,
    };

    let partyPicker = null;
    let itemPicker = null;
    let payments = null;
    // Who did the work — M22. Mounted on a sale only; null on a purchase, where
    // there is nobody in the building who fitted what a supplier delivered.
    let staff = null;

    /*
    | Past which a purchase line stops looking like ordinary trade.
    |
    | ₹5,00,000 on a single line. Not a limit — a workshop buying a lathe or a
    | year's copper legitimately goes past it, and a rule that refused would be
    | worked around by splitting the line, which is worse than the typo it was
    | guarding against. It asks once, on the confirmation, and takes yes for an
    | answer.
    |
    | Purchases only. A sale of the same size is a shop's best day of the year
    | and does not want a challenge; a purchase of it is a debt the workshop
    | takes on, and 999999 typed into a quantity box is the commonest way to do
    | that by accident.
    */
    const LARGE_LINE_AMOUNT = 500000;

    const isPurchase = () => state.direction === 'purchase';
    const partyRole = () => (isPurchase() ? 'vendor' : 'customer');

    /** Scoped, so two documents could coexist and neither reaches into the other. */
    const el = (selector) => $(selector, root);

    /* ---------------------------------------------------------------------
     | Lines
     | ------------------------------------------------------------------ */

    /**
     * Put a picked catalogue row on the document.
     *
     * A repeat of something already on it increments the quantity rather than
     * adding a second row — scanning the same bearing twice means two bearings,
     * and two identical lines is a document nobody would write by hand.
     */
    function addLine(choice, {
        quantity = '1', unit_price = null, discount = '0', discount_mode = 'amount', memo = null,
    } = {}) {
        // Whatever was missing when somebody last pressed post, this is part of
        // the answer — the notice goes as soon as they start filling it in.
        state.emptyAttempt = false;

        const existing = state.lines.find((line) => line.key === choice.key);

        if (existing && unit_price === null) {
            existing.quantity = String((Number(existing.quantity) || 0) + 1);
            renderLines();

            return;
        }

        state.lines.push({
            id: ++state.lineSeq,
            key: choice.key,
            item_id: choice.item_id,
            variant_id: choice.variant_id,
            label: choice.label,
            unit_symbol: choice.unit_symbol ?? '',
            gst_rate: choice.gst_rate ?? '0',
            available: choice.quantity,
            // Carried so a purchase line can say what this last cost without a
            // second lookup — the picker already fetched it. See `rateHint`.
            average_cost: choice.average_cost ?? null,
            quantity: String(quantity),
            unit_price: unit_price ?? defaultRate(choice),
            discount: String(discount ?? '0'),
            // Rupees or a percentage of this line. UI state only — what goes to
            // the server is the typed figure under one key or the other.
            discount_mode,
            memo,
        });

        renderLines();
    }

    /**
     * What the rate box starts on.
     *
     * **Nothing, on a purchase.** The list price is what the workshop *sells* it
     * for, and seeding a purchase line with it would put a selling figure into
     * the cost basis. That basis is the weighted average, this arrival is what
     * recomputes it, and a wrong average is not correctable afterwards by
     * editing anything — see `PurchaseTemplate`. A supplier's invoice always
     * states its own price, so there is nothing to save by guessing.
     *
     * On a sale the list price fills the rate the first time and never
     * overwrites what somebody typed: a rewind is quoted per job, and a stored
     * price silently replacing what was agreed puts the wrong figure on the
     * invoice.
     */
    function defaultRate(choice) {
        if (isPurchase()) return '';

        return choice.price === '' || choice.price === null || choice.price === undefined
            ? ''
            : String(choice.price);
    }

    function removeLine(id) {
        state.lines = state.lines.filter((line) => line.id !== Number(id));
        renderLines();
    }

    /**
     * A line's amount before tax, for the row only.
     *
     * The footer's figures all come from the server; this one is here because a
     * row that showed nothing until a round trip finished would flicker on every
     * keystroke. It is explicitly the pre-tax amount, so it cannot be mistaken
     * for the line total the invoice carries.
     */
    function rowAmount(line) {
        // An unpriceable line has no amount, and showing one is worse than
        // showing nothing: a quantity of −5 at a rate of −100 multiplied out to a
        // perfectly plausible ₹500.00, so the row that was about to be refused
        // was the row that looked most normal on the screen.
        if (lineProblem(line)) return null;

        const amount = lineGross(line) - lineDiscount(line);

        return Math.max(amount, 0).toFixed(2);
    }

    /** Quantity × rate, before any discount. Indicative, like `rowAmount`. */
    function lineGross(line) {
        return (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
    }

    /**
     * What this line's discount comes to, in rupees, *for the row only*.
     *
     * The percentage is worked out here so the Amount column moves as somebody
     * types, and nowhere else — this figure is never sent. `BillDiscount`
     * resolves the percentage server-side against the same line it then taxes,
     * because `4237.29 * 0.1` is 423.72900000000004 in this language and an
     * invoice cannot be a paisa out.
     */
    function lineDiscount(line) {
        const typed = Number(line.discount) || 0;

        return line.discount_mode === 'percent' ? (lineGross(line) * typed) / 100 : typed;
    }

    /**
     * Why this line cannot be priced, or null when it can.
     *
     * The server has said all of this since M9 — `items.*.quantity` is
     * `gt:0` on both the preview and the post — and it kept on saying it into a
     * void, because `itemsPayload()` filtered a bad line out before the request
     * was built. With one line on the bill that left nothing to send, so no
     * preview was asked for, no total came back, and pressing "Review & post"
     * produced "Still working out the total — try again in a moment." on a total
     * that was never going to arrive.
     *
     * So the rule is not a new one and is not a second copy of one. It is the
     * server's own rule, said early enough and beside the box it is about, and
     * the server still refuses independently (§6.1).
     *
     * A blank quantity is not a problem, deliberately: it is a row somebody is
     * still typing into, and shouting at a half-typed line is how a form becomes
     * unpleasant to use. It simply does not price until there is a number.
     */
    function lineProblem(line) {
        const quantity = String(line.quantity ?? '').trim();

        if (quantity === '') return null;

        if (!Number.isFinite(Number(quantity))) return 'Quantity must be a number.';
        if (Number(quantity) === 0) return 'A quantity of zero moves nothing — remove the line instead.';
        if (Number(quantity) < 0) return 'Quantity must be more than zero.';

        const rate = String(line.unit_price ?? '').trim();

        if (rate !== '' && (!Number.isFinite(Number(rate)) || Number(rate) < 0)) {
            return 'A rate cannot be negative.';
        }

        const discount = String(line.discount ?? '').trim();

        if (discount !== '') {
            if (!Number.isFinite(Number(discount)) || Number(discount) < 0) {
                return 'A discount cannot be negative.';
            }

            /*
            | Only in percentage mode, and deliberately not in rupees. ₹200 off
            | a ₹100 line is a real thing somebody types on a warranty job and
            | the engine clamps it to free; 120% is not a discount anybody
            | means, and the request refuses it — so it is said here first,
            | beside the box, rather than after a round trip.
            */
            if (line.discount_mode === 'percent' && Number(discount) > 100) {
                return 'A discount cannot be more than 100%.';
            }
        }

        return null;
    }

    /**
     * Put one row's verdict on the row, in place.
     *
     * The counterpart of what `renderLines` paints, kept beside it so the two
     * cannot say different things — the initial render and every keystroke after
     * it go through the same three toggles.
     */
    function paintLineProblem(row, problem) {
        const input = $('input[name="quantity"]', row);
        const note = $('[data-line-problem]', row);

        input.classList.toggle('border-rose-400', problem !== null);
        input.classList.toggle('bg-rose-50/40', problem !== null);
        input.setAttribute('aria-invalid', String(problem !== null));

        note.textContent = problem ?? '';
        note.classList.toggle('hidden', problem === null);
    }

    /**
     * Every line the bill cannot be posted with, in row order.
     *
     * Wider than `lineProblem` by exactly one case: a blank quantity. That is not
     * worth a red box while somebody is still typing, but it is absolutely worth
     * one at the moment they press post, because the alternative is the line
     * quietly not being on the bill — the same defect a negative quantity had,
     * reached from the other side. `itemsPayload` skips it, the total comes back
     * without it, and the confirmation shows a document missing a row somebody
     * typed.
     */
    function postingProblems() {
        return state.lines
            .map((line) => ({
                line,
                problem: String(line.quantity ?? '').trim() === ''
                    ? 'This line has no quantity yet.'
                    : lineProblem(line),
            }))
            .filter((row) => row.problem !== null);
    }

    /**
     * One sentence for the panel and the toast, naming the row it is about.
     *
     * The row already carries its own message beside the box; this is for the
     * places that are not looking at the row — the totals panel, and the toast
     * somebody gets when they press "Review & post" from the bottom of a long
     * bill without having scrolled back up.
     */
    function firstProblemMessage(problems) {
        const { line, problem } = problems[0];
        const rest = problems.length - 1;

        return `${line.label}: ${problem}`
            + (rest > 0 ? ` (${rest} other line${rest === 1 ? '' : 's'} need${rest === 1 ? 's' : ''} fixing too.)` : '');
    }

    function renderLines() {
        const body = el('[data-lines]');

        body.innerHTML = state.lines.length
            ? state.lines.map((line) => {
                // Shown against what the shelf holds, at the moment of typing
                // rather than at the moment of posting. It refuses nothing — a
                // workshop legitimately bills a part it is about to buy in — but
                // the decision gets made knowingly. M17 has the final say.
                //
                // Never on a purchase: stock arriving cannot be short of itself.
                const short = !isPurchase()
                    && line.available !== null && line.available !== undefined
                    && Number(line.quantity) > Number(line.available);

                const problem = lineProblem(line);

                return `
                <tr class="border-b border-border align-top" data-line="${line.id}">
                    <td class="px-2 py-2">
                        <span class="block text-sm font-medium text-foreground">${esc(line.label)}</span>
                        <span class="block text-xs text-muted-foreground">
                            ${esc(line.gst_rate)}% GST${
                                line.available === null || line.available === undefined
                                    ? ''
                                    : ` · ${esc(formatQuantity(line.available, line.unit_symbol))} on hand`
                            }
                        </span>
                        ${short ? `
                            <span class="mt-1 block text-xs font-medium text-rose-600">
                                Only ${esc(formatQuantity(line.available, line.unit_symbol))} available in stock.
                            </span>` : ''}
                    </td>

                    <td class="px-2 py-2">
                        <div class="flex items-center justify-end gap-1">
                            <input type="text" class="field-input w-20 text-right font-mono ${
                                problem ? 'border-rose-400 bg-rose-50/40' : ''
                            }" inputmode="decimal"
                                   name="quantity" value="${esc(line.quantity)}"
                                   aria-invalid="${problem ? 'true' : 'false'}"
                                   aria-label="Quantity of ${esc(line.label)}">
                            <span class="text-xs text-muted-foreground">${esc(line.unit_symbol)}</span>
                        </div>
                        <span class="mt-1 block text-right text-xs font-medium text-rose-600
                                     ${problem ? '' : 'hidden'}" data-line-problem>${esc(problem ?? '')}</span>
                    </td>

                    <td class="px-2 py-2">
                        <input type="text" class="field-input w-24 text-right font-mono" inputmode="decimal"
                               name="unit_price" value="${esc(line.unit_price)}" placeholder="0.00"
                               aria-label="Rate for ${esc(line.label)}">
                        ${rateHint(line)}
                    </td>

                    <td class="px-2 py-2">
                        <div class="flex items-center justify-end gap-1">
                            <input type="text" class="field-input w-20 text-right font-mono" inputmode="decimal"
                                   name="discount" value="${esc(line.discount)}" placeholder="0"
                                   aria-label="Discount on ${esc(line.label)}${
                                       line.discount_mode === 'percent' ? ', as a percentage' : ', in rupees'
                                   }">
                            <button type="button" data-discount-mode
                                    class="h-[2.625rem] w-9 shrink-0 rounded-[10px] border border-border bg-card
                                           text-sm font-semibold text-muted-foreground
                                           hover:bg-secondary hover:text-foreground"
                                    aria-label="${line.discount_mode === 'percent'
                                        ? `Discount on ${esc(line.label)} is a percentage — switch to rupees`
                                        : `Discount on ${esc(line.label)} is in rupees — switch to a percentage`}">
                                ${line.discount_mode === 'percent' ? '%' : '&#8377;'}
                            </button>
                        </div>
                    </td>

                    <td class="px-2 py-2 text-right font-mono text-[0.8125rem]">
                        ${rowAmount(line) === null
                            ? '<span class="text-muted-foreground">—</span>'
                            : esc(formatMoney(rowAmount(line)))}
                    </td>

                    <td class="px-2 py-2 text-right">
                        <button type="button" class="btn btn-ghost btn-icon" data-remove-line
                                aria-label="Remove ${esc(line.label)}">×</button>
                    </td>
                </tr>`;
            }).join('')
            : `<tr><td colspan="6" class="px-2 py-8 text-center text-sm text-muted-foreground">
                   Nothing on this bill yet. Search above — every result shows what is on the shelf.
               </td></tr>`;

        refreshPreview();
        save();
    }

    /**
     * What this last cost, under an empty rate box on a purchase.
     *
     * A hint and never a value, for the reason `defaultRate` gives: it is there
     * so somebody can tell at a glance whether the supplier has put their prices
     * up, not so it can be accepted by tabbing past it.
     */
    function rateHint(line) {
        if (!isPurchase() || line.average_cost === null || line.average_cost === undefined) return '';

        return `<span class="mt-1 block text-right text-[11px] text-muted-foreground">
                    avg ${esc(formatMoney(line.average_cost))}
                </span>`;
    }

    /* ---------------------------------------------------------------------
     | The running total — the server's arithmetic, not ours
     | ------------------------------------------------------------------ */

    function itemsPayload() {
        /*
        | A line still being typed into is skipped; a line that is *wrong* is
        | not. It used to be the same filter for both, which is what turned a
        | quantity of −5 into a bill with nothing on it — and then into "still
        | working out the total" on a total nobody had asked for. `lineProblem`
        | now holds those back before the request is built and says why on the
        | row, so this only ever sends lines the server can price.
        */
        return state.lines
            .filter((line) => String(line.quantity ?? '').trim() !== '' && lineProblem(line) === null)
            .map((line) => ({
                item_id: line.item_id,
                variant_id: line.variant_id,
                quantity: line.quantity,
                unit_price: line.unit_price === '' ? '0' : line.unit_price,
                ...discountKeys(line.discount, line.discount_mode),
                memo: line.memo,
            }));
    }

    /**
     * A typed discount as the one key the server wants — or neither.
     *
     * The key is **omitted**, not nulled. `discount` and `discount_percent` are
     * a `prohibits` pair on both requests, and Laravel counts a key that is
     * present-but-empty as present: sending `{discount: null, discount_percent:
     * '10'}` would have every discounted bill refused for stating two discounts,
     * one of which was nothing.
     *
     * Zero is omitted for the same reason it always was — a discount of nothing
     * is not a discount, and it keeps `bill_discount` off an untouched payload.
     */
    function discountKeys(value, mode, amountKey = 'discount', percentKey = 'discount_percent') {
        const typed = String(value ?? '').trim();

        if (typed === '' || !Number.isFinite(Number(typed)) || Number(typed) === 0) return {};

        return mode === 'percent' ? { [percentKey]: typed } : { [amountKey]: typed };
    }

    /** The whole-bill discount, in the same one-key-or-neither shape. */
    function billDiscountPayload() {
        return discountKeys(
            el('[data-bill-discount]').value,
            state.billDiscountMode,
            'bill_discount',
            'bill_discount_percent',
        );
    }

    /** Say which unit the two bill-discount controls are currently in. */
    function paintBillDiscountMode() {
        const percent = state.billDiscountMode === 'percent';

        const button = el('[data-bill-discount-mode]');
        button.innerHTML = percent ? '%' : '&#8377;';
        button.setAttribute('aria-label', percent
            ? 'Bill discount is a percentage — switch to rupees'
            : 'Bill discount is in rupees — switch to a percentage');

        el('[data-bill-discount]').setAttribute('aria-label', percent
            ? 'Discount on the whole bill, as a percentage'
            : 'Discount on the whole bill, in rupees');
    }

    /**
     * Ask the server what the document comes to.
     *
     * Sequenced, because two of these can be in the air at once and the older
     * one must not overwrite the newer: a slow response landing after a fast
     * one is the same stale figure by another route.
     */
    async function runPreview() {
        const token = ++state.previewSeq;
        const settle = () => {
            if (token !== state.previewSeq) return false;

            state.previewStale = false;

            return true;
        };

        const items = itemsPayload();
        const problems = postingProblems();

        if (items.length === 0) {
            if (!settle()) return;

            state.preview = null;

            // The distinction the old code could not draw. Nothing to price
            // because nothing has been typed yet is a blank panel; nothing to
            // price because every line is invalid is a *reason*, and it is the
            // reason the post button and the confirmation both go on to give.
            state.previewError = problems.length
                ? firstProblemMessage(problems)
                : null;

            renderTotals();

            return;
        }

        try {
            const { data } = await auth.call('/transactions/preview', {
                method: 'POST',
                body: {
                    type: state.direction,
                    date: el('[data-bill-date]').value || null,
                    // Sent where it is known, because the party's state code
                    // decides CGST+SGST against IGST — and a total that changed
                    // when the customer was chosen, with nothing saying why, is
                    // worse than one that was right from the start.
                    party_id: partyPicker?.id() ?? null,
                    items,
                    // Priced through the same BillTemplate the posting uses, so
                    // the discount on the panel is the discount on the invoice.
                    ...billDiscountPayload(),
                },
            });

            if (!settle()) return;

            state.previewError = null;
            state.preview = data;
        } catch (error) {
            /*
            | A failed preview must not block the bill — the server prices it
            | again on the way in. What it must not do is go on saying "working
            | out the total…", which is what this used to leave on screen
            | forever: the commonest failure here is a line the engine will
            | refuse, and a spinner is the one response that tells nobody that.
            */
            if (!settle()) return;

            state.preview = null;
            state.previewError = error.message;
            toast(error.message, 'error');
        }

        renderTotals();
    }

    const schedulePreview = debounce(runPreview, 350);

    /**
     * Mark the figures out of date and queue a fresh set.
     *
     * The flag goes up *now*, synchronously, rather than when the request
     * eventually goes out — everything that reads a total has to be able to see
     * that the one it is holding is already wrong.
     */
    function refreshPreview() {
        state.previewStale = true;
        // Invalidates anything already in the air. A response for the figures as
        // they were two keystrokes ago must not land and declare itself current.
        state.previewSeq += 1;
        schedulePreview();
    }

    /** Overtake the wait and hold until the figures are the ones on screen. */
    async function settlePreview() {
        if (!state.previewStale) return;

        schedulePreview.cancel();

        await runPreview();
    }

    function renderTotals() {
        const host = el('[data-totals-host]');
        const totals = state.preview?.totals ?? null;
        // A bill with one good line and one bad one previewed happily — the bad
        // line had been filtered out — and would have posted a total that was
        // missing a row somebody had typed. The server refuses it, but only after
        // the confirmation has already shown the wrong figure.
        const problems = postingProblems();

        /*
        | An empty document leaves the control *live*.
        |
        | It used to be disabled, and a disabled button with nothing beside it
        | is indistinguishable from a broken one: the press did nothing, no
        | message appeared and no request went out. It now opens on a sentence
        | naming what is missing. A line that is actually wrong still disables
        | it, because that case already paints its reason on the row and in this
        | panel — there is nothing left for a press to explain.
        */
        el('[data-post]').disabled = state.posting || problems.length > 0;

        if (!totals) {
            host.innerHTML = state.previewError
                ? `<div class="text-[0.8125rem]">
                       <p class="font-semibold text-rose-700">
                           ${problems.length ? 'Check the lines' : 'This cannot be priced yet'}
                       </p>
                       <p class="mt-1 text-muted-foreground">${esc(state.previewError)}</p>
                       ${problems.length
                           // No retry button on a line the user has to change:
                           // offering one invites somebody to press it until they
                           // conclude the product is broken.
                           ? ''
                           : `<button type="button" class="btn btn-secondary btn-sm mt-3" data-preview-retry>
                                  Try again
                              </button>`}
                   </div>`
                : state.emptyAttempt && state.lines.length === 0
                    ? `<div class="text-[0.8125rem]">
                           <p class="font-semibold text-rose-700">Nothing to post yet</p>
                           <p class="mt-1 text-muted-foreground">${esc(whatIsMissing())}</p>
                       </div>`
                    : `<p class="text-sm text-muted-foreground">
                           ${state.lines.length ? 'Working out the total…' : 'Add a line to see what this comes to.'}
                       </p>`;

            payments?.repaint();

            return;
        }

        const shortfalls = state.preview.stock ?? [];

        host.innerHTML = `
            <h3 class="text-sm font-semibold text-foreground">What this comes to</h3>

            <dl class="mt-3 space-y-1.5 text-[0.8125rem]">
                ${Number(totals.discount) > 0 ? `
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd class="font-mono">${esc(formatMoney(totals.gross))}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Discount</dt>
                        <dd class="font-mono text-emerald-700">−${esc(formatMoney(totals.discount))}</dd>
                    </div>` : ''}

                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Taxable value</dt>
                    <dd class="font-mono">${esc(formatMoney(totals.taxable))}</dd>
                </div>

                ${totals.inter_state
                    ? `<div class="flex justify-between">
                           <dt class="text-muted-foreground">IGST</dt>
                           <dd class="font-mono">${esc(formatMoney(totals.igst))}</dd>
                       </div>`
                    : `<div class="flex justify-between">
                           <dt class="text-muted-foreground">CGST</dt>
                           <dd class="font-mono">${esc(formatMoney(totals.cgst))}</dd>
                       </div>
                       <div class="flex justify-between">
                           <dt class="text-muted-foreground">SGST</dt>
                           <dd class="font-mono">${esc(formatMoney(totals.sgst))}</dd>
                       </div>`}

                ${isZeroAmount(totals.round_off) ? '' : `
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Round off</dt>
                        <dd class="font-mono">${esc(signedPaise(totals.round_off))}</dd>
                    </div>`}

                <div class="flex justify-between border-t border-border pt-2 text-base font-bold text-foreground">
                    <dt>Total</dt>
                    <dd class="font-mono">₹${esc(formatMoney(totals.total))}</dd>
                </div>
            </dl>

            ${shortfalls.length ? `
                <div class="mt-3 rounded-[10px] border border-amber-200 bg-amber-50/60 px-3 py-2.5 text-xs
                            text-amber-900">
                    <p class="font-semibold">Short on the shelf</p>
                    <ul class="mt-1 space-y-0.5">
                        ${shortfalls.map((row) => `<li>${esc(row.label)} — ${esc(row.message)}</li>`).join('')}
                    </ul>
                    ${state.preview.can_post
                        ? '<p class="mt-1.5">Your workshop allows billing stock it does not hold, so this will still post.</p>'
                        : '<p class="mt-1.5 font-semibold">This will be refused until the stock is entered.</p>'}
                </div>` : ''}

            ${problems.length ? `
                <div class="mt-3 rounded-[10px] border border-rose-200 bg-rose-50/60 px-3 py-2.5 text-xs
                            text-rose-700">
                    <p class="font-semibold">A line is not priced into this</p>
                    <p class="mt-1">${esc(firstProblemMessage(problems))}</p>
                    <p class="mt-1.5">The total above leaves it out, so nothing can be posted until it is fixed.</p>
                </div>` : ''}

            <p class="mt-3 text-xs text-muted-foreground">
                Worked out on the server from each item’s rate and the two state codes.
            </p>`;

        payments?.repaint();
    }

    /**
     * The rounding line: `+0.64` or `−0.36`, never a bare `0.36`.
     *
     * Written here rather than by asking `formatMoney` for a sign, because its
     * `sign` option deliberately withholds the `+` on an amount under a rupee —
     * and a round-off is *always* under a rupee. Changing that shared rule to
     * suit this one row would put a plus sign on every sub-rupee figure in the
     * product (§4.4).
     */
    function signedPaise(amount) {
        const value = Number(amount) || 0;

        return `${value < 0 ? '−' : '+'}${formatMoney(Math.abs(value).toFixed(2))}`;
    }

    /* ---------------------------------------------------------------------
     | §12 — the last look
     | ------------------------------------------------------------------ */

    /** Priced lines that are large enough to be worth a second look. */
    function oversizedLines() {
        if (!isPurchase()) return [];

        return (state.preview?.lines ?? [])
            .filter((line) => Number(line.line_total) > LARGE_LINE_AMOUNT);
    }

    /** What an empty document still needs, named rather than implied. */
    function whatIsMissing() {
        const needsParty = !partyPicker?.id();
        const needsLines = state.lines.length === 0;
        const noun = partyRole();

        if (needsParty && needsLines) return `Add a ${noun} and at least one item.`;
        if (needsLines) return 'Add at least one item.';

        return `Choose a ${noun}.`;
    }

    async function openConfirmation() {
        /*
        | The press that used to do nothing at all.
        |
        | A document with no lines cannot be reviewed, but silence is not the
        | way to say so — no toast, no highlight, no request, and a Save button
        | that reads as broken. The panel says what is missing and the cursor
        | goes to the box that answers it.
        */
        if (state.lines.length === 0) {
            state.emptyAttempt = true;
            toast(whatIsMissing(), 'error');
            renderTotals();

            if (partyPicker?.id()) itemPicker?.focus();
            else partyPicker?.focus();

            return;
        }

        state.emptyAttempt = false;

        /*
        | Three situations, where there used to be two — and the third was being
        | reported as the first. A line the server will refuse is not a total
        | that has not arrived yet, and telling somebody to "try again in a
        | moment" for a quantity of −5 sends them back to wait for a figure that
        | is never coming, with nothing on the screen pointing at the box that is
        | actually wrong.
        */
        const problems = postingProblems();

        if (problems.length) {
            toast(firstProblemMessage(problems), 'error');

            // Repainted rather than merely refused, so the offending row is
            // outlined even if the last render predates the bad keystroke.
            renderLines();

            return;
        }

        /*
        | The figures are brought up to date before the dialog is built, rather
        | than the dialog being built from whatever the last completed request
        | happened to say. A rate typed and reviewed inside the 350ms wait used
        | to open a confirmation reading 0.00 over a panel already showing
        | ₹500.00 — harmless to what posts, since the server prices it again,
        | and exactly the sort of thing somebody reads as a save that failed.
        */
        el('[data-post]').disabled = true;

        try {
            await settlePreview();
        } finally {
            renderTotals();
        }

        const totals = state.preview?.totals;

        if (!totals) {
            toast(
                state.previewError ?? 'Still working out the total — try again in a moment.',
                state.previewError ? 'error' : 'info',
            );

            refreshPreview();

            return;
        }

        const party = partyPicker.value();
        const split = payments.value();
        const settled = split.reduce((sum, row) => sum + (Number(row.amount) || 0), 0);

        $('[data-confirm-subtitle]').textContent =
            `${party?.name ?? 'No party chosen'} · ${el('[data-bill-date]').value}`;

        /*
        | Asked inside the confirmation, not in a dialog over it — §2.2 allows
        | nothing above level 3, and a second modal is exactly the thing somebody
        | clicks through without reading. A tick beats an extra button for the
        | same reason.
        */
        const oversized = oversizedLines();

        // A column only where there is something in it. The apportioned share of
        // a bill discount lands on the lines, and this is the one screen that
        // shows *which* lines got it before anybody commits.
        const discounted = state.preview.lines.some((line) => Number(line.discount_amount) > 0);

        state.acknowledgedLarge = false;

        $('[data-confirm-body]').innerHTML = `
            ${oversized.length === 0 ? '' : `
                <label class="mb-3 flex items-start gap-2.5 rounded-[10px] border border-amber-300 bg-amber-50
                              px-3.5 py-2.5 text-[0.8125rem] text-amber-900">
                    <input type="checkbox" class="mt-0.5 size-4 rounded border-amber-400" data-confirm-large>
                    <span>
                        <span class="block font-semibold">
                            ${oversized.length === 1
                                ? `One line comes to ₹${esc(formatMoney(oversized[0].line_total))}.`
                                : `${oversized.length} lines come to more than `
                                    + `₹${esc(formatMoney(String(LARGE_LINE_AMOUNT)))} each.`}
                        </span>
                        <span class="block">
                            That is larger than this workshop usually buys in one go. Tick to confirm the
                            quantity and the rate are right.
                        </span>
                    </span>
                </label>`}

            ${party ? '' : `
                <p class="mb-3 rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-[0.8125rem]
                          text-rose-700">
                    Nobody has been chosen for this bill. An invoice made out to nobody cannot be collected.
                </p>`}

            <table class="w-full border-collapse text-[0.8125rem]">
                <thead>
                    <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="px-2 py-2 font-semibold">Item</th>
                        <th class="px-2 py-2 text-right font-semibold">Qty</th>
                        <th class="px-2 py-2 text-right font-semibold">Rate</th>
                        ${discounted ? '<th class="px-2 py-2 text-right font-semibold">Discount</th>' : ''}
                        <th class="px-2 py-2 text-right font-semibold">Tax</th>
                        <th class="px-2 py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${state.preview.lines.map((line) => `
                        <tr class="border-b border-border">
                            <td class="px-2 py-2">${esc(line.description)}</td>
                            <td class="px-2 py-2 text-right font-mono">
                                ${esc(formatQuantity(line.quantity, line.unit_symbol))}
                            </td>
                            <td class="px-2 py-2 text-right font-mono">${esc(formatMoney(line.unit_price))}</td>
                            ${discounted
                                ? `<td class="px-2 py-2 text-right font-mono text-muted-foreground">
                                       ${esc(formatMoney(line.discount_amount))}
                                   </td>`
                                : ''}
                            <td class="px-2 py-2 text-right font-mono">${esc(formatMoney(line.tax_amount))}</td>
                            <td class="px-2 py-2 text-right font-mono font-semibold">
                                ${esc(formatMoney(line.line_total))}
                            </td>
                        </tr>`).join('')}
                </tbody>
            </table>

            <dl class="mt-4 space-y-1.5 border-t border-border pt-3 text-[0.8125rem]">
                ${Number(totals.discount) > 0 ? `
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd class="font-mono">${esc(formatMoney(totals.gross))}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Discount</dt>
                        <dd class="font-mono text-emerald-700">−${esc(formatMoney(totals.discount))}</dd>
                    </div>` : ''}

                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Taxable value</dt>
                    <dd class="font-mono">${esc(formatMoney(totals.taxable))}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">${totals.inter_state ? 'IGST' : 'CGST + SGST'}</dt>
                    <dd class="font-mono">${esc(formatMoney(totals.tax))}</dd>
                </div>
                ${isZeroAmount(totals.round_off) ? '' : `
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Round off</dt>
                        <dd class="font-mono">${esc(signedPaise(totals.round_off))}</dd>
                    </div>`}
                <div class="flex justify-between text-base font-bold text-foreground">
                    <dt>Total</dt>
                    <dd class="font-mono">₹${esc(formatMoney(totals.total))}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">${isPurchase() ? 'Paid now' : 'Collected now'}</dt>
                    <dd class="font-mono">${esc(formatMoney(settled.toFixed(2)))}</dd>
                </div>
            </dl>`;

        $('[data-confirm-post]').disabled = !party || oversized.length > 0;

        showModal('#confirm-bill-modal');
    }

    /* ---------------------------------------------------------------------
     | Posting
     | ------------------------------------------------------------------ */

    function bodyFor(post) {
        return {
            date: el('[data-bill-date]').value,
            notes: el('[data-bill-notes]').value.trim() || null,
            post,
            // The same value on every attempt for this document. A retry after a
            // timeout lands on the row the first attempt wrote rather than a
            // second invoice.
            client_ref: state.clientRef,
            party_id: partyPicker.id(),
            items: itemsPayload(),
            ...billDiscountPayload(),
            payments: payments.value(),
            // Who did the work — M22. Absent on a purchase, where the server
            // refuses it: sending an empty array there would be the form asking
            // a question it has already been told is meaningless.
            ...(staff === null ? {} : { staff: staff.value() }),
        };
    }

    async function submit(post) {
        if (state.posting) return;

        state.posting = true;
        clearErrors();

        const button = post ? $('[data-confirm-post]') : el('[data-draft]');
        const idle = button.textContent;

        button.disabled = true;
        button.textContent = post ? 'Posting…' : 'Saving…';

        try {
            const payload = bodyFor(post);

            // The host may send this document somewhere other than its type
            // endpoint — a workshop bill goes through the job, which stamps the
            // invoice with it inside one database transaction. Null means the
            // ordinary way.
            const response = await submitWith(payload, { post })
                ?? await auth.call(`/transactions/${state.direction}`, { method: 'POST', body: payload });

            hideModal('#confirm-bill-modal');
            clearDraft();
            toast(response.message ?? 'Saved.');

            // Warnings ride back on the same response that confirms the posting,
            // and are shown *as well as* it: the bill did post, and somebody
            // still needs to look at it.
            (response.meta?.warnings ?? []).forEach((warning) => toast(warning.message, 'error'));

            onPosted(response);
        } catch (error) {
            hideModal('#confirm-bill-modal');
            paintError(error);
        } finally {
            state.posting = false;
            button.disabled = false;
            button.textContent = idle;
        }
    }

    /**
     * Put an API failure where somebody will see it — §27.
     *
     * Field errors go beside the field they are about; everything else becomes a
     * toast carrying the server's own sentence. The messages are already written
     * in plain language on the server ("Only 5 PCS available in stock."), so
     * there is deliberately no translation table here: a second copy of the
     * wording is a second thing to keep in step, and the copy is what goes stale.
     */
    function paintError(error) {
        if (error.fields) {
            Object.entries(error.fields).forEach(([field, messages]) => {
                const slot = el(`[data-error-for="${field.split('.')[0]}"]`);

                if (slot) {
                    slot.textContent = messages[0];
                    slot.classList.remove('hidden');
                }
            });
        }

        toast(error.message, 'error');
    }

    function clearErrors() {
        $$('[data-error-for]', root).forEach((slot) => {
            slot.textContent = '';
            slot.classList.add('hidden');
        });
    }

    /* ---------------------------------------------------------------------
     | §26 — the document survives the tab
     | ------------------------------------------------------------------ */

    function save() {
        if (!live) return;

        try {
            localStorage.setItem(draftKey, JSON.stringify({
                ...extraState(),
                clientRef: state.clientRef,
                partyId: partyPicker?.id() ?? null,
                date: el('[data-bill-date]')?.value ?? '',
                notes: el('[data-bill-notes]')?.value ?? '',
                lines: state.lines,
                billDiscount: el('[data-bill-discount]')?.value ?? '',
                billDiscountMode: state.billDiscountMode,
                payments: payments?.value() ?? [],
                // §26. A bill restored from a closed tab keeps the two names
                // that were picked — retyping the lines and forgetting the
                // fitter is the version of this bug nobody notices until the
                // month's figures are read.
                staff: staff?.value() ?? state.staffPicked ?? [],
                savedAt: Date.now(),
            }));
        } catch {
            // A full or disabled localStorage is not a reason to stop somebody
            // writing a bill. They simply lose the safety net, which is the same
            // position they were in before this existed.
        }
    }

    function clearDraft() {
        try {
            localStorage.removeItem(draftKey);
        } catch {
            // As above.
        }
    }

    /**
     * Put an unfinished document back.
     *
     * Runs during mount, while `save` is still inert — so reading the draft
     * cannot race the writes that painting the empty form sets off. It paints
     * nothing itself; boot does that once, after going live.
     */
    async function restoreSaved() {
        let saved = null;

        try {
            saved = JSON.parse(localStorage.getItem(draftKey) ?? 'null');
        } catch {
            saved = null;
        }

        if (!saved || !Array.isArray(saved.lines) || saved.lines.length === 0) return false;

        // A week is the outer edge of "I was in the middle of this". Older than
        // that and restoring it is not a rescue, it is a surprise — the prices
        // have moved and the customer has been and gone.
        if (Date.now() - (saved.savedAt ?? 0) > 7 * 24 * 60 * 60 * 1000) {
            clearDraft();

            return false;
        }

        // Before anything is put back, so a host that has its own mode to settle
        // — which document kind this was, which job it came off — has done so
        // while the form is still empty.
        await onRestore(saved);

        // The same reference the interrupted attempt would have used, so a bill
        // that was actually posted before the tab closed cannot be written twice.
        state.clientRef = saved.clientRef ?? state.clientRef;
        state.lines = saved.lines;
        state.lineSeq = saved.lines.reduce((max, line) => Math.max(max, line.id ?? 0), 0);

        if (saved.date) el('[data-bill-date]').value = saved.date;
        if (saved.notes) el('[data-bill-notes]').value = saved.notes;

        // A draft written before this existed has neither, and lands on rupees
        // and an empty box — which is what it meant.
        el('[data-bill-discount]').value = saved.billDiscount ?? '';
        state.billDiscountMode = saved.billDiscountMode === 'percent' ? 'percent' : 'amount';
        paintBillDiscountMode();

        if (saved.partyId) await partyPicker.load(saved.partyId);

        payments.set(saved.payments);

        /*
        | A draft written before this existed carries no `staff` key at all, and
        | lands on empty boxes — which is what it meant. Restored through the
        | component rather than by setting state alone, so the boxes on screen
        | agree with what the next post will send.
        */
        state.staffPicked = Array.isArray(saved.staff) ? saved.staff : [];
        staff?.set(state.staffPicked);

        return true;
    }

    /* ---------------------------------------------------------------------
     | The party, remounted whenever the direction changes
     | ------------------------------------------------------------------ */

    function mountParty() {
        partyPicker = mountPartyPicker(el('[data-party-host]'), {
            role: partyRole(),
            onSelect: () => {
                // The party's state code decides CGST+SGST against IGST, so
                // choosing one changes the total.
                refreshPreview();
                save();
            },
        });

        /*
        | Adding a counterparty without losing the bill — §4.
        |
        | The form is `components/quick-party.js`, shared with the Customers and
        | Vendors screens. What is decided here is the part that is the
        | *document's* business: the quick shape rather than the record one, the
        | role this document needs and not a choice between both, and putting
        | whoever comes back straight into the picker so the bill carries on.
        */
        partyPicker.onAdd((typed) => openQuickParty({
            role: partyRole(),
            name: typed,
            onSaved: (party) => {
                partyPicker.set(party);
                refreshPreview();
                save();
            },
        }));
    }

    /*
    | Who did the work — M22, and the sale's alone.
    |
    | A purchase carries none: goods arriving from a supplier were not fitted by
    | anybody in the building, and the server refuses the whole idea rather than
    | accepting and ignoring it. So the host is emptied rather than merely hidden
    | when the counter flips direction — a hidden box that still had a name in it
    | would put that name back on the next sale, silently.
    */
    function mountStaff() {
        const host = el('[data-staff-host]');

        if (!host) return;

        if (isPurchase()) {
            staff = null;
            host.innerHTML = '';
            host.classList.add('hidden');

            return;
        }

        staff = mountStaffAttribution(host, {
            slots: state.staffSlots,
            // Whatever was already picked survives a direction flip back — the
            // clerk who chose sale, then purchase, then sale again did not mean
            // to un-name the fitter.
            value: state.staffPicked ?? [],
            onChange: () => {
                state.staffPicked = staff.value();
                save();
            },
        });
    }

    /* ---------------------------------------------------------------------
     | Boot
     | ------------------------------------------------------------------ */

    el('[data-bill-date]').value = new Date().toISOString().slice(0, 10);

    paintBillDiscountMode();

    // The two quick-add dialogs are wired here rather than by the host, because
    // their markup arrives with the document's own partial and this file is what
    // opens them. Wiring them twice would submit them twice.
    initQuickItem();
    initQuickParty();

    mountParty();

    /** Whatever quick-item just made, straight onto the bill. */
    const addCreated = (created) => {
        if (!created) return;

        // Somebody who stopped mid-invoice to create a bearing wanted the
        // bearing on the invoice.
        addLine({
            key: `v:${created.id}`,
            item_id: created.item_id,
            variant_id: created.id,
            label: created.display_label ?? 'New item',
            unit_symbol: '',
            gst_rate: '0',
            quantity: null,
            price: created.sell_price ?? '',
        });

        itemPicker.refresh();
    };

    itemPicker = mountItemPicker(el('[data-item-host]'), {
        /*
        | The rate is prefilled on a sale and never on a purchase, so the line
        | under the box cannot claim "price comes from the shelf" on both. It had
        | been doing exactly that, and a purchase line that then opened at 0.00
        | read as a prefill that had failed rather than one that was withheld on
        | purpose — see docs/purchase-module.md.
        */
        hint: () => (isPurchase()
            ? 'Enter adds the highlighted row. Stock and unit come from the shelf; '
                + 'the rate comes from the supplier’s invoice.'
            : 'Enter adds the highlighted row. Stock, unit and price come from the shelf.'),

        onPick: (choice) => addLine(choice),
        onCreate: () => openQuickItem({ onCreated: addCreated }),

        /*
        | A family the workshop holds in stock but has never specified. Stock is
        | counted per variant, so it cannot go on a bill as itself — the form
        | that adds the missing specification opens instead, and what comes back
        | goes on the line the operator was trying to write.
        */
        onNeedsVariant: (item) => openQuickItem({ item, onCreated: addCreated }),
    });

    // The payment modes are the only reference data this form loads up front,
    // and it is four rows that never change.
    try {
        const { data } = await auth.call('/transactions/meta');
        state.paymentModes = data.payment_modes ?? [];
        // Who can be named on a sale — M22. It rides on the request the form was
        // already making, so recording this costs no round trip.
        state.staffSlots = data.staff_slots ?? [];
    } catch {
        state.paymentModes = [{ value: 'cash', label: 'Cash' }];
        // A failed meta call must not put empty pickers on the form. No slots
        // means no section, which is the same thing a workshop that records none
        // sees — and better than two boxes that can only be left blank.
        state.staffSlots = [];
    }

    mountStaff();

    payments = mountPaymentRows(el('[data-payments-host]'), {
        modes: state.paymentModes,
        outstanding: () => state.preview?.totals?.total ?? '0.00',
        onChange: save,
        // Money leaves on a purchase and arrives on a sale, and the section that
        // says where the rest of it sits has to name the right party.
        heading: () => (isPurchase() ? 'Paid now' : 'Collected now'),
        noun: partyRole,
        verb: () => (isPurchase() ? 'paid' : 'collected'),
        /*
        | A bill is the one document here that may go unsettled, so this is the
        | one place "On credit" is offered. A receipt against an invoice and an
        | expense both record money that has already moved — see `canCredit`.
        */
        canCredit: true,
    });

    /* --- events --------------------------------------------------------- */

    const lines = el('[data-lines]');

    lines.addEventListener('input', (event) => {
        const row = event.target.closest('[data-line]');

        if (!row) return;

        const line = state.lines.find((candidate) => candidate.id === Number(row.dataset.line));

        if (!line || !event.target.name) return;

        line[event.target.name] = event.target.value;

        // The row's own amount is repainted without a re-render, so the caret
        // stays where the operator left it. The footer is the server's and is
        // debounced.
        const amount = rowAmount(line);

        $$('td', row).at(-2).textContent = amount === null ? '—' : formatMoney(amount);

        // And so is its verdict, for exactly the same reason. Re-rendering the
        // table to show "quantity must be more than zero" would take the caret
        // out of the box the message is about, at the one moment somebody is
        // still typing in it.
        paintLineProblem(row, lineProblem(line));

        refreshPreview();
        save();
    });

    lines.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');

        if (remove) {
            removeLine(remove.closest('[data-line]').dataset.line);

            return;
        }

        const toggle = event.target.closest('[data-discount-mode]');

        if (!toggle) return;

        const row = toggle.closest('[data-line]');
        const line = state.lines.find((candidate) => candidate.id === Number(row.dataset.line));

        if (!line) return;

        line.discount_mode = line.discount_mode === 'percent' ? 'amount' : 'percent';

        // The typed figure is kept. "10" meaning ₹10 and "10" meaning 10% are
        // both things somebody meant to type, and clearing the box on a switch
        // would make the control cost a re-type every time it was pressed.
        renderLines();

        // The table was rebuilt, so the caret has to be put back — on the box
        // whose unit just changed, at the end of what is in it.
        const input = $(`[data-line="${line.id}"] input[name="discount"]`, root);

        input?.focus();
        input?.setSelectionRange(input.value.length, input.value.length);
    });

    el('[data-bill-date]').addEventListener('change', () => {
        refreshPreview();
        save();
    });

    el('[data-bill-notes]').addEventListener('input', save);

    el('[data-bill-discount]').addEventListener('input', () => {
        refreshPreview();
        save();
    });

    el('[data-bill-discount-mode]').addEventListener('click', () => {
        state.billDiscountMode = state.billDiscountMode === 'percent' ? 'amount' : 'percent';

        paintBillDiscountMode();
        el('[data-bill-discount]').focus();

        refreshPreview();
        save();
    });

    el('[data-post]').addEventListener('click', openConfirmation);
    el('[data-draft]').addEventListener('click', () => submit(false));

    // Delegated: the totals panel is repainted on every change, so the retry
    // button is a different node each time.
    el('[data-totals-host]').addEventListener('click', (event) => {
        if (event.target.closest('[data-preview-retry]')) refreshPreview();
    });

    /*
    | Document-scoped, and safe only because of what the shell guarantees.
    |
    | The confirmation and the two quick-add dialogs arrive with this partial, so
    | every host that includes it carries its own copy — Sales, Purchase and the
    | counter. Three ids that look like page-level singletons and are not.
    |
    | What keeps that from being a bug is that the shell holds at most one module
    | root *attached*: a `document` query resolves to the copy inside whichever
    | module is on screen, at mount and at every use after it. Scoping these to
    | `root` would be more obviously correct — but the dialogs are moved and
    | repainted by their own components, which look them up the same way, so
    | half-scoping them would be worse than either.
    |
    | The rule to keep: never attach two module roots at once.
    */
    $('#confirm-bill-modal').addEventListener('change', (event) => {
        if (!event.target.closest('[data-confirm-large]')) return;

        state.acknowledgedLarge = event.target.checked;
        $('[data-confirm-post]').disabled = !state.acknowledgedLarge || !partyPicker?.id();
    });

    $('[data-confirm-post]').addEventListener('click', () => submit(true));

    /**
     * Empty the document and start a new one.
     *
     * Two callers, and they mean the same thing by it: "start again" on the
     * restored-draft banner, and a module that has just posted one and wants the
     * form clear for the next (§2A.8).
     *
     * The counter answered the first with `location.reload()`, which a page
     * could get away with and a module cannot — it would drop the whole shell
     * (§3.2). Emptying in place is what the rule asks for, and it is faster.
     */
    function reset() {
        clearDraft();

        state.lines = [];
        state.lineSeq = 0;
        // A fresh document deserves a fresh reference, or the abandoned bill's
        // idempotency key would follow the next one in and the server would
        // answer with the first bill instead of writing the second.
        state.clientRef = crypto.randomUUID();

        partyPicker.set(null);
        payments.reset();

        // §2A.8 — the next sale starts with nobody named. Carrying the fitter
        // forward would be convenient exactly until the one invoice somebody
        // else did, which is the invoice nobody would check.
        state.staffPicked = [];
        staff?.clear();

        el('[data-bill-notes]').value = '';
        el('[data-bill-discount]').value = '';
        state.billDiscountMode = 'amount';
        paintBillDiscountMode();
        el('[data-restored]').classList.add('hidden');

        renderLines();
    }

    el('[data-discard-draft]').addEventListener('click', reset);

    /* --- the unfinished document, then go live -------------------------- */

    const restored = restoreDraft ? await restoreSaved() : false;

    live = true;

    renderLines();

    el('[data-restored]').classList.toggle('hidden', !restored);

    /* --- the handle the host keeps -------------------------------------- */

    return {
        /** Sale or purchase. Remounts the picker, because the role decides what
         *  it searches and half a chosen vendor left in a customer field is the
         *  shape of bug that bills the wrong party. */
        setDirection(next) {
            state.direction = next;
            mountParty();
            // Who did the work is a sale's question only, so the section arrives
            // and leaves with the direction.
            mountStaff();
            renderLines();
            // The payment section names a party and an act, and the item hint
            // says where a rate comes from. All three just changed. Repainted
            // rather than remounted, so what was typed survives.
            payments?.repaint();
            itemPicker?.repaintHint();
        },

        party: () => partyPicker,

        /** The live array. A host that mutates it must call `repaint()`. */
        lines: () => state.lines,

        addLine,

        clearLines() {
            state.lines = [];
            state.lineSeq = 0;
            renderLines();
        },

        repaint: renderLines,
        reset,

        /** The ways money can move, fetched once at mount. Shared so a host does
         *  not make a second request for the four rows it already has. */
        paymentModes: () => state.paymentModes,

        /**
         * Who did the work — M22. Null on a purchase, so every caller uses `?.`.
         *
         * Handed out so a host can load a document's names back in — a correction
         * does, a repeat deliberately does not. See `bill-revision.js`.
         *
         * The wrapper mirrors into `staffPicked` on the way through, because the
         * autosaved draft is written from state: a `set()` that only reached the
         * component would be lost the moment the tab closed, which on a
         * correction means the replacement posts crediting nobody.
         */
        /**
         * The trades this workshop records on a sale, as fetched at mount.
         *
         * Handed out so a host can raise the same pickers elsewhere — Sales'
         * correction dialog does — without a second `/transactions/meta` call
         * for a list this form is already holding.
         */
        staffSlots: () => state.staffSlots,

        staff: () => (staff === null ? null : {
            value: () => staff.value(),
            set(pairs) {
                staff.set(pairs);
                state.staffPicked = staff.value();
                save();
            },
        }),
    };
}
