import auth from '../auth-client';
import { $, $$, confirmAction, toast } from '../ui';
import { describeShortfalls } from './stock-position';

/**
 * Loading a document back into the create form — a posted one to correct or to
 * repeat, or a draft to finish — and sending it where that kind of document goes.
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
 * ## Three entry points, one loader
 *
 * `begin()` loads a document as a **correction**: the original's date, its
 * notes, its lines and its prices, with a handle that sends the post to
 * `/revise`.
 *
 * `continueDraft()` loads a **draft** — the reason somebody saved one in the
 * first place was to come back to it. Everything it was saved with comes back,
 * its terms included, and the handle sends the form's own two buttons to the
 * draft rather than to a new document: "Save as a draft" rewrites it with
 * `PATCH`, and posting rewrites it and then authorises it with `/post`. Both are
 * the draft's own row, so it keeps its id, and the list never shows the draft
 * and a second copy of it side by side.
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
        // `correct` — a posted document being reissued — or `draft` — a draft
        // being finished. The two send the same payload to different places, and
        // telling them apart is the whole of what this handle is for.
        kind: null,
        id: null,
        docNo: null,
        // Generated once per correction, not per attempt. A value regenerated on
        // retry would make the second tap look like a different correction,
        // which is precisely what it must not look like — see `CarriesClientRef`.
        ref: null,
    };

    const banner = $('[data-revise-banner]', root);

    /*
    | One banner for both, because it answers one question — "what will the post
    | button do to which document?" — and the two answers differ in every word.
    | The copy for each lives in the template, side by side, and this shows the
    | one that is true.
    */
    function paintBanner() {
        if (!banner) return;

        banner.classList.toggle('hidden', revising.id === null);
        banner.classList.toggle('flex', revising.id !== null);

        if (revising.id === null) return;

        $('[data-revise-title]', banner).textContent = revising.kind === 'draft'
            ? `Continuing ${revising.docNo}`
            : `Correcting ${revising.docNo}`;

        $$('[data-revise-for]', banner).forEach((node) => {
            node.classList.toggle('hidden', node.dataset.reviseFor !== revising.kind);
        });
    }

    function clearHandle() {
        revising.kind = null;
        revising.id = null;
        revising.docNo = null;
        revising.ref = null;
    }

    /** Whether loading something would throw away work the form is holding. */
    function holdingWork() {
        return revising.id !== null || doc().lines().length > 0;
    }

    /**
     * Put a posted document's contents into the empty form.
     *
     * A full `reset()` rather than `clearLines()`, because everything the form is
     * holding belongs to whatever was being written before this — the payment
     * split most of all. A document that inherited the last one's cash line would
     * post money nobody paid.
     */
    async function loadInto(bill, { keepDate, keepNotes, keepRates, keepStaff, keepTerms = false }) {
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

        // The vendor's number goes with the note: a correction is still that
        // vendor bill, a repeat is a new one they have not printed yet. After
        // the party, so the duplicate check has a vendor to ask about — and
        // told which bill this is, so it does not report the bill against itself.
        doc().setVendorInvoice(keepNotes ? bill.vendor_invoice_no : '', keepNotes ? bill.id : null);

        (bill.items ?? []).forEach((line) => doc().addLine({
            key: `${line.item_id}:${line.variant_id ?? ''}`,
            item_id: line.item_id,
            variant_id: line.variant_id,
            label: line.description ?? `Line ${line.line_no}`,
            unit_symbol: line.unit_symbol ?? '',
            gst_rate: line.gst_rate ?? '0',
            // Which basis the rate above was struck on. Carried for the same
            // reason the rate is, and it matters more: price and rate together
            // cannot say whether ₹118 meant ₹118 or ₹139.24, so a correction
            // reloaded without this restates the whole document at a total the
            // original never carried.
            price_includes_tax: line.price_includes_tax === true,
        }, {
            quantity: line.quantity,
            // See the docblock: omitted on a repeated purchase, and only there.
            ...(keepRates ? { unit_price: line.unit_price } : {}),
            /*
            | A draft keeps a percentage as the percentage that was typed, and it
            | goes back in that unit: resolved to rupees it would stop following
            | the line when the quantity is changed, which is exactly what
            | somebody finishing a draft is likely to do. A posted line only ever
            | has rupees.
            */
            ...(line.discount_percent !== null && line.discount_percent !== undefined
                ? { discount: line.discount_percent, discount_mode: 'percent' }
                : { discount: line.discount_amount ?? '0' }),
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

        /*
        | The document's terms — a draft's only. A posted bill's are already
        | inside its lines and its total, and its split is money that moved; a
        | correction is refused once any has, so there is never one to carry.
        |
        | Last, after the party and the lines, because naming a party and adding
        | a line each forget a short settlement (see `putTerms`).
        */
        if (keepTerms) {
            const terms = bill.bill_terms ?? {};
            const splits = (bill.payments ?? []).map((split) => ({
                mode: split.mode,
                amount: split.amount,
                reference: split.reference ?? '',
            }));

            doc().setTerms({
                billDiscount: terms.bill_discount_percent ?? terms.bill_discount ?? '',
                billDiscountMode: terms.bill_discount_percent !== null && terms.bill_discount_percent !== undefined
                    ? 'percent'
                    : 'amount',
                payments: splits,
                /*
                | Settled short is "paid in full", by definition. Otherwise no
                | split is credit — or, for a walk-in sale, the full payment the
                | form insists on anyway — and a split is read the way the
                | autosave reads one: part paid until the total says otherwise.
                */
                settlement: terms.settle_total ? 'full' : (splits.length === 0 ? 'credit' : null),
                settleAt: terms.settle_total ?? null,
            });
        }

        // Lines that were not picked carry no "on hand" figure. Not awaited: the
        // form is usable while the figures catch up.
        doc().refreshAvailability();
    }

    /**
     * Rewrite the draft with what the form says, and authorise it if asked.
     *
     * A **whole restatement**, every time. The server merges an edit over the
     * request it stored, so a term the form leaves out — and it leaves out a
     * bill discount or a short settlement that is not there any more, because
     * a new bill has no reason to name one — would survive from the old draft
     * into the posted bill. So each of those is named, as null, unless the form
     * is sending a figure for it.
     *
     * Posting is two requests, the rewrite and then `/post`, rather than one:
     * authorising a draft is its own act on the server, kept apart so that
     * saving an edit can never commit it by accident. If the second is refused
     * the first has still saved, which is the right half to keep — the form
     * still shows exactly what the draft now holds.
     */
    async function sendDraft(payload, { post }) {
        const id = revising.id;
        // `post` is the create form's instruction and `client_ref` is a new
        // document's name; a draft is neither created nor posted by this call.
        const restated = { ...payload };
        delete restated.post;
        delete restated.client_ref;

        try {
            const saved = await auth.call(`/transactions/${id}`, {
                method: 'PATCH',
                body: {
                    bill_discount: null,
                    bill_discount_percent: null,
                    settle_total: null,
                    ...restated,
                },
            });

            if (!post) return saved;

            return await auth.call(`/transactions/${id}/post`, { method: 'POST' });
        } catch (error) {
            throw await explainLostDraft(id, error);
        }
    }

    /**
     * Say what became of a draft the form can no longer write to.
     *
     * Two ways for that to happen, and the server's own words for both are
     * written for an API caller: it was posted — by somebody else, or by this
     * form on an attempt whose answer never arrived — or it was discarded. Either
     * way the edits on screen are not in it, and the one thing that must not
     * happen is somebody being told so in a sentence that reads like a glitch
     * and pressing again. Anything else is passed through untouched.
     */
    async function explainLostDraft(id, error) {
        if (error.status === 404) {
            return new Error(`${revising.docNo} has been discarded, so there is nothing left to save this to. `
                + `Stop editing, then start a new ${nouns.document} if it is still needed.`);
        }

        if (!['TRANSACTION_IMMUTABLE', 'TRANSACTION_NOT_A_DRAFT'].includes(error.code)) return error;

        try {
            const { data } = await auth.call(`/transactions/${id}`);

            if (data?.status === 'posted') {
                return new Error(`${revising.docNo} has already been posted as ${data.doc_no ?? `#${data.id}`}, `
                    + 'so this form can no longer change it. Open it from the list to check what it says.');
            }
        } catch {
            // The original refusal is still true, and still says why.
        }

        return error;
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
            const id = saved?.revising?.id ?? null;

            Object.assign(revising, {
                // A handle saved before drafts could be continued has no kind,
                // and every one of those was a correction.
                kind: id === null ? null : (saved?.revising?.kind ?? 'correct'),
                id,
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

            revising.kind = 'correct';
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
         * Pick a draft up where it was left — the reason it was saved as one.
         *
         * Everything comes back: the date and the notes it was saved with, the
         * lines at the prices typed, each line's discount in the unit it was
         * typed in and its tax basis, the bill discount, the split and how it was
         * being settled, and who did the work. Leaving any of it behind would
         * post a bill that differs from the draft in a way nothing on the screen
         * shows.
         *
         * Asks first only where the form is holding something else, because that
         * is the one thing loading would lose. The draft itself is untouched
         * until somebody saves or posts.
         */
        async continueDraft(bill) {
            const docNo = `Draft #${bill.id}`;

            if (holdingWork()) {
                const sameDraft = revising.kind === 'draft' && Number(revising.id) === Number(bill.id);
                let body = `The ${nouns.document} on the form has not been saved. Continuing this draft `
                    + 'replaces it.';

                if (sameDraft) {
                    body = `${docNo} is already on the form. Loading it again puts back what was last `
                        + 'saved, and any change made since is lost.';
                } else if (revising.kind === 'correct') {
                    body = `You are in the middle of correcting ${revising.docNo}. Continuing this draft `
                        + 'puts the correction down — nothing has been posted, so nothing is lost from '
                        + 'the books.';
                } else if (revising.kind === 'draft') {
                    body = `${revising.docNo} is on the form. Continuing this draft puts it down — `
                        + 'anything not saved to it since is lost.';
                }

                const ok = await confirmAction({
                    title: 'Replace what is on the form?',
                    body,
                    confirmLabel: 'Continue the draft',
                    tone: 'primary',
                });

                if (!ok) return false;
            }

            revising.kind = 'draft';
            revising.id = bill.id;
            revising.docNo = docNo;
            // A draft is rewritten in place and authorised by id, so a retry can
            // never become a second document — there is nothing to name.
            revising.ref = null;

            workspace()?.showForm();
            paintBanner();

            await loadInto(bill, {
                keepDate: true, keepNotes: true, keepRates: true, keepStaff: true, keepTerms: true,
            });

            toast(`Continuing ${docNo}. Save it again, or post it when it is ready.`, 'info');

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

        /**
         * Put the correction — or the draft — down without posting it. Nothing
         * has moved, and a draft is left exactly as it was last saved.
         */
        cancel() {
            const wasDraft = revising.kind === 'draft';
            const docNo = revising.docNo;

            clearHandle();
            paintBanner();
            doc().reset();
            doc().party().focus();

            if (wasDraft) toast(`Stopped. ${docNo} is as it was last saved.`, 'info');
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
         * Take a draft off the form because it was posted or discarded from its
         * drawer. Left there, the form would go on saying "Continuing" a
         * document that no longer accepts edits, and the next press of Save
         * would be the first anybody heard of it. Returns whether the form was
         * holding it, so the host can say so.
         */
        release(id) {
            // Compared as numbers: a drawer opened from a row carries the id it
            // read out of a data attribute, which is a string.
            if (revising.kind !== 'draft' || Number(revising.id) !== Number(id)) return false;

            clearHandle();
            paintBanner();
            doc().reset();

            return true;
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

            if (revising.kind === 'draft') return sendDraft(payload, { post });

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
