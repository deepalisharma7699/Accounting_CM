import auth from '../auth-client';
import {
    $, $$, clearFormErrors, esc, formatDate, formatMoney,
    hideModal, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';

/**
 * Sales, purchases and expenses.
 *
 * Three things drive the design.
 *
 * **The total is computed on the server and previewed here, never the other way
 * round.** The preview below is deliberately advisory: it applies the item's rate
 * to quantity × price, which is what the bill template does, but the figure that
 * is posted is the one the template produced. A client that computed the tax and
 * sent it would be a second implementation of the arithmetic that ends up on a
 * government return.
 *
 * **A sale and a purchase share one form**, because the payload is identical and
 * the direction is the route. The same choice the API makes, for the same reason:
 * two forms would mean two places for the same decision to drift.
 *
 * **An expense does not.** It is a different kind of money — what it costs to be
 * open rather than what was bought to sell — and giving it the same form would
 * invite people to book the electricity bill as a purchase, which is exactly the
 * distinction a P&L needs kept.
 */

const PAGE_SIZE = 25;

const KIND_BADGE = {
    sale: 'bg-emerald-100 text-emerald-800',
    purchase: 'bg-sky-100 text-sky-800',
    expense: 'bg-amber-100 text-amber-800',
};

const STATUS_BADGE = {
    draft: 'bg-muted text-muted-foreground',
    posted: 'bg-emerald-100 text-emerald-800',
    reversed: 'bg-rose-100 text-rose-800',
};

const state = {
    type: '',
    status: '',
    from: '',
    to: '',
    page: 1,
    hasMore: false,

    kind: 'sale',        // which direction the shared form is open for
    parties: [],
    variants: [],        // priced catalogue, for the line picker
    expenseAccounts: [],
    paymentModes: [],

    // The chooser's two answers. Cleared every time it opens: a half-made
    // choice from last time is not a default, it is a trap.
    chosenKind: null,
    chosenMethod: null,
};

/* -------------------------------------------------------------------------
 | Reference data
 | ---------------------------------------------------------------------- */

/**
 * Everything the forms need to offer a choice, fetched once.
 *
 * Each is allowed to fail on its own: a user without READ:PARTIES can still
 * read the list of bills, and a form they cannot open is not worth an error
 * banner over the page they can.
 */
async function loadReferenceData() {
    const [parties, variants, accounts, meta] = await Promise.allSettled([
        auth.call('/parties?per_page=200'),
        auth.call('/items?per_page=200&with_variants=1'),
        auth.call('/accounts?per_page=200'),
        auth.call('/transactions/meta'),
    ]);

    state.parties = parties.status === 'fulfilled' ? parties.value.data : [];

    state.variants = variants.status === 'fulfilled'
        ? variants.value.data.flatMap((item) => (item.variants ?? []).map((variant) => ({
            ...variant,
            item_name: item.name,
            item_id: item.id,
            tracks_stock: item.tracks_stock,
            gst_rate: item.gst_rate,
            unit: item.base_uom_symbol,
        })))
        : [];

    state.expenseAccounts = accounts.status === 'fulfilled'
        ? accounts.value.data.filter((account) => account.type === 'expense' && account.is_active)
        : [];

    state.paymentModes = meta.status === 'fulfilled' ? meta.value.data.payment_modes : [];
}

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams();

    // The API filters one type at a time; with no filter the page asks for the
    // three kinds it is about rather than every transaction in the workshop,
    // because a journal entry on a "Bills" screen is a surprise.
    if (state.type) params.set('type', state.type);
    if (state.status) params.set('status', state.status);
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    $('#bills-body').innerHTML = tableMessage(6, 'Loading…');

    try {
        const payload = await auth.call(`/transactions?${query()}`);

        const rows = state.type
            ? payload.data
            : payload.data.filter((row) => ['sale', 'purchase', 'expense'].includes(row.type));

        render(rows, payload.meta);
    } catch (error) {
        $('#bills-body').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(6, 'Your account administers the platform rather than a single workshop, so it has no bills of its own.')
            : tableMessage(6, error.message, 'error');
    }
}

function render(rows, meta) {
    const body = $('#bills-body');

    body.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(6, 'Nothing here yet. A sale, a purchase or an expense will appear the moment one is recorded.');

    const pagination = meta?.pagination ?? {};

    state.hasMore = Boolean(pagination.has_more);

    $('#bills-summary').textContent = rows.length
        ? `${rows.length} on this page.`
        : '';

    $('#page-prev').disabled = (pagination.current_page ?? 1) <= 1;
    $('#page-next').disabled = !state.hasMore;
}

