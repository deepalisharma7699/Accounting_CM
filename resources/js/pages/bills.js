import auth from '../auth-client';
import { badge, lifecycleTone } from '../components/badge';
import { mountPaymentRows } from '../components/payment-rows';
import { can } from '../permissions';
import {
    $, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Expenses — what it costs the workshop to be open.
 *
 * ```
 * card → EXPENSE FORM              ← always lands here (§2A.5)
 *      → "Show list (18)"          → every expense ever recorded
 *      → row → drawer (level 2)    → reverse → confirm (level 3)
 * ```
 *
 * ## What this file stopped doing
 *
 * It was the Bills list: sales, purchases, expenses and both kinds of note in
 * one table, with a payment-status filter and the expense form behind a button.
 * Every part of that list now has a better home — Sales lists invoices and
 * credit notes, Purchase lists bills and debit notes, and Insights' Day Book
 * lists every posted document, including the journals neither of those shows.
 * Keeping a fourth copy here would be three screens answering one question, and
 * the second one is always the one nobody opens (§5.1).
 *
 * What was only ever here is **writing an expense**: `/transactions/expense` has
 * exactly one caller in the front end and it is this file. So that is the whole
 * module now, and the list behind "Show list" is expenses and nothing else.
 *
 * ## Why an expense is not a purchase, and why that is worth a card
 *
 * A purchase is bought to sell or to fit: it becomes inventory, and then cost of
 * goods when it leaves. An expense is what it costs to be open. Keeping the two
 * apart is the only reason a P&L can separate gross margin from overheads —
 * which is the only reason either figure is worth having. With no way to enter
 * one, the margin was reported against overheads of nil and the cash position
 * drifted from the tin.
 *
 * ## The client reference is per document, not per attempt
 *
 * Minted when the form is cleared for the next entry and reused on every retry
 * of the same expense — M17. Without it a request that timed out after the
 * server had already posted would be re-sent by an operator who saw no
 * confirmation, and the electricity bill would be in the books twice. The API
 * has always accepted the field; this form never sent it.
 */

const PAGE_SIZE = 25;

const state = {
    search: '',
    account: '',
    status: '',
    from: '',
    to: '',
    page: 1,
    total: null,

    /** The workshop's own expense accounts, for the form and the filter. */
    accounts: [],
    modes: [],

    /** This document's name for itself, until it is posted. */
    clientRef: null,
};

let root = null;
let formRoot = null;
let listRoot = null;
let form = null;
let payments = null;
let workspace = null;

/*
| Each surface's own node, held from mount.
|
| §2A.2 keeps exactly one of the form and the list attached, so for half the
| module's life the other is not a descendant of `document` at all and every
| lookup into it comes back null. That is not a rare state — §2A.8 keeps the
| clerk on the *form* after a post, which is precisely when the list wants
| bringing up to date. Querying a node works whether or not it is in the
| document; querying `document` for it does not.
*/
const inForm = (selector) => $(selector, formRoot);
const inList = (selector) => $(selector, listRoot);

/** The drawer, which lives outside both surfaces and is always attached. */
const el = (selector) => $(selector, root);

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);

    /*
    | Expenses and nothing else, asked for by name rather than filtered after
    | the fact — so the page count agrees with the rows on it. There is
    | deliberately no kind filter above the table: a list with one kind on it
    | does not need a control for choosing which.
    */
    params.append('types[]', 'expense');

    // "Everything that touched this account", which for an expense is the head
    // it was booked to. See the note in the Blade for why this is a filter
    // rather than a column.
    if (state.account) params.set('account_id', state.account);

    if (state.status) params.set('status', state.status);
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    inList('[data-expense-body]').innerHTML = tableMessage(6, 'Loading…');

    try {
        const payload = await auth.call(`/transactions?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        state.total = null;

        inList('[data-expense-body]').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(6, 'Your account administers the platform rather than a single workshop, '
                + 'so it has no expenses of its own.')
            : tableMessage(6, error.message, 'error');
    }
}

/** Refetch from page one — what every filter change means. */
const refetch = debounce(async () => {
    state.page = 1;
    await load();
}, 250);

function render(rows, meta) {
    const body = inList('[data-expense-body]');

    body.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(6, 'Nothing here yet. Rent, electricity or a courier will appear the moment one '
            + 'is recorded.');

    const pagination = meta?.pagination ?? {};

    state.total = pagination.total ?? null;

    inList('[data-expense-summary]').textContent = pagination.total
        ? `${rows.length} of ${pagination.total}.`
        : '';

    inList('[data-page-prev]').disabled = (pagination.current_page ?? 1) <= 1;
    inList('[data-page-next]').disabled = !pagination.has_more;

    // §2A.4 — the count rides on the Show control, so the form says how much is
    // behind it without anybody having to switch.
    workspace?.refresh();
}

/**
 * How the money left, from the split the listing already carries.
 *
 * Deduplicated by mode rather than listed row by row: "two thousand cash and the
 * rest cash" is one answer to the question this column asks, and the amounts
 * behind it are on the document.
 */
function paidBy(row) {
    const modes = [...new Set((row.payments ?? []).map((split) => split.mode_label).filter(Boolean))];

    return modes.length ? modes.join(', ') : '—';
}

function renderRow(row) {
    // §2A.8 — an expense written while the list was detached carries the flash
    // with it, so the eye finds it the first time somebody does look. The
    // workspace spends the flag when the list reaches the screen, not here.
    const flash = workspace?.isNew(row.id) ? ' row-new' : '';

    // A document from before the numbering scheme has none and never will. A
    // bare dash said it had no identity at all, while its own drawer had been
    // calling it "#11" the whole time.
    const docLabel = row.doc_no ?? `#${row.id}`;

    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
            data-expense="${row.id}" tabindex="0" role="link"
            aria-label="Open ${esc(docLabel)}">

            <td class="table-cell w-40">
                <span class="block font-mono text-[0.8125rem] font-medium ${
                    row.doc_no ? 'text-foreground' : 'text-muted-foreground'
                }">
                    ${esc(docLabel)}
                </span>
                ${row.reverses_id
                    ? `<span class="text-xs text-muted-foreground">reverses #${esc(String(row.reverses_id))}</span>`
                    : ''}
            </td>

            <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>

            <td class="table-cell text-[0.8125rem]">
                ${row.notes
                    ? esc(row.notes)
                    : '<span class="text-muted-foreground">No note</span>'}
            </td>

            <td class="table-cell w-32 text-[0.8125rem] text-muted-foreground">${esc(paidBy(row))}</td>

            <td class="table-cell w-32 text-right font-mono text-[0.8125rem] font-semibold">
                ${esc(formatMoney(row.total))}
            </td>

            <td class="table-cell w-28">${badge(row.status_label, lifecycleTone(row.status))}</td>
        </tr>`;
}

/* -------------------------------------------------------------------------
 | One expense — level 2
 |
 | Read-only, plus the one act a posted document still permits. There is no
 | edit: a posted transaction is immutable, and an expense has no `revise` path
 | — that is for bills, whose replacement has to be re-priced and re-taxed
 | against a shelf that has moved since.
 | ---------------------------------------------------------------------- */

const drawer = {
    id: null,
    expense: null,
};

async function openDrawer(id) {
    drawer.id = id;
    drawer.expense = null;

    $('#expense-drawer-title', root).textContent = 'Loading…';
    el('[data-drawer-subtitle]').textContent = '';
    el('[data-drawer-status]').innerHTML = '';
    el('[data-drawer-actions]').innerHTML = '';
    el('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    showModal('#expense-drawer');

    await loadDocument();
}

async function loadDocument() {
    try {
        const { data } = await auth.call(`/transactions/${drawer.id}`);

        drawer.expense = data;

        paint();
    } catch (error) {
        el('[data-drawer-body]').innerHTML =
            `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

function paint() {
    const expense = drawer.expense;

    $('#expense-drawer-title', root).textContent = expense.doc_no ?? `Expense #${expense.id}`;

    el('[data-drawer-subtitle]').textContent = [
        formatDate(expense.date),
        expense.notes,
    ].filter(Boolean).join(' · ');

    el('[data-drawer-status]').innerHTML = badge(expense.status_label, lifecycleTone(expense.status));

    el('[data-drawer-body]').innerHTML = renderDocument(expense);

    paintActions();
}

/**
 * What the expense was, which for this document *is* its ledger entries.
 *
 * Shown outright rather than folded into a "what this did to the books"
 * disclosure, as a bill's are. An expense has no item lines: the account it was
 * booked to and the account the money came out of are the entire document, and
 * hiding them behind a summary would leave the drawer with nothing in it.
 */
function renderDocument(expense) {
    const lines = (expense.lines ?? []).map((line) => `
        <tr class="border-t border-border">
            <td class="px-2 py-2 text-[0.8125rem]">
                ${esc(line.account?.name ?? `Account ${line.account_id}`)}
                ${line.memo ? `<span class="block text-xs text-muted-foreground">${esc(line.memo)}</span>` : ''}
            </td>
            <td class="px-2 py-2 text-right font-mono text-[0.8125rem]">
                ${line.debit === '0.00' ? '' : esc(formatMoney(line.debit))}
            </td>
            <td class="px-2 py-2 text-right font-mono text-[0.8125rem]">
                ${line.credit === '0.00' ? '' : esc(formatMoney(line.credit))}
            </td>
        </tr>`).join('');

    // A reference is the whole point of recording a cheque or a transfer, so it
    // is shown where it can be read back against a bank statement.
    const references = (expense.payments ?? [])
        .filter((split) => split.reference)
        .map((split) => `${split.mode_label}: ${split.reference}`)
        .join(' · ');

    return `
        <div class="mb-4 flex items-baseline justify-between gap-4">
            <span class="text-[0.8125rem] text-muted-foreground">Total</span>
            <span class="font-mono text-lg font-bold text-foreground">${esc(formatMoney(expense.total))}</span>
        </div>

        ${lines ? `
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-border text-left text-xs uppercase tracking-wide
                               text-muted-foreground">
                        <th class="px-2 py-2 font-semibold">Account</th>
                        <th class="px-2 py-2 text-right font-semibold">Debit</th>
                        <th class="px-2 py-2 text-right font-semibold">Credit</th>
                    </tr>
                </thead>
                <tbody>${lines}</tbody>
            </table>` : ''}

        ${references
            ? `<p class="mt-4 text-[0.8125rem] text-muted-foreground">${esc(references)}</p>`
            : ''}

        ${renderLink(expense)}

        <p class="mt-4 text-xs text-muted-foreground">
            Recorded${expense.created_by ? ` by ${esc(expense.created_by)}` : ''}${
                expense.posted_at ? ` on ${esc(formatDate(expense.posted_at.slice(0, 10)))}` : ''
            }.
        </p>`;
}

/** Which way a reversal points. Neither end is derivable from the other. */
function renderLink(expense) {
    if (expense.reverses_id) {
        return `
            <p class="mt-4 rounded-[10px] border border-border bg-muted/40 px-3 py-2 text-[0.8125rem]
                      text-muted-foreground">
                This is the reversing entry for #${esc(String(expense.reverses_id))}.
            </p>`;
    }

    if (expense.reversal_id) {
        return `
            <p class="mt-4 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-[0.8125rem]
                      text-amber-900">
                Reversed by #${esc(String(expense.reversal_id))}. Both documents stay on the record.
            </p>`;
    }

    return '';
}

function paintActions() {
    const expense = drawer.expense;
    const buttons = [];

    /*
    | Only what this document can still have done to it. Offering an act that
    | would be refused teaches somebody the product is unreliable — and a
    | reversed expense is refused by the engine, not merely discouraged here.
    */
    if (expense.status === 'posted' && can('WRITE', 'TRANSACTIONS')) {
        buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-reverse>Reverse</button>');
    }

    el('[data-drawer-actions]').innerHTML = buttons.join('')
        + '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
}

async function reverseExpense() {
    const expense = drawer.expense;

    const ok = await confirmAction({
        title: `Reverse ${expense.doc_no ?? `#${expense.id}`}?`,
        body: 'A mirroring entry is posted: the cost comes back out of the expense account and the money '
            + 'goes back into whatever it was paid from. Both documents stay on the record — nothing is '
            + 'erased, which is what makes the correction auditable.',
        confirmLabel: 'Reverse it',
    });

    if (!ok) return;

    try {
        const response = await auth.call(`/transactions/${expense.id}/reverse`, { method: 'POST' });

        toast(response.message ?? 'Reversing entry posted.');

        await loadDocument();

        // Only where a list is actually held (§2A.7). Reversing from the drawer
        // means the list is on screen, but the guard costs nothing and keeps
        // this honest if that ever stops being true.
        if (workspace?.hasList()) await load();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Writing an expense
 | ---------------------------------------------------------------------- */

/**
 * The expense accounts and the payment modes.
 *
 * Narrowed server-side rather than fetched whole and filtered here: the chart
 * holds every account the workshop has, and this form is only ever about the
 * expense ones (§7.2).
 *
 * Settled rather than awaited together, because neither is fatal. A caller
 * without `READ:ACCOUNTS` still gets a working form that books to Misc Expense,
 * which is the template's own default and a real answer rather than a fallback.
 */
async function loadReference() {
    const [accounts, meta] = await Promise.allSettled([
        auth.call('/accounts?type=expense&is_active=1&sort=code&per_page=200'),
        auth.call('/transactions/meta'),
    ]);

    state.accounts = accounts.status === 'fulfilled' ? accounts.value.data : [];
    state.modes = meta.status === 'fulfilled' ? meta.value.data.payment_modes : [];

    const options = state.accounts
        .map((account) => `<option value="${account.id}">${esc(account.code)} · ${esc(account.name)}</option>`)
        .join('');

    inForm('#expense-account').innerHTML = `<option value="">Misc Expense</option>${options}`;
    inList('[data-filter-account]').innerHTML = `<option value="">Every expense account</option>${options}`;
}

/** The date, the account and the split — everything the next entry needs blank. */
function resetForm() {
    clearFormErrors(form);

    form.elements.account_id.value = '';
    form.elements.amount.value = '';
    form.elements.gst_amount.value = '';
    form.elements.notes.value = '';
    payments.reset();

    /*
    | The date is deliberately *not* cleared. Somebody working through a stack of
    | receipts is entering several from one day, and a form that reset it would
    | make them retype the same date every time — the same judgement the bill
    | document already makes about its own date field.
    */

    // A fresh document deserves a fresh reference, or the posted expense's
    // idempotency key would follow the next one in and the server would answer
    // with the first document instead of writing the second.
    state.clientRef = crypto.randomUUID();
}

/**
 * What the browser can answer without asking the server.
 *
 * Shape only, and never the whole of it (§6.1): that the account is an expense
 * account, that the split equals the receipt, and that the date is inside the
 * open books are all decided server-side, where they cannot be skipped.
 */
function validate() {
    const errors = {};
    const amount = form.elements.amount.value.trim();
    const gst = form.elements.gst_amount.value.trim();

    if (!form.elements.date.value) {
        errors.date = ['Give the date on the receipt.'];
    }

    if (!amount || !(Number(amount) > 0)) {
        errors.amount = ['An expense needs an amount greater than zero.'];
    }

    if (gst && !(Number(gst) >= 0)) {
        errors.gst_amount = ['Claimable GST is an amount, or empty where none is claimable.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function submit(event) {
    event.preventDefault();

    clearFormErrors(form);

    const errors = validate();

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Check the highlighted fields.' });

        return;
    }

    setSubmitting(form, true, 'Recording…');

    try {
        const response = await auth.call('/transactions/expense', {
            method: 'POST',
            body: {
                date: form.elements.date.value,
                notes: form.elements.notes.value.trim() || null,
                post: true,
                client_ref: state.clientRef,
                account_id: Number(form.elements.account_id.value) || null,
                amount: form.elements.amount.value.trim(),
                gst_amount: form.elements.gst_amount.value.trim() || null,
                payments: payments.value(),
            },
        });

        toast(response.message ?? 'Expense recorded.');

        /*
        | §2A.8 — a successful entry stays on the form, clears it for the next
        | one and returns focus to the first field that needs a new answer. A
        | clerk works through the morning's receipts several at a time, and being
        | thrown to a list after each would mean several trips back.
        */
        if (response.data?.id) workspace?.flagNew(response.data.id);

        resetForm();
        form.elements.account_id.focus();

        // Only where a list is actually held. §2A.7 is that it is fetched on the
        // first Show and not before, so somebody who only ever writes expenses
        // must not be made to pay for one by posting.
        if (workspace?.hasList()) refetch();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, 'Record the expense');
    }
}

/* -------------------------------------------------------------------------
 | Wiring
 | ---------------------------------------------------------------------- */

function bindFilters() {
    inList('[data-filter-search]').addEventListener('input', (event) => {
        state.search = event.target.value.trim();
        refetch();
    });

    ['account', 'status', 'from', 'to'].forEach((field) => {
        inList(`[data-filter-${field}]`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            refetch();
        });
    });

    inList('[data-clear-filters]').addEventListener('click', () => {
        Object.assign(state, { search: '', account: '', status: '', from: '', to: '', page: 1 });

        inList('[data-filter-search]').value = '';
        ['account', 'status', 'from', 'to'].forEach((hook) => {
            inList(`[data-filter-${hook}]`).value = '';
        });

        load();
    });

    const open = (event) => {
        const row = event.target.closest('[data-expense]');

        if (row) openDrawer(row.dataset.expense);
    };

    inList('[data-expense-body]').addEventListener('click', open);
    inList('[data-expense-body]').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') open(event);
    });

    inList('[data-page-prev]').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    inList('[data-page-next]').addEventListener('click', () => {
        if (!inList('[data-page-next]').disabled) {
            state.page += 1;
            load();
        }
    });
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initBills() {
    root = $('[data-ws-form]').closest('[data-module-root]');

    // Before `mountWorkspace`, which is what takes both surfaces out of the
    // document. After it, these lookups would find nothing.
    formRoot = $('[data-ws-form]', root);
    listRoot = $('[data-ws-list]', root);

    form = $('#expense-form', formRoot);

    form.elements.date.value = new Date().toISOString().slice(0, 10);
    state.clientRef = crypto.randomUUID();

    // Before the payment rows, which take the modes by value at mount: chips
    // built from an empty list stay empty, however often they are repainted.
    await loadReference();

    /*
    | Mounted once and reset between entries, rather than rebuilt per open as
    | the modal it replaced did. The rows are part of the form now, so they
    | survive the trip to the list and back with everything else on it (§2A.6).
    |
    | "On credit" is not offered, which is the component's default: an expense
    | *is* its split — take the money away and there is no event left.
    */
    payments = mountPaymentRows(inForm('[data-expense-payments]'), {
        modes: state.modes,
        outstanding: () => {
            const amount = Number(form.elements.amount.value) || 0;
            const gst = Number(form.elements.gst_amount.value) || 0;

            return (amount + gst).toFixed(2);
        },
        heading: 'Paid by',
    });

    form.addEventListener('submit', submit);

    bindFilters();

    el('[data-drawer-actions]').addEventListener('click', (event) => {
        if (event.target.closest('[data-drawer-reverse]')) reverseExpense();
    });

    workspace = mountWorkspace(root, {
        key: 'bills',
        title: 'Expenses',
        formSubtitle: 'What it costs the workshop to be open — rent, power, a courier, the tea.',
        listSubtitle: (count) => (count === null
            ? 'Every expense recorded, newest first.'
            : `${count} expense${count === 1 ? '' : 's'}, newest first.`),
        createLabel: 'Record an expense',
        count: () => state.total,
        canCreate: can('WRITE', 'TRANSACTIONS'),
        onShowList: load,

        /*
        | The rows are a copy of what has been posted, and an expense is not the
        | only thing that can post one: a reversal raised from this drawer, an
        | import, or anything else the engine writes under `/transactions`. See
        | `data-bus.js` — the announcement is made there so a new write site
        | cannot forget to make it.
        */
        refreshOn: ['transactions'],

        // §2A.8 — back on the form, the account is where the next entry starts.
        // The date is kept from the last one; see `resetForm`.
        onShowForm: () => form.elements.account_id.focus(),
    });
}
