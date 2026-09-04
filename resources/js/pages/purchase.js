import auth from '../auth-client';
import { badge, formatQuantity, kindBadge, lifecycleTone } from '../components/badge';
import { mountBillDocument } from '../components/bill-document';
import { mountBillRevision } from '../components/bill-revision';
import { mountPaymentRows } from '../components/payment-rows';
import { describeShortfalls } from '../components/stock-position';
import { can } from '../permissions';
import {
    $, $$, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Purchase — the §2A module for what the workshop buys in.
 *
 * ```
 * card → PURCHASE BILL FORM        ← always lands here (§2A.5)
 *      → "Show list (42)"          → the bills and the debit notes
 * ```
 *
 * Almost nothing here is new. The form is `components/bill-document.js`, which
 * the counter at /bills/new raises the identical document through; the flow is
 * `workspace.js`; the counterparty form, the item picker and the payment rows
 * are the shared components. What this file contributes is the three decisions
 * that are the module's own — where it lands, what its list asks for, and what
 * happens after a bill posts.
 *
 * ## Why a posted bill stays on the form
 *
 * §2A.8, and it is the difference between this and the counter. The counter
 * exists to write one bill and hands the operator to the list afterwards. A
 * clerk working through the morning's deliveries writes six in a row, and being
 * thrown to a list after each one would mean six trips back. So the document is
 * emptied for the next entry, focus returns to the supplier, and the new row is
 * flagged so it is highlighted whenever the list is next looked at.
 *
 * ## Why debit notes are on this list
 *
 * "What did we buy from them" and "what did we send back" are one question asked
 * of one supplier. Two lists would have to be reconciled by hand, and the second
 * one is always the one nobody opens.
 */

const PAGE_SIZE = 25;

/** The document kinds this module is about. A sale here would be a surprise. */
const KINDS = ['purchase', 'purchase_return'];

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

/*
| The list surface's own node, held from mount.
|
| §2A.2 keeps exactly one of the form and the list attached, so for half the
| module's life `[data-purchase-body]` is not a descendant of `root` at all — and
| every lookup for it comes back null. That is not a rare state: §2A.8 keeps the
| clerk on the *form* after a post, which is precisely when `onPosted` wants to
| bring the list up to date.
|
| Querying a node works whether or not it is in the document, so the list is
| scoped to itself. Scoping it to `root` throws on the first `.innerHTML`, aborts
| the refetch behind it, and leaves the table showing its pre-purchase rows —
| which is exactly what it was doing.
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
    listEl('[data-purchase-body]').innerHTML = tableMessage(8, 'Loading…');

    try {
        const payload = await auth.call(`/transactions?${query()}`);

        render(payload.data, payload.meta);
    } catch (error) {
        state.total = null;

        listEl('[data-purchase-body]').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(8, 'Your account administers the platform rather than a single workshop, '
                + 'so it has no purchases of its own.')
            : tableMessage(8, error.message, 'error');
    }
}

/** Refetch from page one — what every filter change means. */
const refetch = debounce(async () => {
    state.page = 1;
    await load();
}, 250);

function render(rows, meta) {
    const body = listEl('[data-purchase-body]');

    body.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : tableMessage(8, 'Nothing here yet. A purchase bill will appear the moment one is recorded.');

    const pagination = meta?.pagination ?? {};

    state.total = pagination.total ?? null;

    listEl('[data-purchase-summary]').textContent = pagination.total
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
 * posted bill — a draft, a reversed one. That is deliberate on the server's side
 * and honoured here: a dash says the question does not apply, where a zero would
 * say "nothing has been paid" and invite somebody to chase it.
 */
function renderRow(row) {
    const settled = row.payment_status !== undefined;

    // §2A.8 — a bill written while the list was detached carries the flash with
    // it, so the eye finds it the first time somebody does look. The workspace
    // spends the flag when the list actually reaches the screen, not here.
    const flash = workspace?.isNew(row.id) ? ' row-new' : '';

    /*
    | A record from before the PUR/YY-YY/NNNN scheme has no document number and
    | never will. A bare dash said it had no identity at all, while its own
    | drawer had been calling it "#11" the whole time — so the internal id is
    | shown instead, muted, so it still reads as the fallback it is.
    */
    const docLabel = row.doc_no ?? `#${row.id}`;

    /*
    | The document's own rows, not the ledger's.
    |
    | `line_count` counts postings — a single item bought at 18% is three of
    | them, Dr Inventory / Dr GST Input / Cr Payables — and this column, headed
    | "Lines", had been printing that. Every single-item purchase read 2 or 3
    | against a detail view showing one row.
    */
    const itemLines = row.item_line_count === null ? '—' : String(row.item_line_count);

    return `
        <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
            data-purchase="${row.id}" tabindex="0" role="link"
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
 |
 | Three states in one surface: read the bill, pay it, or send some of it back.
 | Stacking a form on a drawer would be level 3 doing level 2's job, which §2.2
 | says to redesign as a step-based flow — so the body swaps and the footer
 | swaps with it.
 | ---------------------------------------------------------------------- */

const drawer = {
    id: null,
    /** `view`, `pay` or `return`. */
    mode: 'view',
    bill: null,
    meta: null,
    returnable: null,
    payments: null,
    // Minted per debit note being written and reused on every retry of it — a
    // duplicate return puts stock back on the shelf twice, which is the one
    // idempotency failure here that corrupts the ledger rather than the record.
    returnRef: null,
    busy: false,
};

/**
 * Correcting a posted bill, and starting a new one from an old one.
 *
 * `components/bill-revision.js` — the same component Sales mounts, because the
 * two modules do exactly this and the parts that go wrong in a second copy are
 * not the obvious ones. See its docblock.
 */
let revision = null;

async function openDrawer(id) {
    drawer.id = id;
    drawer.mode = 'view';
    drawer.bill = null;
    drawer.meta = null;
    drawer.returnable = null;
    // Per document, not per session. Carrying one bill's reference to the next
    // would make a return on B come back as the debit note already raised on A.
    drawer.returnRef = null;

    el('[data-drawer-subtitle]').textContent = '';
    el('[data-drawer-status]').innerHTML = '';
    el('[data-drawer-alert]').classList.add('hidden');
    el('[data-drawer-actions]').innerHTML = '';
    $('#purchase-drawer-title', root).textContent = 'Loading…';
    el('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    showModal('#purchase-drawer');

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

    $('#purchase-drawer-title', root).textContent =
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
 * Recomputed every time the bill is opened rather than only when it was posted,
 * because "why is the shelf negative" is asked long after the toast has gone.
 */
function paintAlert() {
    const warnings = drawer.mode === 'view' ? (drawer.meta?.warnings ?? []) : [];

    el('[data-drawer-alert]').classList.toggle('hidden', warnings.length === 0);
    el('[data-drawer-alert]').classList.toggle('flex', warnings.length > 0);

    el('[data-drawer-alert-text]').innerHTML =
        warnings.map((warning) => `<p>${esc(warning.message)}</p>`).join('');
}

/* --- read ------------------------------------------------------------- */

function paintView() {
    const bill = drawer.bill;
    const tax = drawer.meta?.tax ?? {};
    const isDraft = bill.status === 'draft';

    el('[data-drawer-body]').innerHTML = `
        ${settlementPanel(bill)}

        ${isDraft ? `
            <p class="mb-4 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5 text-[0.8125rem]
                      text-muted-foreground">
                A draft. Nothing has reached the ledger and nothing has moved on the shelf, so the tax and
                the totals below are blank — they are worked out against the item's rate and the two state
                codes at the moment it is posted, not now.
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
                        ${tax.inter_state ? 'IGST' : 'CGST + SGST'} — claimable as input
                    </dt>
                    <dd class="font-mono">${esc(formatMoney(tax.tax ?? null))}</dd>
                </div>
                <div class="flex justify-between text-base font-bold text-foreground">
                    <dt>Total</dt>
                    <dd class="font-mono">${isDraft ? '—' : `₹${esc(formatMoney(bill.total))}`}</dd>
                </div>
            </dl>

            ${isDraft ? '' : `
                <p class="mt-3 text-xs text-muted-foreground">
                    Stock arrived at the taxable value, not the total — the GST above is claimable, so it is
                    not part of what the goods cost. That is the figure the weighted average moved on.
                </p>`}`
        : `<p class="py-6 text-sm text-muted-foreground">
               This document has no item lines.
           </p>`}`;

    paintActions();
}

/** What is owed on this one, at the top where somebody looking for it will find it. */
function settlementPanel(bill) {
    if (bill.payment_status === undefined) return '';

    return `
        <div class="mb-4 flex flex-wrap items-center gap-4 rounded-[10px] bg-secondary/50 px-3.5 py-2.5
                    text-[0.8125rem]">
            <span class="text-muted-foreground">Paid
                <span class="font-mono text-foreground">${esc(formatMoney(bill.paid))}</span></span>
            <span class="text-muted-foreground">Due
                <span class="font-mono font-semibold text-foreground">${esc(formatMoney(bill.due))}</span></span>
            ${bill.credited && bill.credited !== '0.00'
                ? `<span class="text-muted-foreground">Sent back
                       <span class="font-mono text-foreground">${esc(formatMoney(bill.credited))}</span></span>`
                : ''}
            ${bill.due_date
                ? `<span class="text-muted-foreground">Due by ${esc(formatDate(bill.due_date))}</span>`
                : ''}
        </div>`;
}

/* --- pay -------------------------------------------------------------- */

function paintPay() {
    const bill = drawer.bill;

    el('[data-drawer-body]').innerHTML = `
        <p class="mb-4 text-[0.8125rem] text-muted-foreground">
            Paying <strong class="text-foreground">${esc(bill.party?.name ?? '')}</strong> against
            ${esc(bill.doc_no ?? `#${bill.id}`)}. The payment is pointed at this bill, so what is owed on it
            moves rather than only the supplier's overall balance.
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
        // Pre-fills "paid in full" with what is actually left on this bill,
        // rather than its total — a part-paid invoice is the common case.
        outstanding: () => bill.due ?? '0.00',
        heading: 'Paid now',
        // A vendor bill. What is left sits on their account, not a customer's.
        noun: 'vendor',
        verb: 'paid',
    });

    paintActions();
}

