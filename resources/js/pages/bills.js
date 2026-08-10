import auth from '../auth-client';
import { can } from '../permissions';
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
    items: [],           // the catalogue, variants included
    choices: [],         // what a line may name, derived from the above
    itemMeta: null,      // types, their attribute schemas, and the units
    expenseAccounts: [],
    paymentModes: [],

    // Which line opened the catalogue form, and — once it has been created —
    // which family the specification is being added to. Held here rather than on
    // the modal so a failed variant post cannot end up creating the item twice.
    quick: { lineId: null, itemId: null, existing: false },

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
    const [parties, items, accounts, meta, itemMeta] = await Promise.allSettled([
        auth.call('/parties?per_page=200'),
        auth.call('/items?per_page=200&with_variants=1'),
        auth.call('/accounts?per_page=200'),
        auth.call('/transactions/meta'),
        auth.call('/items/meta'),
    ]);

    state.parties = parties.status === 'fulfilled' ? parties.value.data : [];

    state.items = items.status === 'fulfilled' ? items.value.data : [];
    buildChoices();

    state.expenseAccounts = accounts.status === 'fulfilled'
        ? accounts.value.data.filter((account) => account.type === 'expense' && account.is_active)
        : [];

    state.paymentModes = meta.status === 'fulfilled' ? meta.value.data.payment_modes : [];
    state.itemMeta = itemMeta.status === 'fulfilled' ? itemMeta.value.data : null;
}