function renderRow(row) {
    return `
        <tr class="border-t border-border cursor-pointer transition hover:bg-secondary/60"
            data-bill="${row.id}" tabindex="0" role="link"
            aria-label="Open bill ${row.id}">
            <td class="table-cell w-32 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>
            <td class="table-cell w-32">
                <span class="badge ${KIND_BADGE[row.type] ?? 'bg-muted'}">${esc(row.type_label)}</span>
            </td>
            <td class="table-cell text-[0.8125rem]">${esc(row.party?.name ?? '—')}</td>
            <td class="table-cell text-[0.8125rem] text-muted-foreground">${esc(row.notes ?? '')}</td>
            <td class="table-cell w-36 text-right font-mono text-[0.8125rem] font-semibold">
                ${esc(formatMoney(row.total))}
            </td>
            <td class="table-cell w-28">
                <span class="badge ${STATUS_BADGE[row.status] ?? 'bg-muted'}">${esc(row.status_label)}</span>
            </td>
        </tr>`;
}

/* -------------------------------------------------------------------------
 | Reading one bill
 | ---------------------------------------------------------------------- */

async function openBill(id) {
    $('#bill-modal-title').textContent = `Bill #${id}`;
    $('#bill-modal-subtitle').textContent = '';
    $('#bill-modal-body').innerHTML = `<p class="px-5 py-6 text-sm text-muted-foreground">Loading…</p>`;

    showModal('#bill-modal');

    try {
        const { data, meta } = await auth.call(`/transactions/${id}`);

        $('#bill-modal-title').textContent = `${data.type_label} #${data.id}`;
        $('#bill-modal-subtitle').textContent =
            `${formatDate(data.date)}${data.party ? ` · ${data.party.name}` : ''}${data.notes ? ` · ${data.notes}` : ''}`;

        $('#bill-modal-body').innerHTML = renderBill(data, meta ?? {});
    } catch (error) {
        $('#bill-modal-body').innerHTML =
            `<p class="px-5 py-6 text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

function renderBill(bill, meta) {
    const warnings = (meta.warnings ?? []).map((warning) => `
        <div class="surface mx-5 mt-4 border-amber-200 bg-amber-50/60 px-4 py-3 text-[0.8125rem] text-amber-900">
            ${esc(warning.message)}
        </div>`).join('');

    if (!bill.items?.length) {
        return warnings + `
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
                ${esc(trimQuantity(line.quantity))} <span class="text-xs text-muted-foreground">${esc(line.unit_symbol ?? '')}</span>
            </td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(line.unit_price))}</td>
            <td class="table-cell w-24 text-right font-mono text-[0.8125rem]">${esc(line.gst_rate)}%</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(line.taxable_value))}</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(formatMoney(line.tax_amount))}</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem] font-semibold">${esc(formatMoney(line.line_total))}</td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem] ${line.below_cost ? 'text-rose-700 font-semibold' : ''}">
                ${line.margin === null ? '<span class="text-muted-foreground">—</span>' : esc(formatMoney(line.margin))}
            </td>
        </tr>`).join('');

    const tax = meta.tax ?? {};
    const margin = meta.margin ?? null;

    return warnings + `
        <table class="w-full min-w-[720px] border-collapse">
            <thead>
                <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
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

function trimQuantity(value) {
    const amount = String(value ?? '0');

    return amount.includes('.') ? amount.replace(/\.?0+$/, '') : amount;
}

/* -------------------------------------------------------------------------
 | Choosing what to write
 | ---------------------------------------------------------------------- */

/**
 * The chooser behind both "New" buttons.
 *
 * It asks two questions and then opens one of the forms below. It posts
 * nothing itself, which is the point: there is still exactly one place a bill
 * is written, and this only decides which one to open.
 *
 * The kind it collects is the API's own route segment — `sale`, `purchase`,
 * `expense` — so nothing translates the answer on the way to the form.
 */

const KIND_LABEL = {
    sale: 'a sale',
    purchase: 'a purchase bill',
    expense: 'an expense',
};

function openChooser() {
    state.chosenKind = null;
    state.chosenMethod = null;

    // Both sets, not just the step being shown: a card left selected behind the
    // Back button would come back pressed when somebody returns to it.
    $$('#new-transaction-modal .selection-card').forEach((card) => markCard(card, false));

    $('#new-transaction-modal [data-choose-method]').disabled = true;
    $('#new-transaction-modal [data-start]').disabled = true;

    showStep('kind');
    showModal('#new-transaction-modal');
}

