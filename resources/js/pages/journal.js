import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    formatRelative, hideModal, isZeroAmount, setSubmitting, showFormErrors, showModal,
    tableMessage, toast,
} from '../ui';

/**
 * Transactions: four views of the list, the raw double-entry screen, and the
 * payment and receipt forms.
 *
 * Four things drive the design.
 *
 * **The tabs are sets of types, not single types.** A customer receipt sits on
 * the Sales tab beside the invoice it settles, because it is not a different
 * subject — it is the next thing that happened. Grouping by enum case instead
 * would organise the screen around the posting engine rather than around the
 * question somebody opened it to answer.
 *
 * **Each tab has its own columns.** An expense has a payment mode and no
 * counterparty; an invoice has a balance and no mode. One table carrying the
 * union of both would be half empty on either tab, so the head is rendered per
 * tab along with the rows.
 *
 * **A posted transaction is never editable**, so the row actions differ by
 * status rather than being uniformly disabled.
 *
 * **A settlement has its own form** rather than sharing the double-entry grid:
 * the person recording the day's takings should never have to know which account
 * Sundry Debtors is, which is the whole reason posting templates exist. The
 * journal balance is shown live while an entry is being written for the mirror
 * reason — the server refuses an unbalanced entry, but finding that out on
 * submit means retyping a voucher.
 */

const PAGE_SIZE = 25;

/**
 * What "Paid" on a document actually means, attached to the columns that report
 * it so the caveat travels with the figure rather than living in a doc nobody
 * opens.
 *
 * A settlement in this product reduces what a *party* owes, not what a named
 * invoice does — there is no allocation table linking a receipt to the bill it
 * was prompted by. So this column is money taken on the document itself, and a
 * customer who pays a fortnight later leaves it untouched. Showing the party's
 * whole outstanding here instead would put one number against every one of their
 * invoices, and each row would read as an answer about itself.
 */
const PAID_NOTE = 'Money settled on this document itself. A later payment reduces the party’s '
    + 'overall balance rather than this document’s — see their statement on the Parties screen.';

/**
 * What each tab covers, and what it calls its columns.
 *
 * `types` is what the tab asks the API for. `status` is set only on Drafts,
 * which is a tab about a status rather than about a kind of event — everything
 * unauthorised, whatever it was going to be.
 *
 * The column labels follow the design, and follow the tab rather than the data:
 * the same `party` field is the "Customer" on one tab and the "Vendor" on the
 * next, because that is what the person reading it is looking for.
 */
const TABS = {
    sales: {
        types: ['sale', 'receipt'],
        empty: 'No invoices or receipts yet. One appears the moment a sale is recorded.',
        columns: [
            { label: 'Invoice No.' },
            { label: 'Customer' },
            { label: 'Date' },
            { label: 'Total', align: 'right' },
            { label: 'Paid', align: 'right', note: PAID_NOTE },
            { label: 'Balance', align: 'right', note: PAID_NOTE },
            { label: 'Status' },
            { label: '', align: 'right' },
        ],
    },

    purchases: {
        types: ['purchase', 'payment'],
        empty: 'No bills or payments yet. One appears the moment a purchase is recorded.',
        columns: [
            { label: 'Bill No.' },
            { label: 'Vendor' },
            { label: 'Date' },
            { label: 'Total', align: 'right' },
            { label: 'Paid', align: 'right', note: PAID_NOTE },
            { label: 'Outstanding', align: 'right', note: PAID_NOTE },
            { label: 'Status' },
            { label: '', align: 'right' },
        ],
    },

    expenses: {
        types: ['expense'],
        empty: 'No expenses yet. Rent, electricity and the courier all belong here.',
        columns: [
            { label: 'Expense ID' },
            { label: 'Description' },
            { label: 'Amount', align: 'right' },
            { label: 'Mode' },
            { label: 'Date' },
            { label: 'Status' },
            { label: '', align: 'right' },
        ],
    },

    drafts: {
        status: 'draft',
        empty: 'Nothing is parked. Everything that was started has been authorised.',
        columns: [
            { label: 'Draft ID' },
            { label: 'Type' },
            { label: 'Source' },
            { label: 'Last updated' },
            { label: 'Status' },
            { label: 'Actions', align: 'right' },
        ],
    },
};

const state = {
    tab: 'sales',
    page: 1,
    search: '',
    type: '',       // an explicit type filter, which overrides the tab's set
    status: '',
    from: '',
    to: '',
    counts: null,   // the type/status breakdown behind the tab badges
    accounts: [],   // active chart, for the journal line pickers
    parties: [],    // active parties, for the counterparty pickers
    modes: [],      // payment modes, from the server rather than hard-coded here
    lastMeta: null,
};

function tab() {
    return TABS[state.tab];
}

/** True when the type filter is showing something the open tab does not cover. */
function overriding() {
    const types = tab().types;

    return Boolean(state.type) && Boolean(types) && !types.includes(state.type);
}

/**
 * What the two settlement directions are called, and what they mean. Kept here
 * rather than in the markup because the same form serves both, and the wording is
 * the only thing that tells a user which one they are looking at.
 */
const SETTLEMENT = {
    receipt: {
        title: 'Record receipt',
        submit: 'Record receipt',
        endpoint: '/transactions/receipt',
        partyLabel: 'Customer',
        partyHint: 'Their outstanding falls by this amount.',
        explainer: 'Money collected from a customer. This reduces what they owe you and raises the '
            + 'account the money arrived in — it does not touch GST, because the tax was recorded '
            + 'when the invoice was raised.',
        role: 'customer',
        emptyParties: 'No customers yet. Add one on the Parties screen first.',
    },
    payment: {
        title: 'Record payment',
        submit: 'Record payment',
        endpoint: '/transactions/payment',
        partyLabel: 'Supplier',
        partyHint: 'What you owe them falls by this amount.',
        explainer: 'Money paid out to a supplier. This reduces what you owe them and lowers the '
            + 'account the money left — it does not touch GST, because the tax was recorded when '
            + 'the bill was entered.',
        role: 'vendor',
        emptyParties: 'No suppliers yet. Add one on the Parties screen first.',
    },
};

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

/**
 * The chart of accounts, once. Archived accounts are excluded because the
 * engine refuses to post to them — offering one would only produce a 422.
 */
async function loadAccounts() {
    if (state.accounts.length) return;

    try {
        const { data } = await auth.call('/accounts?per_page=200&is_active=1');
        state.accounts = data;
    } catch {
        // A DATA_ENTRY user without READ:ACCOUNTS cannot write an entry anyway;
        // the picker stays empty and the form explains itself when opened.
        state.accounts = [];
    }
}

/**
 * Active parties, for the optional counterparty picker.
 *
 * `with_position` is deliberately absent: the form only needs names, and asking
 * for outstanding figures here would run a ledger query the picker never shows.
 * A user without READ:PARTIES simply gets no picker — the field is optional, so
 * there is nothing to explain and nothing they cannot do.
 */
async function loadParties() {
    if (state.parties.length) return;

    try {
        const { data } = await auth.call('/parties?per_page=200&is_active=1');
        state.parties = data;
    } catch {
        state.parties = [];
    }
}

/**
 * The ways money can move, and what each one's reference is called.
 *
 * Fetched rather than hard-coded so the form asks for "Cheque number" — and knows
 * that a cheque needs one — without a second copy of the mapping drifting out of
 * step with the server's.
 */
async function loadPaymentModes() {
    if (state.modes.length) return;

    try {
        const { data } = await auth.call('/transactions/meta');
        state.modes = data.payment_modes ?? [];
    } catch {
        state.modes = [];
    }
}

