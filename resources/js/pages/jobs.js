import auth from '../auth-client';
import { badge, formatQuantity } from '../components/badge';
import { mountBillDocument } from '../components/bill-document';
import { mountItemPicker } from '../components/item-picker';
import { mountPartyPicker } from '../components/party-picker';
import { openQuickParty } from '../components/quick-party';
import { can } from '../permissions';
import { clearModuleParams, moduleParams, registerEscape } from '../shell';
import {
    $, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Jobs — the bench. M19, and the brief's §16 to §18 and §23. C4.
 *
 * ```
 * card → RECEIVE A MOTOR           ← always lands here (§2A.1, §2A.5)
 *      → "Show list (12)"          → what is on the bench
 *      → row → drawer (level 2)    → the pipeline, the parts, the estimate
 *                                  → Correct the card (a state of the drawer)
 *                                  → Generate bill → the document, at level 1
 *                                  → confirm (level 3)
 * ```
 *
 * ## The one thing this screen has to keep saying
 *
 * **A part written onto a job moves no stock.** The card says so where parts are
 * added, and the invoice is where the shelf finally changes. That is decision D2
 * and it is the sort of rule that is obvious in a design document and baffling
 * at a counter, so it is stated on the screen rather than only in the schema.
 *
 * ## Why the pipeline is a row of buttons and not a select
 *
 * Because the legal moves depend on where the job is, and the server already
 * knows them: every job carries `next_states`. Rendering exactly those means a
 * fitter cannot pick a move that will be refused, and a state added to
 * `WorkshopJobStatus` appears here without this file being touched. A `<select>`
 * of all seven would offer six wrong answers and one right one.
 *
 * ## The bill is a level-1 pane, not a drawer state
 *
 * `components/bill-document.js` is a two-column form with a searched item
 * picker, a line table, a payment split and a sticky totals panel. §2.1 calls a
 * list with filters and forms inside a dialog a scroll trap, and that is exactly
 * what it would become in a drawer. So the form surface holds two panes —
 * booking a motor in, and billing one — with one shown at a time, the same
 * judgement §2A.2 makes one level up.
 *
 * ## Why the lines are sent only when they were changed
 *
 * This is the part that is wrong in a way that looks right. `POST {job}/bill`
 * pairs each part with the invoice line it became **by position**, and that
 * pairing only holds while the lines are the ones `billPayloadFor()` produced —
 * so `JobService::bill()` marks nothing at all when the payload carries an
 * `items` key. The shared document always builds one. Left alone, every bill
 * raised here would leave every part unbilled and the same bearings would be
 * billable again next week.
 *
 * So the document's lines are fingerprinted the moment they are loaded, and
 * `items` is **omitted** when nothing about them changed — which is the ordinary
 * case, since the operator usually presses Generate bill and posts. Where a rate
 * really was argued down at the counter the lines are sent, the invoice posts,
 * and the parts stay visible on the job card. That is the service's deliberate
 * "safe way to be wrong", and the banner says so before anybody presses post.
 */

const PAGE_SIZE = 25;

const state = {
    search: '',
    status: '',
    open: true,
    overdue: false,
    page: 1,
    hasMore: false,
    total: null,

    statuses: [],
    counts: {},

    // The job the drawer is showing, kept so an action can re-read it without a
    // second lookup of which row was clicked.
    current: null,
};

/*
| The job the bill pane is being written against, or null.
|
| `baseline` is the fingerprint of the lines as they were loaded — see the note
| at the top of this file. Everything else on it is what the banner says.
*/
let billing = null;

/** The job whose card is being corrected, or null. */
let editing = null;

let root = null;
let formRoot = null;
let listRoot = null;
let intakePane = null;
let billPane = null;

let workspace = null;
let doc = null;
let form = null;
let jobParty = null;

/** Anything outside the two swapped surfaces — the drawer. Always attached. */
const el = (selector) => $(selector, root);

/*
| Scoped to their own surface, because §2A.2 keeps exactly one of the form and
| the list attached. For half this module's life `[data-job-body]` is not a
| descendant of `root` at all, and that is precisely when a post wants to bring
| the list up to date. Querying a node works whether or not it is in the
| document; querying `root` for it returns null and throws on the first
| `.innerHTML`.
*/
const listEl = (selector) => $(selector, listRoot);
const formEl = (selector) => $(selector, formRoot);

/* -------------------------------------------------------------------------
 | Decimal strings, compared without floats
 | ---------------------------------------------------------------------- */

/**
 * A decimal string as scaled integer digits — "1.5" at three places is "1500".
 *
 * Used only to tell two figures apart, never to add them, but the arithmetic
 * rule holds all the same: `Number('0.1') + Number('0.2')` is why nothing in
 * this application compares money by parsing it.
 */
function scaledDigits(amount, places) {
    const text = String(amount ?? '').trim();

    if (text === '' || text === 'null' || text === 'undefined') return '0';

    const negative = text.startsWith('-');
    const [whole = '', fraction = ''] = text.replace(/^[-+]/, '').split('.');
    const padded = `${fraction}${'0'.repeat(places)}`.slice(0, places);
    const digits = `${whole || '0'}${padded}`.replace(/^0+(?=\d)/, '');

    return `${negative && digits !== '0' ? '-' : ''}${digits}`;
}

/**
 * One line, reduced to everything the server would post about it.
 *
 * Takes either shape: a line the document is holding (`discount_mode`) or a line
 * the engine has already built for the request (`discount_percent`). Both reduce
 * to the same string, so retyping the same rate is not mistaken for a change —
 * which matters, because a false "changed" is what leaves a part unbilled.
 */
function lineFingerprint(line) {
    const percent = line.discount_mode === 'percent' ? line.discount : line.discount_percent;
    const amount = line.discount_mode === 'percent' ? '0' : line.discount;

    return [
        line.item_id ?? '',
        line.variant_id ?? '',
        scaledDigits(line.quantity, 3),
        scaledDigits(line.unit_price, 2),
        scaledDigits(amount, 2),
        scaledDigits(percent, 4),
        line.price_includes_tax === true ? 'incl' : 'plus',
        (line.memo ?? '').trim(),
    ].join('|');
}

const fingerprint = (lines) => lines.map(lineFingerprint).join('\n');

/**
 * What the motor is, where anything about it is known.
 *
 * `motorLabel()` falls back to the job number when the plate said nothing and
 * nobody typed anything — which is right for a Motor column and wrong beside the
 * number itself, where it reads "JOB/26-27/1006 — JOB/26-27/1006". A pump
 * wheeled in by a driver who knew none of it is a job this form accepts on
 * purpose, so this is the ordinary case rather than an edge one.
 */
const motorOf = (job) => (job.motor && job.motor !== job.job_no ? job.motor : null);

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);
    if (state.status) params.set('status', state.status);
    if (state.open && !state.status) params.set('open', '1');
    if (state.overdue) params.set('overdue', '1');

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    listEl('[data-job-body]').innerHTML = tableMessage(7, 'Loading…');

    try {
        const payload = await auth.call(`/workshop-jobs?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        listEl('[data-job-body]').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(7, 'Your account administers the platform rather than a single workshop, so it has no jobs of its own.')
            : tableMessage(7, error.message, 'error');
    }
}

/** A refetch after a write. Only where a list is actually held (§2A.7). */
function refetch() {
    if (workspace?.hasList()) load();
}

function render(rows, meta) {
    listEl('[data-job-body]').innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(7, 'Nothing here. Book a motor in and it will appear on the bench.');

    const pagination = meta?.pagination ?? {};

    state.hasMore = Boolean(pagination.has_more);
    state.total = pagination.total ?? null;

    listEl('[data-job-summary]').textContent = pagination.total
        ? `${rows.length} of ${pagination.total}.`
        : '';
    listEl('[data-job-prev]').disabled = (pagination.current_page ?? 1) <= 1;
    listEl('[data-job-next]').disabled = !state.hasMore;

    // The count rides on the Show control (§2A.4), so a list that just grew has
    // to repaint the heading as well as the table.
    workspace?.refresh();
}

function renderRow(job) {
    // §2A.8 — a motor booked in while the list was detached carries the flash
    // whenever the list is next looked at, not at a moment nobody was watching.
    const flash = workspace?.isNew(job.id) ? ' row-new' : '';

    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
            data-job="${job.id}" tabindex="0" role="link" aria-label="Open ${esc(job.job_no)}">

            <td class="table-cell w-36">
                <span class="block font-mono text-[0.8125rem] font-medium text-foreground">${esc(job.job_no)}</span>
                ${job.part_count ? `<span class="text-xs text-muted-foreground">${esc(String(job.part_count))} parts</span>` : ''}
            </td>

            <td class="table-cell text-[0.8125rem]">${esc(job.party?.name ?? '—')}</td>

            <td class="table-cell text-[0.8125rem]">${esc(job.motor)}</td>

            <td class="table-cell max-w-xs truncate text-[0.8125rem] text-muted-foreground">
                ${esc(job.complaint)}
            </td>

            <td class="table-cell w-36">
                ${badge(job.status_label, job.status_tone)}
                ${job.is_overdue ? badge('Late', 'danger') : ''}
            </td>

            <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">
                ${job.billed && job.billed.count
                    ? esc(formatMoney(job.billed.total))
                    : '<span class="text-muted-foreground">—</span>'}
            </td>

            <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">
                ${esc(formatDate(job.received_date))}
                ${job.promised_date
                    ? `<span class="block text-xs text-muted-foreground">by ${esc(formatDate(job.promised_date))}</span>`
                    : ''}
            </td>
        </tr>`;
}

/* -------------------------------------------------------------------------
 | Tabs
 | ---------------------------------------------------------------------- */

async function loadMeta() {
    try {
        const { data } = await auth.call('/workshop-jobs/meta');

        state.statuses = data.statuses ?? [];
        state.counts = data.counts ?? {};
    } catch {
        state.statuses = [];
        state.counts = {};
    }

    renderTabs();
}

function renderTabs() {
    const open = state.statuses
        .filter((status) => status.is_open)
        .reduce((sum, status) => sum + (state.counts[status.value] ?? 0), 0);

    const tabs = [
        { value: '', label: 'On the bench', count: open },
        ...state.statuses.map((status) => ({
            value: status.value,
            label: status.label,
            count: state.counts[status.value] ?? 0,
        })),
    ];

    listEl('[data-job-tabs]').innerHTML = tabs.map((tab) => `
        <button type="button" class="tab" role="tab" data-tab="${esc(tab.value)}"
                aria-selected="${tab.value === state.status}">
            ${esc(tab.label)}
            <span class="ml-1.5 text-xs text-muted-foreground">${esc(String(tab.count))}</span>
        </button>`).join('');
}

/* -------------------------------------------------------------------------
 | The job card — level 2
 | ---------------------------------------------------------------------- */

async function openDrawer(id) {
    closeEdit();

    el('#job-drawer-title').textContent = 'Job';
    el('[data-drawer-subtitle]').textContent = '';
    el('[data-drawer-status]').innerHTML = '';
    el('[data-drawer-actions]').innerHTML = '';
    el('[data-drawer-body]').innerHTML =
        '<p class="px-6 py-6 text-sm text-muted-foreground">Loading…</p>';

    showModal('#job-drawer');

    await refreshDrawer(id);
}

async function refreshDrawer(id) {
    try {
        const { data } = await auth.call(`/workshop-jobs/${id}`);

        state.current = data;

        el('#job-drawer-title').textContent = motorOf(data)
            ? `${data.job_no} — ${motorOf(data)}`
            : data.job_no;
        el('[data-drawer-subtitle]').textContent =
            `${data.party?.name ?? ''} · received ${formatDate(data.received_date)}`
            + (data.promised_date ? ` · promised ${formatDate(data.promised_date)}` : '');
        el('[data-drawer-status]').innerHTML = badge(data.status_label, data.status_tone);

        el('[data-drawer-body]').innerHTML = renderCard(data);
        paintDrawerActions(data);

        mountPartPicker();
    } catch (error) {
        el('[data-drawer-body]').innerHTML =
            `<p class="px-6 py-6 text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

function renderCard(job) {
    const mayWrite = can('UPDATE', 'WORKSHOP_JOBS');

    return `
        ${renderPipeline(job, mayWrite)}
        ${renderComplaint(job)}
        ${renderParts(job, mayWrite)}
        ${renderEstimate(job, mayWrite)}
        ${renderBills(job)}`;
}

/**
 * Where the job is, and exactly the moves the server says are legal from there.
 */
function renderPipeline(job, mayWrite) {
    if (!mayWrite || job.next_states.length === 0) return '';

    return `
        <div class="flex flex-wrap items-center gap-2 border-b border-border px-6 py-4">
            <span class="text-[0.8125rem] text-muted-foreground">Move to</span>
            ${job.next_states.map((next) => `
                <button type="button" class="btn btn-secondary btn-sm" data-advance="${esc(next.value)}">
                    ${esc(next.label)}
                </button>`).join('')}
        </div>`;
}

function renderComplaint(job) {
    return `
        <div class="border-b border-border px-6 py-4">
            <h3 class="text-sm font-semibold text-foreground">What the customer reported</h3>
            <p class="mt-1 text-[0.8125rem] text-secondary-foreground">${esc(job.complaint)}</p>
            ${job.serial_no
                ? `<p class="mt-2 text-xs text-muted-foreground">Serial ${esc(job.serial_no)}</p>`
                : ''}
            ${job.notes ? `<p class="mt-2 text-[0.8125rem] text-muted-foreground">${esc(job.notes)}</p>` : ''}
        </div>`;
}

function renderParts(job, mayWrite) {
    const parts = job.parts ?? [];

    return `
        <div class="border-b border-border px-6 py-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-foreground">Parts and labour</h3>
                <span class="text-[0.8125rem] text-muted-foreground">
                    Not yet billed: <span class="font-mono">${esc(formatMoney(job.unbilled_total ?? '0.00'))}</span>
                </span>
            </div>

            <p class="mt-0.5 text-xs text-muted-foreground">
                Adding a part here moves no stock. The bearing leaves the shelf when the invoice posts.
            </p>

            ${parts.length ? `
                <table class="mt-3 w-full border-collapse text-[0.8125rem]">
                    <tbody>
                        ${parts.map((part) => `
                            <tr class="border-t border-border ${part.is_billed ? 'text-muted-foreground' : ''}">
                                <td class="px-2 py-2">
                                    ${esc(part.description)}
                                    ${part.is_billed ? badge('Billed', 'neutral') : ''}
                                    ${part.memo ? `<span class="block text-xs text-muted-foreground">${esc(part.memo)}</span>` : ''}
                                </td>
                                <td class="px-2 py-2 text-right font-mono">
                                    ${esc(formatQuantity(part.quantity, part.unit_symbol))}
                                </td>
                                <td class="px-2 py-2 text-right font-mono">${esc(formatMoney(part.unit_price))}</td>
                                <td class="px-2 py-2 text-right font-mono font-semibold">
                                    ${esc(formatMoney(part.line_total))}
                                </td>
                                <td class="px-2 py-2 text-right">
                                    ${mayWrite && !part.is_billed
                                        ? `<button type="button" class="btn btn-ghost btn-icon"
                                                   data-remove-part="${part.id}"
                                                   aria-label="Remove ${esc(part.description)}">×</button>`
                                        : ''}
                                </td>
                            </tr>`).join('')}
                    </tbody>
                </table>`
            : '<p class="mt-3 text-[0.8125rem] text-muted-foreground">Nothing on this job yet.</p>'}

            ${mayWrite && job.is_open ? `
                <div class="mt-4 grid gap-3 sm:grid-cols-[1fr_6rem_7rem_auto] sm:items-end" data-part-form>
                    <div data-part-picker-host></div>

                    <label class="field">
                        <span class="field-label">Qty</span>
                        <input type="text" class="field-input text-right font-mono" inputmode="decimal"
                               value="1" data-part-quantity>
                    </label>

                    <label class="field">
                        <span class="field-label">Rate</span>
                        <input type="text" class="field-input text-right font-mono" inputmode="decimal"
                               placeholder="0.00" data-part-price>
                    </label>

                    <button type="button" class="btn btn-primary" data-add-part disabled>Add</button>
                </div>
                <p class="mt-1 text-xs text-muted-foreground" data-part-chosen></p>` : ''}
        </div>`;
}

function renderEstimate(job, mayWrite) {
    return `
        <div class="border-b border-border px-6 py-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-foreground">Estimate</h3>

                <div class="flex items-center gap-2">
                    ${job.has_estimate
                        ? `<span class="font-mono text-[0.8125rem]">${esc(formatMoney(job.estimate_total))}</span>
                           ${job.estimate_approved_at
                                ? badge('Approved', 'success')
                                : badge('Awaiting approval', 'warning')}`
                        : '<span class="text-[0.8125rem] text-muted-foreground">Not quoted</span>'}
                </div>
            </div>

            <p class="mt-0.5 text-xs text-muted-foreground">
                A quotation, not a document — nothing is posted until it becomes an invoice. The total is
                before tax.
            </p>

            ${job.has_estimate ? `
                <table class="mt-3 w-full border-collapse text-[0.8125rem]">
                    <tbody>
                        ${job.estimate_lines.map((line) => `
                            <tr class="border-t border-border">
                                <td class="px-2 py-1.5">${esc(line.description)}</td>
                                <td class="px-2 py-1.5 text-right font-mono">${esc(line.quantity)}</td>
                                <td class="px-2 py-1.5 text-right font-mono">${esc(formatMoney(line.unit_price))}</td>
                            </tr>`).join('')}
                    </tbody>
                </table>` : ''}

            ${mayWrite && job.is_open ? `
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-quote-from-parts>
                        ${job.has_estimate ? 'Re-quote from the parts' : 'Quote from the parts'}
                    </button>

                    ${job.has_estimate && !job.estimate_approved_at
                        ? '<button type="button" class="btn btn-primary btn-sm" data-approve-estimate>Customer approved</button>'
                        : ''}

                    ${job.has_estimate
                        ? '<button type="button" class="btn btn-ghost btn-sm" data-apply-estimate>Copy onto the job</button>'
                        : ''}
                </div>` : ''}
        </div>`;
}

function renderBills(job) {
    const bills = job.bills ?? [];

    if (bills.length === 0) return '';

    return `
        <div class="px-6 py-4">
            <h3 class="text-sm font-semibold text-foreground">Invoices off this job</h3>

            <table class="mt-3 w-full border-collapse text-[0.8125rem]">
                <tbody>
                    ${bills.map((bill) => `
                        <tr class="border-t border-border">
                            <td class="px-2 py-1.5 font-mono">${esc(bill.doc_no ?? `#${bill.id}`)}</td>
                            <td class="px-2 py-1.5">${esc(formatDate(bill.date))}</td>
                            <td class="px-2 py-1.5">${esc(bill.status_label)}</td>
                            <td class="px-2 py-1.5 text-right font-mono font-semibold">
                                ${esc(formatMoney(bill.total))}
                            </td>
                        </tr>`).join('')}
                </tbody>
            </table>

            <p class="mt-2 text-[0.8125rem] text-muted-foreground">
                Paid <span class="font-mono">${esc(formatMoney(job.billed?.paid ?? '0.00'))}</span> ·
                Due <span class="font-mono">${esc(formatMoney(job.billed?.due ?? '0.00'))}</span>
            </p>
        </div>`;
}

/**
 * The footer.
 *
 * Raising the invoice needs WRITE:TRANSACTIONS as well as the jobs grant, and
 * the route enforces both — recording a repair and posting to the ledger are
 * different authorities, which is the whole reason M19 has a permission of its
 * own.
 */
function paintDrawerActions(job) {
    const buttons = [];

    if (job.is_billable && can('WRITE', 'TRANSACTIONS')) {
        buttons.push('<button type="button" class="btn btn-primary" data-generate-bill>Generate bill</button>');
    }

    if (can('UPDATE', 'WORKSHOP_JOBS')) {
        buttons.push('<button type="button" class="btn btn-secondary" data-edit-job>Correct the card</button>');
    }

    // Grants less than it sounds like: a job with a bill against it cannot be
    // deleted by anybody, and the server says so rather than this screen
    // guessing at it.
    if (can('DELETE', 'WORKSHOP_JOBS')) {
        buttons.push('<button type="button" class="btn btn-ghost ml-auto text-rose-600" data-delete-job>Delete</button>');
    }

    el('[data-drawer-actions]').innerHTML = buttons.join('');
}

/* -------------------------------------------------------------------------
 | Adding a part
 | ---------------------------------------------------------------------- */

let pendingPart = null;

/**
 * The same picker the bill document uses, so a part is chosen the same way
 * whether it is written onto a job or straight onto an invoice — and so the
 * stock badge is here too, where the fitter is standing next to the shelf.
 *
 * No "+ Create a new item" and no variant dialog. The one quick-add modal in
 * this module belongs to the bill document, which is a level-1 surface — and it
 * is therefore detached exactly while this drawer is open over the list. A
 * second copy would be two nodes with one id. The picker already says where a
 * missing item comes from.
 */
function mountPartPicker() {
    const host = el('[data-part-picker-host]');

    if (!host) return;

    pendingPart = null;

    mountItemPicker(host, {
        hint: 'Anything on the catalogue. A part that is not on it yet is added from the Items card.',
        onPick: (choice) => {
            pendingPart = choice;

            el('[data-part-chosen]').textContent = `${choice.label} — ${choice.unit_symbol || 'each'}`;
            el('[data-add-part]').disabled = false;

            const price = el('[data-part-price]');

            if (price && !price.value && choice.price) price.value = choice.price;
        },
    });
}

async function addPart(jobId) {
    if (!pendingPart) return;

    try {
        await auth.call(`/workshop-jobs/${jobId}/parts`, {
            method: 'POST',
            body: {
                item_id: pendingPart.item_id,
                variant_id: pendingPart.variant_id,
                quantity: el('[data-part-quantity]').value.trim() || '1',
                unit_price: el('[data-part-price]').value.trim() || null,
            },
        });

        toast('Added to the job.');
        await refreshDrawer(jobId);
        refetch();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function removePart(jobId, partId, description) {
    const confirmed = await confirmAction({
        title: 'Take this part off the job?',
        body: `${description} will be removed. Nothing has been billed for it, so nothing in the books changes.`,
        confirmLabel: 'Remove',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/workshop-jobs/${jobId}/parts/${partId}`, { method: 'DELETE' });

        toast('Removed.');
        await refreshDrawer(jobId);
        refetch();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Actions on the card
 | ---------------------------------------------------------------------- */

async function advance(jobId, status) {
    try {
        const response = await auth.call(`/workshop-jobs/${jobId}/status`, {
            method: 'PUT',
            body: { status },
        });

        toast(response.message ?? 'Moved.');
        await refreshDrawer(jobId);
        await loadMeta();
        refetch();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/**
 * Quote from what is already on the job.
 *
 * The other direction — copying an approved estimate onto the job as parts — is
 * `data-apply-estimate`. Both exist because a workshop works in both directions:
 * sometimes the quotation comes first and sometimes the fitter opens the motor
 * up and then prices what they found.
 */
async function quoteFromParts(job) {
    const lines = (job.parts ?? [])
        .filter((part) => !part.is_billed)
        .map((part) => ({
            item_id: part.item_id,
            variant_id: part.variant_id,
            quantity: part.quantity,
            unit_price: part.unit_price,
            discount: part.discount_amount,
            memo: part.memo,
        }));

    if (lines.length === 0) {
        toast('There is nothing on the job to quote from yet.', 'info');

        return;
    }

    try {
        await auth.call(`/workshop-jobs/${job.id}/estimate`, { method: 'PUT', body: { lines } });

        toast('Estimate saved.');
        await refreshDrawer(job.id);
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function jobAction(jobId, path, message) {
    try {
        await auth.call(`/workshop-jobs/${jobId}/${path}`, { method: 'POST' });

        toast(message);
        await refreshDrawer(jobId);
    } catch (error) {
        toast(error.message, 'error');
    }
}

/**
 * Deleting a job.
 *
 * The endpoint had no caller anywhere until C4, which meant a motor booked in
 * against the wrong customer stayed on the bench for ever. A job with a bill
 * against it is refused by the server, and the refusal is shown rather than
 * pre-empted here: what a job has been billed for is the server's answer.
 */
async function destroyJob(job) {
    const confirmed = await confirmAction({
        title: `Delete ${job.job_no}?`,
        body: 'The job card, its parts and its estimate go with it. Anything already billed off this job '
            + 'stays in the books, and a job that has been billed cannot be deleted at all.',
        confirmLabel: 'Delete the job',
        tone: 'danger',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/workshop-jobs/${job.id}`, { method: 'DELETE' });

        hideModal('#job-drawer');
        toast(`${job.job_no} deleted.`);

        state.current = null;

        await loadMeta();
        refetch();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Booking a motor in, and correcting the card
 |
 | One form node for both. `adoptForm()` moves it between the level-1 pane and
 | the drawer, and the blocks marked `data-form-chrome` decide which frame is
 | shown — the customer and the received date are inline only, because neither
 | is editable once the job exists and `UpdateJobRequest` accepts neither.
 | ---------------------------------------------------------------------- */

function resetForm() {
    clearFormErrors(form);
    form.reset();
    form.elements.received_date.value = new Date().toISOString().slice(0, 10);
    jobParty.set(null);
}

function fillForm(job) {
    clearFormErrors(form);

    form.elements.complaint.value = job.complaint ?? '';
    form.elements.hp.value = job.hp ?? '';
    form.elements.phase.value = job.phase ?? '';
    form.elements.brand.value = job.brand ?? '';
    form.elements.model.value = job.model ?? '';
    form.elements.serial_no.value = job.serial_no ?? '';
    form.elements.promised_date.value = job.promised_date ?? '';
    form.elements.notes.value = job.notes ?? '';
}

function openEdit(job) {
    editing = job.id;

    fillForm(job);
    adoptForm(form, el('[data-job-edit-slot]'), { chrome: 'modal' });

    el('[data-job-edit-slot]').classList.remove('hidden');
    el('[data-drawer-body]').classList.add('hidden');
    el('[data-drawer-actions]').innerHTML = '';
}

/**
 * Give the form back to the create pane.
 *
 * Idempotent, and called from three places — Cancel, the drawer closing, and
 * Escape — because a form left behind in a hidden drawer is a create surface
 * with no fields on it, and nothing on screen would say why.
 */
function closeEdit() {
    if (editing === null) return;

    editing = null;

    /*
    | Before the move, while the drawer's own Save is still the visible submit
    | button. `setSubmitting` restores whichever one is on screen — so releasing
    | it afterwards would release the create pane's button instead and leave
    | "Saving…", disabled, on a control nobody could press again.
    */
    setSubmitting(form, false);

    adoptForm(form, formEl('[data-job-form-slot]'), { chrome: 'inline' });

    el('[data-job-edit-slot]').classList.add('hidden');
    el('[data-drawer-body]').classList.remove('hidden');

    resetForm();

    if (state.current) paintDrawerActions(state.current);
}

async function submitForm(event) {
    event.preventDefault();

    const editingId = editing;

    clearFormErrors(form);
    setSubmitting(form, true, editingId ? 'Saving…' : 'Booking in…');

    try {
        if (editingId) {
            await auth.call(`/workshop-jobs/${editingId}`, {
                method: 'PATCH',
                body: {
                    hp: form.elements.hp.value.trim() || null,
                    phase: form.elements.phase.value || null,
                    brand: form.elements.brand.value.trim() || null,
                    model: form.elements.model.value.trim() || null,
                    serial_no: form.elements.serial_no.value.trim() || null,
                    complaint: form.elements.complaint.value.trim(),
                    promised_date: form.elements.promised_date.value || null,
                    notes: form.elements.notes.value.trim() || null,
                },
            });

            closeEdit();
            toast('Job card corrected.');

            await refreshDrawer(editingId);
            refetch();

            return;
        }

        const response = await auth.call('/workshop-jobs', {
            method: 'POST',
            body: {
                party_id: jobParty?.id() ?? null,
                complaint: form.elements.complaint.value.trim(),
                hp: form.elements.hp.value.trim() || null,
                phase: form.elements.phase.value || null,
                brand: form.elements.brand.value.trim() || null,
                model: form.elements.model.value.trim() || null,
                serial_no: form.elements.serial_no.value.trim() || null,
                received_date: form.elements.received_date.value || null,
                promised_date: form.elements.promised_date.value || null,
                notes: form.elements.notes.value.trim() || null,
            },
        });

        /*
        | §2A.8 — the counter takes in three motors off one pickup, so a booking
        | stays on the form, clears it and puts the cursor back on the customer.
        | The new row is flagged rather than shown: whoever is booking them in
        | never sees the list in between.
        */
        const created = response.data;

        const motor = motorOf(created);

        resetForm();
        paintOutcome(`
            <p><strong>${esc(created.job_no)}</strong> is on the bench${motor ? ` — ${esc(motor)}` : ''}.
            Write the number on the casing.</p>
            <button type="button" class="mt-1 font-semibold underline" data-outcome-job="${created.id}">
                Open the job card
            </button>`);

        workspace?.flagNew(created.id);
        jobParty.focus();

        await loadMeta();
        refetch();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, editingId ? 'Save changes' : 'Book it in');
    }
}

/* -------------------------------------------------------------------------
 | What just happened, above the cleared form
 |
 | A line rather than a toast, for C3's reason: §2A.8 empties the form the
 | instant something posts, and a toast is gone before somebody has finished
 | reading a number they may need to write down.
 | ---------------------------------------------------------------------- */

function paintOutcome(html) {
    const host = formEl('[data-job-outcome]');

    host.innerHTML = `
        <span class="mt-0.5 shrink-0 text-emerald-600">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>
            </svg>
        </span>
        <div class="min-w-0 flex-1">${html}</div>`;

    host.classList.remove('hidden');
    host.classList.add('flex');
}

function clearOutcome() {
    const host = formEl('[data-job-outcome]');

    host.classList.add('hidden');
    host.classList.remove('flex');
    host.innerHTML = '';
}

/* -------------------------------------------------------------------------
 | The bill
 | ---------------------------------------------------------------------- */

function showBillPane() {
    intakePane.classList.add('hidden');
    billPane.classList.remove('hidden');
    paintFormSubtitle();
}

function showIntakePane() {
    billPane.classList.add('hidden');
    intakePane.classList.remove('hidden');
    paintFormSubtitle();
}

/**
 * Keep the workspace heading honest across the pane swap.
 *
 * `formSubtitle` is a fixed string by design — the frame is built once — so the
 * line is rewritten here instead, and re-applied from `onShowForm` because
 * `setMode` repaints the header just before it runs.
 */
function paintFormSubtitle() {
    const sub = $('[data-ws-sub]', root);

    if (!sub || workspace?.mode() === 'list') return;

    if (!billing) {
        sub.textContent = 'Take a motor in. A job number is issued straight away, '
            + 'so there is something to write on the casing.';

        return;
    }

    sub.textContent = billing.motor
        ? `Billing ${billing.job_no} — ${billing.motor}.`
        : `Billing ${billing.job_no}.`;
}

/**
 * Load the job's unbilled parts onto the document.
 *
 * The lines are built from three reads and then added once, rather than added
 * as ids and relabelled afterwards. That is not tidiness: the rate a line is
 * priced at depends on whether the figure in the box already has the GST in it,
 * the answer to that is the *item's* default, and the first `/transactions/preview`
 * runs the moment a line exists. Adding lines that did not know it would show a
 * total the post would not match.
 */
async function loadBill(job) {
    const { data, meta } = await auth.call(`/workshop-jobs/${job.id}/bill-preview`);

    const items = data.items ?? [];

    if (items.length === 0) {
        toast('There is nothing on this job left to bill.', 'info');

        return false;
    }

    // The parts themselves, for their descriptions and units. Already in hand:
    // `part_ids` comes back in the same order as the lines it produced.
    const parts = new Map((state.current?.parts ?? []).map((part) => [String(part.id), part]));
    const partIds = data.part_ids ?? [];

    const variantIds = [...new Set(items.map((item) => item.variant_id).filter(Boolean))];
    const bareIds = [...new Set(items.filter((item) => !item.variant_id).map((item) => item.item_id))];

    const [stock, bare] = await Promise.all([
        variantIds.length
            ? auth.call(`/stock?per_page=200&${variantIds.map((id) => `variant_ids[]=${encodeURIComponent(id)}`).join('&')}`)
                .catch(() => ({ data: [] }))
            : Promise.resolve({ data: [] }),
        // Labour and anything else that is not counted on a shelf, one read each.
        // There is no id filter on /items, and a job carries one or two of these.
        Promise.all(bareIds.map((id) => auth.call(`/items/${id}`).catch(() => null))),
    ]);

    const byVariant = new Map((stock.data ?? []).map((row) => [row.variant_id, row]));
    const byItem = new Map(bare.filter(Boolean).map((response) => [response.data.id, response.data]));

    doc.clearLines();

    await doc.party().load(data.party_id);

    /*
    | The customer is the job's, and the endpoint takes it from there —
    | `BillJobRequest` accepts no `party_id` at all. A box that could be retyped
    | would be a box whose answer is silently ignored.
    */
    doc.party().lock();

    formEl('[data-bill-date]').value = data.date ?? new Date().toISOString().slice(0, 10);
    formEl('[data-bill-notes]').value = data.notes ?? '';

    items.forEach((item, index) => {
        const part = parts.get(String(partIds[index] ?? ''));
        const stocked = item.variant_id ? byVariant.get(item.variant_id) : null;
        const catalogue = stocked?.item ?? byItem.get(item.item_id) ?? null;

        const label = stocked
            ? (stocked.item?.name === stocked.display_label
                ? stocked.display_label
                : `${stocked.item?.name ?? ''} · ${stocked.display_label}`)
            : (part?.description ?? catalogue?.name ?? `Item #${item.item_id}`);

        doc.addLine({
            key: item.variant_id ? `v:${item.variant_id}` : `i:${item.item_id}`,
            item_id: item.item_id,
            variant_id: item.variant_id,
            label,
            unit_symbol: catalogue?.base_uom_symbol ?? part?.unit_symbol ?? '',
            gst_rate: catalogue?.gst_rate ?? '0',
            quantity: stocked?.quantity ?? null,
            average_cost: stocked?.average_cost ?? null,
            price_includes_tax: catalogue?.price_includes_tax === true,
        }, {
            quantity: item.quantity,
            unit_price: item.unit_price,
            discount: item.discount ?? '0',
            memo: item.memo,
        });
    });

    billing = {
        id: job.id,
        job_no: meta.job.job_no,
        motor: motorOf(meta.job),
        baseline: fingerprint(doc.lines()),
    };

    const customer = state.current?.party?.name;
    const named = billing.motor ? `${billing.job_no} — ${billing.motor}` : billing.job_no;

    formEl('[data-job-bill-title]').textContent = customer
        ? `Billing ${named}, for ${customer}.`
        : `Billing ${named}.`;
    formEl('[data-job-bill-note]').textContent =
        'Posted as it stands, each part is marked off the job card. Change, add or remove a line and the '
        + 'invoice still posts — but the parts stay on the card, because line three is no longer part three.';

    return true;
}

async function generateBill(job) {
    try {
        if (!await loadBill(job)) return;
    } catch (error) {
        toast(error.message, 'error');

        return;
    }

    hideModal('#job-drawer');
    clearOutcome();

    // The document is a create surface, so the workspace has to be on the form
    // for it to be attached at all.
    workspace?.showForm();
    showBillPane();

    formEl('[data-bill-document] [data-item-host] input')?.focus();
}

/** Leave the job unbilled. The parts are still on its card, which is the record. */
function cancelBill() {
    billing = null;
    doc.reset();
    doc.party().lock(false);
    showIntakePane();
    jobParty.focus();
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initJobs() {
    root = $('[data-module-root="jobs"]');

    // Before `mountWorkspace`, which is what takes both surfaces out of the
    // document. After it, these lookups would find nothing.
    formRoot = $('[data-ws-form]', root);
    listRoot = $('[data-ws-list]', root);

    intakePane = $('[data-job-intake]', formRoot);
    billPane = $('[data-job-bill]', formRoot);
    form = $('#job-form', formRoot);

    /*
    | The document first. It is what calls `initQuickItem()` and
    | `initQuickParty()`, and the intake form's own picker offers "+ Add" — so
    | the dialog has to be wired before anything can open it.
    |
    | `restoreDraft: false`, deliberately. §26 restores an unfinished bill
    | because losing six typed lines is the failure people remember; here the
    | lines come off the job card, which is itself the record of work not yet
    | billed, and pressing Generate bill again rebuilds them exactly. What a
    | restore would add is a document with no job behind it.
    */
    doc = await mountBillDocument($('[data-bill-document]', billPane), {
        key: 'jobs',
        direction: 'sale',
        restoreDraft: false,

        /*
        | Through the job, never past it. `/transactions/sale` would produce an
        | invoice the job knew nothing about, and the job would happily bill the
        | same bearings again next week.
        |
        | `items` is omitted while the lines are the ones the job produced — see
        | the note at the top of this file. Everything else the shared document
        | carries is sent and accepted: the payment split, a bill discount, and
        | who did the work.
        */
        submitWith: async (payload) => {
            if (!billing) return null;

            const changed = fingerprint(payload.items ?? []) !== billing.baseline;

            return auth.call(`/workshop-jobs/${billing.id}/bill`, {
                method: 'POST',
                body: {
                    date: payload.date,
                    notes: payload.notes,
                    client_ref: payload.client_ref,
                    ...(changed ? { items: payload.items } : {}),
                    ...(payload.bill_discount === undefined ? {} : { bill_discount: payload.bill_discount }),
                    ...(payload.bill_discount_percent === undefined
                        ? {}
                        : { bill_discount_percent: payload.bill_discount_percent }),
                    payments: payload.payments,
                    staff: payload.staff ?? [],
                },
            });
        },

        onPosted: (response) => {
            const created = response.data;
            const job = billing;

            billing = null;

            doc.reset();
            doc.party().lock(false);
            showIntakePane();

            paintOutcome(`
                <p><strong>${esc(created.doc_no ?? `#${created.id}`)}</strong> raised against
                ${esc(job?.job_no ?? 'the job')} —
                <span class="font-mono">${esc(formatMoney(created.total))}</span>.</p>
                ${job ? `<button type="button" class="mt-1 font-semibold underline" data-outcome-job="${job.id}">
                    Open the job card
                </button>` : ''}`);

            if (job) workspace?.flagNew(job.id);

            jobParty.focus();
            loadMeta();
            refetch();
        },
    });

    /*
    | A workshop bill cannot be parked, so the control is not offered. Money that
    | has moved is a fact — C3's judgement, and the same one M22 makes about a
    | payroll run — and here there is a second reason: the draft path is the
    | ordinary sale one, which has no way to stamp the job onto the document.
    */
    $('[data-draft]', billPane)?.classList.add('hidden');

    /*
    | The quick-add drawer, lifted out of the bill pane.
    |
    | It arrives inside `partials/bill-document.blade.php`, which on this screen
    | is the second pane of the create surface — so it carries `hidden` for the
    | whole time a motor is being booked in, which is exactly when the intake
    | form's picker offers "+ Add". `showModal` clears the drawer's own `hidden`
    | class and nothing else, so the dialog was opening underneath a
    | `display: none` ancestor: no dialog, no toast, no error, a control that
    | read as dead.
    |
    | Moved to the form surface, which is attached whenever either pane is on
    | screen. Both openers find it by id from `document`, so the node — and the
    | form `initQuickParty` has already wired to it — survives the move. Sales
    | and Purchase include the document straight into `[data-ws-form]` and need
    | none of this.
    */
    const quickPartyDrawer = $('#quick-party-drawer', billPane);

    if (quickPartyDrawer) formRoot.append(quickPartyDrawer);

    /*
    | The customer, on the intake form. A real quick-create rather than the note
    | this screen used to show: the dialog is `components/quick-party.js`, the
    | same form the Customers screen opens, and the document above has already
    | initialised it.
    */
    jobParty = mountPartyPicker(formEl('[data-job-party-host]'), {
        role: 'customer',
        label: 'Customer',
    });

    jobParty.onAdd((typed) => openQuickParty({
        role: 'customer',
        name: typed,
        onSaved: (party) => jobParty.set(party),
    }));

    form.elements.received_date.value = new Date().toISOString().slice(0, 10);
    form.addEventListener('submit', submitForm);

    formEl('[data-job-bill-cancel]').addEventListener('click', cancelBill);

    formEl('[data-job-outcome]').addEventListener('click', (event) => {
        const opener = event.target.closest('[data-outcome-job]');

        if (opener) openDrawer(opener.dataset.outcomeJob);
    });

    bindList();
    bindDrawer();

    await loadMeta();

    const canWrite = can('WRITE', 'WORKSHOP_JOBS');

    workspace = mountWorkspace(root, {
        key: 'jobs',
        title: 'Jobs',
        formSubtitle: 'Take a motor in. A job number is issued straight away, so there is something to write on the casing.',
        listSubtitle: (count) => (count === null
            ? 'What is on the bench, what it is waiting for, and what is ready to go home.'
            : `${count} job${count === 1 ? '' : 's'}, newest first.`),
        createLabel: 'Book a motor in',
        count: () => state.total,
        canCreate: canWrite,
        onShowList: load,

        /*
        | The rows carry what has been billed off each job, and the invoice that
        | moves that figure need not be raised here: reversing a workshop bill
        | from the Sales drawer changes it too. See `data-bus.js`.
        */
        refreshOn: ['transactions'],

        onShowForm: () => {
            paintFormSubtitle();

            if (!billing) jobParty.focus();
        },
    });

    /*
    | §2A.9, one level per press. Registered *after* the workspace, which
    | registers this same key — so this handler replaces it and takes both steps
    | itself: the bill pane back to the intake form, then the list back to it.
    |
    | Neither runs while the drawer is open: the shell stands down whenever a
    | dialog is on screen and lets `ui.js` close that first.
    */
    registerEscape('jobs', () => {
        if (workspace?.mode() !== 'list' && billing) {
            showIntakePane();

            return true;
        }

        if (workspace?.mode() !== 'list' || !canWrite) return false;

        workspace.showForm();

        return true;
    });

    applyDeepLink(moduleParams());

    /*
    | Reopening an already-mounted module cannot run this function again, so a
    | second deep link is announced instead — the filters are applied and the
    | list reloaded without the module being rebuilt.
    */
    root.addEventListener('module:params', (event) => applyDeepLink(event.detail));
}

/**
 * `#jobs?status=ready`, `#jobs?overdue=1`, `#jobs?job=41`.
 *
 * The intent comes from the shell rather than from `location.search`, because a
 * module's URL is a fragment of the dashboard's now. It is spent once acted on:
 * surviving a refresh or a Back would reopen a drawer somebody has just closed.
 */
function applyDeepLink(params) {
    if (![...params.keys()].length) return;

    const wantsList = params.get('status') || params.get('overdue') === '1' || params.get('open') === '1';

    if (params.get('status')) state.status = params.get('status');
    if (params.get('overdue') === '1') state.overdue = true;
    if (params.get('open') === '1') state.open = true;

    if (wantsList) {
        state.page = 1;
        listEl('[data-job-overdue]').setAttribute('aria-pressed', String(state.overdue));
        renderTabs();

        // Showing the list fetches it the first time by itself (§2A.7), so the
        // refetch is only for a link followed into a module that already holds
        // one — otherwise the filters would land on rows fetched without them.
        const held = workspace?.hasList();

        workspace?.showList();

        if (held) load();
    }

    if (params.get('job')) openDrawer(params.get('job'));

    clearModuleParams();
}

/* -------------------------------------------------------------------------
 | Events
 | ---------------------------------------------------------------------- */

function bindList() {
    listEl('[data-job-search]').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
    }, 300));

    listEl('[data-job-tabs]').addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tab]');

        if (!tab) return;

        state.status = tab.dataset.tab;
        state.page = 1;
        renderTabs();
        load();
    });

    listEl('[data-job-overdue]').addEventListener('click', (event) => {
        state.overdue = !state.overdue;
        event.currentTarget.setAttribute('aria-pressed', String(state.overdue));
        state.page = 1;
        load();
    });

    listEl('[data-job-clear]').addEventListener('click', () => {
        Object.assign(state, { search: '', status: '', overdue: false, open: true, page: 1 });
        listEl('[data-job-search]').value = '';
        listEl('[data-job-overdue]').setAttribute('aria-pressed', 'false');
        renderTabs();
        load();
    });

    const open = (event) => {
        const row = event.target.closest('[data-job]');

        if (row) openDrawer(row.dataset.job);
    };

    listEl('[data-job-body]').addEventListener('click', open);
    listEl('[data-job-body]').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') open(event);
    });

    listEl('[data-job-prev]').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    listEl('[data-job-next]').addEventListener('click', () => {
        if (state.hasMore) {
            state.page += 1;
            load();
        }
    });
}

function bindDrawer() {
    // One delegated handler for the whole card, because it is re-rendered after
    // every action and bound listeners would go with it.
    el('[data-drawer-body]').addEventListener('click', (event) => {
        const job = state.current;

        if (!job) return;

        const move = event.target.closest('[data-advance]');
        if (move) return advance(job.id, move.dataset.advance);

        const remove = event.target.closest('[data-remove-part]');
        if (remove) {
            const part = (job.parts ?? []).find((row) => row.id === Number(remove.dataset.removePart));

            return removePart(job.id, remove.dataset.removePart, part?.description ?? 'This part');
        }

        if (event.target.closest('[data-add-part]')) return addPart(job.id);
        if (event.target.closest('[data-quote-from-parts]')) return quoteFromParts(job);

        if (event.target.closest('[data-approve-estimate]')) {
            return jobAction(job.id, 'estimate/approve', 'Estimate approved.');
        }

        if (event.target.closest('[data-apply-estimate]')) {
            return jobAction(job.id, 'estimate/apply', 'Estimate copied onto the job.');
        }

        return undefined;
    });

    el('[data-drawer-actions]').addEventListener('click', (event) => {
        const job = state.current;

        if (!job) return;

        if (event.target.closest('[data-generate-bill]')) generateBill(job);
        if (event.target.closest('[data-edit-job]')) openEdit(job);
        if (event.target.closest('[data-delete-job]')) destroyJob(job);
    });

    el('#job-drawer').addEventListener('click', (event) => {
        if (event.target.closest('[data-job-edit-cancel]')) {
            closeEdit();

            return;
        }

        // Beside `ui.js`'s own handler rather than instead of it: it hides the
        // drawer, and this takes the form back out of it first.
        if (event.target.closest('[data-modal-close]') || event.target.matches('[data-modal]')) {
            closeEdit();
        }
    });

    /*
    | Escape closes the drawer through `ui.js`, which knows nothing about the
    | form that is standing inside it. Without this the create pane would come
    | back empty, with nothing on screen saying where its fields went.
    */
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && editing !== null) closeEdit();
    });
}