/** The catalogue again, after something has been added to it. */
async function refreshCatalogue() {
    try {
        const { data } = await auth.call('/items?per_page=200&with_variants=1');

        state.items = data;
        buildChoices();
        refreshLinePickers();
    } catch (error) {
        // The item was still created — the picker is simply a page behind, and
        // an error banner over a bill somebody is in the middle of writing would
        // be about the wrong thing.
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | What a line may name
 | ---------------------------------------------------------------------- */

/**
 * Flatten the catalogue into the things a bill line can point at.
 *
 * A line names a **variant** wherever one exists, because that is what stock is
 * counted against: "a motor" leaves the position of every rating unknowable, so
 * the API refuses it for a stocked item.
 *
 * An item with no variants is still offered, and what happens when it is picked
 * depends on whether it holds stock. A service can be billed as it stands — an
 * hour of rewinding has nothing to vary — and goes on the line as `item_id`.
 * Anything stocked cannot, so picking it opens the specification form instead of
 * quietly selecting something the server will reject on save. The old picker
 * listed variants only, which is why a catalogue of families with no variants
 * yet showed an empty dropdown and no way out of it.
 */
function buildChoices() {
    state.choices = [];

    state.items
        .filter((item) => item.is_active)
        .forEach((item) => {
            const variants = (item.variants ?? []).filter((variant) => variant.is_active);

            if (variants.length) {
                variants.forEach((variant) => state.choices.push({
                    value: `v:${variant.id}`,
                    // display_label falls back to the family name where a variant
                    // has neither a label nor attributes to build one from, and
                    // "Rewinding · Rewinding" reads like a bug.
                    label: variant.display_label === item.name
                        ? item.name
                        : `${item.name} · ${variant.display_label}`,
                    price: variant.sell_price ?? '',
                    gstRate: item.gst_rate,
                    item,
                    variant,
                    needsVariant: false,
                }));

                return;
            }

            state.choices.push({
                value: `i:${item.id}`,
                label: item.tracks_stock ? `${item.name} — add a specification` : item.name,
                price: '',
                gstRate: item.gst_rate,
                item,
                variant: null,
                needsVariant: item.tracks_stock,
            });
        });
}

function findChoice(value) {
    return state.choices.find((choice) => choice.value === value) ?? null;
}

function mayCreateItems() {
    return can('WRITE', 'ITEMS');
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

/**
 * The picker's options, with the way out of an empty catalogue at the top.
 *
 * "Create a new item" sits above the list rather than beside the select: the
 * moment somebody discovers a part is missing is while they are typing the bill
 * that needs it, and sending them to another screen loses the bill.
 */
function itemOptions() {
    const create = mayCreateItems()
        ? '<option value="__new">＋ Create a new item…</option>'
        : '';

    return create + state.choices
        .map((choice) => `
            <option value="${esc(choice.value)}" data-price="${esc(choice.price)}">
                ${esc(choice.label)}
            </option>`)
        .join('');
}

/** Re-offer the catalogue on every open line, keeping what each already names. */
function refreshLinePickers() {
    $$('#bill-lines [data-line]').forEach((row) => {
        const select = $('[name=pick]', row);
        const chosen = select.value;

        select.innerHTML = `<option value="">Choose…</option>${itemOptions()}`;
        select.value = chosen;
    });

    renderCatalogueHint();
}

function renderCatalogueHint() {
    const hint = $('#bill-catalogue-hint');
    const empty = state.choices.length === 0;

    hint.classList.toggle('hidden', !empty);

    if (empty) {
        hint.textContent = mayCreateItems()
            ? 'Nothing in the catalogue yet. Choose “Create a new item” on the line above and it will be '
                + 'added to Items as well.'
            : 'Nothing in the catalogue yet, and your account cannot add to it. Ask someone who can to '
                + 'create the item first.';
    }
}

function billLine() {
    const id = ++lineSeq;

    return `
        <div class="grid gap-2 rounded-[10px] border border-border p-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]"
             data-line="${id}" data-pick="">
            <label class="field">
                <span class="field-label">Item</span>
                <select name="pick" class="field-input" required>
                    <option value="">Choose…</option>
                    ${itemOptions()}
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
    renderCatalogueHint();
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
        const choice = findChoice($('[name=pick]', line).value);

        if (!choice) return;

        const quantity = Number($('[name=quantity]', line).value) || 0;
        const price = Number($('[name=unit_price]', line).value) || 0;
        const discount = Number($('[name=discount]', line).value) || 0;
        const base = Math.max(quantity * price - discount, 0);

        taxable += base;
        tax += base * (Number(choice.gstRate) || 0) / 100;
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

    // Each line names one of two things and the API takes either: a variant
    // where the catalogue has one, and the family itself for a service, which has
    // nothing to vary and no stock to count.
    const items = collect('#bill-lines [data-line]', ['pick', 'quantity', 'unit_price', 'discount'])
        .map((line) => ({ ...line, choice: findChoice(line.pick) }))
        .filter((line) => line.choice && line.quantity !== '')
        .map((line) => ({
            item_id: line.choice.item.id,
            variant_id: line.choice.variant?.id ?? null,
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
 | Adding to the catalogue from the bill
 | ---------------------------------------------------------------------- */

/**
 * The vocabulary of the catalogue, fetched late if the first attempt failed.
 *
 * Which fields describe a motor is the server's answer — a copy of that schema
 * here would drift, and the drift shows up as a motor saved without its rating,
 * which nobody can recover afterwards.
 */
async function ensureItemMeta() {
    if (state.itemMeta) return state.itemMeta;

    try {
        const { data } = await auth.call('/items/meta');
        state.itemMeta = data;
    } catch {
        state.itemMeta = { types: [], units: [] };
    }

    return state.itemMeta;
}

function quickTypeMeta(value) {
    return state.itemMeta?.types?.find((type) => type.value === value) ?? null;
}

/**
 * Reflect the chosen type into the rest of the form.
 *
 * The type decides which word the tax code goes by, which unit it is counted in,
 * whether stock is possible at all, and which specification fields appear.
 * Saying so as it is picked beats refusing the save afterwards.
 */
function applyQuickType() {
    const type = quickTypeMeta($('#quick-type').value);

    if (!type) return;

    $('#quick-hsn-label').textContent = `${type.tax_code_label} code`;
    $('#quick-uom').value = type.default_uom;
    $('#quick-type-hint').textContent = `Described by ${describeQuickAttributes(type)}.`;

    const checkbox = $('#quick-stock');

    checkbox.disabled = !type.can_hold_stock;
    if (!type.can_hold_stock) checkbox.checked = false;

    $('#quick-stock-hint').textContent = type.can_hold_stock
        ? 'Turn this off for something you buy to order and never hold.'
        : 'A service cannot be held in stock — an hour is produced when it is sold.';

    renderQuickAttributes(type);
}

function describeQuickAttributes(type) {
    const keys = Object.keys(type.attributes ?? {});

    return keys.length
        ? keys.map((key) => type.attributes[key].label.toLowerCase()).join(', ')
        : 'nothing — a service has no variations';
}

/** A select where the values are genuinely fixed, a text box where they are not. */
function renderQuickAttributes(type) {
    const schema = type?.attributes ?? {};
    const keys = Object.keys(schema);
    const host = $('#quick-item-attributes');

    $('#quick-variant-hint').textContent = keys.length
        ? 'Stock is counted per specification, so the bill needs the exact one.'
        : 'Nothing to describe — this goes on the bill as it stands.';

    host.innerHTML = keys.map((key) => {
        const field = schema[key];
        const suffix = field.suffix
            ? ` <span class="font-normal text-muted-foreground">(${esc(field.suffix)})</span>`
            : '';

        const input = field.values
            ? `<select class="field-input" data-attribute="${esc(key)}">
                   <option value="">Choose…</option>
                   ${field.values.map((option) =>
                       `<option value="${esc(option)}">${esc(option)}</option>`).join('')}
               </select>`
            : `<input type="text" class="field-input" data-attribute="${esc(key)}" autocomplete="off">`;

        return `
            <div>
                <label class="field-label">
                    ${esc(field.label)}${suffix}
                    ${field.required ? '' : '<span class="font-normal text-muted-foreground">(optional)</span>'}
                </label>
                ${input}
            </div>`;
    }).join('');
}

function collectQuickAttributes() {
    const bag = {};

    $$('[data-attribute]', $('#quick-item-attributes')).forEach((input) => {
        const value = input.value.trim();

        // Blank is absent, not "". A form submits every field it renders, and an
        // untouched box stored as an empty string is noise every later reader has
        // to filter out.
        if (value !== '') bag[input.dataset.attribute] = value;
    });

    return bag;
}

/**
 * Open the catalogue form over the bill.
 *
 * Two ways in, one form. With no item it creates a family and its first
 * specification; with one — reached by picking an item that has no variants yet —
 * it adds only the specification, and the family's own fields are hidden because
 * they are already settled.
 */
async function openQuickItem(row, item = null) {
    await ensureItemMeta();

    const form = $('#quick-item-form');

    clearFormErrors(form);
    form.reset();

    state.quick = {
        lineId: row.dataset.line,
        itemId: item?.id ?? null,
        existing: item !== null,
    };

    showQuickItemFields(item === null);

    if (item) {
        $('#quick-item-title').textContent = `Which ${item.name}?`;
        $('#quick-item-subtitle').textContent =
            'The family is in your catalogue but nothing says which one is on the shelf. '
            + 'Stock is counted per specification, so a bill needs the exact one.';

        renderQuickAttributes(quickTypeMeta(item.type));
    } else {
        $('#quick-item-title').textContent = 'New item';
        $('#quick-item-subtitle').textContent =
            'Added to your catalogue, so it appears on Items too — not just on this bill.';

        $('#quick-type').value = state.itemMeta?.types?.[0]?.value ?? '';
        applyQuickType();
    }

    showModal('#quick-item-modal');
}

/** Hidden, not disabled: once the family exists its fields are answered. */
function showQuickItemFields(visible) {
    $('#quick-item-fields').classList.toggle('hidden', !visible);
    $('#quick-item-fields').classList.toggle('grid', visible);
}

/**
 * Create the item, then its first specification.
 *
 * Two requests, because they are two resources — the same two the Items screen
 * posts, which is what makes this a catalogue item rather than something that
 * only exists on a bill. The item's id is kept the moment it comes back: if the
 * specification is refused, the retry adds one to the family that now exists
 * instead of creating a second family with the same name.
 */
async function submitQuickItem(event) {
    event.preventDefault();

    const form = event.target;

    clearFormErrors(form);
    setSubmitting(form, true, 'Saving…');

    try {
        if (state.quick.itemId === null) {
            const created = await auth.call('/items', {
                method: 'POST',
                body: {
                    name: $('#quick-name').value.trim(),
                    type: $('#quick-type').value,
                    hsn_sac: $('#quick-hsn').value.trim() || null,
                    gst_rate: $('#quick-gst').value.trim() || '0',
                    base_uom: $('#quick-uom').value,
                    is_stock: $('#quick-stock').checked,
                },
            });

            state.quick.itemId = created.data.id;
            showQuickItemFields(false);
        }

        const response = await auth.call(`/items/${state.quick.itemId}/variants`, {
            method: 'POST',
            body: {
                attributes: collectQuickAttributes(),
                sku: $('#quick-sku').value.trim() || null,
                sell_price: $('#quick-price').value.trim() || null,
            },
        });

        hideModal('#quick-item-modal');
        toast(state.quick.existing ? 'Specification added.' : 'Item added to your catalogue.');

        // A second specification matching one that already exists is saved and
        // reported, never refused: two brands at one rating is a real
        // arrangement, and the commoner cause is the same thing entered twice.
        (response.meta?.warnings ?? []).forEach((warning) => toast(warning.message, 'info'));

        await refreshCatalogue();
        selectInLine(state.quick.lineId, `v:${response.data.id}`);
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/**
 * Close the catalogue form without saving.
 *
 * The catalogue is still refetched where the family was created before the
 * specification failed — that item exists now, and a picker that did not list it
 * would invite somebody to create it a second time.
 */
async function cancelQuickItem() {
    const orphaned = state.quick.itemId !== null && !state.quick.existing;

    hideModal('#quick-item-modal');
    state.quick = { lineId: null, itemId: null, existing: false };

    if (orphaned) await refreshCatalogue();
}

/** Put a freshly created variant on the line that asked for it. */
function selectInLine(lineId, value) {
    const row = $(`#bill-lines [data-line="${lineId}"]`);

    if (!row) return;

    const select = $('[name=pick]', row);

    select.value = value;
    row.dataset.pick = value;

    const choice = findChoice(value);
    const price = $('[name=unit_price]', row);

    if (price && !price.value && choice?.price) price.value = choice.price;

    renderTotals();
    select.focus();
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
    //
    // Two of the options are not selections at all. "Create a new item" opens the
    // catalogue form, and a family with no specification yet opens the same form
    // to add one — both put the select back where it was first, so a cancelled
    // detour leaves the line exactly as it found it.
    $('#bill-lines').addEventListener('change', (event) => {
        if (event.target.name === 'pick') {
            const row = event.target.closest('[data-line]');
            const chosen = event.target.value;

            if (chosen === '__new') {
                event.target.value = row.dataset.pick ?? '';
                openQuickItem(row);

                return;
            }

            const choice = findChoice(chosen);

            if (choice?.needsVariant) {
                event.target.value = row.dataset.pick ?? '';
                openQuickItem(row, choice.item);

                return;
            }

            row.dataset.pick = chosen;

            const price = $('[name=unit_price]', row);
            const suggested = event.target.selectedOptions[0]?.dataset.price;

            if (price && !price.value && suggested) price.value = suggested;
        }

        renderTotals();
    });

    $('#bill-lines').addEventListener('input', renderTotals);

    $('#quick-item-form').addEventListener('submit', submitQuickItem);
    $('#quick-type').addEventListener('change', applyQuickType);

    // Its own close handler rather than data-modal-close: cancelling may still
    // have left a new family in the catalogue.
    $('#quick-item-modal').addEventListener('click', (event) => {
        if (event.target.closest('[data-quick-cancel]') || event.target.matches('[data-modal]')) {
            cancelQuickItem();
        }
    });

    // Escape, taken in the capture phase and stopped there, so the shell's own
    // handler does not go on to close the bill underneath as well.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if ($('#quick-item-modal').classList.contains('hidden')) return;

        event.stopPropagation();
        cancelQuickItem();
    }, true);

    $('#add-bill-payment').addEventListener('click', () => paymentLine($('#bill-payments')));
    $('#add-expense-payment').addEventListener('click', () => paymentLine($('#expense-payments')));

    document.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-payment]');
        if (remove) remove.closest('[data-payment]').remove();
    });

    $('#bill-form').addEventListener('submit', submitBill);
    $('#expense-form').addEventListener('submit', submitExpense);
}