async function submitPayment() {
    const bill = drawer.bill;
    const split = drawer.payments.value();

    if (split.length === 0) {
        toast('Say how the money moved — cash, bank, UPI or cheque.', 'error');

        return;
    }

    await run('[data-drawer-submit]', 'Paying…', async () => {
        const response = await auth.call('/transactions/payment', {
            method: 'POST',
            body: {
                date: el('[data-pay-date]').value,
                post: true,
                party_id: bill.party?.id,
                payments: split,
                // Named explicitly rather than left to the oldest-first default:
                // somebody paying from this bill's own screen means this bill.
                allocations: [{ bill_transaction_id: bill.id }],
            },
        });

        toast(response.message ?? 'Payment recorded.');

        drawer.mode = 'view';

        await loadDocument();
        await refreshList();
    });
}

/* --- return ----------------------------------------------------------- */

async function paintReturn() {
    el('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    paintActions();

    try {
        const { data, meta } = await auth.call(`/transactions/${drawer.id}/returnable`);

        drawer.returnable = data;

        const notes = meta?.returns ?? [];

        el('[data-drawer-body]').innerHTML = `
            <p class="mb-4 text-[0.8125rem] text-muted-foreground">
                How many of each came back. A debit note credits what was actually charged and takes the
                stock off the shelf at what it arrived at — so the pair nets to nothing in Inventory even
                though the average has moved since.
            </p>

            ${notes.length ? `
                <div class="mb-4 rounded-[10px] border border-border bg-secondary/40 px-3.5 py-2.5
                            text-[0.8125rem]">
                    <p class="font-semibold text-foreground">Already sent back</p>
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
                            <th class="py-2 text-right font-semibold">Billed</th>
                            <th class="py-2 text-right font-semibold">Left</th>
                            <th class="py-2 text-right font-semibold">Send back</th>
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
                                           aria-label="Quantity of ${esc(line.description ?? '')} to send back"
                                           ${Number(line.remaining) > 0 ? '' : 'disabled'}>
                                </td>
                            </tr>`).join('')}
                    </tbody>
                </table>

                <p class="field-error hidden" data-error-for="lines"></p>`
            : `<p class="py-6 text-sm text-muted-foreground">
                   Nothing on this bill is still returnable — every line has already come back.
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
        toast('Say which lines are coming back, and how many of each.', 'error');

        return;
    }

    // Checked here as well as server-side, so the commonest mistake is explained
    // before a round trip. The service is still the authority on what is left.
    const over = $$('[data-return-line]', root).find((row) => {
        const input = $('[data-return-qty]', row);

        return input.value.trim() !== '' && Number(input.value) > Number(input.dataset.max);
    });

    if (over) {
        toast('One line is sending back more than was bought. Check the "Left" column.', 'error');

        return;
    }

    await run('[data-drawer-submit]', 'Sending back…', async () => {
        const response = await auth.call(`/transactions/${drawer.id}/return`, {
            method: 'POST',
            body: {
                lines,
                client_ref: drawer.returnRef ??= crypto.randomUUID(),
            },
        });

        toast(response.message ?? 'Debit note raised.');

        drawer.returnRef = null;
        drawer.mode = 'view';

        await loadDocument();
        await refreshList();
    });
}

/* --- correcting a bill that is already in the books -------------------- */

/**
 * Edit a posted purchase — §F5, and the one action on this drawer that does not
 * exist on a ledger anywhere else in the application.
 *
 * The whole of it is `components/bill-revision.js`, which Sales mounts as well.
 * What is left here is closing the drawer, because only this module knows which
 * drawer that is.
 */
async function correctDocument() {
    if (await revision.begin(drawer.bill)) hideModal('#purchase-drawer');
}

/* --- the acts that cannot be undone ----------------------------------- */

async function reverseDocument() {
    const bill = drawer.bill;

    const ok = await confirmAction({
        title: `Reverse ${bill.doc_no ?? `#${bill.id}`}?`,
        body: 'A mirroring entry is posted and the stock comes back off the shelf. Both documents stay on '
            + 'the record — nothing is erased. If only part of the delivery is going back, send a debit '
            + 'note instead.',
        confirmLabel: 'Reverse it',
    });

    if (!ok) return;

    await run(null, null, async () => {
        try {
            await postReversal(false);
        } catch (error) {
            /*
            | The server refused because some of what this bill brought in has
            | already left by another route, so taking all of it back would post
            | a negative shelf. That is a decision, not a failure — sometimes the
            | bill really was entered against the wrong supplier and the negative
            | is the honest intermediate state — so it is put to the user rather
            | than swallowed or forced.
            |
            | Everything else rethrows to `run`, which toasts it. A blanket catch
            | here would turn a permission error into "do you want negative
            | stock?", which is a question about the wrong thing.
            */
            if (error.code !== 'REVERSAL_WOULD_GO_NEGATIVE') throw error;

            const accepted = await confirmAction({
                title: 'This will take stock below zero',
                body: `${describeShortfalls(error.details?.shortfalls ?? [])} Reversing anyway leaves the `
                    + 'shelf showing a negative, which stays wrong until a stock count corrects it. '
                    + 'Send a debit note for what is still here instead, unless you know this bill '
                    + 'should never have been entered at all.',
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

async function postDraft() {
    await run('[data-drawer-post]', 'Posting…', async () => {
        const response = await auth.call(`/transactions/${drawer.id}/post`, { method: 'POST' });

        toast(response.message ?? 'Transaction posted.');

        await loadDocument();
        await refreshList();
    });
}

async function discardDraft() {
    const ok = await confirmAction({
        title: 'Discard this draft?',
        body: 'It has never reached the ledger and nothing has moved on the shelf, so there is nothing to '
            + 'reverse — it simply goes.',
        confirmLabel: 'Discard',
    });

    if (!ok) return;

    await run(null, null, async () => {
        await auth.call(`/transactions/${drawer.id}`, { method: 'DELETE' });

        toast('Draft discarded.');

        hideModal('#purchase-drawer');
        await refreshList();
    });
}

/* --- the footer ------------------------------------------------------- */

function paintActions() {
    const bill = drawer.bill;
    const host = el('[data-drawer-actions]');

    if (drawer.mode !== 'view') {
        host.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" data-drawer-back>← Back to the bill</button>
            <button type="button" class="btn btn-primary btn-sm ml-auto" data-drawer-submit>
                ${drawer.mode === 'pay' ? 'Record the payment' : 'Raise the debit note'}
            </button>`;

        return;
    }

    const write = can('WRITE', 'TRANSACTIONS');
    const buttons = [];

    if (bill.status === 'draft') {
        if (can('UPDATE', 'TRANSACTIONS')) {
            buttons.push('<button type="button" class="btn btn-primary btn-sm" data-drawer-post>Post it</button>');
        }

        if (can('DELETE', 'TRANSACTIONS')) {
            buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-discard>Discard</button>');
        }
    } else if (bill.status === 'posted' && write) {
        /*
        | Only what this document can still have done to it. Offering an act that
        | would be refused teaches somebody the product is unreliable.
        |
        | Paying and returning belong to a purchase bill: there is nothing to pay
        | on a debit note, and nothing to send back off one. Reversing belongs to
        | both — a debit note raised against the wrong bill is as much a
        | mis-posting as the bill itself was, and reversal is the only way either
        | is corrected once it is in the books.
        */
        if (bill.type === 'purchase') {
            if (bill.due && Number(bill.due) > 0) {
                buttons.push('<button type="button" class="btn btn-primary btn-sm" data-drawer-pay>Record payment</button>');
            }

            /*
            | Correcting a bill is UPDATE as well as WRITE — it changes the
            | standing of a document that is already in the books, and somebody
            | trusted to raise a bill but not to alter one should not reach it by
            | the back door of raising two. The route asserts both; this only
            | decides whether the button is worth painting (§6.2).
            |
            | Hidden once anything has been paid against the bill or sent back on
            | a debit note, because either would be left pointing at a cancelled
            | document. The server refuses it both ways, and offering an act that
            | would be refused teaches somebody the product is unreliable.
            */
            const untouched = Number(bill.paid ?? 0) === 0 && Number(bill.credited ?? 0) === 0;

            if (can('UPDATE', 'TRANSACTIONS') && untouched) {
                buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-drawer-edit>Correct</button>');
            }

            buttons.push('<button type="button" class="btn btn-secondary btn-sm" data-drawer-return>Return to vendor</button>');
        }

        buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-reverse>Reverse</button>');
    }

    host.innerHTML = buttons.join('')
        + '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
}

/**
 * Run one drawer action, with the button that started it disabled while it goes.
 *
 * §3.4 — the user must never be left unsure whether something is processing, and
 * a second click on "Post it" while the first is in flight is how a workshop
 * ends up explaining two identical documents.
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
    const open = (row) => openDrawer(row.dataset.purchase);

    listEl('[data-purchase-body]').addEventListener('click', (event) => {
        const row = event.target.closest('[data-purchase]');

        if (row) open(row);
    });

    // A row is `role="link"`, so it has to answer the keyboard like one.
    listEl('[data-purchase-body]').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-purchase]');

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
            if (drawer.mode === 'pay') submitPayment();
            else submitReturn();
        } else if (hit('data-drawer-post')) {
            postDraft();
        } else if (hit('data-drawer-discard')) {
            discardDraft();
        } else if (hit('data-drawer-reverse')) {
            reverseDocument();
        } else if (hit('data-drawer-edit')) {
            correctDocument();
        }
    });
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initPurchase() {
    root = $('[data-ws-form]').closest('[data-module-root]');

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
        direction: 'purchase',
        nouns: { document: 'bill' },
    });

    doc = await mountBillDocument($('[data-bill-document]', root), {
        key: 'purchase',
        direction: 'purchase',

        // Where a correction goes. Returns null for an ordinary new bill, which
        // is every document this form writes except the one started from a
        // posted bill's Edit.
        submitWith: revision.submit,

        extraState: revision.state,
        onRestore: revision.restore,

        /*
        | §2A.8 — a successful post stays on the form, clears it for the next
        | entry and returns focus to the supplier. The new row is flagged rather
        | than shown: a clerk who writes six bills in a row never sees the list
        | in between, so the flash happens whenever they do look.
        */
        onPosted: (response) => {
            const created = response.data;

            if (created?.id) workspace?.flagNew(created.id);

            // A correction that posted is over. Cleared before the reset, so the
            // banner cannot survive onto the next blank document and claim a
            // bill is being corrected when none is.
            revision.finish();

            doc.reset();
            doc.party().focus();

            // Only where a list is actually held. §2A.7 is that it is fetched on
            // the first Show and not before, so a clerk who only ever writes
            // bills must not be made to pay for one by posting.
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
    | blank document claiming a bill is being corrected.
    */
    $('[data-discard-draft]', root).addEventListener('click', () => {
        if (revision.isActive()) revision.cancel();
    });

    const canWrite = can('WRITE', 'TRANSACTIONS');

    workspace = mountWorkspace(root, {
        key: 'purchase',
        title: 'Purchase',
        formSubtitle: 'Record a bill from a supplier. The stock arrives and the ledger follows, in one posting.',
        listSubtitle: (count) => (count === null
            ? 'Bills from suppliers, and what is still owed on each.'
            : `${count} document${count === 1 ? '' : 's'}, newest first.`),
        createLabel: 'New purchase bill',
        count: () => state.total,
        canCreate: canWrite,
        onShowList: load,

        // §2A.8 — back on the form, the supplier is where the next bill starts.
        onShowForm: () => doc.party().focus(),
    });
}