function modeFor(value) {
    return state.modes.find((mode) => mode.value === value) ?? null;
}

function fillPartyPicker(selectedId = '') {
    const picker = $('#journal-party');
    if (!picker) return;

    picker.innerHTML = '<option value="">No counterparty</option>'
        + state.parties.map((party) => `
            <option value="${party.id}">${esc(party.name)}</option>`).join('');

    picker.value = selectedId ? String(selectedId) : '';
    // Hidden rather than shown empty when there are no parties to choose:
    // an empty dropdown reads as something broken.
    picker.closest('div').classList.toggle('hidden', state.parties.length === 0);
}

/**
 * The tab badges.
 *
 * One request for the whole breakdown rather than one per tab, and it is a
 * separate request from the list on purpose: the badges count the workshop's
 * books, where the list counts what the current filters match. A badge that
 * shrank as somebody typed in the search box would be answering a different
 * question from the one it appears to.
 *
 * Failure is silent. A missing count leaves the label bare, which is honest and
 * costs nothing; an error banner over a page whose rows loaded fine would not be.
 */
async function loadCounts() {
    try {
        const { data } = await auth.call('/transactions/counts');
        state.counts = data;
    } catch {
        state.counts = null;
    }

    paintCounts();
}

function countFor(name) {
    if (!state.counts) return null;

    const { types = {}, statuses = {} } = state.counts;
    const config = TABS[name];

    if (config.status) return statuses[config.status] ?? 0;

    return config.types.reduce((total, type) => total + (types[type] ?? 0), 0);
}

function paintCounts() {
    $$('#txn-tabs [data-tab]').forEach((button) => {
        const count = countFor(button.dataset.tab);

        // Blank rather than "0" while unknown: a zero is a claim about an empty
        // workshop, and nothing has checked yet.
        $('[data-tab-count]', button).textContent = count === null ? '' : String(count);
    });
}

function paintTabs() {
    $$('#txn-tabs [data-tab]').forEach((button) => {
        button.setAttribute('aria-selected', button.dataset.tab === state.tab ? 'true' : 'false');
    });

    // Drafts *is* a status. Offering the status filter there would let somebody
    // ask for the posted drafts, which is not a thing.
    const status = $('#filter-status');
    const onDrafts = Boolean(tab().status);

    status.disabled = onDrafts;
    status.value = onDrafts ? tab().status : state.status;
    status.title = onDrafts ? 'The Drafts tab is already filtered to drafts.' : '';

    const override = $('#txn-override');

    override.classList.toggle('hidden', !overriding());
    override.classList.toggle('flex', overriding());

    if (overriding()) {
        const label = $(`#filter-type option[value="${state.type}"]`)?.textContent.trim() ?? state.type;

        $('[data-override-label]', override).textContent
            = `Showing ${label} — a type this tab does not normally cover.`;
    }
}

function query() {
    const params = new URLSearchParams({ per_page: PAGE_SIZE, page: state.page });

    // An explicit type wins outright rather than narrowing the tab's set. That
    // is what makes a manual journal, a stock adjustment and an opening balance
    // reachable at all: no tab covers them, and a filter that quietly returned
    // nothing inside the open tab would look like an empty workshop.
    if (state.type) {
        params.set('type', state.type);
    } else {
        (tab().types ?? []).forEach((type) => params.append('types[]', type));
    }

    // The tab's own status, where it has one, is not negotiable.
    const status = tab().status ?? state.status;

    if (state.search) params.set('search', state.search);
    if (status) params.set('status', status);
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    return params;
}

async function loadTransactions() {
    const span = tab().columns.length;

    renderHead();
    $('#journal-rows').innerHTML = tableMessage(span, 'Loading transactions…');

    try {
        const payload = await auth.call(`/transactions?${query()}`);

        state.lastMeta = payload.meta?.pagination ?? null;
        render(payload.data);
    } catch (error) {
        // A platform super-admin holds every grant but belongs to no workshop,
        // so they can reach this page by typing the URL. That is a situation,
        // not a failure — say so plainly.
        $('#journal-rows').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(span, 'Your account administers the platform rather than a single workshop, so it has no books of its own.')
            : tableMessage(span, error.message, 'error');

        $('#journal-summary').textContent = '';
        ['#new-journal', '#new-payment', '#new-receipt']
            .forEach((id) => $(id)?.classList.add('hidden'));
    }
}

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

const STATUS_BADGE = {
    draft: 'bg-amber-100 text-amber-800',
    posted: 'bg-emerald-100 text-emerald-800',
    reversed: 'bg-muted text-muted-foreground',
};

/**
 * Where a transaction came from. Worth a badge only on the Drafts tab, which is
 * the one place it changes what you do next: a draft an OCR pass produced needs
 * checking against the paper in a way one somebody typed does not.
 */
const SOURCE_BADGE = {
    manual: 'bg-muted text-muted-foreground',
    import: 'bg-violet-100 text-violet-800',
    ai: 'bg-sky-100 text-sky-800',
};

function renderHead() {
    $('#journal-head').innerHTML = `
        <tr class="border-b border-border bg-secondary/40">
            ${tab().columns.map((column) => `
                <th scope="col"
                    class="table-head ${column.align === 'right' ? 'text-right' : ''}"
                    ${column.note ? `title="${esc(column.note)}"` : ''}>
                    ${esc(column.label)}${column.note ? '<span aria-hidden="true"> ⓘ</span>' : ''}
                </th>`).join('')}
        </tr>`;
}

function render(rows) {
    const body = $('#journal-rows');
    const renderRow = ROWS[state.tab];

    body.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(
            tab().columns.length,
            // Two different situations, and conflating them is how a screen
            // teaches somebody their books are empty when they are only filtered.
            filtered() ? 'No transactions match these filters.' : tab().empty,
        );

    const pagination = state.lastMeta;
    const total = pagination?.total ?? rows.length;

    $('#journal-summary').textContent = total === 0
        ? ''
        : `Showing ${rows.length} of ${total} · page ${pagination?.current_page ?? 1} of ${pagination?.last_page ?? 1}`;

    $('#page-prev').disabled = (pagination?.current_page ?? 1) <= 1;
    $('#page-next').disabled = !(pagination?.has_more ?? false);
}

/** True when anything the user chose is narrowing the tab's own view. */
function filtered() {
    return Boolean(state.search || state.type || state.from || state.to
        || (state.status && !tab().status));
}

/* --- The cells the tabs share ------------------------------------------- */