function showStep(step) {
    const asking = step === 'kind';

    $('#new-transaction-modal [data-step=kind]').classList.toggle('hidden', !asking);
    $('#new-transaction-modal [data-step=method]').classList.toggle('hidden', asking);

    $('#new-transaction-title').textContent = asking
        ? 'Create a new transaction'
        : 'Choose how to enter it';

    $('#new-transaction-subtitle').textContent = asking
        ? 'What kind of transaction is this?'
        : `For ${KIND_LABEL[state.chosenKind] ?? 'this'} — how would you like to proceed?`;
}

function markCard(card, selected) {
    card.setAttribute('aria-pressed', selected ? 'true' : 'false');
    $('[data-check]', card)?.classList.toggle('hidden', !selected);
}

/** One card pressed at a time, within its own step. */
function selectCard(card, attribute) {
    const group = card.closest('[data-step]');

    $$(`[data-${attribute}]`, group).forEach((sibling) => markCard(sibling, sibling === card));
}

/**
 * Hand off to the form the two answers picked.
 *
 * Only manual entry has somewhere to go today — reading a photographed invoice
 * and taking dictation are M15's, and their cards are disabled rather than
 * shown live, so this never runs for them. The guard is here anyway: a method
 * that gained a card before it gained a destination should close quietly
 * rather than open the wrong form.
 */
function startChosen() {
    if (state.chosenMethod !== 'manual') return;

    hideModal('#new-transaction-modal');

    if (state.chosenKind === 'expense') {
        openExpenseForm();
    } else {
        openBillForm(state.chosenKind);
    }
}

/* -------------------------------------------------------------------------
 | Writing a bill
 | ---------------------------------------------------------------------- */

let lineSeq = 0;

function partyOptions(role) {
    return state.parties
        .filter((party) => party.is_active && (party.roles ?? []).includes(role))
        .map((party) => `<option value="${party.id}">${esc(party.name)}</option>`)
        .join('');
}

function variantOptions() {
    return state.variants
        .filter((variant) => variant.is_active)
        .map((variant) => `
            <option value="${variant.id}" data-price="${esc(variant.sell_price ?? '')}">
                ${esc(variant.item_name)} · ${esc(variant.display_label)}
            </option>`)
        .join('');
}

function billLine() {
    const id = ++lineSeq;

    return `
        <div class="grid gap-2 rounded-[10px] border border-border p-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]" data-line="${id}">
            <label class="field">
                <span class="field-label">Item</span>
                <select name="variant_id" class="field-input" required>
                    <option value="">Choose…</option>
                    ${variantOptions()}
                </select>
            </label>

            <label class="field">
                <span class="field-label">Quantity</span>
                <input type="text" name="quantity" class="field-input font-mono" inputmode="decimal" value="1" required>
            </label>

            <label class="field">
                <span class="field-label">Rate</span>
                <input type="text" name="unit_price" class="field-input font-mono" inputmode="decimal" required>
            </label>

            <label class="field">
                <span class="field-label">Discount</span>
                <input type="text" name="discount" class="field-input font-mono" inputmode="decimal">
            </label>

            <button type="button" class="btn btn-ghost btn-icon self-end" data-remove-line
                    aria-label="Remove this line">×</button>
        </div>`;
}

function paymentLine(host) {
    const modes = state.paymentModes
        .map((mode) => `<option value="${mode.value}">${esc(mode.label)}</option>`)
        .join('');

    host.insertAdjacentHTML('beforeend', `
        <div class="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]" data-payment>
            <label class="field">
                <span class="field-label">How</span>
                <select name="mode" class="field-input">${modes}</select>
            </label>

            <label class="field">
                <span class="field-label">Amount</span>
                <input type="text" name="amount" class="field-input font-mono" inputmode="decimal">
            </label>

            <label class="field">
                <span class="field-label">Reference</span>
                <input type="text" name="reference" class="field-input" maxlength="100">
            </label>

            <button type="button" class="btn btn-ghost btn-icon self-end" data-remove-payment
                    aria-label="Remove this payment">×</button>
        </div>`);
}

/**
 * Open the form the URL asked for, if it asked for one.
 *
 * Reached from the Customers and Vendors screens. The query string is dropped
 * afterwards so a refresh — or a back button — does not reopen a form somebody
 * has just closed.
 */
function openRequestedForm() {
    const params = new URLSearchParams(window.location.search);
    const kind = params.get('new');

    if (kind !== 'sale' && kind !== 'purchase') return;

    const party = params.get('party');

    openBillForm(kind, { partyId: /^\d+$/.test(party ?? '') ? party : null });

    window.history.replaceState({}, '', window.location.pathname);
}

