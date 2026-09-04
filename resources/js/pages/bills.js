import auth from '../auth-client';
import { badge, formatQuantity, kindBadge, lifecycleTone } from '../components/badge';
import { mountPaymentRows } from '../components/payment-rows';
import { can } from '../permissions';
import { clearModuleParams, moduleParams } from '../shell';
import {
    $, $$, clearFormErrors, debounce, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

/**
 * The bills list — the brief's §23.
 *
 * ## What changed in M20, and why
 *
 * This file used to be the list *and* the form: a modal that preloaded two
 * hundred parties and two hundred items into `<select>`s before it could open,
 * with no search, no stock on the picker and no confirmation step. The form is
 * now `/bills/new` — a page, because a modal cannot host a search-first picker,
 * a running total and a keyboard flow without becoming a scroll trap (decision
 * D8) — and what is left here is a list that finally has something to list.
 *
 * The Total / Paid / Due / Status columns are the point. They were impossible
 * before M16: a receipt carried a `party_id` and nothing else, so the books
 * could say Rajesh Kumar owed ₹15,000 but not which of his three invoices it was
 * left on. Every one of those four figures is derived on read — nothing is
 * stored on the bill — so a reversed receipt or a corrected allocation moves the
 * status without anything having to remember to update it.
 *
 * The expense form stays, and stays separate. An expense is a different kind of
 * money — what it costs to be open rather than what was bought to sell — and
 * giving it a place on the bill counter would invite people to book the
 * electricity as a purchase, which is exactly the distinction a P&L needs kept.
 */

const PAGE_SIZE = 25;

/** The types this screen is about. A journal entry on a "Bills" page is a surprise. */
const KINDS = ['sale', 'purchase', 'expense', 'sales_return', 'purchase_return'];

const state = {
    search: '',
    type: '',
    status: '',
    payment: '',
    outstanding: false,
    from: '',
    to: '',
    page: 1,
    hasMore: false,

    expenseAccounts: [],
    paymentModes: [],
};

let expensePayments = null;

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);

    if (state.type) {
        params.set('type', state.type);
    } else {
        // Asked for by name rather than filtered after the fact, so the page
        // count agrees with the rows on it — the same reason the payment-status
        // filter is a server-side one.
        KINDS.forEach((kind) => params.append('types[]', kind));
    }

    if (state.status) params.set('status', state.status);
    if (state.payment) params.set('payment_status', state.payment);
    if (state.outstanding) params.set('outstanding', '1');
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    $('#bills-body').innerHTML = tableMessage(8, 'Loading…');

    try {
        const payload = await auth.call(`/transactions?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        $('#bills-body').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(8, 'Your account administers the platform rather than a single workshop, so it has no bills of its own.')
            : tableMessage(8, error.message, 'error');
    }
}

function render(rows, meta) {
    const body = $('#bills-body');

    body.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(8, 'Nothing here yet. A sale, a purchase or an expense will appear the moment one is recorded.');

    const pagination = meta?.pagination ?? {};

    state.hasMore = Boolean(pagination.has_more);

    $('#bills-summary').textContent = pagination.total
        ? `${rows.length} of ${pagination.total}.`
        : '';

    $('#page-prev').disabled = (pagination.current_page ?? 1) <= 1;
    $('#page-next').disabled = !state.hasMore;
}

/**
 * One row.
 *
 * `paid`, `due` and `payment_status` are absent for anything that is not a
 * posted bill — a draft, a reversed invoice, an expense. That is deliberate on
 * the server's side and honoured here: a dash says the question does not apply,
 * where a zero would say "nothing has been paid" and invite somebody to chase
 * it.
 */
function renderRow(row) {
    const settled = row.payment_status !== undefined;

    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60"
            data-bill="${row.id}" tabindex="0" role="link"
            aria-label="Open ${esc(row.doc_no ?? `bill ${row.id}`)}">

            <td class="table-cell w-44">
                <span class="block font-mono text-[0.8125rem] font-medium text-foreground">
                    ${esc(row.doc_no ?? '—')}
                </span>
                ${kindBadge(row.type, row.type_label)}
            </td>

            <td class="table-cell text-[0.8125rem]">
                ${esc(row.party?.name ?? '—')}
                ${row.notes ? `<span class="block text-xs text-muted-foreground">${esc(row.notes)}</span>` : ''}
            </td>

            <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>

            <td class="table-cell w-16 text-right font-mono text-[0.8125rem] text-muted-foreground">
                ${row.line_count === null ? '—' : esc(String(row.line_count))}
            </td>

            <td class="table-cell w-32 text-right font-mono text-[0.8125rem] font-semibold">
                ${esc(formatMoney(row.total))}
            </td>

            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">
                ${settled ? esc(formatMoney(row.paid)) : '<span class="text-muted-foreground">—</span>'}
            </td>

            <td class="table-cell w-28 text-right font-mono text-[0.8125rem] ${
                settled && row.payment_status !== 'paid' ? 'font-semibold text-rose-700' : ''
            }">
                ${settled ? esc(formatMoney(row.due)) : '<span class="text-muted-foreground">—</span>'}
            </td>

            <td class="table-cell w-32">
                ${settled
                    ? badge(row.payment_status_label, row.payment_status_tone,
                        { title: row.due_date ? `Due ${formatDate(row.due_date)}` : null })
                    : badge(row.status_label, lifecycleTone(row.status))}
            </td>
        </tr>`;
}

/* -------------------------------------------------------------------------
 | Reading one bill
 | ---------------------------------------------------------------------- */

async function openBill(id) {
    $('#bill-modal-title').textContent = `Bill #${id}`;
    $('#bill-modal-subtitle').textContent = '';
    $('#bill-modal-body').innerHTML = '<p class="px-5 py-6 text-sm text-muted-foreground">Loading…</p>';

    showModal('#bill-modal');

    try {
        const { data, meta } = await auth.call(`/transactions/${id}`);

        $('#bill-modal-title').textContent = `${data.type_label} ${data.doc_no ?? `#${data.id}`}`;
        $('#bill-modal-subtitle').textContent =
            `${formatDate(data.date)}${data.party ? ` · ${data.party.name}` : ''}${data.notes ? ` · ${data.notes}` : ''}`;

        $('#bill-modal-body').innerHTML = renderBill(data, meta ?? {});
    } catch (error) {
        $('#bill-modal-body').innerHTML =
            `<p class="px-5 py-6 text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

/** What is owed on this one, at the top where somebody looking for it will find it. */
function renderSettlement(bill) {
    if (bill.payment_status === undefined) return '';

    return `
        <div class="flex flex-wrap items-center gap-4 border-b border-border px-5 py-3 text-[0.8125rem]">
            <span>${badge(bill.payment_status_label, bill.payment_status_tone)}</span>
            <span class="text-muted-foreground">Paid <span class="font-mono text-foreground">${esc(formatMoney(bill.paid))}</span></span>
            <span class="text-muted-foreground">Due <span class="font-mono text-foreground">${esc(formatMoney(bill.due))}</span></span>
            ${bill.credited && bill.credited !== '0.00'
                ? `<span class="text-muted-foreground">Credited <span class="font-mono text-foreground">${esc(formatMoney(bill.credited))}</span></span>`
                : ''}
            ${bill.due_date ? `<span class="text-muted-foreground">Due by ${esc(formatDate(bill.due_date))}</span>` : ''}
        </div>`;
}

function renderBill(bill, meta) {
    const warnings = (meta.warnings ?? []).map((warning) => `
        <div class="surface mx-5 mt-4 border-amber-200 bg-amber-50/60 px-4 py-3 text-[0.8125rem] text-amber-900">
            ${esc(warning.message)}
        </div>`).join('');

    if (!bill.items?.length) {
        return renderSettlement(bill) + warnings + `
            <div class="px-5 py-6 text-sm text-muted-foreground">
                This document has no item lines — an expense is recorded as an amount and an account rather
                than as a list of things.
            </div>` + renderLedgerLines(bill);
    }

    const rows = bill.items.map((line) => `
        <tr class="border-t border-border">
            <td class="table-cell">
                <span class="font-medium">${esc(line.description ?? '')}</span>
                ${line.memo ? `<div class="text-[0.8125rem] text-muted-foreground">${esc(line.memo)}</div>` : ''}
            </td>
            <td class="table-cell w-24 text-right font-mono text-[0.8125rem]">
                ${esc(formatQuantity(line.quantity, line.unit_symbol))}
            </td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(line.unit_price))}</td>
            <td class="table-cell w-20 text-right font-mono text-[0.8125rem]">${esc(line.gst_rate)}%</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(line.taxable_value))}</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(line.tax_amount))}</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem] font-semibold">${esc(formatMoney(line.line_total))}</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem] ${line.below_cost ? 'font-semibold text-rose-700' : ''}">
                ${line.margin === null ? '<span class="text-muted-foreground">—</span>' : esc(formatMoney(line.margin))}
            </td>
        </tr>`).join('');

    const tax = meta.tax ?? {};
    const margin = meta.margin ?? null;

    return renderSettlement(bill) + warnings + `
        <table class="w-full min-w-[720px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide
                           text-muted-foreground">
                    <th class="px-4 py-3 font-semibold">Item</th>
                    <th class="px-4 py-3 text-right font-semibold">Qty</th>
                    <th class="px-4 py-3 text-right font-semibold">Rate</th>
                    <th class="px-4 py-3 text-right font-semibold">GST</th>
                    <th class="px-4 py-3 text-right font-semibold">Taxable</th>
                    <th class="px-4 py-3 text-right font-semibold">Tax</th>
                    <th class="px-4 py-3 text-right font-semibold">Total</th>
                    <th class="px-4 py-3 text-right font-semibold">Margin</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>

        <div class="grid gap-4 border-t border-border px-5 py-4 sm:grid-cols-2">
            <dl class="space-y-1 text-[0.8125rem]">
                <div class="flex justify-between"><dt class="text-muted-foreground">Taxable value</dt>
                    <dd class="font-mono">${esc(formatMoney(tax.taxable ?? '0.00'))}</dd></div>
                ${tax.inter_state
                    ? `<div class="flex justify-between"><dt class="text-muted-foreground">IGST</dt>
                           <dd class="font-mono">${esc(formatMoney(tax.igst ?? '0.00'))}</dd></div>`
                    : `<div class="flex justify-between"><dt class="text-muted-foreground">CGST</dt>
                           <dd class="font-mono">${esc(formatMoney(tax.cgst ?? '0.00'))}</dd></div>
                       <div class="flex justify-between"><dt class="text-muted-foreground">SGST</dt>
                           <dd class="font-mono">${esc(formatMoney(tax.sgst ?? '0.00'))}</dd></div>`}
                <div class="flex justify-between border-t border-border pt-1 font-semibold">
                    <dt>Total</dt><dd class="font-mono">${esc(formatMoney(bill.total))}</dd></div>
            </dl>

            ${margin ? `
                <dl class="space-y-1 text-[0.8125rem]">
                    <div class="flex justify-between"><dt class="text-muted-foreground">Revenue</dt>
                        <dd class="font-mono">${esc(formatMoney(margin.revenue))}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted-foreground">Cost of goods</dt>
                        <dd class="font-mono">${esc(formatMoney(margin.cost))}</dd></div>
                    <div class="flex justify-between border-t border-border pt-1 font-semibold">
                        <dt>Margin</dt>
                        <dd class="font-mono">${esc(formatMoney(margin.margin))} (${esc(margin.margin_percent)}%)</dd></div>
                </dl>` : ''}
        </div>

        ${renderLedgerLines(bill)}`;
}

function renderLedgerLines(bill) {
    if (!bill.lines?.length) return '';

    return `
        <details class="border-t border-border px-5 py-4">
            <summary class="cursor-pointer text-[0.8125rem] font-medium text-muted-foreground">
                What this did to the books — ${bill.lines.length} ledger lines
            </summary>
            <table class="mt-3 w-full border-collapse text-[0.8125rem]">
                ${bill.lines.map((line) => `
                    <tr class="border-t border-border">
                        <td class="px-2 py-1.5">${esc(line.account?.name ?? `Account ${line.account_id}`)}</td>
                        <td class="px-2 py-1.5 text-muted-foreground">${esc(line.memo ?? '')}</td>
                        <td class="px-2 py-1.5 text-right font-mono">${line.debit === '0.00' ? '' : esc(formatMoney(line.debit))}</td>
                        <td class="px-2 py-1.5 text-right font-mono">${line.credit === '0.00' ? '' : esc(formatMoney(line.credit))}</td>
                    </tr>`).join('')}
            </table>
        </details>`;
}

/* -------------------------------------------------------------------------
 | Writing an expense
 | ---------------------------------------------------------------------- */

async function loadExpenseReference() {
    const [accounts, meta] = await Promise.allSettled([
        auth.call('/accounts?per_page=200'),
        auth.call('/transactions/meta'),
    ]);

    state.expenseAccounts = accounts.status === 'fulfilled'
        ? accounts.value.data.filter((account) => account.type === 'expense' && account.is_active)
        : [];

    state.paymentModes = meta.status === 'fulfilled' ? meta.value.data.payment_modes : [];
}

function openExpenseForm() {
    const form = $('#expense-form');

    clearFormErrors(form);
    form.reset();
    form.elements.date.value = new Date().toISOString().slice(0, 10);

    form.elements.account_id.innerHTML = `<option value="">Misc Expense</option>${
        state.expenseAccounts
            .map((account) => `<option value="${account.id}">${esc(account.code)} · ${esc(account.name)}</option>`)
            .join('')
    }`;

    // The same payment rows the counter uses. An expense *is* its split — take
    // the money away and there is no event left — so the section is headed for
    // what it is rather than for what it might be.
    expensePayments = mountPaymentRows($('#expense-payments-host'), {
        modes: state.paymentModes,
        outstanding: () => {
            const amount = Number(form.elements.amount.value) || 0;
            const gst = Number(form.elements.gst_amount.value) || 0;

            return (amount + gst).toFixed(2);
        },
        heading: 'Paid by',
    });

    showModal('#expense-modal');
}

async function submitExpense(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);
    setSubmitting(form, true, 'Recording…');

    try {
        await auth.call('/transactions/expense', {
            method: 'POST',
            body: {
                date: form.elements.date.value,
                notes: form.elements.notes.value.trim() || null,
                post: true,
                account_id: Number(form.elements.account_id.value) || null,
                amount: form.elements.amount.value.trim(),
                gst_amount: form.elements.gst_amount.value.trim() || null,
                payments: expensePayments?.value() ?? [],
            },
        });

        hideModal('#expense-modal');
        toast('Expense recorded.');

        await load();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, 'Record the expense');
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initBills() {
    await loadExpenseReference();
    await load();

    const reload = () => {
        state.page = 1;
        load();
    };

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();
        reload();
    }, 300));

    ['type', 'status', 'from', 'to'].forEach((field) => {
        $(`#filter-${field}`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            reload();
        });
    });

    $('#filter-payment').addEventListener('change', (event) => {
        state.payment = event.target.value;
        reload();
    });

    $('#filter-outstanding').addEventListener('click', (event) => {
        state.outstanding = !state.outstanding;
        event.currentTarget.setAttribute('aria-pressed', String(state.outstanding));
        reload();
    });

    $('#clear-filters').addEventListener('click', () => {
        Object.assign(state, {
            search: '', type: '', status: '', payment: '', outstanding: false, from: '', to: '', page: 1,
        });

        $('#filter-search').value = '';
        ['type', 'status', 'payment', 'from', 'to'].forEach((field) => { $(`#filter-${field}`).value = ''; });
        $('#filter-outstanding').setAttribute('aria-pressed', 'false');

        load();
    });

    const open = (event) => {
        const row = event.target.closest('[data-bill]');

        if (row) openBill(row.dataset.bill);
    };

    $('#bills-body').addEventListener('click', open);
    $('#bills-body').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') open(event);
    });

    $('#page-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    $('#page-next').addEventListener('click', () => {
        if (state.hasMore) {
            state.page += 1;
            load();
        }
    });

    $('[data-new-expense]').addEventListener('click', openExpenseForm);
    $('#expense-form').addEventListener('submit', submitExpense);

    /*
    | `#bills?new=expense` and `#bills?payment=overdue` — the dashboard's tiles
    | and its attention list. Deep links rather than screens of their own, so
    | there is exactly one place an expense is written.
    |
    | The intent comes from the shell rather than from `location.search`: a
    | module's URL is a fragment of the dashboard's now. It is cleared once acted
    | on, so a refresh or a Back does not reopen a form somebody just closed.
    */
    const applyIntent = async (params) => {
        if (params.get('payment')) {
            state.payment = params.get('payment');
            $('#filter-payment').value = state.payment;
            await load();
        }

        if (params.get('outstanding') === '1') {
            state.outstanding = true;
            $('#filter-outstanding').setAttribute('aria-pressed', 'true');
            await load();
        }

        if (params.get('type')) {
            state.type = params.get('type');
            $('#filter-type').value = state.type;
            await load();
        }

        if (params.get('new') === 'expense' && can('WRITE', 'TRANSACTIONS')) openExpenseForm();

        clearModuleParams();
    };

    // Reopening an already-mounted module cannot run this function again, so the
    // shell announces a fresh intent instead.
    $('#bills-body').closest('[data-module-root]')
        ?.addEventListener('module:params', (event) => applyIntent(event.detail));

    await applyIntent(moduleParams());
}
