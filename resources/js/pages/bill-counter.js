import auth from '../auth-client';
import { mountBillDocument } from '../components/bill-document';
import { $, $$, debounce, esc, hideModal, showModal, toast } from '../ui';

/**
 * The bill counter — the brief's §2, §4, §5, §12, §25, §26 and §27.
 *
 * What is left of it. The document — lines, the server-priced total, the
 * confirmation, the payment split, the autosaved draft and the post — is
 * `components/bill-document.js`, shared with the Purchase module. This file is
 * the two things that are the counter's alone:
 *
 *   * **which kind of document is being written**, chosen from three cards
 *     rather than a select, because it is the first decision and it is made
 *     once;
 *   * **billing a workshop job**, which is a sale that came off a job card and
 *     has to post through the job rather than past it.
 *
 * The engine knows nothing about either, and that is deliberate: the Purchase
 * module writes one kind of document and bills no jobs, and it should not ship
 * the code for a chooser it does not paint (§7.2).
 *
 * ## A workshop bill is not a fourth type
 *
 * It lands on `sale`, with the same lines and the same tax. What differs is
 * where it is posted — `/workshop-jobs/{id}/bill` stamps the invoice with the
 * job and points each part at the line that consumed it, inside one database
 * transaction. Posting the identical payload to `/transactions/sale` would
 * produce an invoice the job knew nothing about, and the job would happily bill
 * the same bearings again next week. That is also why a job bill cannot be
 * parked as a draft: the draft path is the ordinary sale one, which has no way
 * to stamp anything.
 */

const state = {
    // `sale`, `purchase` or `workshop`. The third posts through the job; it is a
    // way of writing the document, not a fourth transaction type.
    kind: 'sale',
    job: null,
};

/** The document engine. Everything about the bill itself lives behind this. */
let doc = null;

/* -------------------------------------------------------------------------
 | Kind
 | ---------------------------------------------------------------------- */

function setKind(kind) {
    state.kind = kind;

    $$('[data-kind]').forEach((card) => {
        const on = card.dataset.kind === kind;

        card.setAttribute('aria-pressed', String(on));
        $('[data-check]', card)?.classList.toggle('hidden', !on);
    });

    const selling = kind !== 'purchase';

    $('[data-counter-title]').textContent = kind === 'workshop'
        ? 'Workshop bill'
        : selling ? 'New sale' : 'New purchase';

    $('[data-counter-hint]').textContent = selling
        ? 'Tax follows each item’s HSN rate and the two state codes. Cost of goods is the weighted average '
            + 'at the moment you post.'
        : 'Stock arrives at the price before tax — the claimable GST is not part of what it cost.';

    // A workshop bill is a sale, so only two directions ever reach the engine.
    doc.setDirection(selling ? 'sale' : 'purchase');

    if (kind === 'workshop') {
        openJobPicker();
    } else {
        clearJob();
    }
}

/* -------------------------------------------------------------------------
 | Workshop bills
 | ---------------------------------------------------------------------- */

function clearJob() {
    state.job = null;
    $('[data-job-banner]').classList.add('hidden');

    const draftButton = $('[data-draft]');

    draftButton.disabled = false;
    draftButton.removeAttribute('title');
}

async function loadJob(id, { silent = false } = {}) {
    try {
        const { data, meta } = await auth.call(`/workshop-jobs/${id}/bill-preview`);

        state.job = { id, ...meta.job };

        const banner = $('[data-job-banner]');

        banner.classList.remove('hidden');
        banner.innerHTML = `
            Billing <strong>${esc(meta.job.job_no)}</strong> — ${esc(meta.job.motor)}.
            The invoice will be stamped with this job, and its parts marked as billed.
            <button type="button" class="ml-2 font-semibold underline" data-change-job>Change</button>`;

        /*
        | A workshop bill cannot be parked as a draft, and the button says so
        | rather than quietly doing something else. The honest options are to
        | raise the invoice or to leave the parts on the job card, which is
        | already a perfectly good record of unfinished work.
        */
        const draftButton = $('[data-draft]');

        draftButton.disabled = true;
        draftButton.title = 'A workshop bill is raised against its job, so it cannot be parked as a draft. '
            + 'The job card is already the record of work not yet billed.';

        if (silent) return;

        // The payload's own lines replace whatever was on the counter: a job bill
        // is the job's parts, and merging them with something half-typed would
        // produce an invoice nobody chose.
        doc.clearLines();

        await doc.party().load(data.party_id);

        $('[data-bill-notes]').value = data.notes ?? '';

        // Resolved through the catalogue so each line carries its live stock and
        // its unit — the payload is ids and amounts, and a row that could not say
        // what was on the shelf would be a step backwards from the picker.
        for (const item of data.items) {
            doc.addLine({
                key: item.variant_id ? `v:${item.variant_id}` : `i:${item.item_id}`,
                item_id: item.item_id,
                variant_id: item.variant_id,
                label: item.memo ?? `Item #${item.item_id}`,
                unit_symbol: '',
                gst_rate: '0',
                quantity: null,
                price: item.unit_price,
            }, {
                quantity: item.quantity,
                unit_price: item.unit_price,
                discount: item.discount,
                memo: item.memo,
            });
        }

        await labelJobLines();
    } catch (error) {
        toast(error.message, 'error');
        clearJob();
    }
}

/**
 * Give the job's lines their proper names and stock figures.
 *
 * The bill-preview payload is ids and amounts — it is the *posting* payload, and
 * rightly carries nothing a screen invented. So each line is looked up once, and
 * until that lands the rows read "Item #14". Doing it in one pass afterwards
 * rather than one request per line keeps a ten-part job to a single round trip.
 */
