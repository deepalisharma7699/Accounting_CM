import auth from '../auth-client';
import { $, confirmAction, toast } from '../ui';
import { describeShortfalls } from './stock-position';

/**
 * Loading a posted document back into the create form — and, if it is a
 * correction, posting it as one.
 *
 * ## Why this is a component and not two copies
 *
 * Purchase built this first: a posted bill comes back into the module's own
 * create form, a banner says what the post button is now going to do, and
 * posting calls `/revise`, which reverses the original and issues the corrected
 * document in its place inside one database transaction. Sales needs exactly
 * that, and Bills will make three.
 *
 * The parts that would go wrong in a second copy are not the obvious ones. They
 * are the banner surviving onto a blank document, the correction handle being
 * dropped from the autosaved draft, the client reference being regenerated per
 * attempt instead of per correction, and a correction being allowed to park as a
 * draft. Each of those is a one-line mistake with a several-document
 * consequence, and each is solved once here.
 *
 * ## Two entry points, one loader
 *
 * `begin()` loads a document as a **correction**: the original's date, its
 * notes, its lines and its prices, with a handle that sends the post to
 * `/revise`.
 *
 * `repeat()` loads the same lines as a **new document**: today's date, no notes,
 * no handle. Nothing about the original is touched, and posting writes an
 * ordinary bill. It exists because a workshop that services the same pump every
 * quarter should not re-type the same six lines, and because the loader for it
 * already existed.
 *
 * ## The one place direction matters
 *
 * A correction always carries the original's rate, on either side of the
 * counter: the figure is already known, and re-typing it is how a correction to
 * a quantity turns into an accidental change to the cost basis.
 *
 * A **repeat of a purchase** deliberately does not. Stock arrives at the line's
 * taxable value and that arrival is what recomputes the weighted average, so a
 * stale rate carried forward from last quarter's delivery would put a wrong cost
 * on the shelf — the same reason `bill-document.js` leaves a new purchase's rate
 * box empty. A repeat of a sale carries its prices, which is the whole point of
 * repeating one.
 */

/**
 * Both `doc` and `workspace` are **getters**, not values, and that is not
 * fussiness. This has to be mounted before the document engine is, because the
 * engine calls `onRestore` during its own mount and a restored correction has to
 * find its banner already wired. Passing the objects themselves would mean
 * passing whatever they were before either existed.
 *
 * @param {Element} root      the module root — the banner lives inside it
 * @param {object}  options
 * @param {Function} options.doc        () => the mounted bill document
 * @param {Function} options.workspace  () => the mounted workspace
 * @param {'sale'|'purchase'} options.direction
 * @param {object}  options.nouns      { document } — "invoice", "bill"
 */