function reference(transaction) {
    // The id, as the document number. A workshop's own invoice numbering is not
    // something this product issues yet, so the row shows the handle the rest of
    // the system uses rather than inventing one that would not match anything.
    return `<td class="table-cell w-28 whitespace-nowrap">
        <span class="font-mono text-[0.8125rem] font-semibold text-primary">#${transaction.id}</span>
        ${transaction.reverses_id
            ? `<span class="mt-0.5 block text-[0.6875rem] text-muted-foreground">reverses #${transaction.reverses_id}</span>`
            : ''}
    </td>`;
}

function party(transaction) {
    return `<td class="table-cell max-w-44 truncate text-[0.8125rem]">
        ${transaction.party?.name ? esc(transaction.party.name) : '<span class="text-muted-foreground">—</span>'}
        <span class="mt-0.5 block text-[0.6875rem] text-muted-foreground">${esc(transaction.type_label)}</span>
    </td>`;
}

function date(transaction) {
    return `<td class="table-cell w-28 whitespace-nowrap text-[0.8125rem] text-muted-foreground">
        ${esc(formatDate(transaction.date))}
    </td>`;
}

function money(amount, { emphasis = false, tone = '' } = {}) {
    return `<td class="table-cell w-32 whitespace-nowrap text-right font-mono text-[0.8125rem] ${emphasis ? 'font-semibold' : ''} ${tone}">
        ${amount === null || amount === undefined ? '<span class="text-muted-foreground">—</span>' : esc(formatMoney(amount))}
    </td>`;
}

function status(transaction) {
    return `<td class="table-cell w-24">
        <span class="badge ${STATUS_BADGE[transaction.status] ?? 'bg-muted'}">${esc(transaction.status_label)}</span>
    </td>`;
}

function rowAttributes(transaction) {
    return `class="cursor-pointer border-t border-border transition hover:bg-secondary/60
                   ${transaction.status === 'reversed' ? 'opacity-60' : ''}"
            data-open="${transaction.id}" tabindex="0" role="button"
            aria-label="Open transaction ${transaction.id}"`;
}

/**
 * The row menu, as the ⋮ in the design.
 *
 * What it offers depends on the status rather than being a fixed list with
 * half of it disabled: a posted entry has no edit to grey out, because there
 * is no circumstance in which it becomes editable.
 */
function menu(transaction) {
    const items = [];

    if (transaction.is_draft) {
        if (can('UPDATE', 'TRANSACTIONS')) {
            items.push([`data-edit="${transaction.id}"`, iconPencil, 'Resume', '']);
            items.push([`data-post="${transaction.id}"`, iconCheck, 'Post to the ledger', 'text-emerald-600']);
        }

        if (can('DELETE', 'TRANSACTIONS')) {
            items.push([`data-discard="${transaction.id}"`, iconTrash, 'Discard draft', 'text-rose-600']);
        }
    } else if (transaction.status === 'posted' && can('WRITE', 'TRANSACTIONS')) {
        items.push([`data-reverse="${transaction.id}"`, iconReverse, 'Post a reversing entry', '']);
    }

    const entries = [[`data-open="${transaction.id}"`, iconEye, 'View details', '']].concat(items);

    return `<td class="table-cell w-12 text-right">
        <div class="relative inline-block" data-menu>
            <button type="button" class="btn btn-ghost btn-icon" data-menu-toggle
                    title="More" aria-label="More actions" aria-expanded="false">${iconMore}</button>

            <div class="surface absolute right-0 top-full z-20 mt-1 hidden w-48 py-1.5 text-left" data-menu-panel>
                ${entries.map(([attribute, icon, label, tone]) => `
                    <button type="button" ${attribute}
                            class="flex w-full items-center gap-2.5 px-3 py-2 text-[0.8125rem] font-medium
                                   transition hover:bg-secondary ${tone || 'text-secondary-foreground'}">
                        ${icon}${esc(label)}
                    </button>`).join('')}
            </div>
        </div>
    </td>`;
}

/* --- One renderer per tab ---------------------------------------------- */

const ROWS = {
    sales: (transaction) => `
        <tr ${rowAttributes(transaction)}>
            ${reference(transaction)}
            ${party(transaction)}
            ${date(transaction)}
            ${money(transaction.total, { emphasis: true })}
            ${money(transaction.paid, { tone: 'text-emerald-600' })}
            ${money(transaction.balance, { tone: isZeroAmount(transaction.balance ?? '0') ? 'text-muted-foreground' : 'text-rose-600' })}
            ${status(transaction)}
            ${menu(transaction)}
        </tr>`,

    // The same shape as a sale's, deliberately. What the workshop owes a
    // supplier is the mirror of what a customer owes it, and a reader who has
    // learned one column order should not have to learn a second.
    purchases: (transaction) => ROWS.sales(transaction),

    expenses: (transaction) => `
        <tr ${rowAttributes(transaction)}>
            ${reference(transaction)}
            <td class="table-cell max-w-72 text-[0.8125rem]">
                ${transaction.notes
                    ? esc(transaction.notes)
                    : `<span class="text-muted-foreground">${esc(transaction.type_label)}</span>`}
            </td>
            ${money(transaction.total, { emphasis: true })}
            <td class="table-cell w-32">${modeBadges(transaction)}</td>
            ${date(transaction)}
            ${status(transaction)}
            ${menu(transaction)}
        </tr>`,

    drafts: (transaction) => `
        <tr ${rowAttributes(transaction)}>
            ${reference(transaction)}
            <td class="table-cell w-40 text-[0.8125rem]">${esc(transaction.type_label)}</td>
            <td class="table-cell w-32">
                <span class="badge ${SOURCE_BADGE[transaction.source] ?? 'bg-muted'}">${esc(transaction.source_label)}</span>
            </td>
            <td class="table-cell w-36 whitespace-nowrap text-[0.8125rem] text-muted-foreground">
                ${esc(formatRelative(transaction.updated_at ?? transaction.created_at))}
            </td>
            ${status(transaction)}
            <td class="table-cell w-40 text-right">
                <div class="flex justify-end gap-1">
                    ${can('UPDATE', 'TRANSACTIONS')
                        ? `<button type="button" class="btn btn-secondary btn-sm" data-edit="${transaction.id}">
                               ${iconPencil}Resume
                           </button>`
                        : ''}
                    ${menuless(transaction)}
                </div>
            </td>
        </tr>`,
};

/**
 * The draft row's two icon actions, beside Resume rather than behind a ⋮.
 *
 * A worklist is a page somebody works *through*, so the two things they came to
 * do are one click each. Everywhere else the same actions sit in the row menu,
 * because on those tabs they are the exception rather than the task.
 */
function menuless(transaction) {
    const buttons = [];

    if (can('UPDATE', 'TRANSACTIONS')) {
        buttons.push(`<button type="button" class="btn btn-ghost btn-icon text-emerald-600" data-post="${transaction.id}"
                              title="Post to the ledger" aria-label="Post to the ledger">${iconCheck}</button>`);
    }

    if (can('DELETE', 'TRANSACTIONS')) {
        buttons.push(`<button type="button" class="btn btn-ghost btn-icon" data-discard="${transaction.id}"
                              title="Discard draft" aria-label="Discard draft">${iconTrash}</button>`);
    }

    return buttons.join('');
}

/**
 * How the money moved, from the document's own split.
 *
 * Several badges where a bill was settled several ways, because "₹2,000 cash and
 * ₹3,000 by UPI" is one expense that touched two accounts, and each of those is
 * reconciled against a different thing — a cash box and a passbook.
 */
function modeBadges(transaction) {
    const split = transaction.payments ?? [];

    if (!split.length) return '<span class="text-[0.8125rem] text-muted-foreground">—</span>';

    const modes = [...new Set(split.map((payment) => payment.mode_label ?? payment.mode))];

    return `<div class="flex flex-wrap gap-1">${modes
        .map((mode) => `<span class="badge bg-secondary text-secondary-foreground">${esc(mode)}</span>`)
        .join('')}</div>`;
}

const iconEye = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>';
const iconPencil = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/></svg>';
const iconTrash = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';
const iconCheck = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
const iconReverse = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>';
const iconMore = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="0.5"/><circle cx="12" cy="12" r="0.5"/><circle cx="12" cy="19" r="0.5"/></svg>';

/* -------------------------------------------------------------------------
 | Writing an entry
 | ---------------------------------------------------------------------- */

function accountOptions(selected = '') {
    const groups = {};

    state.accounts.forEach((account) => {
        (groups[account.type_label] ??= []).push(account);
    });

    return Object.entries(groups).map(([label, accounts]) => `
        <optgroup label="${esc(label)}">
            ${accounts.map((account) => `
                <option value="${account.id}" ${String(account.id) === String(selected) ? 'selected' : ''}>
                    ${esc(account.code)} · ${esc(account.name)}
                </option>`).join('')}
        </optgroup>`).join('');
}

function lineRow(line = {}) {
    return `
        <tr class="border-t border-border" data-line>
            <td class="px-3 py-2">
                <select class="field-input" data-account aria-label="Account">
                    <option value="">Choose an account…</option>
                    ${accountOptions(line.account_id ?? '')}
                </select>
            </td>
            <td class="px-3 py-2">
                <input type="text" inputmode="decimal" class="field-input text-right font-mono" data-debit
                       aria-label="Debit" placeholder="0.00"
                       value="${line.debit && !isZeroAmount(line.debit) ? esc(line.debit) : ''}">
            </td>
            <td class="px-3 py-2">
                <input type="text" inputmode="decimal" class="field-input text-right font-mono" data-credit
                       aria-label="Credit" placeholder="0.00"
                       value="${line.credit && !isZeroAmount(line.credit) ? esc(line.credit) : ''}">
            </td>
            <td class="px-3 py-2">
                <input type="text" class="field-input" data-memo aria-label="Memo"
                       placeholder="Optional" value="${esc(line.memo ?? '')}">
            </td>
            <td class="px-3 py-2 text-right">
                <button type="button" class="btn btn-ghost btn-icon" data-remove-line
                        title="Remove line" aria-label="Remove line">${iconTrash}</button>
            </td>
        </tr>`;
}

/**
 * Sum a column in whole paise. Amounts are decimal strings and stay that way —
 * adding them as floats is how a form claims to balance when it does not.
 */
function totalPaise(selector) {
    return $$('[data-line]', $('#journal-lines')).reduce((total, row) => {
        const raw = $(selector, row).value.trim();

        if (!/^\d+(\.\d{1,2})?$/.test(raw)) return total;

        const [whole, fraction = ''] = raw.split('.');

        return total + Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
    }, 0);
}

function paiseToAmount(paise) {
    const sign = paise < 0 ? '-' : '';
    const absolute = Math.abs(paise);

    return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

/** Live balance feedback, so an unbalanced entry is visible before submitting. */
function refreshTotals() {
    const debit = totalPaise('[data-debit]');
    const credit = totalPaise('[data-credit]');
    const note = $('#balance-note');

    $('#total-debit').textContent = formatMoney(paiseToAmount(debit));
    $('#total-credit').textContent = formatMoney(paiseToAmount(credit));

    if (debit === 0 && credit === 0) {
        note.textContent = '';
        note.className = 'px-3 py-2 text-[0.8125rem] font-medium';

        return;
    }

    const balanced = debit === credit;

    note.textContent = balanced
        ? 'Balanced'
        : `Out by ${formatMoney(paiseToAmount(Math.abs(debit - credit)))}`;
    note.className = `px-3 py-2 text-[0.8125rem] font-semibold ${balanced ? 'text-emerald-600' : 'text-rose-600'}`;
}

function collectLines() {
    return $$('[data-line]', $('#journal-lines'))
        .map((row) => ({
            account_id: $('[data-account]', row).value,
            debit: $('[data-debit]', row).value.trim(),
            credit: $('[data-credit]', row).value.trim(),
            memo: $('[data-memo]', row).value.trim(),
        }))
        // A row with nothing on it is a row the user has not filled in yet, not
        // an error — the two blank rows the form opens with are exactly that.
        .filter((line) => line.account_id || line.debit || line.credit)
        .map((line) => ({
            account_id: Number(line.account_id),
            debit: line.debit || '0',
            credit: line.credit || '0',
            memo: line.memo || null,
        }));
}

function validate(lines) {
    const errors = {};

    if (!$('#journal-date').value) errors.date = ['Choose a date for the entry.'];

    if (lines.length < 2) {
        return {
            ...errors,
            lines: ['A journal entry needs at least two lines — one account to debit and one to credit.'],
        };
    }

    const bad = lines.findIndex((line) => !line.account_id
        || (isZeroAmount(line.debit) === isZeroAmount(line.credit)));

    if (bad !== -1) {
        errors.lines = [`Line ${bad + 1} needs an account and an amount in exactly one of the two columns.`];
    }

    return Object.keys(errors).length ? errors : null;
}

async function openForm(transaction = null) {
    await Promise.all([loadAccounts(), loadParties()]);

    const form = $('#journal-form');

    clearFormErrors(form);
    form.reset();

    const editing = transaction !== null;

    $('#journal-modal-title').textContent = editing ? `Edit draft #${transaction.id}` : 'New journal entry';
    form.elements.id.value = editing ? transaction.id : '';
    $('#journal-date').value = editing ? transaction.date : new Date().toISOString().slice(0, 10);
    $('#journal-notes').value = editing ? (transaction.notes ?? '') : '';

    fillPartyPicker(editing ? (transaction.party_id ?? '') : '');

    const lines = editing && transaction.lines?.length ? transaction.lines : [{}, {}];
    $('#journal-lines').innerHTML = lines.map(lineRow).join('');

    refreshTotals();
    showModal('#journal-modal');
}