async function labelJobLines() {
    const lines = doc.lines();
    const variantIds = lines.map((line) => line.variant_id).filter(Boolean);

    if (variantIds.length === 0) return;

    try {
        const { data } = await auth.call('/stock?per_page=200&is_active=1');
        const byVariant = new Map(data.map((row) => [row.variant_id, row]));

        lines.forEach((line) => {
            const row = byVariant.get(line.variant_id);

            if (!row) return;

            line.label = row.item?.name === row.display_label
                ? row.display_label
                : `${row.item?.name ?? ''} · ${row.display_label}`;
            line.unit_symbol = row.item?.base_uom_symbol ?? '';
            line.gst_rate = row.item?.gst_rate ?? '0';
            line.available = row.quantity;
            line.average_cost = row.average_cost ?? null;
        });

        doc.repaint();
    } catch {
        // The rows keep their placeholder names. The bill is still correct — the
        // ids are what get posted — and the labels are cosmetic.
    }
}

async function openJobPicker() {
    showModal('#job-picker-modal');
    await searchJobs('');
    $('[data-job-search]').focus();
}

async function searchJobs(term) {
    const host = $('[data-job-results]');

    host.innerHTML = '<p class="px-3 py-6 text-center text-sm text-muted-foreground">Loading…</p>';

    try {
        const { data } = await auth.call(
            `/workshop-jobs?open=1&per_page=20&search=${encodeURIComponent(term)}`
        );

        const billable = data.filter((job) => job.is_billable);

        host.innerHTML = billable.length
            ? billable.map((job) => `
                <button type="button" class="selection-card w-full" data-job="${job.id}">
                    <span class="min-w-0 flex-1 text-left">
                        <span class="block text-sm font-semibold text-foreground">
                            ${esc(job.job_no)} — ${esc(job.party?.name ?? '')}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            ${esc(job.motor)} · ${esc(job.complaint)}
                        </span>
                    </span>
                    <span class="badge bg-sky-100 text-sky-800">${esc(job.status_label)}</span>
                </button>`).join('')
            : `<p class="px-3 py-6 text-center text-sm text-muted-foreground">
                   No job here can be billed yet. Work has to have started on one first.
               </p>`;
    } catch (error) {
        host.innerHTML = `<p class="px-3 py-6 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initBillCounter() {
    // A deep link from a job screen, or from the Customers and Vendors screens —
    // `/bills/new?kind=purchase&party=12` and `/bills/new?job=41`. Read before
    // the document is mounted, because whether there is one decides whether the
    // engine goes looking for an unfinished bill at all.
    const params = new URLSearchParams(window.location.search);
    const kind = params.get('kind');
    const job = params.get('job');
    const party = params.get('party');

    doc = await mountBillDocument($('[data-bill-document]'), {
        // Keeps the key the counter has always saved under, so a draft written
        // before this refactor is still found afterwards.
        key: 'bill-counter',

        // A deep link wins over a saved draft: somebody who clicked "bill this
        // job" asked for that job, not for what they were doing yesterday.
        restoreDraft: !job && !party,

        // What a workshop bill carries beyond the document itself, and what has
        // to be settled before a restored one is put back.
        extraState: () => ({ kind: state.kind, jobId: state.job?.id ?? null }),

        onRestore: async (saved) => {
            setKind(saved.kind ?? 'sale');

            if (saved.jobId) await loadJob(saved.jobId, { silent: true });
        },

        // A job bill posts through the job. Null for everything else, which
        // means "the ordinary way" — see the note at the top of this file.
        submitWith: async (payload) => {
            if (!state.job) return null;

            return auth.call(`/workshop-jobs/${state.job.id}/bill`, {
                method: 'POST',
                body: {
                    date: payload.date,
                    notes: payload.notes,
                    client_ref: payload.client_ref,
                    items: payload.items,
                    payments: payload.payments,
                },
            });
        },

        // This screen's whole purpose is the one bill, so it hands the operator
        // to the list once it is written. The Purchase module stays on its form
        // instead, because a clerk writes several in a row (§2A.8).
        onPosted: () => window.location.assign('/bills'),
    });

    if (job) {
        setKind('workshop');
        hideModal('#job-picker-modal');
        await loadJob(job);
    } else if (kind === 'purchase' || kind === 'sale') {
        setKind(kind);
    }

    if (party) await doc.party().load(party);

    /* --- events ------------------------------------------------------- */

    $$('[data-kind]').forEach((card) => {
        card.addEventListener('click', () => setKind(card.dataset.kind));
    });

    // Beside the engine's own handler on the same button, not instead of it: it
    // empties the document, and this puts the counter's own mode back with it.
    $('[data-discard-draft]').addEventListener('click', () => setKind('sale'));

    // The job picker.
    const jobSearch = debounce((term) => searchJobs(term), 250);

    $('[data-job-search]').addEventListener('input', (event) => jobSearch(event.target.value));

    $('[data-job-results]').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-job]');

        if (!button) return;

        hideModal('#job-picker-modal');
        await loadJob(button.dataset.job);
    });

    $('[data-job-banner]').addEventListener('click', (event) => {
        if (event.target.closest('[data-change-job]')) openJobPicker();
    });

    // Somebody who backs out of the job picker without choosing one has changed
    // their mind about writing a workshop bill, not about writing a bill.
    $('#job-picker-modal').addEventListener('click', (event) => {
        if (event.target.matches('[data-modal]') || event.target.closest('[data-modal-close]')) {
            if (!state.job) setKind('sale');
        }
    });
}