function openBillForm(kind, { partyId = null } = {}) {
    const form = $('#bill-form');

    state.kind = kind;
    lineSeq = 0;

    clearFormErrors(form);
    form.reset();
    form.elements.date.value = new Date().toISOString().slice(0, 10);

    const selling = kind === 'sale';

    $('#bill-form-title').textContent = selling ? 'New sale' : 'New purchase';
    $('#bill-form-hint').textContent = selling
        ? 'Tax follows each item’s HSN rate and the two state codes. Cost of goods is the weighted average at the moment you post.'
        : 'Stock arrives at the price before tax — the claimable GST is not part of what it cost.';
    $('#bill-party-label').textContent = selling ? 'Customer' : 'Vendor';
    $('#bill-payment-heading').textContent = selling ? 'Collected now' : 'Paid now';

    form.elements.party_id.innerHTML =
        `<option value="">Choose…</option>${partyOptions(selling ? 'customer' : 'vendor')}`;

    // Arrived from a counterparty screen with somebody already in mind. Applied
    // only when that party is actually in the list — an archived one, or one who
    // does not hold the role this bill needs, has no option to select and would
    // leave the field silently blank either way.
    if (partyId !== null) {
        form.elements.party_id.value = String(partyId);
    }

    $('#bill-lines').innerHTML = billLine();
    $('#bill-payments').innerHTML = '';
    renderTotals();

    showModal('#bill-form-modal');
}

/**
 * A running total, and it says plainly that it is an estimate.
 *
 * The figure that gets posted is the template's. This is here so somebody typing
 * a bill can see roughly where it lands — not so the client can decide what the
 * tax is.
 */
function renderTotals() {
    let taxable = 0;
    let tax = 0;

    $$('#bill-lines [data-line]').forEach((line) => {
        const variant = state.variants.find(
            (item) => String(item.id) === $('[name=variant_id]', line).value,
        );

        if (!variant) return;

        const quantity = Number($('[name=quantity]', line).value) || 0;
        const price = Number($('[name=unit_price]', line).value) || 0;
        const discount = Number($('[name=discount]', line).value) || 0;
        const base = Math.max(quantity * price - discount, 0);

        taxable += base;
        tax += base * (Number(variant.gst_rate) || 0) / 100;
    });

    $('#bill-totals').innerHTML = `
        <div class="flex justify-between"><span class="text-muted-foreground">Taxable value</span>
            <span class="font-mono">${esc(formatMoney(taxable.toFixed(2)))}</span></div>
        <div class="flex justify-between"><span class="text-muted-foreground">GST</span>
            <span class="font-mono">${esc(formatMoney(tax.toFixed(2)))}</span></div>
        <div class="mt-1 flex justify-between border-t border-border pt-1 font-semibold">
            <span>Estimated total</span>
            <span class="font-mono">${esc(formatMoney((taxable + tax).toFixed(2)))}</span></div>
        <p class="mt-2 text-xs text-muted-foreground">
            An estimate. The figures that are posted are worked out on the server from the item’s rate and
            the two state codes, so the invoice and the books can never differ.
        </p>`;
}

function collect(selector, fields) {
    return $$(selector).map((row) => {
        const value = {};

        fields.forEach((field) => {
            const input = $(`[name=${field}]`, row);
            value[field] = input ? input.value.trim() : '';
        });

        return value;
    });
}

async function submitBill(event) {
    event.preventDefault();

    const form = event.target;
    const post = event.submitter?.dataset.post === '1';

    clearFormErrors(form);

    const items = collect('#bill-lines [data-line]', ['variant_id', 'quantity', 'unit_price', 'discount'])
        .filter((line) => line.variant_id && line.quantity !== '')
        .map((line) => ({
            variant_id: Number(line.variant_id),
            quantity: line.quantity,
            unit_price: line.unit_price || '0',
            discount: line.discount || null,
        }));

    const payments = collect('#bill-payments [data-payment]', ['mode', 'amount', 'reference'])
        .filter((split) => split.amount !== '')
        .map((split) => ({
            mode: split.mode,
            amount: split.amount,
            reference: split.reference || null,
        }));

    setSubmitting(form, true, post ? 'Posting…' : 'Saving…');

    try {
        const response = await auth.call(`/transactions/${state.kind}`, {
            method: 'POST',
            body: {
                date: form.elements.date.value,
                notes: form.elements.notes.value.trim() || null,
                post,
                party_id: Number(form.elements.party_id.value) || null,
                items,
                payments,
            },
        });

        hideModal('#bill-form-modal');
        toast(response.message ?? 'Saved.');

        // Warnings ride back on the same response that confirms the posting, and
        // they are shown *as well as* the confirmation rather than instead of it:
        // the bill did post, and somebody still needs to look at it.
        (response.meta?.warnings ?? []).forEach((warning) => toast(warning.message, 'error'));

        await load();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false, post ? 'Post the bill' : 'Save as draft');
    }
}

