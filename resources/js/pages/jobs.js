import auth from '../auth-client';
import {
    collectAttributes, defaultsFor, describeAttributes, renderAttributeFields,
} from '../components/attribute-fields';
import { badge, formatQuantity, lifecycleTone } from '../components/badge';
import { mountBillDocument } from '../components/bill-document';
import { bindDelivery, openInvoicePreview } from '../components/invoice-delivery';
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
 * card → BOOK SOMETHING IN         ← always lands here (§2A.1, §2A.5)
 *      → "Show list (12)"          → what is on the bench
 *      → row → drawer (level 2)    → the pipeline, the parts, the estimate
 *                                  → Correct the card (a state of the drawer)
 *                                  → Generate bill → the document, at level 1
 *                                  → confirm (level 3)
 * ```
 *
 * ## What comes in is not always a motor
 *
 * Most of it is; a good deal of it is a cooler, a table fan or a pump. So the
 * intake form asks **what kind of thing** arrived and then draws whatever that
 * kind is described by, from `GET /workshop-jobs/meta`. Nothing about motors is
 * written in this file — no HP, no phase, no list of kinds — for the reason the
 * catalogue's vocabulary rule already gives: a product type in code is a product
 * type a workshop cannot change, and a copy of the list in a screen goes stale
 * the moment an admin adds one.
 *
 * The fields themselves are `components/attribute-fields.js`, which is also what
 * the Items create form draws its specification with. One renderer, one set of
 * data types, one "blank is absent" rule (§4.4).
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

    /*
    | What can be booked in, and what each kind asks about — the workshop's own
    | categories, from the server.
    |
    | Held for the life of the module and refreshed with the rest of the meta,
    | because the intake form redraws its fields on every change of the Kind
    | select and a fetch per change would be a request per keystroke of somebody
    | making up their mind.
    */
    kinds: [],

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

/**
 * The last invoice this module posted, so the line above the cleared form can
 * open it again.
 *
 * §2A.8 empties the document the instant it posts, so nothing on screen is
 * holding it any more — and "Print or share it" a minute later has to be about
 * the invoice that was raised rather than about whatever the preview happens to
 * be pointing at.
 */
let lastBill = null;

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
 * What came in, where anything about it is known.
 *
 * `equipmentLabel()` falls back to the job number when the plate said nothing
 * and nobody typed anything — which is right for a column and wrong beside the
 * number itself, where it reads "JOB/26-27/1006 — JOB/26-27/1006". A pump
 * wheeled in by a driver who knew none of it is a job this form accepts on
 * purpose, so this is the ordinary case rather than an edge one.
 */
const equipmentOf = (job) => (job.equipment && job.equipment !== job.job_no ? job.equipment : null);

/** The kind's published question set, or an empty one. */
const kindMeta = (id) => state.kinds.find((kind) => String(kind.value) === String(id ?? '')) ?? null;

/**
 * Whether anything has been invoiced off this job — beside the status, never
 * folded into it.
 *
 * The two answer different questions. A job's status is about the motor, and
 * `WorkshopJobStatus::Delivered` says in as many words that going home and being
 * billed do not imply each other: a regular customer's pump goes out on Friday
 * against an invoice raised at the end of the month. So a repair that has been
 * charged for reads *In progress · Invoiced*, and neither half is a lie.
 *
 * Silent while nothing has been billed, which is most of a bench: a badge on
 * every row says nothing. The word and the colour are the server's, from
 * `JobBillingState` — never a map from state to colour written here (§38).
 */
const billingBadge = (job) => (job.billing_state && job.billing_state !== 'unbilled'
    ? badge(job.billing_state_label, job.billing_state_tone)
    : '');

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
        : tableMessage(7, 'Nothing here. Book something in and it will appear on the bench.');

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
    // §2A.8 — something booked in while the list was detached carries the flash
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

            <td class="table-cell text-[0.8125rem]">${esc(job.equipment)}</td>

            <td class="table-cell max-w-xs truncate text-[0.8125rem] text-muted-foreground">
                ${esc(job.complaint)}
            </td>

            <td class="table-cell w-44">
                <span class="flex flex-wrap items-center gap-1">
                    ${badge(job.status_label, job.status_tone)}
                    ${job.is_overdue ? badge('Late', 'danger') : ''}
                    ${billingBadge(job)}
                </span>
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
        state.kinds = data.kinds ?? [];
    } catch {
        state.statuses = [];
        state.counts = {};
        state.kinds = [];
    }

    renderTabs();
    renderKinds();
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

        el('#job-drawer-title').textContent = equipmentOf(data)
            ? `${data.job_no} — ${equipmentOf(data)}`
            : data.job_no;
        el('[data-drawer-subtitle]').textContent =
            `${data.party?.name ?? ''} · received ${formatDate(data.received_date)}`
            + (data.promised_date ? ` · promised ${formatDate(data.promised_date)}` : '');
        // Two badges, never one: where the motor has got to and what has been
        // charged for are different questions — see `billingBadge`.
        el('[data-drawer-status]').innerHTML =
            badge(data.status_label, data.status_tone) + billingBadge(data);

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
        ${renderSpecification(job)}
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

/**
 * What came in, and what its kind recorded about it.
 *
 * Printed from `specs_display`, which the server resolved through the category
 * that asked — never from the raw bag, which is `{"hp": "7.5"}` and would put
 * JSON keys on a job card. A job whose kind was never chosen has nothing to
 * print, and the block is absent rather than empty: a heading over "—" claims
 * somebody looked and found nothing.
 */
function renderSpecification(job) {
    const specs = job.specs_display ?? [];

    const identity = [
        job.kind_label ? ['Kind', job.kind_label] : null,
        job.brand ? ['Brand', job.brand] : null,
        job.model ? ['Model', job.model] : null,
    ].filter(Boolean);

    const rows = [
        ...identity,
        ...specs.map((spec) => [spec.label, `${spec.value}${spec.suffix ? ` ${spec.suffix}` : ''}`]),
    ];

    if (!rows.length) return '';

    return `
        <div class="border-b border-border px-6 py-4">
            <h3 class="text-sm font-semibold text-foreground">What came in</h3>

            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[0.8125rem] sm:grid-cols-3">
                ${rows.map(([label, value]) => `
                    <div>
                        <dt class="text-xs text-muted-foreground">${esc(label)}</dt>
                        <dd class="text-secondary-foreground">${esc(value)}</dd>
                    </div>`).join('')}
            </dl>
        </div>`;
}

function renderParts(job, mayWrite) {
    const parts = job.parts ?? [];

    return `
        <div class="border-b border-border px-6 py-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 class="text-sm font-semibold text-foreground">Parts and labour</h3>
                <span class="text-[0.8125rem] text-muted-foreground">
                    Not yet billed
                    <span class="ml-1 font-mono font-semibold text-foreground">
                        ${esc(formatMoney(job.unbilled_total ?? '0.00'))}
                    </span>
                </span>
            </div>

            <p class="mt-0.5 text-xs text-muted-foreground">
                Adding a part here moves no stock. The bearing leaves the shelf when the invoice posts.
            </p>

            ${parts.length ? `
                <div class="mt-3 -mx-1 overflow-x-auto">
                    <table class="w-full min-w-[26rem] border-collapse text-[0.8125rem]">
                        <thead>
                            <tr class="border-b border-border text-[0.6875rem] uppercase tracking-wide
                                       text-muted-foreground">
                                <th class="px-1 py-1.5 text-left font-semibold">Part or labour</th>
                                <th class="px-2 py-1.5 text-right font-semibold">Qty</th>
                                <th class="px-2 py-1.5 text-right font-semibold">Rate</th>
                                <th class="px-2 py-1.5 text-right font-semibold">Amount</th>
                                <th class="w-8"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${parts.map((part) => `
                                <tr class="border-t border-border align-top ${part.is_billed ? 'text-muted-foreground' : ''}">
                                    <td class="px-1 py-2">
                                        <span class="inline-flex flex-wrap items-center gap-1.5">
                                            ${esc(part.description)}
                                            ${part.is_billed ? badge('Billed', 'neutral') : ''}
                                        </span>
                                        ${part.memo ? `<span class="block text-xs text-muted-foreground">${esc(part.memo)}</span>` : ''}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-2 text-right font-mono">
                                        ${esc(formatQuantity(part.quantity, part.unit_symbol))}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-2 text-right font-mono">
                                        ${esc(formatMoney(part.unit_price))}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-2 text-right font-mono font-semibold
                                               text-foreground">
                                        ${esc(formatMoney(part.line_total))}
                                    </td>
                                    <td class="py-2 text-right">
                                        ${mayWrite && !part.is_billed
                                            ? `<button type="button" class="btn btn-ghost btn-icon size-7"
                                                       data-remove-part="${part.id}"
                                                       aria-label="Remove ${esc(part.description)}">×</button>`
                                            : ''}
                                    </td>
                                </tr>`).join('')}
                        </tbody>
                    </table>
                </div>`
            : '<p class="mt-3 text-[0.8125rem] text-muted-foreground">Nothing on this job yet.</p>'}

            ${mayWrite && job.is_open ? `
                <div class="mt-4 rounded-[10px] border border-border bg-secondary/40 p-3.5" data-part-form>
                    ${/*
                        The picker gets a row to itself.

                        It renders a label, a box and a hint, where the two number
                        fields beside it render a label and a box — so on one grid
                        row nothing lines up: bottom-aligned, the quantity sat a
                        line below the search box it belongs to, and top-aligned
                        the Add button floated above both. Splitting them settles
                        the alignment and gives the search the whole width of the
                        drawer, which is what it needs to show a part number, a
                        price and a stock badge on one result.
                    */''}
                    <div data-part-picker-host></div>

                    <p class="mt-2 min-h-4 text-xs font-medium text-foreground" data-part-chosen></p>

                    <div class="mt-2 grid grid-cols-2 items-end gap-3 sm:grid-cols-[7rem_9rem_1fr]">
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

                        <button type="button" data-add-part disabled
                                class="btn btn-primary col-span-2 sm:col-span-1 sm:justify-self-end">
                            Add to the job
                        </button>
                    </div>
                </div>` : ''}
        </div>`;
}

function renderEstimate(job, mayWrite) {
    return `
        <div class="border-b border-border px-6 py-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 class="text-sm font-semibold text-foreground">Estimate</h3>

                <span class="flex items-center gap-2">
                    ${job.has_estimate
                        ? `<span class="font-mono text-[0.8125rem] font-semibold">${esc(formatMoney(job.estimate_total))}</span>
                           ${job.estimate_approved_at
                                ? badge('Approved', 'success')
                                : badge('Awaiting approval', 'warning')}`
                        : '<span class="text-[0.8125rem] text-muted-foreground">Not quoted</span>'}
                </span>
            </div>

            <p class="mt-0.5 text-xs text-muted-foreground">
                A quotation, not a document — nothing is posted until it becomes an invoice. The total is
                before tax.
            </p>

            ${job.has_estimate ? `
                <div class="mt-3 -mx-1 overflow-x-auto">
                    <table class="w-full min-w-[22rem] border-collapse text-[0.8125rem]">
                        <thead>
                            <tr class="border-b border-border text-[0.6875rem] uppercase tracking-wide
                                       text-muted-foreground">
                                <th class="px-1 py-1.5 text-left font-semibold">Quoted</th>
                                <th class="px-2 py-1.5 text-right font-semibold">Qty</th>
                                <th class="px-2 py-1.5 text-right font-semibold">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${job.estimate_lines.map((line) => `
                                <tr class="border-t border-border">
                                    <td class="px-1 py-1.5">${esc(line.description)}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono">
                                        ${esc(formatQuantity(line.quantity))}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono">
                                        ${esc(formatMoney(line.unit_price))}
                                    </td>
                                </tr>`).join('')}
                        </tbody>
                    </table>
                </div>` : ''}

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