async function submit({ post }) {
    const form = $('#journal-form');
    const id = form.elements.id.value;
    const lines = collectLines();

    clearFormErrors(form);

    const errors = validate(lines);

    if (errors) {
        showFormErrors(form, {
            fields: errors,
            message: errors.lines?.[0] ?? 'Please correct the highlighted fields.',
        });

        return;
    }

    const body = {
        date: $('#journal-date').value,
        notes: $('#journal-notes').value.trim() || null,
        // Always sent, including as null: on a PATCH an absent key means
        // "leave it alone", so omitting it would make clearing the party
        // impossible.
        party_id: Number($('#journal-party')?.value) || null,
        lines,
    };

    setSubmitting(form, true, post ? 'Posting…' : 'Saving…');

    try {
        if (id) {
            await auth.call(`/transactions/${id}`, { method: 'PATCH', body });

            // Posting a draft is a separate call: saving an edit must never
            // commit it to the ledger as a side effect.
            if (post) await auth.call(`/transactions/${id}/post`, { method: 'POST' });
        } else {
            await auth.call('/transactions/journal', { method: 'POST', body: { ...body, post } });
        }

        hideModal('#journal-modal');
        toast(post ? 'Entry posted.' : 'Draft saved.');
        loadTransactions();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Payments and receipts
 | ---------------------------------------------------------------------- */

function settlementRow(split = {}) {
    const selected = split.mode ?? state.modes[0]?.value ?? '';
    const mode = modeFor(selected);

    return `
        <tr class="border-t border-border" data-payment>
            <td class="px-3 py-2">
                <select class="field-input" data-mode aria-label="How the money moved">
                    ${state.modes.map((option) => `
                        <option value="${esc(option.value)}" ${option.value === selected ? 'selected' : ''}>
                            ${esc(option.label)}
                        </option>`).join('')}
                </select>
            </td>
            <td class="px-3 py-2">
                <input type="text" inputmode="decimal" class="field-input text-right font-mono" data-amount
                       aria-label="Amount" placeholder="0.00"
                       value="${split.amount && !isZeroAmount(split.amount) ? esc(split.amount) : ''}">
            </td>
            <td class="px-3 py-2">
                <input type="text" class="field-input" data-reference
                       aria-label="Reference"
                       placeholder="${esc(mode?.reference_label ?? 'Reference')}"
                       value="${esc(split.reference ?? '')}">
            </td>
            <td class="px-3 py-2 text-right">
                <button type="button" class="btn btn-ghost btn-icon" data-remove-payment
                        title="Remove tender" aria-label="Remove tender">${iconTrash}</button>
            </td>
        </tr>`;
}

/**
 * Keep each reference field labelled for the mode beside it, and mark the one
 * that is actually required.
 *
 * A cheque without its number cannot be matched against a statement or chased
 * when it bounces, so the server refuses one — saying so here means finding out
 * before submitting rather than after.
 */
function refreshPaymentRows() {
    $$('[data-payment]', $('#settlement-rows')).forEach((row) => {
        const mode = modeFor($('[data-mode]', row).value);
        const reference = $('[data-reference]', row);

        reference.placeholder = mode?.reference_label ?? 'Reference';
        reference.required = Boolean(mode?.requires_reference);
    });

    const total = $$('[data-payment]', $('#settlement-rows')).reduce((sum, row) => {
        const raw = $('[data-amount]', row).value.trim();

        if (!/^\d+(\.\d{1,2})?$/.test(raw)) return sum;

        const [whole, fraction = ''] = raw.split('.');

        // Whole paise, from the digits. Adding these as floats is how a form
        // shows a total the ledger disagrees with.
        return sum + Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
    }, 0);

    $('#settlement-total').textContent = formatMoney(paiseToAmount(total));
}

function fillSettlementPartyPicker(kind, selectedId = '') {
    const config = SETTLEMENT[kind];
    const picker = $('#settlement-party');

    // Filtered to the role this direction requires, because the server refuses
    // the mismatch — offering a customer in a payment form would only produce a
    // 422 the user could not have predicted. `is_customer` is role *membership*,
    // so a counterparty who is both appears in both lists, correctly.
    const eligible = state.parties.filter((party) => party[`is_${config.role}`]);

    picker.innerHTML = `<option value="">Choose a ${config.partyLabel.toLowerCase()}…</option>`
        + eligible.map((party) => `
            <option value="${party.id}">${esc(party.name)}</option>`).join('');

    picker.value = selectedId ? String(selectedId) : '';

    $('#settlement-party-hint').textContent = eligible.length
        ? config.partyHint
        : config.emptyParties;
}

function collectSplit() {
    return $$('[data-payment]', $('#settlement-rows'))
        .map((row) => ({
            mode: $('[data-mode]', row).value,
            amount: $('[data-amount]', row).value.trim(),
            reference: $('[data-reference]', row).value.trim(),
        }))
        // A row with no amount is one the user has not filled in yet, not an
        // error — the form opens with exactly one such row.
        .filter((split) => split.amount !== '')
        .map((split) => ({
            mode: split.mode,
            amount: split.amount,
            reference: split.reference || null,
        }));
}

function validateSplit(kind, split) {
    const errors = {};

    if (!$('#settlement-party').value) {
        errors.party_id = [`Choose the ${SETTLEMENT[kind].partyLabel.toLowerCase()} the money moved ${kind === 'receipt' ? 'from' : 'to'}.`];
    }

    if (!$('#settlement-date').value) errors.date = ['Choose the date the money moved.'];

    if (!split.length) {
        errors.payments = ['Say how the money moved — enter at least one amount.'];

        return errors;
    }

    const missingReference = split.findIndex(
        (line) => modeFor(line.mode)?.requires_reference && !line.reference
    );

    if (missingReference !== -1) {
        const mode = modeFor(split[missingReference].mode);

        errors.payments = [`Line ${missingReference + 1} is a ${mode.label.toLowerCase()}, so it needs its ${mode.reference_label.toLowerCase()}.`];
    }

    return Object.keys(errors).length ? errors : null;
}

async function openSettlementForm(kind, transaction = null) {
    await Promise.all([loadParties(), loadPaymentModes()]);

    const config = SETTLEMENT[kind];
    const form = $('#settlement-form');
    const editing = transaction !== null;

    clearFormErrors(form);
    form.reset();

    form.elements.kind.value = kind;
    form.elements.id.value = editing ? transaction.id : '';

    $('#settlement-modal-title').textContent = editing ? `Edit draft #${transaction.id}` : config.title;
    $('#settlement-submit').textContent = config.submit;
    $('#settlement-explainer').textContent = config.explainer;
    $('#settlement-party-label').textContent = config.partyLabel;

    $('#settlement-date').value = editing ? transaction.date : new Date().toISOString().slice(0, 10);
    $('#settlement-notes').value = editing ? (transaction.notes ?? '') : '';

    fillSettlementPartyPicker(kind, editing ? (transaction.party_id ?? '') : '');

    const split = editing && transaction.payments?.length ? transaction.payments : [{}];
    $('#settlement-rows').innerHTML = split.map(settlementRow).join('');

    refreshPaymentRows();
    showModal('#settlement-modal');
}

async function submitSettlement({ post }) {
    const form = $('#settlement-form');
    const kind = form.elements.kind.value;
    const id = form.elements.id.value;
    const split = collectSplit();

    clearFormErrors(form);

    const errors = validateSplit(kind, split);

    if (errors) {
        showFormErrors(form, {
            fields: errors,
            message: errors.payments?.[0] ?? 'Please correct the highlighted fields.',
        });

        return;
    }

    const body = {
        date: $('#settlement-date').value,
        notes: $('#settlement-notes').value.trim() || null,
        party_id: Number($('#settlement-party').value),
        payments: split,
    };

    setSubmitting(form, true, post ? 'Recording…' : 'Saving…');

    try {
        if (id) {
            await auth.call(`/transactions/${id}`, { method: 'PATCH', body });

            // Authorising a draft is its own call, exactly as with a journal:
            // saving an edit must never commit it to the ledger.
            if (post) await auth.call(`/transactions/${id}/post`, { method: 'POST' });
        } else {
            await auth.call(SETTLEMENT[kind].endpoint, { method: 'POST', body: { ...body, post } });
        }

        hideModal('#settlement-modal');
        toast(post ? `${SETTLEMENT[kind].title.replace('Record ', '')} recorded.` : 'Draft saved.');
        loadTransactions();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Reading a voucher
 | ---------------------------------------------------------------------- */

/**
 * Which sections a voucher has, in the order the design shows them.
 *
 * Computed from the transaction rather than fixed, because the sections are not
 * all applicable to all of it: only a bill has items, only a stock-moving
 * posting has movements, only something settleable has a payment split. A tab
 * that opened onto "no data" would be a tab promising something the record
 * cannot have.
 *
 * Two of the design's sections are deliberately absent. **Comments** has nothing
 * behind it — this product has no discussion thread on a voucher, and a box that
 * dropped what you typed would be worse than no box. **Attachments** likewise:
 * files exist in this product, but nothing links one to a transaction, so the
 * panel could only ever be empty. Both belong here the day the data does.
 */
function voucherSections(transaction) {
    const sections = [{ id: 'summary', label: 'Summary' }];

    if ((transaction.lines ?? []).length) {
        sections.push({ id: 'ledger', label: 'Ledger' });
    }

    if (transaction.paid !== undefined) {
        sections.push({ id: 'payments', label: 'Payments' });
    }

    if ((transaction.movements ?? []).length) {
        sections.push({ id: 'inventory', label: 'Inventory' });
    }

    sections.push({ id: 'timeline', label: 'Timeline' });

    return sections;
}

/** A labelled row in one of the drawer's key/value blocks. */
function field(label, value) {
    if (value === null || value === undefined || value === '') return '';

    return `
        <div class="flex items-start justify-between gap-4 py-1.5">
            <span class="shrink-0 text-[0.8125rem] text-muted-foreground">${esc(label)}</span>
            <span class="text-right text-[0.8125rem] font-medium text-foreground">${value}</span>
        </div>`;
}

function sectionTitle(text) {
    return `<h4 class="section-label mb-2">${esc(text)}</h4>`;
}

/**
 * How much of the document has been settled, as the design's progress bar.
 *
 * The bar is the reason this is worth drawing at all: "₹10,000 of ₹23,500" is
 * arithmetic somebody has to do, and a part-paid invoice is the whole thing a
 * sales list is scanned for.
 */
function paidProgress(transaction) {
    // On the digits, never as floats — the same rule the totals follow.
    const minor = (amount) => {
        const [whole = '0', fraction = ''] = String(amount ?? '0').replace(/^[-+]/, '').split('.');

        return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
    };

    const total = minor(transaction.total);
    const paid = minor(transaction.paid);
    const percent = total > 0 ? Math.min(100, Math.round((paid / total) * 100)) : 0;

    return `
        <div class="mb-1.5 flex items-center justify-between text-[0.8125rem] text-muted-foreground">
            <span>${percent}% settled on this document</span>
            <span class="font-mono">${esc(formatMoney(transaction.paid))} of ${esc(formatMoney(transaction.total))}</span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full ${percent === 100 ? 'bg-emerald-500' : 'bg-primary'}"
                 style="width: ${percent}%"></div>
        </div>`;
}

function voucherSummary(transaction) {
    // What was billed, where the document has its own lines — as distinct from
    // what it did to the books, which is the Ledger section's job.
    const items = (transaction.items ?? []).map((line) => `
        <tr class="border-t border-border">
            <td class="px-3 py-2 text-[0.8125rem]">
                ${esc(line.description ?? `Item #${line.item_id ?? '—'}`)}
                ${line.memo ? `<span class="mt-0.5 block text-[0.6875rem] text-muted-foreground">${esc(line.memo)}</span>` : ''}
            </td>
            <td class="px-3 py-2 text-right text-[0.8125rem] text-muted-foreground whitespace-nowrap">
                ${esc(line.quantity ?? '')}${line.unit_symbol ? ` ${esc(line.unit_symbol)}` : ''}
            </td>
            <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-medium">
                ${line.line_total === null || line.line_total === undefined
                    ? '<span class="text-muted-foreground" title="Not priced until the draft is posted.">—</span>'
                    : esc(formatMoney(line.line_total))}
            </td>
        </tr>`).join('');

    return `
        <div>
            ${sectionTitle(transaction.party ? 'Document' : 'Voucher')}
            <div class="rounded-[10px] bg-secondary/40 px-4 py-3">
                ${field('Reference', `<span class="font-mono">#${transaction.id}</span>`)}
                ${field('Type', esc(transaction.type_label))}
                ${field(transaction.party ? 'Party' : '', transaction.party ? esc(transaction.party.name) : '')}
                ${field('Date', esc(formatDate(transaction.date)))}
                ${field('Total', `<span class="font-mono">${esc(formatMoney(transaction.total))}</span>`)}
                ${field('Entered as', esc(transaction.source_label))}
                ${field('Narration', transaction.notes ? esc(transaction.notes) : '')}
            </div>
        </div>

        ${items ? `
            <div>
                ${sectionTitle(transaction.type === 'purchase' ? 'Purchased items' : 'Items')}
                <div class="overflow-hidden rounded-[10px] border border-border">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-secondary/40">
                                <th class="table-head">Item</th>
                                <th class="table-head text-right">Qty</th>
                                <th class="table-head text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>${items}</tbody>
                        <tfoot>
                            <tr class="border-t border-border bg-secondary/30">
                                <td class="px-3 py-2 text-right text-[0.8125rem] font-semibold text-muted-foreground" colspan="2">Total</td>
                                <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-bold">${esc(formatMoney(transaction.total))}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>` : ''}

        ${transaction.is_draft ? `
            <p class="rounded-[10px] border border-amber-200 bg-amber-50/60 px-3.5 py-2.5 text-[0.8125rem] text-amber-800">
                Nothing here has reached the ledger. A draft's figures are recomputed when it is posted —
                stock is valued at the average on that day, and the tax follows the rate then in force — so
                what is shown is what was intended rather than what will be recorded.
            </p>` : ''}

        ${transaction.reverses_id ? `
            <p class="rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5 text-[0.8125rem] text-secondary-foreground">
                This entry reverses transaction #${transaction.reverses_id}. Both remain in the books — the
                ledger records what happened, including what was got wrong.
            </p>` : ''}

        ${transaction.status === 'reversed' ? `
            <p class="rounded-[10px] border border-amber-200 bg-amber-50/60 px-3.5 py-2.5 text-[0.8125rem] text-amber-800">
                This entry has been reversed. Its lines stay in the ledger and are cancelled by the reversing entry.
            </p>` : ''}`;
}

function voucherLedger(transaction) {
    const lines = (transaction.lines ?? []).map((line) => `
        <tr class="border-t border-border">
            <td class="px-3 py-2">
                <span class="font-mono text-[0.8125rem] text-muted-foreground">${esc(line.account?.code ?? '')}</span>
                <span class="ml-1.5 text-[0.8125rem] font-medium">${esc(line.account?.name ?? `Account #${line.account_id}`)}</span>
                ${line.memo ? `<span class="mt-0.5 block text-[0.6875rem] text-muted-foreground">${esc(line.memo)}</span>` : ''}
            </td>
            <td class="px-3 py-2 text-right font-mono text-[0.8125rem]">
                ${isZeroAmount(line.debit) ? '' : esc(formatMoney(line.debit))}
            </td>
            <td class="px-3 py-2 text-right font-mono text-[0.8125rem]">
                ${isZeroAmount(line.credit) ? '' : esc(formatMoney(line.credit))}
            </td>
        </tr>`).join('');

    return `
        <div>
            ${sectionTitle('Ledger details')}
            <div class="overflow-hidden rounded-[10px] border border-border">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-secondary/40">
                            <th class="table-head">Account</th>
                            <th class="table-head text-right">Debit</th>
                            <th class="table-head text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody>${lines}</tbody>
                    <tfoot>
                        <tr class="border-t border-border bg-secondary/30">
                            <td class="px-3 py-2 text-right text-[0.8125rem] font-semibold text-muted-foreground">Total</td>
                            <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-bold">${esc(formatMoney(transaction.total))}</td>
                            <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-bold">${esc(formatMoney(transaction.total))}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                Both columns equal the total because every entry balances — that is what makes it postable.
            </p>
        </div>`;
}

function voucherPayments(transaction) {
    // The modes and references behind the settlement lines — the part of a
    // voucher the ledger structurally cannot express, since a cheque and a
    // transfer both land on the Bank account.
    const split = (transaction.payments ?? []).map((payment) => `
        <tr class="border-t border-border">
            <td class="px-3 py-2 text-[0.8125rem]">
                <span class="font-medium">${esc(payment.mode_label ?? payment.mode)}</span>
                ${payment.reference
                    ? `<span class="mt-0.5 block font-mono text-[0.6875rem] text-muted-foreground">${esc(payment.reference)}</span>`
                    : ''}
            </td>
            <td class="px-3 py-2 text-right font-mono text-[0.8125rem] font-medium">${esc(formatMoney(payment.amount))}</td>
        </tr>`).join('');

    return `
        <div>
            ${sectionTitle('Settled on this document')}
            <div class="rounded-[10px] bg-secondary/40 px-4 py-3">${paidProgress(transaction)}</div>
        </div>

        ${split ? `
            <div>
                ${sectionTitle('How the money moved')}
                <div class="overflow-hidden rounded-[10px] border border-border">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-secondary/40">
                                <th class="table-head">Tender</th>
                                <th class="table-head text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>${split}</tbody>
                    </table>
                </div>
            </div>`
        : `
            <p class="rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5 text-[0.8125rem] text-secondary-foreground">
                Nothing was taken on this document.
            </p>`}

        <p class="text-xs text-muted-foreground">
            ${esc(PAID_NOTE)}
        </p>`;
}

function voucherInventory(transaction) {
    const movements = (transaction.movements ?? []).map((movement) => {
        const inward = !String(movement.quantity ?? '0').startsWith('-');

        return `
            <div class="rounded-[10px] border border-border p-3">
                <div class="flex items-start justify-between gap-3">
                    <span class="text-[0.8125rem] font-medium text-foreground">
                        ${esc(movement.memo ?? movement.type_label)}
                    </span>
                    <span class="badge ${inward ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}">
                        ${inward ? 'In' : 'Out'}
                    </span>
                </div>
                <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[0.8125rem] text-muted-foreground">
                    <span>Qty <span class="font-mono text-foreground">${esc(movement.quantity)}</span></span>
                    <span>At <span class="font-mono text-foreground">${esc(formatMoney(movement.unit_cost))}</span></span>
                    <span>Value <span class="font-mono text-foreground">${esc(formatMoney(movement.value))}</span></span>
                </div>
            </div>`;
    }).join('');

    return `
        <div class="space-y-2">
            ${sectionTitle('What moved on the shelf')}
            ${movements}
            <p class="pt-1 text-xs text-muted-foreground">
                Valued at the weighted average at the moment this was posted, which is why a draft has no
                movements at all — there is no such moment yet.
            </p>
        </div>`;
}

/**
 * What happened to this transaction, and when.
 *
 * Built from the transaction's own fields rather than from an audit trail,
 * because there deliberately is not one for transactions: nothing can change a
 * posted figure, so `created_by` and `posted_at` are the whole history. See the
 * note on App\Enums\AuditResource.
 */
function voucherTimeline(transaction) {
    const events = [
        {
            at: transaction.created_at,
            label: transaction.is_draft ? 'Draft started' : 'Entered',
            detail: transaction.created_by ? `by ${transaction.created_by}` : null,
            tone: 'bg-sky-100 text-sky-700',
        },
        transaction.updated_at && transaction.updated_at !== transaction.created_at
            ? {
                at: transaction.updated_at,
                label: 'Last edited',
                detail: null,
                tone: 'bg-secondary text-muted-foreground',
            }
            : null,
        transaction.posted_at
            ? {
                at: transaction.posted_at,
                label: 'Posted to the ledger',
                detail: `${formatMoney(transaction.total)} across ${transaction.line_count ?? 0} lines`,
                tone: 'bg-emerald-100 text-emerald-700',
            }
            : null,
        transaction.reversal_id
            ? {
                at: null,
                label: 'Reversed',
                detail: `by transaction #${transaction.reversal_id}`,
                tone: 'bg-amber-100 text-amber-800',
            }
            : null,
    ].filter(Boolean);

    return `
        <div>
            ${sectionTitle('Activity')}
            <ol class="space-y-3">
                ${events.map((event) => `
                    <li class="flex gap-3">
                        <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full ${event.tone}">
                            <span class="size-1.5 rounded-full bg-current"></span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.8125rem] font-medium text-foreground">${esc(event.label)}</span>
                            <span class="block text-[0.6875rem] text-muted-foreground">
                                ${[event.at ? formatDate(event.at) : null, event.detail]
                                    .filter(Boolean).map(esc).join(' · ')}
                            </span>
                        </span>
                    </li>`).join('')}
            </ol>
        </div>`;
}

const VOUCHER_SECTIONS = {
    summary: voucherSummary,
    ledger: voucherLedger,
    payments: voucherPayments,
    inventory: voucherInventory,
    timeline: voucherTimeline,
};

/** The transaction currently open in the drawer, so a sub-tab click can redraw. */
let openVoucher = null;

function renderVoucherSection(id) {
    if (!openVoucher) return;

    $$('#voucher-tabs [data-section]').forEach((button) => {
        button.setAttribute('aria-selected', button.dataset.section === id ? 'true' : 'false');
    });

    $('#voucher-body').innerHTML = `<div class="space-y-5">${VOUCHER_SECTIONS[id](openVoucher)}</div>`;
}

function renderVoucher(transaction) {
    openVoucher = transaction;

    $('#voucher-drawer-kind').textContent = transaction.type_label;
    $('#voucher-drawer-title').textContent = `#${transaction.id}`;
    $('#voucher-drawer-status').innerHTML = `
        <span class="badge ${STATUS_BADGE[transaction.status] ?? 'bg-muted'}">${esc(transaction.status_label)}</span>`;

    const sections = voucherSections(transaction);

    $('#voucher-tabs').innerHTML = sections.map((section) => `
        <button type="button" class="tab" role="tab" data-section="${section.id}"
                aria-selected="${section.id === 'summary' ? 'true' : 'false'}">
            ${esc(section.label)}
        </button>`).join('');

    // What can still be done, which depends on the status: a draft is resumed,
    // posted or discarded; a posted entry can only be reversed. Nothing is shown
    // greyed out, because none of these becomes available later.
    const actions = ['<button type="button" class="btn btn-secondary" data-modal-close>Close</button>'];

    if (transaction.is_draft) {
        if (can('UPDATE', 'TRANSACTIONS')) {
            actions.push(`<button type="button" class="btn btn-secondary" data-edit="${transaction.id}">
                ${iconPencil}Resume
            </button>`);
            actions.push(`<button type="button" class="btn btn-primary" data-post="${transaction.id}">
                ${iconCheck}Post entry
            </button>`);
        }

        if (can('DELETE', 'TRANSACTIONS')) {
            actions.push(`<button type="button" class="btn btn-ghost text-rose-600" data-discard="${transaction.id}">
                ${iconTrash}Discard
            </button>`);
        }
    } else if (transaction.status === 'posted' && can('WRITE', 'TRANSACTIONS')) {
        actions.push(`<button type="button" class="btn btn-secondary" data-reverse="${transaction.id}">
            ${iconReverse}Reverse
        </button>`);
    }

    $('#voucher-actions').innerHTML = actions.join('');

    renderVoucherSection('summary');
    showModal('#voucher-drawer');
}

/* -------------------------------------------------------------------------
 | Row actions
 | ---------------------------------------------------------------------- */

/**
 * Redraw after something changed the books.
 *
 * The badges are refetched as well as the rows, because posting a draft moves it
 * between two tabs — leaving "Drafts 5" over a list of four is the sort of stale
 * figure somebody plans around.
 */
function refresh() {
    hideModal('#voucher-drawer');
    openVoucher = null;

    return Promise.all([loadTransactions(), loadCounts()]);
}

async function postDraft(id) {
    const confirmed = await confirmAction({
        title: 'Post this entry',
        body: 'Posting writes the lines to the ledger. A posted entry can never be edited or deleted — '
            + 'a mistake is corrected with a reversing entry, which keeps both on the record.',
        confirmLabel: 'Post entry',
        tone: 'primary',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/transactions/${id}/post`, { method: 'POST' });
        toast('Entry posted.');
        refresh();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function discardDraft(id) {
    const confirmed = await confirmAction({
        title: 'Discard this draft',
        body: 'Nothing was ever written to the ledger, so there is nothing to reverse. The draft is deleted.',
        confirmLabel: 'Discard draft',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/transactions/${id}`, { method: 'DELETE' });
        toast('Draft discarded.');
        refresh();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function reverse(id) {
    const confirmed = await confirmAction({
        title: 'Reverse this entry',
        body: 'A new transaction is posted with every line on the opposite side, cancelling this one. '
            + 'Both stay visible — the original is not deleted or changed.',
        confirmLabel: 'Post reversing entry',
        tone: 'primary',
    });

    if (!confirmed) return;

    try {
        await auth.call(`/transactions/${id}/reverse`, { method: 'POST', body: {} });
        toast('Reversing entry posted.');
        refresh();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initJournal() {
    paintTabs();

    // The rows are awaited and the badges are not: the list is what the page is
    // for, and holding it back for a count nobody has looked at yet would make
    // the slower of the two requests decide when the page appears.
    await loadTransactions();
    loadCounts();

    $('#new-journal')?.addEventListener('click', () => openForm());
    $('#new-receipt')?.addEventListener('click', () => openSettlementForm('receipt'));
    $('#new-payment')?.addEventListener('click', () => openSettlementForm('payment'));

    $('#journal-form').addEventListener('submit', (event) => {
        event.preventDefault();
        submit({ post: true });
    });

    $('#save-draft').addEventListener('click', () => submit({ post: false }));

    $('#settlement-form').addEventListener('submit', (event) => {
        event.preventDefault();
        submitSettlement({ post: true });
    });

    $('#save-settlement-draft').addEventListener('click', () => submitSettlement({ post: false }));

    $('#add-payment-row').addEventListener('click', () => {
        $('#settlement-rows').insertAdjacentHTML('beforeend', settlementRow());
        refreshPaymentRows();
    });

    $('#settlement-rows').addEventListener('input', refreshPaymentRows);
    $('#settlement-rows').addEventListener('change', refreshPaymentRows);

    $('#settlement-rows').addEventListener('click', (event) => {
        if (!event.target.closest('[data-remove-payment]')) return;

        const rows = $$('[data-payment]', $('#settlement-rows'));

        // Never below one: a settlement with no tender has no amount at all, and
        // an empty grid gives the user nothing to type into.
        if (rows.length > 1) {
            event.target.closest('[data-payment]').remove();
            refreshPaymentRows();
        }
    });

    $('#settlement-party').addEventListener('change', () => {
        const slot = $('[data-error-for="party_id"]', $('#settlement-form'));
        slot.textContent = '';
        slot.classList.add('hidden');
    });

    $('#add-line').addEventListener('click', () => {
        $('#journal-lines').insertAdjacentHTML('beforeend', lineRow());
    });

    $('#journal-lines').addEventListener('input', refreshTotals);

    $('#journal-lines').addEventListener('click', (event) => {
        if (!event.target.closest('[data-remove-line]')) return;

        const rows = $$('[data-line]', $('#journal-lines'));

        // Never below two: a journal with one line cannot balance, and an empty
        // grid gives the user nothing to type into.
        if (rows.length > 2) {
            event.target.closest('[data-line]').remove();
            refreshTotals();
        }
    });

    const reload = () => {
        state.page = 1;
        paintTabs();
        loadTransactions();
    };

    $('#txn-tabs').addEventListener('click', (event) => {
        const button = event.target.closest('[data-tab]');

        if (!button || button.dataset.tab === state.tab) return;

        state.tab = button.dataset.tab;

        // A type override is dropped on the way out, not carried across. It was
        // chosen against the tab that was open, and silently reapplying it to the
        // next one would show a filtered list to somebody who thinks they have
        // just opened a fresh tab.
        state.type = '';
        $('#filter-type').value = '';

        reload();
    });

    // Sub-tabs inside the drawer. The transaction is already in hand, so
    // switching section is a redraw rather than a request.
    $('#voucher-tabs').addEventListener('click', (event) => {
        const button = event.target.closest('[data-section]');

        if (button) renderVoucherSection(button.dataset.section);
    });

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        reload();
    }, 350));

    $('#filter-type').addEventListener('change', (event) => {
        state.type = event.target.value;
        reload();
    });

    $('[data-override-clear]').addEventListener('click', () => {
        state.type = '';
        $('#filter-type').value = '';
        reload();
    });

    $('#filter-status').addEventListener('change', (event) => {
        state.status = event.target.value;
        reload();
    });

    $('#filter-from').addEventListener('change', (event) => {
        state.from = event.target.value;
        reload();
    });

    $('#filter-to').addEventListener('change', (event) => {
        state.to = event.target.value;
        reload();
    });

    $('#page-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            loadTransactions();
        }
    });

    $('#page-next').addEventListener('click', () => {
        state.page += 1;
        loadTransactions();
    });

    /*
    | One handler for the row actions, bound to the list and to the drawer's own
    | footer. The same five things can be asked for in both places, and a second
    | copy of the wiring is a second place for "post" to stop refreshing.
    */
    const handleAction = async (event) => {
        // The ⋮ has its own handler below. Without this the same click would
        // also count as a click on the row it sits in, and opening the menu
        // would open the drawer behind it.
        if (event.target.closest('[data-menu-toggle]')) return;

        const open = event.target.closest('[data-open]');
        const edit = event.target.closest('[data-edit]');
        const post = event.target.closest('[data-post]');
        const discard = event.target.closest('[data-discard]');
        const undo = event.target.closest('[data-reverse]');

        // A click on a menu item is not also a click on the row behind it.
        if (edit || post || discard || undo) event.stopPropagation();

        closeMenus();

        try {
            if (open && !(edit || post || discard || undo)) {
                const { data } = await auth.call(`/transactions/${open.dataset.open}`);
                renderVoucher(data);
            }

            if (edit) {
                const { data } = await auth.call(`/transactions/${edit.dataset.edit}`);

                hideModal('#voucher-drawer');

                // Each type is edited in the form that produced it. A payment
                // draft opened in the double-entry grid would ask the user to
                // choose accounts its template already decides — and the server
                // refuses `lines` for it, so there would be nothing to save.
                if (SETTLEMENT[data.type]) {
                    openSettlementForm(data.type, data);
                } else if (data.type === 'journal') {
                    openForm(data);
                } else {
                    // A bill, a stock adjustment or an opening balance. Each is
                    // composed from a payload this page has no form for, and the
                    // server refuses raw lines for all three — so offering the
                    // journal grid would be offering something that cannot save.
                    toast(`A ${data.type_label.toLowerCase()} draft is resumed on the screen that created it.`, 'info');
                }
            }
        } catch (error) {
            toast(error.message, 'error');
        }

        if (post) postDraft(post.dataset.post);
        if (discard) discardDraft(discard.dataset.discard);
        if (undo) reverse(undo.dataset.reverse);
    };

    $('#journal-rows').addEventListener('click', handleAction);
    $('#voucher-actions').addEventListener('click', handleAction);

    // A row is a button, so it answers to the keyboard like one.
    $('#journal-rows').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (!event.target.matches('[data-open]')) return;

        event.preventDefault();
        handleAction(event);
    });

    // The ⋮ menus. One open at a time, and any click outside closes it.
    $('#journal-rows').addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-menu-toggle]');

        if (!toggle) return;

        event.stopPropagation();

        const panel = $('[data-menu-panel]', toggle.closest('[data-menu]'));
        const wasOpen = !panel.classList.contains('hidden');

        closeMenus();

        panel.classList.toggle('hidden', wasOpen);
        toggle.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-menu]')) closeMenus();
    });
}

function closeMenus() {
    $$('#journal-rows [data-menu-panel]').forEach((panel) => panel.classList.add('hidden'));
    $$('#journal-rows [data-menu-toggle]').forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));
}
