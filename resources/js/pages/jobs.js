import auth from '../auth-client';
import { badge, formatQuantity } from '../components/badge';
import { mountItemPicker } from '../components/item-picker';
import { mountPartyPicker } from '../components/party-picker';
import { initQuickItem, openQuickItem } from '../components/quick-item';
import { can } from '../permissions';
import { clearModuleParams, moduleParams } from '../shell';
import {
    $, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

/**
 * The bench — M19's screen, and the brief's §16 to §18 and §23.
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
 */

const PAGE_SIZE = 25;

const state = {
    search: '',
    status: '',
    open: true,
    overdue: false,
    page: 1,
    hasMore: false,

    statuses: [],
    counts: {},

    // The job the card is showing, kept so an action can re-read it without a
    // second lookup of which row was clicked.
    current: null,
};

let jobParty = null;

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
    $('#jobs-body').innerHTML = tableMessage(7, 'Loading…');

    try {
        const payload = await auth.call(`/workshop-jobs?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        $('#jobs-body').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(7, 'Your account administers the platform rather than a single workshop, so it has no jobs of its own.')
            : tableMessage(7, error.message, 'error');
    }
}

function render(rows, meta) {
    $('#jobs-body').innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(7, 'Nothing on the bench. Book a motor in and it will appear here.');

    const pagination = meta?.pagination ?? {};

    state.hasMore = Boolean(pagination.has_more);
    $('#jobs-summary').textContent = pagination.total ? `${rows.length} of ${pagination.total}.` : '';
    $('#jobs-prev').disabled = (pagination.current_page ?? 1) <= 1;
    $('#jobs-next').disabled = !state.hasMore;
}

function renderRow(job) {
    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60"
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

    $('[data-tabs]').innerHTML = tabs.map((tab) => `
        <button type="button" class="tab" role="tab" data-tab="${esc(tab.value)}"
                aria-selected="${tab.value === state.status}">
            ${esc(tab.label)}
            <span class="ml-1.5 text-xs text-muted-foreground">${esc(String(tab.count))}</span>
        </button>`).join('');
}

/* -------------------------------------------------------------------------
 | The job card
 | ---------------------------------------------------------------------- */

async function openJob(id) {
    $('#job-modal-title').textContent = 'Job';
    $('#job-modal-subtitle').textContent = '';
    $('#job-modal-body').innerHTML = '<p class="px-5 py-6 text-sm text-muted-foreground">Loading…</p>';

    showModal('#job-modal');

    await refreshJob(id);
}

async function refreshJob(id) {
    try {
        const { data } = await auth.call(`/workshop-jobs/${id}`);

        state.current = data;

        $('#job-modal-title').textContent = `${data.job_no} — ${data.motor}`;
        $('#job-modal-subtitle').textContent =
            `${data.party?.name ?? ''} · received ${formatDate(data.received_date)}`
            + (data.promised_date ? ` · promised ${formatDate(data.promised_date)}` : '');

        $('#job-modal-body').innerHTML = renderCard(data);

        mountCardPicker(data);
    } catch (error) {
        $('#job-modal-body').innerHTML = `<p class="px-5 py-6 text-sm text-rose-600">${esc(error.message)}</p>`;
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
 * The pipeline: where the job is, and exactly the moves the server says are
 * legal from there.
 */
function renderPipeline(job, mayWrite) {
    return `
        <div class="flex flex-wrap items-center gap-2 border-b border-border px-5 py-4">
            <span class="text-[0.8125rem] text-muted-foreground">Now</span>
            ${badge(job.status_label, job.status_tone)}
            ${job.is_overdue ? badge('Past its promised date', 'danger') : ''}

            ${mayWrite && job.next_states.length ? `
                <span class="ml-2 text-[0.8125rem] text-muted-foreground">Move to</span>
                ${job.next_states.map((next) => `
                    <button type="button" class="btn btn-secondary btn-sm" data-advance="${esc(next.value)}">
                        ${esc(next.label)}
                    </button>`).join('')}` : ''}

            ${job.is_billable && can('WRITE', 'TRANSACTIONS') ? `
                <a href="/bills/new?job=${job.id}" class="btn btn-primary btn-sm ml-auto">
                    Generate bill
                </a>` : ''}
        </div>`;
}

function renderComplaint(job) {
    return `
        <div class="border-b border-border px-5 py-4">
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
        <div class="border-b border-border px-5 py-4">
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
        <div class="border-b border-border px-5 py-4">
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
        <div class="px-5 py-4">
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

/* -------------------------------------------------------------------------
 | Adding a part
 | ---------------------------------------------------------------------- */

let pendingPart = null;

/**
 * The same picker the bill counter uses, so a part is chosen the same way
 * whether it is being written onto a job or straight onto an invoice — and so
 * the stock badge is here too, where the fitter is standing next to the shelf.
 */
function mountCardPicker(job) {
    const host = $('[data-part-picker-host]', $('#job-modal-body'));

    if (!host) return;

    pendingPart = null;

    mountItemPicker(host, {
        onPick: (choice) => {
            pendingPart = choice;

            $('[data-part-chosen]').textContent = `${choice.label} — ${choice.unit_symbol || 'each'}`;
            $('[data-add-part]').disabled = false;

            const price = $('[data-part-price]');

            if (price && !price.value && choice.price) price.value = choice.price;
        },
        onCreate: () => openQuickItem({
            onCreated: async (created) => {
                if (created) await refreshJob(job.id);
            },
        }),
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
                quantity: $('[data-part-quantity]').value.trim() || '1',
                unit_price: $('[data-part-price]').value.trim() || null,
            },
        });

        toast('Added to the job.');
        await refreshJob(jobId);
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
        await refreshJob(jobId);
        await Promise.all([loadMeta(), load()]);
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
        await refreshJob(job.id);
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function jobAction(jobId, path, message) {
    try {
        await auth.call(`/workshop-jobs/${jobId}/${path}`, { method: 'POST' });

        toast(message);
        await refreshJob(jobId);
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
        await refreshJob(jobId);
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Booking a motor in
 | ---------------------------------------------------------------------- */

function openJobDrawer() {
    const form = $('#job-form');

    clearFormErrors(form);
    form.reset();
    form.elements.received_date.value = new Date().toISOString().slice(0, 10);

    jobParty = mountPartyPicker($('[data-job-party-host]'), { role: 'customer', label: 'Customer' });
    jobParty.onAdd(() => toast('Add the customer on the Customers screen, then search for them here.', 'info'));

    showModal('#job-drawer');
}

async function submitJob(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);
    setSubmitting(form, true, 'Booking in…');

    try {
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

        hideModal('#job-drawer');
        toast(`Booked in as ${response.data.job_no}.`);

        await Promise.all([loadMeta(), load()]);
        await openJob(response.data.id);
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, 'Book it in');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initJobs() {
    initQuickItem();

    /*
    | A deep link from the dashboard's attention list — `#jobs?status=ready`,
    | `#jobs?overdue=1`, `#jobs?new=1`. The intent comes from the shell rather
    | than from `location.search`, because a module's URL is a fragment of the
    | dashboard's now.
    */
    const params = moduleParams();

    if (params.get('status')) state.status = params.get('status');
    if (params.get('overdue') === '1') state.overdue = true;
    if (params.get('open') === '1') state.open = true;

    await loadMeta();
    await load();

    if (params.get('new') === '1' && can('WRITE', 'WORKSHOP_JOBS')) openJobDrawer();

    clearModuleParams();

    /*
    | Reopening an already-mounted module cannot run this function again, so a
    | second deep link is announced instead — the filters are applied and the
    | list reloaded without the module being rebuilt.
    */
    $('#jobs-body').closest('[data-module-root]')?.addEventListener('module:params', async (event) => {
        state.status = event.detail.get('status') ?? '';
        state.overdue = event.detail.get('overdue') === '1';
        state.open = event.detail.get('open') === '1';
        state.page = 1;

        $('#filter-overdue').setAttribute('aria-pressed', String(state.overdue));

        await load();

        if (event.detail.get('new') === '1' && can('WRITE', 'WORKSHOP_JOBS')) openJobDrawer();

        clearModuleParams();
    });

    $('#filter-overdue').setAttribute('aria-pressed', String(state.overdue));

    $('#job-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        state.page = 1;
        load();
    }, 300));

    $('[data-tabs]').addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tab]');

        if (!tab) return;

        state.status = tab.dataset.tab;
        state.page = 1;
        renderTabs();
        load();
    });

    $('#filter-overdue').addEventListener('click', (event) => {
        state.overdue = !state.overdue;
        event.currentTarget.setAttribute('aria-pressed', String(state.overdue));
        state.page = 1;
        load();
    });

    $('#clear-job-filters').addEventListener('click', () => {
        Object.assign(state, { search: '', status: '', overdue: false, open: true, page: 1 });
        $('#job-search').value = '';
        $('#filter-overdue').setAttribute('aria-pressed', 'false');
        renderTabs();
        load();
    });

    const open = (event) => {
        const row = event.target.closest('[data-job]');

        if (row) openJob(row.dataset.job);
    };

    $('#jobs-body').addEventListener('click', open);
    $('#jobs-body').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') open(event);
    });

    $('#jobs-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    $('#jobs-next').addEventListener('click', () => {
        if (state.hasMore) {
            state.page += 1;
            load();
        }
    });

    $('[data-new-job]').addEventListener('click', openJobDrawer);
    $('#job-form').addEventListener('submit', submitJob);

    // One delegated handler for the whole card, because it is re-rendered after
    // every action and bound listeners would go with it.
    $('#job-modal-body').addEventListener('click', async (event) => {
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
    });
}
