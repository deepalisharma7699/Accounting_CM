import auth from '../auth-client';
import { badge, formatQuantity, kindBadge, lifecycleTone } from '../components/badge';
import { mountBillDocument } from '../components/bill-document';
import { mountBillRevision } from '../components/bill-revision';
import { renderInvoice } from '../components/invoice-document';
import { mountPaymentRows } from '../components/payment-rows';
import { describeAttribution, mountStaffAttribution } from '../components/staff-attribution';
import { describeShortfalls } from '../components/stock-position';
import { can } from '../permissions';
import {
    $, $$, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Sales — the §2A module for what the workshop sold.
 *
 * ```
 * card → INVOICE FORM              ← always lands here (§2A.5)
 *      → "Show list (42)"          → the invoices and the credit notes
 *      → row → drawer (level 2)
 * ```
 *
 * Almost nothing here is new, and that is the point. The form is
 * `components/bill-document.js`, which the Purchase module and the counter at
 * /bills/new raise the identical document through; the flow is `workspace.js`;
 * the customer picker, the item picker and the payment rows are the shared
 * components. What this file contributes is the three decisions that are the
 * module's own — where it lands, what its list asks for, and what happens after
 * an invoice posts.
 *
 * ## Why a posted invoice stays on the form
 *
 * §2A.8, and it matters more here than anywhere else in the application. A
 * counter writes several invoices between one delivery and the next, and being
 * thrown to a list after each one would mean a trip back for every customer in
 * the queue. So the document is emptied for the next sale, focus returns to the
 * customer box, and the new row is flagged so it is highlighted whenever the
 * list is next looked at.
 *
 * ## Why credit notes are on this list
 *
 * "What did we sell them" and "what came back" are one question asked of one
 * customer. Two lists would have to be reconciled by hand, and the second one is
 * always the one nobody opens. The same judgement Purchase makes about debit
 * notes.
 *
 * ## Why the drawer swaps rather than stacks
 *
 * Collecting against an invoice and taking goods back are *states of the drawer*
 * — the body swaps and the footer swaps with it. A form opened over the drawer
 * would be level 3 doing level 2's job, and §2.2 says to redesign that as a
 * step-based flow rather than build it. Nothing opens over this surface except
 * the confirmation for the acts that cannot be undone, which is what level 3 is
 * for.
 */

const PAGE_SIZE = 25;

/** The document kinds this module is about. A purchase here would be a surprise. */
const KINDS = ['sale', 'sales_return'];

const state = {
    search: '',
    kind: '',
    payment: '',
    status: '',
    outstanding: false,
    from: '',
    to: '',
    page: 1,
    total: null,
};

let root = null;
let doc = null;
let workspace = null;

/**
 * Correcting a posted invoice, and starting a new one from an old one.
 *
 * `components/bill-revision.js` — the same component Purchase mounts, because
 * the two modules do exactly this and the parts that go wrong in a second copy
 * are not the obvious ones. See its docblock.
 */
let revision = null;

/*
| The list surface's own node, held from mount.
|
| §2A.2 keeps exactly one of the form and the list attached, so for half the
| module's life `[data-sales-body]` is not a descendant of `root` at all — and
| every lookup for it comes back null. That is not a rare state: §2A.8 keeps the
| operator on the *form* after a post, which is precisely when `onPosted` wants
| to bring the list up to date.
|
| Querying a node works whether or not it is in the document, so the list is
| scoped to itself. Scoping it to `root` throws on the first `.innerHTML`,
| aborts the refetch behind it, and leaves the table showing its pre-sale rows.
*/
let listRoot = null;

/** Anything outside the two swapped surfaces — the drawer. Always attached. */
const el = (selector) => $(selector, root);

/** Anything inside the list, which is attached only while the list is showing. */
const listEl = (selector) => $(selector, listRoot);

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams();

    if (state.search) params.set('search', state.search);

    if (state.kind) {
        params.set('type', state.kind);
    } else {
        // Asked for by name rather than filtered after the fact, so the page
        // count agrees with the rows on it.
        KINDS.forEach((kind) => params.append('types[]', kind));
    }

    if (state.payment) params.set('payment_status', state.payment);
    if (state.status) params.set('status', state.status);
    if (state.outstanding) params.set('outstanding', '1');
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    listEl('[data-sales-body]').innerHTML = tableMessage(8, 'Loading…');

    try {
        const payload = await auth.call(`/transactions?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        state.total = null;

        listEl('[data-sales-body]').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(8, 'Your account administers the platform rather than a single workshop, '
                + 'so it has no sales of its own.')
            : tableMessage(8, error.message, 'error');
    }
}

/** Refetch from page one — what every filter change means. */
const refetch = debounce(async () => {
    state.page = 1;
    await load();
}, 250);

function render(rows, meta) {
    const body = listEl('[data-sales-body]');

    body.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(8, 'Nothing here yet. An invoice will appear the moment one is raised.');

    const pagination = meta?.pagination ?? {};

    state.total = pagination.total ?? null;

    listEl('[data-sales-summary]').textContent = pagination.total
        ? `${rows.length} of ${pagination.total}.`
        : '';

    listEl('[data-page-prev]').disabled = (pagination.current_page ?? 1) <= 1;
    listEl('[data-page-next]').disabled = !pagination.has_more;

    // §2A.4 — the count rides on the Show control, so the form says how much is
    // behind it without anybody having to switch.
    workspace?.refresh();
}

/**
 * One row.
 *
 * `paid`, `due` and `payment_status` are absent for anything that is not a
 * posted bill — a draft, a reversed invoice. That is deliberate on the server's
 * side and honoured here: a dash says the question does not apply, where a zero
 * would say "nothing has been collected" and invite somebody to chase it.
 */
function renderRow(row) {
    const settled = row.payment_status !== undefined;

    // §2A.8 — an invoice raised while the list was detached carries the flash
    // with it, so the eye finds it the first time somebody does look. The
    // workspace spends the flag when the list reaches the screen, not here.
    const flash = workspace?.isNew(row.id) ? ' row-new' : '';

    /*
    | A record from before the INV/YY-YY/NNNN scheme has no document number and
    | never will, and neither does a draft — a number that could be discarded is
    | a gap in the series somebody has to explain. The internal id stands in,
    | muted, so it still reads as the fallback it is.
    */
    const docLabel = row.doc_no ?? `#${row.id}`;

    /*
    | The document's own rows, not the ledger's.
    |
    | `line_count` counts postings — a single item sold at 18% is five of them,
    | Dr Debtors / Cr Sales / Cr GST Output / Dr COGS / Cr Inventory — and a
    | column headed "Lines" printing that would read 5 against a drawer showing
    | one row. The same defect the Purchase list was fixed for.
    */
    const itemLines = row.item_line_count === null ? '—' : String(row.item_line_count);

    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
            data-sale="${row.id}" tabindex="0" role="link"
            aria-label="Open ${esc(docLabel)}">
            <td class="table-cell w-44">
                <span class="block font-mono text-[0.8125rem] font-medium ${
                    row.doc_no ? 'text-foreground' : 'text-muted-foreground'
                }">
                    ${esc(docLabel)}
                </span>
                ${kindBadge(row.type, row.type_label)}
            </td>

            <td class="table-cell text-[0.8125rem]">
                ${esc(row.party?.name ?? '—')}
                ${row.notes ? `<span class="block text-xs text-muted-foreground">${esc(row.notes)}</span>` : ''}
            </td>

            <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>

            <td class="table-cell w-16 text-right font-mono text-[0.8125rem] text-muted-foreground">
                ${esc(itemLines)}
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
 | One document — level 2
 | ---------------------------------------------------------------------- */

const drawer = {
    id: null,
    /** `view`, `pay` or `return`. */
    mode: 'view',
    bill: null,
    meta: null,
    payments: null,
    /*
    | Minted per credit note being written and reused on every retry of it.
    |
    | A duplicate return puts the goods back on the shelf twice *and* credits the
    | customer twice — the one idempotency failure on this surface that corrupts
    | the stock and the ledger together rather than only the record.
    */
    returnRef: null,
    /*
    | The customer's copy of this document, once anything has asked for it.
    |
    | Fetched at most once per opened drawer and then held, because two things
    | want it and neither should pay for the other: Print renders it, and Share
    | needs the customer's phone number to address a WhatsApp message with. It is
    | a *different payload* from `bill` — see InvoiceDocumentService for why the
    | customer's document is built from its own list of fields rather than by
    | filtering this one.
    */
    invoice: null,
    /** The live link for this document, or null. From the invoice call's meta. */
    share: null,
    /** The correction dialog's pickers while it is open — M22. */
    staffEditor: null,
    busy: false,
};

async function openDrawer(id) {
    drawer.id = id;
    drawer.mode = 'view';
    drawer.bill = null;
    drawer.meta = null;
    // Per document, not per session. Carrying one invoice's reference to the
    // next would make a return on B come back as the credit note already raised
    // on A.
    drawer.returnRef = null;
    // Per document, like everything else here: showing invoice B's link on
    // invoice A is how a customer is sent somebody else's bill.
    drawer.invoice = null;
    drawer.share = null;

    el('[data-drawer-subtitle]').textContent = '';
    el('[data-drawer-status]').innerHTML = '';
    el('[data-drawer-alert]').classList.add('hidden');
    el('[data-drawer-actions]').innerHTML = '';
    $('#sales-drawer-title', root).textContent = 'Loading…';
    el('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    showModal('#sales-drawer');

    await loadDocument();
}

async function loadDocument() {
    try {
        const { data, meta } = await auth.call(`/transactions/${drawer.id}`);

        drawer.bill = data;
        drawer.meta = meta ?? {};

        paint();
    } catch (error) {
        el('[data-drawer-body]').innerHTML =
            `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

function paint() {
    const bill = drawer.bill;

    $('#sales-drawer-title', root).textContent =
        `${bill.type_label} ${bill.doc_no ?? `#${bill.id}`}`;

    el('[data-drawer-subtitle]').textContent = [
        bill.party?.name,
        formatDate(bill.date),
        bill.notes,
    ].filter(Boolean).join(' · ');

    el('[data-drawer-status]').innerHTML = bill.payment_status !== undefined
        ? badge(bill.payment_status_label, bill.payment_status_tone,
            { title: bill.due_date ? `Due ${formatDate(bill.due_date)}` : null })
        : badge(bill.status_label, lifecycleTone(bill.status));

    paintAlert();

    if (drawer.mode === 'pay') return paintPay();
    if (drawer.mode === 'return') return paintReturn();

    paintView();
}

/**
 * What somebody ought to look at — M9's warnings, computed on read.
 *
 * Recomputed every time the invoice is opened rather than only when it was
 * posted, because "why did we sell that below cost" is asked long after the
 * toast has gone.
 */
function paintAlert() {
    // Only while reading. A warning about the margin on the invoice, left
    // standing over a form for collecting money against it, reads as a warning
    // about the money.
    const warnings = drawer.mode === 'view' ? (drawer.meta?.warnings ?? []) : [];

    el('[data-drawer-alert]').classList.toggle('hidden', warnings.length === 0);
    el('[data-drawer-alert]').classList.toggle('flex', warnings.length > 0);

    el('[data-drawer-alert-text]').innerHTML =
        warnings.map((warning) => `<p>${esc(warning.message)}</p>`).join('');
}

function paintView() {
    const bill = drawer.bill;
    const tax = drawer.meta?.tax ?? {};
    const margin = drawer.meta?.margin ?? null;
    const isDraft = bill.status === 'draft';

    el('[data-drawer-body]').innerHTML = `
        ${settlementPanel(bill)}

        ${attributionPanel(bill)}

        ${isDraft ? `
            <p class="mb-4 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5 text-[0.8125rem]
                      text-muted-foreground">
                A draft. Nothing has reached the ledger and nothing has left the shelf, so the tax and the
                totals below are blank — they are worked out against the item's rate and the two state codes
                at the moment it is posted, not now.
            </p>` : ''}

        ${bill.items?.length ? `
            <table class="w-full border-collapse text-[0.8125rem]">
                <thead>
                    <tr class="border-b border-border text-left text-xs uppercase tracking-wide
                               text-muted-foreground">
                        <th class="py-2 font-semibold">Item</th>
                        <th class="py-2 text-right font-semibold">Qty</th>
                        <th class="py-2 text-right font-semibold">Rate</th>
                        <th class="py-2 text-right font-semibold">Taxable</th>
                        <th class="py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${bill.items.map((line) => `
                        <tr class="border-b border-border">
                            <td class="py-2">
                                <span class="font-medium">
                                    ${esc(line.description ?? line.memo ?? `Line ${line.line_no}`)}
                                </span>
                                ${line.description && line.memo
                                    ? `<span class="block text-xs text-muted-foreground">${esc(line.memo)}</span>`
                                    : ''}
                            </td>
                            <td class="py-2 text-right font-mono">
                                ${esc(formatQuantity(line.quantity, line.unit_symbol))}
                            </td>
                            <td class="py-2 text-right font-mono">${esc(formatMoney(line.unit_price))}</td>
                            <td class="py-2 text-right font-mono">${esc(formatMoney(line.taxable_value))}</td>
                            <td class="py-2 text-right font-mono font-semibold">
                                ${esc(formatMoney(line.line_total))}
                            </td>
                        </tr>`).join('')}
                </tbody>
            </table>

            <dl class="mt-4 space-y-1.5 border-t border-border pt-3 text-[0.8125rem]">
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Taxable value</dt>
                    <dd class="font-mono">${esc(formatMoney(tax.taxable ?? null))}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">
                        ${tax.inter_state ? 'IGST' : 'CGST + SGST'} — collected on the workshop's behalf
                    </dt>
                    <dd class="font-mono">${esc(formatMoney(tax.tax ?? null))}</dd>
                </div>
                <div class="flex justify-between text-base font-bold text-foreground">
                    <dt>Total</dt>
                    <dd class="font-mono">${isDraft ? '—' : `₹${esc(formatMoney(bill.total))}`}</dd>
                </div>
            </dl>

            ${marginPanel(margin)}`
        : `<p class="py-6 text-sm text-muted-foreground">
               This document has no item lines.
           </p>`}`;

    paintActions();
}

/**
 * Who did the work — M22.
 *
 * Above the lines rather than below the margin, and deliberately: on a repair
 * invoice this is the thing somebody opens the drawer to check, and the panel
 * that answers "how much did we make" is the one they should have to scroll to.
 *
 * ## Three states, not two
 *
 * `bill.staff` absent means nobody asked the server for it — a list row, an
 * older client — and the panel is not painted at all. Present and empty means
 * the document genuinely names nobody, which is worth saying out loud, because
 * it is the state an owner wants to find and fix. See `describeAttribution`.
 *
 * The control says **Change** rather than Edit, and it is offered on a posted
 * invoice: correcting a name moves no figure, and there is no other route to it
 * — a sale whose cost basis has moved cannot be reversed and reissued at all.
 */
function attributionPanel(bill) {
    const described = describeAttribution(bill.staff);

    if (described === null) return '';

    const editable = can('UPDATE', 'TRANSACTIONS');

    return `
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-[10px] bg-secondary/50
                    px-3.5 py-2.5 text-[0.8125rem]">
            <div>
                <span class="text-muted-foreground">Work done by</span>
                <span class="${described.recorded
                    ? 'ml-1.5 font-medium text-foreground'
                    : 'ml-1.5 italic text-muted-foreground'}">${esc(described.text)}</span>
            </div>

            ${editable ? `
                <button type="button" class="btn btn-ghost btn-sm" data-staff-edit>
                    ${described.recorded ? 'Change' : 'Record'}
                </button>` : ''}
        </div>`;
}

/**
 * What the workshop actually made on this one.
 *
 * The sale side's equivalent of the note Purchase prints about the cost basis,
 * and the reason this drawer is worth opening on a document somebody already
 * knows the total of. Absent on a draft and on anything with no stock lines:
 * labour has no cost of goods, and printing ₹0 there would claim a 100% margin
 * on the workshop's most valuable work.
 */
function marginPanel(margin) {
    if (!margin) return '';

    const thin = Number(margin.margin) < 0;

    return `
        <dl class="mt-4 space-y-1.5 rounded-[10px] bg-secondary/50 px-3.5 py-3 text-[0.8125rem]">
            <div class="flex justify-between">
                <dt class="text-muted-foreground">Revenue</dt>
                <dd class="font-mono">${esc(formatMoney(margin.revenue))}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-muted-foreground">Cost of goods, at the shelf's average</dt>
                <dd class="font-mono">${esc(formatMoney(margin.cost))}</dd>
            </div>
            <div class="flex justify-between border-t border-border pt-1.5 font-semibold
                        ${thin ? 'text-rose-700' : 'text-foreground'}">
                <dt>Margin</dt>
                <dd class="font-mono">
                    ${esc(formatMoney(margin.margin))} (${esc(margin.margin_percent)}%)
                </dd>
            </div>
        </dl>`;
}

/** What is owed on this one, at the top where somebody looking for it will find it. */
function settlementPanel(bill) {
    if (bill.payment_status === undefined) return '';

    return `
        <div class="mb-4 flex flex-wrap items-center gap-4 rounded-[10px] bg-secondary/50 px-3.5 py-2.5
                    text-[0.8125rem]">
            <span class="text-muted-foreground">Collected
                <span class="font-mono text-foreground">${esc(formatMoney(bill.paid))}</span></span>
            <span class="text-muted-foreground">Due
                <span class="font-mono font-semibold text-foreground">${esc(formatMoney(bill.due))}</span></span>
            ${bill.credited && bill.credited !== '0.00'
                ? `<span class="text-muted-foreground">Came back
                       <span class="font-mono text-foreground">${esc(formatMoney(bill.credited))}</span></span>`
                : ''}
            ${bill.due_date
                ? `<span class="text-muted-foreground">Due by ${esc(formatDate(bill.due_date))}</span>`
                : ''}
        </div>`;
}

/* --- collect ---------------------------------------------------------- */

/**
 * Take money against this invoice — a *receipt*, not a payment.
 *
 * Two endpoints rather than one with a direction, exactly as the bill routes
 * are two: collecting from a customer and paying a supplier are different
 * events, and the URL should say which happened.
 */
function paintPay() {
    const bill = drawer.bill;

    el('[data-drawer-body]').innerHTML = `
        <p class="mb-4 text-[0.8125rem] text-muted-foreground">
            Collecting from <strong class="text-foreground">${esc(bill.party?.name ?? '')}</strong> against
            ${esc(bill.doc_no ?? `#${bill.id}`)}. The receipt is pointed at this invoice, so what is owed on
            <em>it</em> moves rather than only the customer's overall balance.
        </p>

        <label class="field mb-4">
            <span class="field-label">Date</span>
            <input type="date" class="field-input" data-pay-date value="${esc(todayISO())}">
            <span class="field-error hidden" data-error-for="date"></span>
        </label>

        <div data-pay-host></div>

        <p class="field-error hidden" data-error-for="payments"></p>
        <p class="field-error hidden" data-error-for="party_id"></p>`;

    drawer.payments = mountPaymentRows(el('[data-pay-host]'), {
        modes: doc.paymentModes(),
        // Pre-fills "Paid in full" with what is actually left on this invoice
        // rather than its total — a part-paid invoice being topped up is the
        // common case at a counter.
        outstanding: () => bill.due ?? '0.00',
        heading: 'Collected now',
        noun: 'customer',
        verb: 'collected',
        /*
        | `canCredit` is deliberately left off. This surface records money that
        | has already changed hands; "On credit" here would be a button for
        | banking nothing, and the invoice behind it is *already* the credit.
        */
    });

    paintActions();
}

async function submitReceipt() {
    const bill = drawer.bill;
    const split = drawer.payments.value();

    if (split.length === 0) {
        toast('Say how the money arrived — cash, bank, UPI or cheque.', 'error');

        return;
    }

    await run('[data-drawer-submit]', 'Collecting…', async () => {
        const response = await auth.call('/transactions/receipt', {
            method: 'POST',
            body: {
                date: el('[data-pay-date]').value,
                post: true,
                party_id: bill.party?.id,
                payments: split,
                // Named explicitly rather than left to the oldest-first default:
                // somebody collecting from this invoice's own screen means this
                // invoice, even where an older one is still open.
                allocations: [{ bill_transaction_id: bill.id }],
            },
        });

        toast(response.message ?? 'Receipt recorded.');

        drawer.mode = 'view';

        await loadDocument();
        await refreshList();
    });
}

/* --- return ----------------------------------------------------------- */

/**
 * "The customer brought one of the four bearings back" — M18.
 *
 * Beside `reverse`, never instead of it. A reversal says the sale did not
 * happen, which is not a thing anybody should have to explain to a customer
 * holding the invoice; a return says three of the four are still theirs.
 */
async function paintReturn() {
    el('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    paintActions();

    try {
        const { data, meta } = await auth.call(`/transactions/${drawer.id}/returnable`);

        const notes = meta?.returns ?? [];
        const paid = Number(drawer.bill?.paid ?? 0) > 0;

        el('[data-drawer-body]').innerHTML = `
            <p class="mb-4 text-[0.8125rem] text-muted-foreground">
                How many of each came back. A credit note credits what was actually charged and puts the
                stock back on the shelf at what it left at — so the pair nets to nothing in Inventory even
                though the average has moved since.
            </p>

            ${paid ? `
                <p class="mb-4 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5
                          text-[0.8125rem] text-muted-foreground">
                    Money has already been collected on this invoice, so the credit note does not hand any of
                    it back. It sits on the customer's account until it is set against their next bill or
                    refunded as a payment.
                </p>` : ''}

            ${notes.length ? `
                <div class="mb-4 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5
                            text-[0.8125rem]">
                    <p class="font-semibold text-foreground">Already came back</p>
                    ${notes.map((note) => `
                        <p class="text-muted-foreground">
                            ${esc(note.doc_no ?? '—')} · ${esc(formatDate(note.date))} ·
                            <span class="font-mono">${esc(formatMoney(note.total))}</span>
                        </p>`).join('')}
                </div>` : ''}

            ${data.length ? `
                <table class="w-full border-collapse text-[0.8125rem]">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide
                                   text-muted-foreground">
                            <th class="py-2 font-semibold">Item</th>
                            <th class="py-2 text-right font-semibold">Sold</th>
                            <th class="py-2 text-right font-semibold">Left</th>
                            <th class="py-2 text-right font-semibold">Take back</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.map((line) => `
                            <tr class="border-b border-border" data-return-line="${line.line_no}">
                                <td class="py-2">${esc(line.description ?? '')}</td>
                                <td class="py-2 text-right font-mono text-muted-foreground">
                                    ${esc(formatQuantity(line.billed, line.unit_symbol))}
                                </td>
                                <td class="py-2 text-right font-mono">
                                    ${esc(formatQuantity(line.remaining, line.unit_symbol))}
                                </td>
                                <td class="py-2 text-right">
                                    <input type="text" inputmode="decimal"
                                           class="field-input w-24 text-right font-mono"
                                           data-return-qty data-max="${esc(line.remaining)}" placeholder="0"
                                           aria-label="Quantity of ${esc(line.description ?? '')} coming back"
                                           ${Number(line.remaining) > 0 ? '' : 'disabled'}>
                                </td>
                            </tr>`).join('')}
                    </tbody>
                </table>

                <p class="field-error hidden" data-error-for="lines"></p>`
            : `<p class="py-6 text-sm text-muted-foreground">
                   Nothing on this invoice is still returnable — every line has already come back.
               </p>`}`;
    } catch (error) {
        el('[data-drawer-body]').innerHTML =
            `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

async function submitReturn() {
    const lines = $$('[data-return-line]', root)
        .map((row) => ({
            line_no: Number(row.dataset.returnLine),
            quantity: $('[data-return-qty]', row).value.trim(),
        }))
        .filter((line) => line.quantity !== '' && Number(line.quantity) > 0);

    if (lines.length === 0) {
        toast('Say which lines came back, and how many of each.', 'error');

        return;
    }

    // Checked here as well as server-side, so the commonest mistake is explained
    // before a round trip. The service is still the authority on what is left.
    const over = $$('[data-return-line]', root).find((row) => {
        const input = $('[data-return-qty]', row);

        return input.value.trim() !== '' && Number(input.value) > Number(input.dataset.max);
    });

    if (over) {
        toast('One line is taking back more than was sold. Check the "Left" column.', 'error');

        return;
    }

    await run('[data-drawer-submit]', 'Crediting…', async () => {
        const response = await auth.call(`/transactions/${drawer.id}/return`, {
            method: 'POST',
            body: {
                lines,
                client_ref: drawer.returnRef ??= crypto.randomUUID(),
            },
        });

        toast(response.message ?? 'Credit note raised.');

        drawer.returnRef = null;
        drawer.mode = 'view';

        await loadDocument();
        await refreshList();
    });
}

/* --- the acts that cannot be undone ----------------------------------- */

async function reverseDocument() {
    const bill = drawer.bill;
    const isInvoice = bill.type === 'sale';

    const ok = await confirmAction({
        title: `Reverse ${bill.doc_no ?? `#${bill.id}`}?`,
        body: isInvoice
            ? 'A mirroring entry is posted and the goods come back onto the shelf. Both documents stay on '
                + 'the record — nothing is erased. If only part of the sale is coming back, raise a credit '
                + 'note instead: that leaves the invoice standing, which is what the customer is holding.'
            : 'A mirroring entry is posted and the goods go back off the shelf. Both documents stay on the '
                + 'record — nothing is erased. The invoice this credit note was raised against is left '
                + 'owing again.',
        confirmLabel: 'Reverse it',
    });

    if (!ok) return;

    await run(null, null, async () => {
        try {
            await postReversal(false);
        } catch (error) {
            /*
            | The server refused because taking the goods back off the shelf
            | would post a negative. On a credit note that is the ordinary way it
            | happens: the goods came back, were sold again, and now the note
            | itself is being cancelled. That is a decision rather than a failure
            | — sometimes the negative is the honest intermediate state — so it
            | is put to the user rather than swallowed or forced.
            |
            | Everything else rethrows to `run`, which toasts it. A blanket catch
            | here would turn a permission error into "do you want negative
            | stock?", which is a question about the wrong thing.
            */
            if (error.code !== 'REVERSAL_WOULD_GO_NEGATIVE') throw error;

            const accepted = await confirmAction({
                title: 'This will take stock below zero',
                body: `${describeShortfalls(error.details?.shortfalls ?? [])} Reversing anyway leaves the `
                    + 'shelf showing a negative, which stays wrong until a stock count corrects it.',
                confirmLabel: 'Reverse anyway',
            });

            if (!accepted) return;

            await postReversal(true);
        }
    });
}

/**
 * The reversal request itself, so the first attempt and the acknowledged retry
 * are one code path — see §4.4. The flag is never sent by default: the whole
 * point of the refusal is that somebody has to have seen it.
 */
async function postReversal(acknowledged) {
    const response = await auth.call(`/transactions/${drawer.bill.id}/reverse`, {
        method: 'POST',
        body: acknowledged ? { acknowledge_negative_stock: true } : {},
    });

    toast(response.message ?? 'Reversing entry posted.');

    await loadDocument();
    await refreshList();
}

/* --- correcting an invoice that is already in the books ---------------- */

/**
 * Correct a posted invoice — §F5, and the one action on this drawer that edits
 * something already in the books.
 *
 * The whole of it is `components/bill-revision.js`, which Purchase mounts as
 * well. What is left here is closing the drawer, because only this module knows
 * which drawer that is.
 */
async function correctDocument() {
    if (await revision.begin(drawer.bill)) hideModal('#sales-drawer');
}

/**
 * The same six lines again, as a new invoice — a workshop that services the
 * same pump every quarter should not re-type them.
 *
 * Nothing about the original is touched or referenced: what this writes is an
 * ordinary invoice. It is on the drawer rather than the list because it is a
 * decision about *this* document, taken while looking at what is on it.
 */
async function repeatDocument() {
    await revision.repeat(drawer.bill);

    hideModal('#sales-drawer');
}

/* --- the footer ------------------------------------------------------- */

function paintActions() {
    const bill = drawer.bill;
    const host = el('[data-drawer-actions]');

    // A mode is finished or it is left. "Back to the invoice" rather than
    // "Cancel", because nothing has happened yet that could need cancelling.
    if (drawer.mode !== 'view') {
        host.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" data-drawer-back>← Back to the invoice</button>
            <button type="button" class="btn btn-primary btn-sm ml-auto" data-drawer-submit>
                ${drawer.mode === 'pay' ? 'Record the receipt' : 'Raise the credit note'}
            </button>`;

        return;
    }

    const buttons = [];

    /*
    | Only what this document can still have done to it. Offering an act that
    | would be refused teaches somebody the product is unreliable.
    |
    | A draft never reached the ledger, so it can be authorised or thrown away.
    | A posted invoice can be neither: it is corrected by writing another
    | document.
    */
    if (bill.status === 'draft') {
        if (can('UPDATE', 'TRANSACTIONS')) {
            buttons.push('<button type="button" class="btn btn-primary btn-sm" data-drawer-post>Post it</button>');
        }

        if (can('DELETE', 'TRANSACTIONS')) {
            buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-discard>Discard</button>');
        }
    } else if (bill.status === 'posted') {
        const canWrite = can('WRITE', 'TRANSACTIONS');

        /*
        | Collecting and returning belong to an invoice: there is nothing to
        | collect on a credit note, and nothing to take back off one. Reversing
        | belongs to both — a credit note raised against the wrong invoice is as
        | much a mis-posting as the invoice itself was, and reversal is the only
        | way either is corrected once it is in the books.
        */
        if (canWrite && bill.type === 'sale') {
            if (bill.due && Number(bill.due) > 0) {
                buttons.push('<button type="button" class="btn btn-primary btn-sm" data-drawer-pay>Collect payment</button>');
            }

            buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-drawer-return>Accept return</button>');

            /*
            | Correcting an invoice is UPDATE as well as WRITE — it changes the
            | standing of a document that is already in the books, and somebody
            | trusted to raise an invoice but not to alter one should not reach
            | it by the back door of raising two. The route asserts both; this
            | only decides whether the button is worth painting (§6.2).
            |
            | Hidden once anything has been collected against the invoice or has
            | come back on a credit note, because either would be left pointing
            | at a cancelled document. The server refuses it both ways, and
            | offering an act that would be refused teaches somebody the product
            | is unreliable.
            */
            const untouched = Number(bill.paid ?? 0) === 0 && Number(bill.credited ?? 0) === 0;

            if (can('UPDATE', 'TRANSACTIONS') && untouched) {
                buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-drawer-correct>Correct</button>');
            }

            // Ungated by any of that: repeating writes a *new* invoice and
            // leaves this one exactly as it stands, paid or not.
            buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-repeat>Repeat</button>');
        }

        /*
        | The customer's copy — M20, and the two halves of it are gated
        | differently on purpose.
        |
        | **Print** asks nothing more of a grant than reading does: it draws the
        | document already on the screen. Requiring WRITE for it would mean the
        | one person allowed to look up an invoice could not hand the customer a
        | copy of it.
        |
        | **Share** publishes it outside the workshop, and goes with WRITE —
        | which is the grant the endpoint enforces and the grant the person who
        | raised the invoice holds.
        |
        | Both only on a posted document, because that is the only kind there is
        | a document *of*: a draft has no number, no priced lines and no tax.
        */
        buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-drawer-print>Print</button>');

        if (canWrite) {
            buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-drawer-share>Share</button>');
            buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-reverse>Reverse</button>');
        }
    }

    host.innerHTML = buttons.join('')
        + '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
}

async function postDraft() {
    await run('[data-drawer-post]', 'Posting…', async () => {
        const response = await auth.call(`/transactions/${drawer.id}/post`, { method: 'POST' });

        toast(response.message ?? 'Invoice posted.');

        await loadDocument();
        await refreshList();
    });
}

async function discardDraft() {
    const ok = await confirmAction({
        title: 'Discard this draft?',
        body: 'It has never reached the ledger and nothing has left the shelf, so there is nothing to '
            + 'reverse — it simply goes.',
        confirmLabel: 'Discard',
    });

    if (!ok) return;

    await run(null, null, async () => {
        await auth.call(`/transactions/${drawer.id}`, { method: 'DELETE' });

        toast('Draft discarded.');

        hideModal('#sales-drawer');
        await refreshList();
    });
}

/**
 * Run one drawer action, with the button that started it disabled while it goes.
 *
 * §3.4 — the user must never be left unsure whether something is processing, and
 * a second click on "Post it" while the first is in flight is how a workshop
 * ends up explaining two identical invoices.
 */
async function run(hook, busyLabel, work) {
    if (drawer.busy) return;

    drawer.busy = true;

    const button = hook ? el(hook) : null;
    const idle = button?.textContent;

    if (button) {
        button.disabled = true;
        button.textContent = busyLabel;
    }

    try {
        await work();
    } catch (error) {
        // Field errors land beside the field they are about; anything else
        // becomes a toast carrying the server's own sentence. `showFormErrors`
        // decides which — toasting here as well would say it twice.
        showFormErrors(el('[data-drawer-body]'), error);
    } finally {
        drawer.busy = false;

        if (button && button.isConnected) {
            button.disabled = false;
            button.textContent = idle;
        }
    }
}

/* -------------------------------------------------------------------------
 | The customer's copy — printing it, and sharing it
 | ---------------------------------------------------------------------- */

/**
 * The document as the customer would see it, fetched once per opened drawer.
 *
 * Its `meta` carries whether it is already shared and where, so opening the
 * share dialog costs nothing extra once Print has been pressed, and the other
 * way round.
 */
async function loadInvoice() {
    if (drawer.invoice) return drawer.invoice;

    const response = await auth.call(`/transactions/${drawer.id}/invoice`);

    drawer.invoice = response.data;
    drawer.share = response.meta?.share ?? null;

    return drawer.invoice;
}

/**
 * Print, without leaving the page.
 *
 * The sheet is mounted in the application's layout and hidden; painting it and
 * calling `print()` is the whole of it. No second window, so nothing for a
 * pop-up blocker to swallow and nothing to lose the drawer to — §1.1, and §3.2's
 * rule about never reloading, arrived at from the same direction.
 *
 * `#invoice-print` is in `document` rather than under this module's root, which
 * is correct: it belongs to the page, and the Purchase module will print through
 * the identical node.
 */
async function printInvoice() {
    await run('[data-drawer-print]', 'Preparing…', async () => {
        const invoice = await loadInvoice();

        renderInvoice($('#invoice-print [data-invoice-document]'), invoice);

        window.print();
    });
}

/**
 * The share dialog — level 3, over the drawer.
 *
 * Opened before the fetch resolves, with the panel saying so. Waiting on a
 * request with nothing on screen is how somebody comes to press Share twice.
 */
async function openShare() {
    $('[data-share-subtitle]', root).textContent =
        `${drawer.bill.type_label} ${drawer.bill.doc_no ?? `#${drawer.bill.id}`}`;

    $('[data-share-body]', root).innerHTML =
        '<p class="py-6 text-center text-sm text-muted-foreground">Loading…</p>';
    $('[data-share-actions]', root).innerHTML = '';

    showModal('#sales-share-modal');

    try {
        await loadInvoice();
        paintShare();
    } catch (error) {
        $('[data-share-body]', root).innerHTML =
            `<p class="py-6 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

/**
 * WhatsApp, addressed to the customer where there is a number for them.
 *
 * `wa.me` rather than the `whatsapp://` scheme, because it works on a desktop
 * browser as WhatsApp Web and on a phone as the app, and the workshop's counter
 * is sometimes one and sometimes the other.
 *
 * Digits only, with 91 assumed for a ten-digit number. That assumption is safe
 * in the only product this is: the ledger is in rupees, the tax is GST and the
 * place of supply is a two-digit Indian state code. A number already carrying a
 * country code is left alone.
 */
function whatsappHref(url) {
    const digits = String(drawer.invoice?.customer?.phone ?? '').replace(/\D/g, '');
    const to = digits.length === 10 ? `91${digits}` : digits;

    const text = `${drawer.invoice.document.heading} ${drawer.invoice.document.doc_no ?? ''} `
        + `from ${drawer.invoice.workshop.name}: ${url}`;

    // With no number it still opens WhatsApp, on the contact chooser — which is
    // the right answer for a walk-in whose number the workshop never took.
    return `https://wa.me/${to}?text=${encodeURIComponent(text.replace(/\s+/g, ' ').trim())}`;
}

function paintShare() {
    const body = $('[data-share-body]', root);
    const actions = $('[data-share-actions]', root);

    if (drawer.share === null) {
        body.innerHTML = `
            <p class="text-[0.8125rem] text-secondary-foreground">
                This creates a link anybody holding it can open — no account, no password. Send it to
                <strong class="text-foreground">${esc(drawer.bill.party?.name ?? 'the customer')}</strong>
                and it keeps working until you end it.
            </p>
            <p class="mt-2 text-[0.8125rem] text-muted-foreground">
                The page shows the invoice only: what was sold, the tax and what is owed. It never shows
                what anything cost the workshop.
            </p>`;

        actions.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Not now</button>
            <button type="button" class="btn btn-primary btn-sm ml-auto" data-share-create>Create the link</button>`;

        return;
    }

    const url = drawer.share.url;

    body.innerHTML = `
        <label class="field-label" for="sales-share-url">Anybody with this link can read the invoice</label>
        <input id="sales-share-url" type="text" class="field-input font-mono text-[0.8125rem]"
               value="${esc(url)}" readonly data-share-url>

        <p class="mt-2 text-[0.8125rem] text-muted-foreground">
            Shared ${esc(formatDate(drawer.share.shared_at))}${
                drawer.share.shared_by ? ` by ${esc(drawer.share.shared_by)}` : ''
            }. It works until you end it.
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-share-copy>Copy link</button>
            <a class="btn btn-secondary btn-sm" href="${esc(whatsappHref(url))}"
               target="_blank" rel="noopener noreferrer">Send on WhatsApp</a>
            <a class="btn btn-ghost btn-sm" href="${esc(url)}" target="_blank" rel="noopener noreferrer">
                Open it
            </a>
        </div>`;

    actions.innerHTML = `
        <button type="button" class="btn btn-ghost btn-sm" data-share-revoke>Stop sharing</button>
        <button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Done</button>`;
}

async function createLink() {
    await run('[data-share-create]', 'Creating…', async () => {
        const response = await auth.call(`/transactions/${drawer.id}/share`, { method: 'POST' });

        drawer.share = response.data;

        paintShare();
        toast(response.message ?? 'Link ready to share.');
    });
}

async function revokeLink() {
    const ok = await confirmAction({
        title: 'Stop sharing this invoice?',
        body: 'The link stops working immediately, for everybody holding it. Sharing it again makes a '
            + 'different link — this one can never be brought back.',
        confirmLabel: 'Stop sharing',
    });

    if (!ok) return;

    await run(null, null, async () => {
        await auth.call(`/transactions/${drawer.id}/share`, { method: 'DELETE' });

        drawer.share = null;

        paintShare();
        toast('The link has stopped working.');
    });
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(drawer.share.url);
        toast('Link copied.');
    } catch {
        // Clipboard access is refused outright in some browsers and over plain
        // HTTP. The field is already selectable, so say that rather than failing.
        $('[data-share-url]', root)?.select();
        toast('Could not copy — the link is selected, copy it from there.', 'error');
    }
}

/** The list behind the drawer, where one is held. */
async function refreshList() {
    if (workspace?.hasList()) await load();
}

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

/* -------------------------------------------------------------------------
 | Filters
 | ---------------------------------------------------------------------- */

function clearFilters() {
    Object.assign(state, {
        search: '', kind: '', payment: '', status: '', outstanding: false, from: '', to: '',
    });

    listEl('[data-filter-search]').value = '';
    listEl('[data-filter-kind]').value = '';
    listEl('[data-filter-payment]').value = '';
    listEl('[data-filter-status]').value = '';
    listEl('[data-filter-from]').value = '';
    listEl('[data-filter-to]').value = '';
    listEl('[data-filter-outstanding]').setAttribute('aria-pressed', 'false');

    refetch();
}

function bindFilters() {
    listEl('[data-filter-search]').addEventListener('input', (event) => {
        state.search = event.target.value.trim();
        refetch();
    });

    const select = (hook, key) => listEl(hook).addEventListener('change', (event) => {
        state[key] = event.target.value;
        refetch();
    });

    select('[data-filter-kind]', 'kind');
    select('[data-filter-payment]', 'payment');
    select('[data-filter-status]', 'status');
    select('[data-filter-from]', 'from');
    select('[data-filter-to]', 'to');

    listEl('[data-filter-outstanding]').addEventListener('click', (event) => {
        state.outstanding = !state.outstanding;
        event.currentTarget.setAttribute('aria-pressed', String(state.outstanding));
        refetch();
    });

    listEl('[data-clear-filters]').addEventListener('click', clearFilters);

    listEl('[data-page-prev]').addEventListener('click', async () => {
        if (state.page > 1) {
            state.page -= 1;
            await load();
        }
    });

    listEl('[data-page-next]').addEventListener('click', async () => {
        state.page += 1;
        await load();
    });
}

/* -------------------------------------------------------------------------
 | Events that reach the drawer
 | ---------------------------------------------------------------------- */

function bindDrawer() {
    const open = (row) => openDrawer(row.dataset.sale);

    listEl('[data-sales-body]').addEventListener('click', (event) => {
        const row = event.target.closest('[data-sale]');

        if (row) open(row);
    });

    // A row is `role="link"`, so it has to answer the keyboard like one.
    listEl('[data-sales-body]').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-sale]');

        if (!row) return;

        event.preventDefault();
        open(row);
    });

    // One delegated listener rather than one per painted button: the footer is
    // repainted on every mode change, and listeners bound to buttons that no
    // longer exist are the classic leak in a surface like this.
    el('[data-drawer-actions]').addEventListener('click', (event) => {
        const hit = (hook) => event.target.closest(`[${hook}]`);

        if (hit('data-drawer-pay')) {
            drawer.mode = 'pay';
            paint();
        } else if (hit('data-drawer-return')) {
            drawer.mode = 'return';
            paint();
        } else if (hit('data-drawer-back')) {
            drawer.mode = 'view';
            paint();
        } else if (hit('data-drawer-submit')) {
            if (drawer.mode === 'pay') submitReceipt();
            else submitReturn();
        } else if (hit('data-drawer-post')) {
            postDraft();
        } else if (hit('data-drawer-discard')) {
            discardDraft();
        } else if (hit('data-drawer-reverse')) {
            reverseDocument();
        } else if (hit('data-drawer-print')) {
            printInvoice();
        } else if (hit('data-drawer-share')) {
            openShare();
        } else if (hit('data-drawer-correct')) {
            correctDocument();
        } else if (hit('data-drawer-repeat')) {
            repeatDocument();
        }
    });

    // The share dialog's own controls, delegated for the same reason: the panel
    // is repainted whenever the link is created or ended.
    $('#sales-share-modal', root).addEventListener('click', (event) => {
        const hit = (hook) => event.target.closest(`[${hook}]`);

        if (hit('data-share-create')) createLink();
        else if (hit('data-share-revoke')) revokeLink();
        else if (hit('data-share-copy')) copyLink();
    });

    // Who did the work — M22. Delegated on the drawer body, which is repainted
    // on every state change, so the control cannot be bound directly.
    el('[data-drawer-body]').addEventListener('click', (event) => {
        if (event.target.closest('[data-staff-edit]')) openStaffEditor();
    });

    $('[data-staff-save]', root).addEventListener('click', saveStaffEditor);
}

/* -------------------------------------------------------------------------
 | Who did the work — M22
 | ---------------------------------------------------------------------- */

/**
 * The correction dialog — level 3, over the drawer.
 *
 * The slots come from `/transactions/meta`, which the sale form has already
 * fetched — so this reads them off the mounted document rather than asking
 * again. A workshop that records no trades has no Change control to press in the
 * first place, because `attributionPanel` paints none.
 */
function openStaffEditor() {
    const bill = drawer.bill;

    $('[data-staff-subtitle]', root).textContent =
        `${bill.type_label} ${bill.doc_no ?? `#${bill.id}`}`;

    drawer.staffEditor = mountStaffAttribution($('[data-staff-edit-host]', root), {
        slots: doc?.staffSlots?.() ?? [],
        value: bill.staff ?? [],
        // Nothing autosaves here. A correction is one short decision behind a
        // Save, not a document being written over an afternoon.
        onChange: () => {},
        hint: '',
    });

    showModal('#sales-staff-modal');
}

/**
 * Send the correction, and repaint what is on screen from the answer.
 *
 * The response carries the whole transaction back with its attribution attached,
 * so the drawer is repainted from the server's copy rather than from what the
 * dialog was holding — the same rule §3.2 states for every other save here. No
 * refetch, and certainly no reload.
 */
async function saveStaffEditor() {
    if (drawer.busy || !drawer.staffEditor) return;

    const button = $('[data-staff-save]', root);

    drawer.busy = true;
    button.disabled = true;
    button.textContent = 'Saving…';

    try {
        const { data, message } = await auth.call(`/transactions/${drawer.id}/staff`, {
            method: 'PATCH',
            body: { staff: drawer.staffEditor.value() },
        });

        drawer.bill = { ...drawer.bill, staff: data.staff ?? [] };

        hideModal('#sales-staff-modal');
        toast(message ?? 'Updated who did the work.');

        // Only the reading view can show this panel, and it is the view that is
        // open — the Change control is painted nowhere else.
        paintView();
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        drawer.busy = false;
        button.disabled = false;
        button.textContent = 'Save';
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initSales() {
    /*
    | By the key the shell stamped on the mounted root, rather than by finding a
    | `[data-ws-form]` and climbing to it. Both work today — the shell keeps at
    | most one module root attached — but only this one keeps working if that
    | ever stops being true, and a module that reached into another module's
    | form would bill the wrong document.
    */
    root = $('[data-module-root="sales"]');

    // Before `mountWorkspace`, which is what takes both surfaces out of the
    // document. After it, this lookup would find nothing.
    listRoot = $('[data-ws-list]', root);

    /*
    | The document first, the workspace second — and the order matters. Mounting
    | the workspace is what *detaches* whichever of the two surfaces is not in
    | use, and the engine binds to nodes inside the form. Listeners survive the
    | detachment, because they belong to the elements rather than to the
    | document; a querySelector against a detached tree does not.
    */
    /*
    | Before the document engine, and it has to be: the engine calls `onRestore`
    | during its own mount, and a correction restored from an abandoned draft has
    | to find its banner already wired. It takes `doc` as a getter for the same
    | reason — the engine does not exist yet at this line.
    */
    revision = mountBillRevision(root, {
        doc: () => doc,
        workspace: () => workspace,
        direction: 'sale',
        nouns: { document: 'invoice' },
    });

    doc = await mountBillDocument($('[data-bill-document]', root), {
        key: 'sales',
        direction: 'sale',

        // Where a correction goes. Returns null for an ordinary new invoice,
        // which is every document this form writes except the one started from
        // a posted invoice's Correct.
        submitWith: revision.submit,

        extraState: revision.state,
        onRestore: revision.restore,

        /*
        | §2A.8 — a successful post stays on the form, clears it for the next
        | sale and returns focus to the customer. The new row is flagged rather
        | than shown: a counter that writes six invoices in a row never sees the
        | list in between, so the flash happens whenever they do look.
        */
        onPosted: (response) => {
            const created = response.data;

            if (created?.id) workspace?.flagNew(created.id);

            // A correction that posted is over. Cleared before the reset, so the
            // banner cannot survive onto the next blank document and claim an
            // invoice is being corrected when none is.
            revision.finish();

            doc.reset();
            doc.party().focus();

            // Only where a list is actually held. §2A.7 is that it is fetched on
            // the first Show and not before, so a counter that only ever writes
            // invoices must not be made to pay for one by posting.
            if (workspace?.hasList()) refetch();
        },
    });

    bindFilters();
    bindDrawer();

    // Bound once, on a node the workspace only ever detaches and re-attaches —
    // listeners belong to the element, so they survive the round trip (§2A.6).
    $('[data-revise-cancel]', root).addEventListener('click', () => revision.cancel());

    /*
    | Discarding the draft drops the correction with it. The engine binds its own
    | `reset` to this button and ran first, so by now the form is already empty —
    | all that is left is to take the banner down, which otherwise sits over a
    | blank document claiming an invoice is being corrected.
    */
    $('[data-discard-draft]', root).addEventListener('click', () => {
        if (revision.isActive()) revision.cancel();
    });

    const canWrite = can('WRITE', 'TRANSACTIONS');

    workspace = mountWorkspace(root, {
        key: 'sales',
        title: 'Sales',
        formSubtitle: 'Raise an invoice. The stock leaves the shelf and the ledger follows, in one posting.',
        listSubtitle: (count) => (count === null
            ? 'Invoices raised, and what is still owed on each.'
            : `${count} document${count === 1 ? '' : 's'}, newest first.`),
        createLabel: 'New invoice',
        count: () => state.total,
        canCreate: canWrite,
        onShowList: load,

        // §2A.8 — back on the form, the customer is where the next sale starts.
        onShowForm: () => doc.party().focus(),
    });
}