/**
 * The invoices raised off this repair, and the way back to any of them.
 *
 * Each posted row opens the customer's copy in the shared preview — the one
 * `components/invoice-delivery.js` owns, with Print and Share on it. That is the
 * same drawer the invoice landed on when it posted, borrowed rather than rebuilt
 * (§5.1): there is exactly one invoice sheet in this application and a second
 * copy of it anywhere would put the whole screen on the paper.
 */
function renderBills(job) {
    const bills = job.bills ?? [];

    if (bills.length === 0) return '';

    return `
        <div class="px-6 py-4">
            <h3 class="text-sm font-semibold text-foreground">Invoices off this job</h3>

            <p class="mt-0.5 text-xs text-muted-foreground">
                Open one to print it or send the customer a link.
            </p>

            <div class="mt-3 -mx-1 overflow-x-auto">
                <table class="w-full min-w-[24rem] border-collapse text-[0.8125rem]">
                    <thead>
                        <tr class="border-b border-border text-[0.6875rem] uppercase tracking-wide
                                   text-muted-foreground">
                            <th class="px-1 py-1.5 text-left font-semibold">Invoice</th>
                            <th class="px-2 py-1.5 text-left font-semibold">Date</th>
                            <th class="px-2 py-1.5 text-left font-semibold">Status</th>
                            <th class="px-2 py-1.5 text-right font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${bills.map((bill) => {
                            // A draft has no number, no priced lines and no tax,
                            // and a reversed invoice is one the customer must
                            // stop being able to open — so neither has a copy to
                            // hand over. The server refuses both; this only
                            // decides whether the row is worth offering.
                            const open = bill.status === 'posted';

                            return `
                                <tr class="border-t border-border ${open
                                    ? 'cursor-pointer transition hover:bg-secondary/60'
                                    : ''}"
                                    ${open ? `data-open-invoice="${bill.id}" tabindex="0" role="link"
                                              aria-label="Open ${esc(bill.doc_no ?? `#${bill.id}`)}"` : ''}>
                                    <td class="px-1 py-1.5 font-mono">${esc(bill.doc_no ?? `#${bill.id}`)}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5">${esc(formatDate(bill.date))}</td>
                                    <td class="px-2 py-1.5">${badge(bill.status_label, lifecycleTone(bill.status))}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-semibold">
                                        ${esc(formatMoney(bill.total))}
                                    </td>
                                </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>

            <p class="mt-2 text-[0.8125rem] text-muted-foreground">
                Paid <span class="font-mono">${esc(formatMoney(job.billed?.paid ?? '0.00'))}</span> ·
                Due <span class="font-mono">${esc(formatMoney(job.billed?.due ?? '0.00'))}</span>
            </p>
        </div>`;
}

/**
 * Why this job cannot produce an invoice, or null where it can.
 *
 * Three refusals, and all three are the server's rather than this screen's
 * opinion: `WorkshopJobStatus::isBillable()` decides the first,
 * `InvalidJobStateException::nothingToBill()` the last, and the wording follows
 * both so the sentence somebody reads before pressing is the sentence they would
 * have read after.
 *
 * The status one is the point of the whole function. A job that has had nothing
 * done to it cannot be charged for — an invoice against it would be charging for
 * an intention — and a cancelled job must never produce one at all, which is the
 * brief's scenario 10. That rule was already enforced, in the enum and at the
 * endpoint; what was missing was any sign of it on the screen, because the
 * control was simply left out and nothing said why. A button that is not there
 * teaches nobody anything.
 */
function whyNotBillable(job) {
    if (!job.is_billable) {
        return job.status === 'cancelled'
            ? `${job.job_no} was cancelled, so there is nothing on it to bill.`
            : `${job.job_no} is ${job.status_label.toLowerCase()}. Move it to in progress before billing it `
                + '— an invoice now would be charging for work nobody has started.';
    }

    const parts = job.parts ?? [];

    if (parts.length === 0) {
        return 'Nothing has been written onto this job yet. Add the parts and the labour first.';
    }

    /*
    | The server's own question, asked the server's way: `unbilledParts()` is
    | what decides `JOB_NOTHING_TO_BILL`, and a part points at the line that
    | consumed it. Asking `billing_state` instead would be close but not the
    | same — reversing a bill takes the badge off the job and deliberately
    | leaves the parts marked, because they *were* billed, on a document that
    | was then cancelled. This would have offered a bill that the endpoint would
    | have refused a moment later.
    */
    if (parts.every((part) => part.is_billed)) {
        return `Everything on ${job.job_no} has already reached an invoice. Add what else was fitted.`;
    }

    return null;
}

/**
 * The footer.
 *
 * Raising the invoice needs WRITE:TRANSACTIONS as well as the jobs grant, and
 * the route enforces both — recording a repair and posting to the ledger are
 * different authorities, which is the whole reason M19 has a permission of its
 * own.
 *
 * The grant decides whether the control is painted at all; the job's state
 * decides only whether it can be pressed. Those are different questions and they
 * are answered differently on purpose — the Roles module's judgement about a
 * system role, and for its reason: the answer belongs where the question is
 * asked, and a control that vanishes leaves somebody hunting for it.
 */
function paintDrawerActions(job) {
    const buttons = [];

    const refusal = can('WRITE', 'TRANSACTIONS') ? whyNotBillable(job) : null;

    if (can('WRITE', 'TRANSACTIONS')) {
        buttons.push(`
            <button type="button" class="btn btn-primary" data-generate-bill
                    ${refusal ? `disabled title="${esc(refusal)}"` : ''}>Generate bill</button>`);
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

    // Below the row rather than in a tooltip only: `title` is a mouse's
    // affordance and this screen is used on a tablet at a bench.
    if (refusal) {
        buttons.push(`<p class="basis-full text-xs text-muted-foreground">${esc(refusal)}</p>`);
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

    /*
    | Bring the form up the panel before anybody types into it.
    |
    | The results list is absolutely positioned, so it adds nothing to the
    | drawer's scroll height — on a long job card, opened where the form
    | happened to sit, the matches were cut off at the bottom edge with no way
    | to scroll to them. Centring the form first leaves the whole list inside
    | the panel, and it happens once, on focus, rather than under the typing.
    */
    $('[data-item-input]', host)?.addEventListener('focus', () => {
        el('[data-part-form]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
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

/**
 * Hand the customer a copy of one invoice off this job.
 *
 * Level 2 over the job card rather than a state of it: it is a different record
 * — the invoice, not the repair — and `#invoice-preview` is where this
 * application shows one. The job drawer is left open behind it, so closing the
 * copy is a step back to the card rather than out of the module.
 *
 * The preview is the shared one and it borrows the application's single invoice
 * sheet; nothing here renders any of that. See
 * `components/invoice-delivery.js`.
 */
function openBill(job, billId) {
    const bill = (job.bills ?? []).find((row) => String(row.id) === String(billId));

    if (!bill) return;

    openInvoicePreview(bill, { title: `${job.job_no} — the customer’s copy` });
}

/* -------------------------------------------------------------------------
 | Booking something in, and correcting the card
 |
 | One form node for both. `adoptForm()` moves it between the level-1 pane and
 | the drawer, and the blocks marked `data-form-chrome` decide which frame is
 | shown — the customer and the received date are inline only, because neither
 | is editable once the job exists and `UpdateJobRequest` accepts neither.
 | ---------------------------------------------------------------------- */

/**
 * The Kind select, from the workshop's own categories.
 *
 * Written here rather than into the Blade template, for the reason the
 * catalogue records: a list of product kinds in the markup is a list that goes
 * stale the moment an admin adds one. The choice already made survives the
 * repaint, because `loadMeta()` runs again after every booking (§2A.8 clears
 * the form, and the counts have moved) and re-selecting nothing would silently
 * unset the kind the operator picked for the *next* one.
 */
function renderKinds() {
    if (!form) return;

    const select = form.elements.category_id;
    const chosen = select.value;

    select.innerHTML = `
        <option value="">Not sure yet</option>
        ${state.kinds.map((kind) => `
            <option value="${esc(kind.value)}">${esc(kind.label)}</option>`).join('')}`;

    select.value = chosen;

    // A workshop with no categories at all cannot answer the question, so it is
    // not asked one — and is told where the answer comes from, because "Kind"
    // with one empty option reads as a broken control rather than an empty
    // master.
    select.disabled = state.kinds.length === 0;

    paintSpecFields();
}

/**
 * The fields the chosen kind asks about.
 *
 * Values already typed survive the repaint, which matters for the same reason it
 * does on the Items form: this runs again after every booking, and wiping the
 * boxes would cost the operator a specification they had half finished. What
 * sits underneath them differs by frame — a new job falls back to the kind's own
 * declared defaults, and one being corrected falls back to what it recorded,
 * never to a default that would refill a box somebody deliberately emptied.
 */
function paintSpecFields() {
    const host = formOrDrawer('[data-job-specs]');
    const section = formOrDrawer('[data-job-specs-section]');

    if (!host || !section) return;

    const kind = kindMeta(form.elements.category_id.value);
    const schema = kind?.attributes ?? {};

    section.classList.toggle('hidden', !kind || Object.keys(schema).length === 0);

    const hint = formOrDrawer('[data-job-kind-hint]');

    if (hint) {
        hint.textContent = state.kinds.length === 0
            ? 'No kinds defined yet — add a category from the Items card.'
            : (kind ? `Asks for ${describeAttributes(schema, 'nothing in particular')}.` : '');
    }

    if (!kind) {
        host.innerHTML = '';

        return;
    }

    const held = editing ? (specsOf(state.current) ?? {}) : defaultsFor(schema);

    renderAttributeFields(host, schema, { ...held, ...collectAttributes(host) }, 'job-spec', {
        // Nothing on this form is compulsory, whatever the category demands of a
        // *product*: a motor whose plate nobody could read is still on the bench,
        // and a form that refused it is a form that got a job card written on
        // paper instead.
        requiredMarks: false,
        empty: 'This kind records no specification fields.',
    });
}

/** What a job recorded, but only where the kind on the form is still its own. */
function specsOf(job) {
    if (!job) return null;

    return String(job.category_id ?? '') === String(form.elements.category_id.value)
        ? (job.specs ?? {})
        : {};
}

/**
 * Find a node on the form wherever the form currently is.
 *
 * `adoptForm()` moves the whole node into the drawer for an edit, so a lookup
 * scoped to the level-1 pane finds nothing exactly when the card is being
 * corrected. Scoped to the form itself, which works attached or detached — the
 * rule CLAUDE.md records about a detached surface.
 */
const formOrDrawer = (selector) => $(selector, form);

/**
 * Empty the form for the next one.
 *
 * `keepKind` is the counter's case and not a nicety: three motors come off one
 * pickup, and §2A.8 clears the form the instant each is booked. Re-picking
 * "Motor" three times is the friction that ends in a paper job card — the same
 * argument that keeps every field here optional. It is *not* kept when the form
 * comes back from an edit, where the kind on it belongs to somebody else's job.
 */
function resetForm({ keepKind = false } = {}) {
    const kind = form.elements.category_id.value;

    clearFormErrors(form);
    form.reset();

    if (keepKind) form.elements.category_id.value = kind;

    form.elements.received_date.value = new Date().toISOString().slice(0, 10);
    jobParty.set(null);
    paintSpecFields();
}

function fillForm(job) {
    clearFormErrors(form);

    form.elements.complaint.value = job.complaint ?? '';
    form.elements.category_id.value = job.category_id ?? '';
    form.elements.brand.value = job.brand ?? '';
    form.elements.model.value = job.model ?? '';
    form.elements.serial_no.value = job.serial_no ?? '';
    form.elements.promised_date.value = job.promised_date ?? '';
    form.elements.notes.value = job.notes ?? '';

    // After the select, and after `editing` is set — the fields drawn are the
    // chosen kind's, and what fills them is what this job recorded.
    paintSpecFields();
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
                    // The kind and its answers always travel together: the
                    // server filters the bag against whichever category the job
                    // ends up under, so sending one without the other would
                    // clear a specification nobody meant to clear.
                    category_id: form.elements.category_id.value || null,
                    specs: collectAttributes(formOrDrawer('[data-job-specs]')),
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
                category_id: form.elements.category_id.value || null,
                specs: collectAttributes(formOrDrawer('[data-job-specs]')),
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

        const equipment = equipmentOf(created);

        resetForm({ keepKind: true });
        paintOutcome(`
            <p><strong>${esc(created.job_no)}</strong> is on the bench${equipment ? ` — ${esc(equipment)}` : ''}.
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
        sub.textContent = 'Take something in. A job number is issued straight away, '
            + 'so there is something to write on the casing.';

        return;
    }

    sub.textContent = billing.equipment
        ? `Billing ${billing.job_no} — ${billing.equipment}.`
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
        equipment: equipmentOf(meta.job),
        baseline: fingerprint(doc.lines()),
    };

    const customer = state.current?.party?.name;
    const named = billing.equipment ? `${billing.job_no} — ${billing.equipment}` : billing.job_no;

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
            lastBill = created;

            doc.reset();
            doc.party().lock(false);
            showIntakePane();

            paintOutcome(`
                <p><strong>${esc(created.doc_no ?? `#${created.id}`)}</strong> raised against
                ${esc(job?.job_no ?? 'the job')} —
                <span class="font-mono">${esc(formatMoney(created.total))}</span>.</p>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    <button type="button" class="font-semibold underline" data-outcome-invoice>
                        Print or share it
                    </button>
                    ${job ? `<button type="button" class="font-semibold underline" data-outcome-job="${job.id}">
                        Open the job card
                    </button>` : ''}
                </div>`);

            if (job) workspace?.flagNew(job.id);

            loadMeta();
            refetch();

            /*
            | And the other half of billing a repair: the customer's copy.
            |
            | C4 shipped the post and deliberately stopped here, because the
            | preview was three hundred lines of `pages/sales.js` and there is
            | exactly one invoice sheet in this application — borrowing it meant
            | extracting that first. It is `components/invoice-delivery.js` now,
            | and this is the module the extraction was for.
            |
            | Over the emptied form rather than instead of it, so §2A.8 is
            | untouched: closing the preview leaves a blank intake form with the
            | cursor in the customer box, which is where the next motor starts.
            |
            | Only for a document there is a copy *of*. A workshop bill cannot be
            | parked, so this is all but unconditional — but the guard costs
            | nothing and states the rule.
            */
            if (created?.status === 'posted') {
                openInvoicePreview(created, { onClosed: () => jobParty.focus() });
            } else {
                jobParty.focus();
            }
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

    // Whatever the kind asks, asked. Bound to the node rather than the pane,
    // because the same select goes into the drawer for an edit.
    form.elements.category_id.addEventListener('change', paintSpecFields);

    formEl('[data-job-bill-cancel]').addEventListener('click', cancelBill);

    formEl('[data-job-outcome]').addEventListener('click', (event) => {
        const opener = event.target.closest('[data-outcome-job]');

        if (opener) {
            openDrawer(opener.dataset.outcomeJob);

            return;
        }

        // The same drawer the post landed on, about the same document — so
        // whoever closed it before the customer asked for a copy has a way back
        // that does not involve finding the invoice on the Sales list.
        if (event.target.closest('[data-outcome-invoice]') && lastBill) {
            openInvoicePreview(lastBill, { onClosed: () => jobParty.focus() });
        }
    });

    bindList();
    bindDrawer();
    bindDelivery();

    await loadMeta();

    const canWrite = can('WRITE', 'WORKSHOP_JOBS');

    workspace = mountWorkspace(root, {
        key: 'jobs',
        title: 'Jobs',
        formSubtitle: 'Take something in. A job number is issued straight away, so there is something to write on the casing.',
        listSubtitle: (count) => (count === null
            ? 'What is on the bench, what it is waiting for, and what is ready to go home.'
            : `${count} job${count === 1 ? '' : 's'}, newest first.`),
        createLabel: 'Book something in',
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

        const invoice = event.target.closest('[data-open-invoice]');
        if (invoice) return openBill(job, invoice.dataset.openInvoice);

        return undefined;
    });

    // Enter on an invoice row, for the same reason the list's rows take it: a
    // row that is a link with the mouse is a link with the keyboard.
    el('[data-drawer-body]').addEventListener('keydown', (event) => {
        const invoice = event.key === 'Enter' ? event.target.closest('[data-open-invoice]') : null;

        if (invoice && state.current) openBill(state.current, invoice.dataset.openInvoice);
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