export function mountBillRevision(root, { doc, workspace, direction, nouns }) {
    /**
     * The document currently being corrected, or nulls.
     *
     * Module-level rather than read back off the DOM, because the engine posts
     * through `submitWith` long after the click that started the correction, and
     * a handle rebuilt at that point could not tell a correction from an
     * ordinary new document — which is the one mistake here that reverses a
     * document nobody meant to touch.
     */
    const revising = {
        id: null,
        docNo: null,
        // Generated once per correction, not per attempt. A value regenerated on
        // retry would make the second tap look like a different correction,
        // which is precisely what it must not look like — see `CarriesClientRef`.
        ref: null,
    };

    const banner = $('[data-revise-banner]', root);

    function paintBanner() {
        if (!banner) return;

        banner.classList.toggle('hidden', revising.id === null);
        banner.classList.toggle('flex', revising.id !== null);

        if (revising.id !== null) {
            $('[data-revise-title]', banner).textContent = `Correcting ${revising.docNo}`;
        }
    }

    function clearHandle() {
        revising.id = null;
        revising.docNo = null;
        revising.ref = null;
    }

    /**
     * Put a posted document's contents into the empty form.
     *
     * A full `reset()` rather than `clearLines()`, because everything the form is
     * holding belongs to whatever was being written before this — the payment
     * split most of all. A document that inherited the last one's cash line would
     * post money nobody paid.
     */
    async function loadInto(bill, { keepDate, keepNotes, keepRates, keepStaff }) {
        doc().reset();

        $('[data-bill-date]', root).value = keepDate
            ? (bill.date ?? todayISO())
            : todayISO();

        // A note belongs to the document it was written on — "collected in cash,
        // cheque 4471" is a fact about that day, not about the next one.
        $('[data-bill-notes]', root).value = keepNotes ? (bill.notes ?? '') : '';

        // Loaded by id rather than set from the drawer's copy, which carries only
        // an id, a name and the roles — the same path a restored draft takes, so
        // the picker gets the whole record including the state code that decides
        // CGST+SGST against IGST.
        if (bill.party?.id) await doc().party().load(bill.party.id);

        (bill.items ?? []).forEach((line) => doc().addLine({
            key: `${line.item_id}:${line.variant_id ?? ''}`,
            item_id: line.item_id,
            variant_id: line.variant_id,
            label: line.description ?? `Line ${line.line_no}`,
            unit_symbol: line.unit_symbol ?? '',
            gst_rate: line.gst_rate ?? '0',
        }, {
            quantity: line.quantity,
            // See the docblock: omitted on a repeated purchase, and only there.
            ...(keepRates ? { unit_price: line.unit_price } : {}),
            discount: line.discount_amount ?? '0',
            memo: line.memo,
        }));

        /*
        | Who did the work — M22, and only on a correction.
        |
        | A correction is the *same* repair being re-documented, so the names come
        | with it; dropping them would make every correction quietly un-credit
        | whoever did the job, which is the worst possible way to lose the
        | figures — the document looks complete.
        |
        | A repeat is new work. The same customer is having the same thing done
        | again, and there is no reason at all to think the same two people did
        | it. Carrying the names forward there would credit somebody for a motor
        | they never touched, on a document nobody would think to check.
        */
        doc().staff()?.set(keepStaff ? (bill.staff ?? []) : []);
    }

    return {
        isActive: () => revising.id !== null,

        /** For the document engine's `extraState` — see `restore`. */
        state: () => ({ revising: { ...revising } }),

        /**
         * For the document engine's `onRestore`. The correction has to ride along
         * in the autosaved draft: without it a correction abandoned by closing
         * the tab comes back looking like an ordinary new document carrying the
         * original's lines — and posting that writes a *second* document for
         * work that happened once, with the original still standing. The lines
         * being restored is exactly what makes it dangerous, because the form
         * looks finished and correct.
         */
        restore(saved) {
            Object.assign(revising, {
                id: saved?.revising?.id ?? null,
                docNo: saved?.revising?.docNo ?? null,
                ref: saved?.revising?.ref ?? null,
            });

            paintBanner();
        },

        /**
         * Correct a posted document — the one action on a ledger anywhere in this
         * application that edits something already in the books.
         *
         * A posted transaction stays immutable. What happens is that the document
         * is loaded back into the create form, and posting it writes the reversal
         * and the replacement as one act — the two documents a careful bookkeeper
         * would have written by hand, minus the chance of doing only half of it.
         *
         * The alternative was to keep telling people to work it out themselves: a
         * partial note for a quantity that is too high, a reversal and a full
         * re-entry for anything else. Both are correct, neither is discoverable,
         * and "fix a typo" is the commonest thing a clerk needs to do.
         */
        async begin(bill) {
            const docNo = bill.doc_no ?? `#${bill.id}`;

            const ok = await confirmAction({
                title: `Correct ${docNo}?`,
                body: `The ${nouns.document} comes back into the form so you can change it. Posting the `
                    + 'correction reverses this document and issues the corrected one in its place — both '
                    + 'stay on the record, and the stock moves by the difference. Nothing happens until '
                    + 'you post.',
                confirmLabel: 'Correct it',
                tone: 'primary',
            });

            if (!ok) return false;

            revising.id = bill.id;
            revising.docNo = docNo;
            revising.ref = crypto.randomUUID();

            // The form, not the list — the correction *is* a create form, and
            // landing anywhere else would leave the banner painted on a screen
            // with no document under it.
            workspace()?.showForm();
            paintBanner();

            await loadInto(bill, {
                keepDate: true, keepNotes: true, keepRates: true, keepStaff: true,
            });

            toast(`Correcting ${docNo}. Change what is wrong and post it.`, 'info');

            return true;
        },

        /**
         * Start a new document from an old one. The original is not touched and
         * is not referenced — what this writes is an ordinary bill.
         */
        async repeat(bill) {
            // Never on top of a correction. The banner would still be up, and the
            // post would reverse a document whose lines had just been replaced by
            // a different one's.
            clearHandle();

            workspace()?.showForm();
            paintBanner();

            await loadInto(bill, {
                keepDate: false,
                keepNotes: false,
                keepRates: direction === 'sale',
                // New work — see loadInto.
                keepStaff: false,
            });

            toast(
                direction === 'sale'
                    ? 'Copied. Check the quantities and prices before posting.'
                    : 'Copied. The rates are left blank — enter what the supplier is charging now.',
                'info',
            );
        },

        /** Put the correction down without posting it. Nothing has moved. */
        cancel() {
            clearHandle();
            paintBanner();
            doc().reset();
            doc().party().focus();
        },

        /**
         * A correction that posted is over. Called before the form is reset, so
         * the banner cannot survive onto the next blank document and claim a
         * document is being corrected when none is.
         */
        finish() {
            if (revising.id === null) return;

            clearHandle();
            paintBanner();
        },

        /**
         * Where a correction posts, and where it refuses.
         *
         * Handed to the document engine as `submitWith`, which is the hook a host
         * uses to send the identical payload somewhere other than the type
         * endpoint. Null means "the ordinary way", which is what every document
         * except a correction gets.
         */
        async submit(payload, { post }) {
            if (revising.id === null) return null;

            // A correction is posted or it is nothing. Parking one as a draft
            // would leave somebody believing the document had been fixed while
            // the original still stands, and the server refuses it for the same
            // reason.
            //
            // Thrown rather than toasted: the engine's own catch puts the message
            // on screen, and saying it here as well would say it twice.
            if (!post) {
                throw new Error('A correction cannot be saved as a draft. Post it, or cancel the correction.');
            }

            const body = { ...payload, client_ref: revising.ref };
            const send = (extra = {}) => auth.call(`/transactions/${revising.id}/revise`, {
                method: 'POST',
                body: { ...body, ...extra },
            });

            try {
                return await send();
            } catch (error) {
                /*
                | The one refusal a workshop may knowingly overrule: the corrected
                | quantity is below what has already gone out, so the shelf ends
                | up negative. That is a state somebody can accept and then fix
                | with a stock count.
                |
                | Deliberately the *only* one. REVISION_WOULD_RESTATE_COST carries
                | no acknowledgement, because "the cost of goods sold on last
                | quarter's invoice is now a different number" is not something
                | anybody can meaningfully agree to — see the exception.
                */
                if (error.code !== 'REVERSAL_WOULD_GO_NEGATIVE') throw error;

                const accepted = await confirmAction({
                    title: 'This correction would take stock below zero',
                    body: `${describeShortfalls(error.details?.shortfalls ?? [])} Nothing has been changed `
                        + 'yet. Posting anyway leaves the shelf showing a negative until a stock count '
                        + 'corrects it.',
                    confirmLabel: 'Correct it anyway',
                });

                if (!accepted) throw error;

                return send({ acknowledge_negative_stock: true });
            }
        },
    };
}

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}