/* -------------------------------------------------------------------------
 | Writing an expense
 | ---------------------------------------------------------------------- */

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

    $('#expense-payments').innerHTML = '';
    paymentLine($('#expense-payments'));

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
                payments: collect('#expense-payments [data-payment]', ['mode', 'amount', 'reference'])
                    .filter((split) => split.amount !== '')
                    .map((split) => ({
                        mode: split.mode,
                        amount: split.amount,
                        reference: split.reference || null,
                    })),
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
    await loadReferenceData();
    await load();

    ['type', 'status'].forEach((field) => {
        $(`#filter-${field}`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.page = 1;
            load();
        });
    });

    ['from', 'to'].forEach((field) => {
        $(`#filter-${field}`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.page = 1;
            load();
        });
    });

    $('#clear-filters').addEventListener('click', () => {
        Object.assign(state, { type: '', status: '', from: '', to: '', page: 1 });
        ['type', 'status', 'from', 'to'].forEach((field) => { $(`#filter-${field}`).value = ''; });
        load();
    });

    $('#bills-body').addEventListener('click', (event) => {
        const row = event.target.closest('[data-bill]');
        if (row) openBill(row.dataset.bill);
    });

    $('#bills-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;

        const row = event.target.closest('[data-bill]');
        if (row) openBill(row.dataset.bill);
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

    // Two buttons, one chooser — the header's and the list toolbar's.
    $$('[data-new-transaction]').forEach((button) => {
        button.addEventListener('click', openChooser);
    });

    $('#new-transaction-modal').addEventListener('click', (event) => {
        const kind = event.target.closest('[data-kind]');

        if (kind) {
            state.chosenKind = kind.dataset.kind;
            selectCard(kind, 'kind');
            $('#new-transaction-modal [data-choose-method]').disabled = false;

            return;
        }

        const method = event.target.closest('[data-method]');

        if (method) {
            state.chosenMethod = method.dataset.method;
            selectCard(method, 'method');
            $('#new-transaction-modal [data-start]').disabled = false;

            return;
        }

        // Forward only once a kind is chosen; back at any time. The Continue
        // button is disabled until then, so this is belt and braces for a
        // keyboard reaching it another way.
        if (event.target.closest('[data-choose-method]') && state.chosenKind) showStep('method');
        if (event.target.closest('[data-choose-kind]')) showStep('kind');
        if (event.target.closest('[data-start]')) startChosen();
    });

    // `/bills?new=sale&party=12` — the Customers and Vendors screens' "create a
    // bill for this one" action. A deep link rather than a form of their own,
    // so there is exactly one place a bill is written.
    openRequestedForm();

    $('#add-bill-line').addEventListener('click', () => {
        $('#bill-lines').insertAdjacentHTML('beforeend', billLine());
    });

    $('#bill-lines').addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');

        // Never the last one: an empty form with no way to add a line back
        // without closing and reopening is worse than a line you can ignore.
        if (remove && $$('#bill-lines [data-line]').length > 1) {
            remove.closest('[data-line]').remove();
            renderTotals();
        }
    });

    // The list price fills the rate box the first time an item is chosen, and
    // never overwrites a rate somebody has typed: a rewind is quoted per job, and
    // a stored price silently replacing what was agreed would put the wrong
    // figure on the customer's invoice.
    $('#bill-lines').addEventListener('change', (event) => {
        if (event.target.name === 'variant_id') {
            const row = event.target.closest('[data-line]');
            const price = $('[name=unit_price]', row);
            const suggested = event.target.selectedOptions[0]?.dataset.price;

            if (price && !price.value && suggested) price.value = suggested;
        }

        renderTotals();
    });

    $('#bill-lines').addEventListener('input', renderTotals);

    $('#add-bill-payment').addEventListener('click', () => paymentLine($('#bill-payments')));
    $('#add-expense-payment').addEventListener('click', () => paymentLine($('#expense-payments')));

    document.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-payment]');
        if (remove) remove.closest('[data-payment]').remove();
    });

    $('#bill-form').addEventListener('submit', submitBill);
    $('#expense-form').addEventListener('submit', submitExpense);
}
