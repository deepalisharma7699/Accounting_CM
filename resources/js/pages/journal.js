import auth from '../auth-client';
import { badge, lifecycleTone } from '../components/badge';
import { mountPartyPicker } from '../components/party-picker';
import { mountPaymentRows } from '../components/payment-rows';
import { can } from '../permissions';
import { clearModuleParams, moduleParams, registerEscape } from '../shell';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate, formatMoney,
    hideModal, isZeroAmount, setSubmitting, showFormErrors, showModal, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Transactions — settlement and the journal voucher. C3.
 *
 * ```
 * card → RECEIPT FORM                    ← always lands here (§2A.1, §2A.5)
 *      → tab → PAYMENT / JOURNAL VOUCHER ← each an ordinary §2A workspace
 *      → "Show list (18)"                → what has been taken, paid or posted
 *      → row → drawer (level 2)          → which bills it settles, or reverse
 *                                        → confirm (level 3)
 * ```
 *
 * ## Three sections, and the shared renderer used three times
 *
 * Receipt, Payment and Journal voucher are three write acts on one card, so this
 * follows **Staff** rather than inventing a shape: `mountWorkspace()` is called
 * once per section, on that section's own root, and each inherits the form/list
 * swap, the single switch control, the count badge and the Escape step. There is
 * no per-module flow code here, which is the rule §2A exists to state.
 *
 * Sections mount **lazily**, on the first click of their tab (§2.5, §7.2). A
 * workshop that only ever takes receipts never pays for the voucher grid's chart
 * of accounts, and opening the module fetches the payment modes and nothing
 * else.
 *
 * Each section's workspace registers Escape under a key of its own and the
 * module registers `journal`, because the shell asks for the module key — the
 * same reason Staff does it. Without that the last-mounted section would answer
 * for all three, and a press on the voucher list would swap the receipt form.
 *
 * ## Receipt and Payment are one implementation
 *
 * `settlementSection()` is a *factory*, called twice, each call closing over its
 * own state — the pattern `pages/counterparty.js` uses for Customers and
 * Vendors. Anything module-level in there would belong to whichever initialised
 * last, and the two lists would start reading each other's rows. The markup is
 * shared for the same reason: one `partials/settlement-section.blade.php`,
 * included twice with a direction.
 *
 * The direction decides the endpoint, the party's role and the wording. It
 * decides nothing about the payload, because the server does not either: two
 * routes over one `StoreSettlementRequest`.
 *
 * ## Which invoices the money settled — the drawer, and only the drawer
 *
 * `POST /transactions/{id}/allocate` and `GET /transactions/{id}/open-bills`
 * have existed and been tested since M16 with no caller anywhere in the front
 * end. This is the screen that answers them, and it is deliberately *after* the
 * fact: settling on the way in already exists and is better placed, because
 * Sales collects against the invoice its drawer is open on and Purchase pays a
 * bill the same way, both sending an explicit `allocations` entry.
 *
 * A receipt that names no bills is applied to the party's open ones oldest
 * first — what an accounts department does when nobody says otherwise. That is a
 * default and not an answer, which is why the form states what it did and the
 * drawer lets somebody change it. **Nothing may guess which invoice a cheque was
 * for**, which is also why Insights reports an unallocated receipt as a worklist
 * and refuses to net it away.
 *
 * Two things about the panel are load-bearing. It is drawn from `open-bills`
 * **and** the allocations already on the settlement, because `due` is net of
 * every allocation including this one's — so a bill this receipt has paid off in
 * full is not open any more and is missing from the picker unless the two are
 * merged. And an allocation **replaces** the whole set rather than adding to it,
 * so what is sent is every row that has an amount on it, not just the ones that
 * were touched.
 *
 * ## No drafts
 *
 * This was the last screen in the product that parked a transaction, and it does
 * not any more: every converted module posts outright. Money that has moved is a
 * fact, not a work in progress — the judgement M22 makes about a payroll run. A
 * draft that predates the conversion is still openable from the list and can be
 * posted or discarded from the drawer; nothing here creates one.
 */

const PAGE_SIZE = 25;

/** Receipt/payment columns, and the voucher's. Only the count is needed here. */
const SETTLEMENT_COLUMNS = 7;
const VOUCHER_COLUMNS = 6;

/*
| Held at mount, while everything is still in the document.
|
| §2A.2 keeps exactly one of each section's form and list attached, so a
| `document.querySelector` into the other finds nothing — which is precisely when
| a save wants to bring a list up to date. Querying a *node* works while it is
| detached, so every lookup below is scoped to whichever of these it belongs to.
*/
let root = null;

/** sectionKey -> { root, form, list, workspace, opened, mount } */
const sections = {};

let activeSection = 'receipt';

/** The ways money can move, from the server. Four readers, fetched once. */
let modes = [];

/** The chart, for the voucher's account pickers. Fetched when that tab opens. */
let accounts = [];

/** The drawer, which lives outside every section and is always attached. */
const el = (selector) => $(selector, root);

/* -------------------------------------------------------------------------
 | Money, in whole paise
 |
 | Amounts are decimal strings and stay that way. Adding them as floats is how a
 | form shows a total the ledger disagrees with, and this module has two places
 | that sum one — the voucher's debit and credit columns, and the allocation
 | panel's rows against what the receipt actually holds.
 | ---------------------------------------------------------------------- */

function toPaise(amount) {
    const raw = String(amount ?? '').trim();

    if (!/^-?\d+(\.\d{1,2})?$/.test(raw)) return 0;

    const negative = raw.startsWith('-');
    const [whole, fraction = ''] = raw.replace(/^-/, '').split('.');
    const value = Number(whole) * 100 + Number((fraction + '00').slice(0, 2));

    return negative ? -value : value;
}

function fromPaise(paise) {
    const sign = paise < 0 ? '-' : '';
    const absolute = Math.abs(paise);

    return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

/* -------------------------------------------------------------------------
 | Reference data
 | ---------------------------------------------------------------------- */

/**
 * The ways money can move, and what each one's reference is called.
 *
 * Fetched rather than hard-coded so a row asks for "Cheque number" — and knows
 * that a cheque needs one — without a second copy of the mapping drifting out of
 * step with the server's.
 */
async function loadModes() {
    if (modes.length) return;

    try {
        const { data } = await auth.call('/transactions/meta');

        modes = data.payment_modes ?? [];
    } catch {
        // Not fatal: the split rows paint an empty mode strip and the form says
        // so when it is submitted. A banner over a screen whose only broken part
        // is one picker would be louder than the fact deserves.
        modes = [];
    }
}

/**
 * The chart of accounts, once, and only for the section that needs it.
 *
 * Archived accounts are excluded because the engine refuses to post to them —
 * offering one would only produce a 422 the operator could not have predicted.
 */
async function loadAccounts() {
    if (accounts.length) return;

    const { data } = await auth.call('/accounts?per_page=200&is_active=1&sort=code');

    accounts = data;
}

/* -------------------------------------------------------------------------
 | The rows every list shares
 | ---------------------------------------------------------------------- */

/**
 * How the money moved, from the split the listing already carries.
 *
 * Deduplicated by mode rather than listed row by row: "two thousand cash and the
 * rest by UPI" is one answer to the question this column asks, and the amounts
 * behind it are on the document.
 */
function paidBy(row) {
    const named = [...new Set((row.payments ?? []).map((split) => split.mode_label).filter(Boolean))];

    return named.length ? named.join(', ') : '—';
}

/** The document's own number, or the handle the rest of the system uses. */
function reference(row) {
    const label = row.doc_no ?? `#${row.id}`;

    return `
        <span class="block font-mono text-[0.8125rem] font-medium ${
            row.doc_no ? 'text-foreground' : 'text-muted-foreground'
        }">${esc(label)}</span>
        ${row.reverses_id
            ? `<span class="text-xs text-muted-foreground">reverses #${esc(String(row.reverses_id))}</span>`
            : ''}`;
}

function documentLabel(row) {
    return row.doc_no ?? `#${row.id}`;
}

/* -------------------------------------------------------------------------
 | One document — level 2
 |
 | One drawer for all three sections rather than one each (§5.1). What it offers
 | depends on the document: a posted receipt or payment can be re-pointed at the
 | bills it settles, anything posted can be reversed, and a draft — which nothing
 | here creates any more — can be posted or discarded.
 | ---------------------------------------------------------------------- */

const drawer = {
    id: null,
    txn: null,

    /** The merged allocation picture, or null while it is being fetched. */
    allocation: null,

    /** Whose list to refresh after a write, so the row it came from is current. */
    section: null,
};

async function openDrawer(id, section) {
    drawer.id = id;
    drawer.txn = null;
    drawer.allocation = null;
    drawer.section = section;

    $('#txn-drawer-title', root).textContent = 'Loading…';
    el('[data-drawer-subtitle]').textContent = '';
    el('[data-drawer-status]').innerHTML = '';
    el('[data-drawer-actions]').innerHTML = '';
    el('[data-drawer-body]').innerHTML =
        '<p class="py-8 text-center text-sm text-muted-foreground">Loading…</p>';

    showModal('#txn-drawer');

    await loadDocument();
}

async function loadDocument() {
    try {
        const { data } = await auth.call(`/transactions/${drawer.id}`);

        drawer.txn = data;

        paintDrawer();

        // Not awaited: the document is what the drawer is for, and the
        // allocation panel fills in underneath a moment later.
        if (isSettleable(data)) loadAllocation();
    } catch (error) {
        el('[data-drawer-body]').innerHTML =
            `<p class="py-8 text-center text-sm text-rose-600">${esc(error.message)}</p>`;
    }
}

/** Only a posted receipt or payment has bills to be pointed at. */
function isSettleable(txn) {
    return (txn.type === 'receipt' || txn.type === 'payment') && txn.status === 'posted';
}

function paintDrawer() {
    const txn = drawer.txn;

    $('#txn-drawer-title', root).textContent = documentLabel(txn);

    el('[data-drawer-subtitle]').textContent = [
        txn.type_label,
        formatDate(txn.date),
        txn.party?.name,
    ].filter(Boolean).join(' · ');

    el('[data-drawer-status]').innerHTML = badge(txn.status_label, lifecycleTone(txn.status));
    el('[data-drawer-body]').innerHTML = renderDocument(txn);

    paintDrawerActions();
}

function renderDocument(txn) {
    return `
        <div class="mb-4 flex items-baseline justify-between gap-4">
            <span class="text-[0.8125rem] text-muted-foreground">
                ${txn.type === 'journal' ? 'Voucher total' : 'Amount'}
            </span>
            <span class="font-mono text-lg font-bold text-foreground">${esc(formatMoney(txn.total))}</span>
        </div>

        ${txn.notes
            ? `<p class="mb-4 text-[0.8125rem] text-secondary-foreground">${esc(txn.notes)}</p>`
            : ''}

        ${renderSplit(txn)}

        ${isSettleable(txn)
            ? '<div class="mb-5" data-allocation-panel>'
                + '<p class="py-4 text-center text-[0.8125rem] text-muted-foreground">'
                + 'Reading what is still open…</p></div>'
            : ''}

        ${renderEntries(txn)}

        ${renderReversalLink(txn)}

        <p class="mt-4 text-xs text-muted-foreground">
            Recorded${txn.created_by ? ` by ${esc(txn.created_by)}` : ''}${
                txn.posted_at ? ` on ${esc(formatDate(txn.posted_at.slice(0, 10)))}` : ''
            }.
        </p>`;
}

/**
 * The modes and references behind the money — the part of a settlement the
 * ledger structurally cannot express, since a cheque and a transfer both land on
 * the Bank account.
 */
function renderSplit(txn) {
    const split = txn.payments ?? [];

    if (!split.length) return '';

    const rows = split.map((payment) => `
        <tr class="border-t border-border">
            <td class="px-2 py-2 text-[0.8125rem]">
                <span class="font-medium">${esc(payment.mode_label ?? payment.mode)}</span>
                ${payment.reference
                    ? `<span class="mt-0.5 block font-mono text-[0.6875rem] text-muted-foreground">${esc(payment.reference)}</span>`
                    : ''}
            </td>
            <td class="px-2 py-2 text-right font-mono text-[0.8125rem] font-medium">
                ${esc(formatMoney(payment.amount))}
            </td>
        </tr>`).join('');

    return `
        <div class="mb-5">
            <h4 class="section-label mb-2">How the money moved</h4>
            <div class="overflow-hidden rounded-[10px] border border-border">
                <table class="w-full border-collapse"><tbody>${rows}</tbody></table>
            </div>
        </div>`;
}

/** What it did to the books — the entries themselves, which is the whole of a
 *  voucher and the confirmation on a settlement. */
function renderEntries(txn) {
    const lines = (txn.lines ?? []).map((line) => `
        <tr class="border-t border-border">
            <td class="px-2 py-2 text-[0.8125rem]">
                <span class="font-mono text-[0.6875rem] text-muted-foreground">${esc(line.account?.code ?? '')}</span>
                <span class="ml-1.5">${esc(line.account?.name ?? `Account ${line.account_id}`)}</span>
                ${line.memo ? `<span class="mt-0.5 block text-xs text-muted-foreground">${esc(line.memo)}</span>` : ''}
            </td>
            <td class="px-2 py-2 text-right font-mono text-[0.8125rem]">
                ${isZeroAmount(line.debit) ? '' : esc(formatMoney(line.debit))}
            </td>
            <td class="px-2 py-2 text-right font-mono text-[0.8125rem]">
                ${isZeroAmount(line.credit) ? '' : esc(formatMoney(line.credit))}
            </td>
        </tr>`).join('');

    if (!lines) return '';

    return `
        <div class="mb-1">
            <h4 class="section-label mb-2">What it did to the books</h4>
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
            </table>
        </div>`;
}

/** Which way a reversal points. Neither end is derivable from the other. */
function renderReversalLink(txn) {
    if (txn.reverses_id) {
        return `
            <p class="mt-4 rounded-[10px] border border-border bg-muted/40 px-3 py-2 text-[0.8125rem]
                      text-muted-foreground">
                This is the reversing entry for #${esc(String(txn.reverses_id))}.
            </p>`;
    }

    if (txn.reversal_id) {
        return `
            <p class="mt-4 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-[0.8125rem]
                      text-amber-900">
                Reversed by #${esc(String(txn.reversal_id))}. Both documents stay on the record.
            </p>`;
    }

    return '';
}

function paintDrawerActions() {
    const txn = drawer.txn;
    const buttons = [];

    /*
    | Only what this document can still have done to it. Offering an act that
    | would be refused teaches somebody the product is unreliable, and none of
    | these becomes available later — so nothing is shown greyed out.
    */
    if (txn.is_draft) {
        if (can('UPDATE', 'TRANSACTIONS')) {
            buttons.push('<button type="button" class="btn btn-primary btn-sm" data-drawer-post>Post it</button>');
        }

        if (can('DELETE', 'TRANSACTIONS')) {
            buttons.push('<button type="button" class="btn btn-ghost btn-sm text-rose-600" data-drawer-discard>'
                + 'Discard draft</button>');
        }
    } else if (txn.status === 'posted' && can('WRITE', 'TRANSACTIONS')) {
        buttons.push('<button type="button" class="btn btn-ghost btn-sm" data-drawer-reverse>Reverse</button>');
    }

    el('[data-drawer-actions]').innerHTML = buttons.join('')
        + '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
}

/* -------------------------------------------------------------------------
 | Which bills this money settles — M16's screen
 | ---------------------------------------------------------------------- */

/**
 * What this settlement could be pointed at, and what it is pointed at now.
 *
 * Both, merged, and that is the part that is wrong in a way that looks right:
 * `due` on an open bill is net of *every* allocation including this
 * settlement's own, so a bill this receipt has already paid off in full is not
 * open any more and never appears in `data`. A picker built from that alone
 * would silently drop the rows the operator is most likely to want to change.
 *
 * Merged, a row's ceiling is what is still owing plus whatever this settlement
 * is currently holding against it.
 */
async function loadAllocation() {
    try {
        const payload = await auth.call(`/transactions/${drawer.id}/open-bills`);

        const rows = new Map();

        (payload.data ?? []).forEach((bill) => {
            rows.set(String(bill.id), {
                id: bill.id,
                doc_no: bill.doc_no,
                date: bill.date,
                due: toPaise(bill.due),
                allocated: 0,
            });
        });

        (payload.meta?.bills ?? []).forEach((bill) => {
            const held = rows.get(String(bill.id)) ?? {
                id: bill.id,
                doc_no: bill.doc_no,
                date: bill.date,
                due: 0,
                allocated: 0,
            };

            held.allocated = toPaise(bill.amount);
            rows.set(String(bill.id), held);
        });

        drawer.allocation = {
            rows: [...rows.values()].sort((a, b) => String(a.date).localeCompare(String(b.date))),
            unallocated: toPaise(payload.meta?.unallocated ?? '0'),
        };

        paintAllocation();
    } catch (error) {
        const panel = el('[data-allocation-panel]');

        if (panel) {
            panel.innerHTML = `<p class="py-3 text-center text-[0.8125rem] text-rose-600">${esc(error.message)}</p>`;
        }
    }
}

function paintAllocation() {
    const panel = el('[data-allocation-panel]');

    if (!panel || !drawer.allocation) return;

    const { rows } = drawer.allocation;
    const total = toPaise(drawer.txn.total);
    const editable = can('UPDATE', 'TRANSACTIONS');
    const noun = drawer.txn.type === 'receipt' ? 'invoice' : 'bill';

    /*
    | Nothing open and nothing held. Said as the state it is — money sitting on
    | the party's account — rather than as an empty table, because "on account"
    | is a correct and common outcome and an empty grid reads as a fault.
    */
    if (!rows.length) {
        panel.innerHTML = `
            <h4 class="section-label mb-2">What it settles</h4>
            <p class="rounded-[10px] border border-border bg-muted/40 px-3 py-2.5 text-[0.8125rem]
                      text-muted-foreground">
                Nothing of theirs is open, so the whole
                ${esc(formatMoney(drawer.txn.total))} sits on their account and comes off the next
                ${esc(noun)} raised.
            </p>`;

        return;
    }

    const body = rows.map((row) => {
        const ceiling = row.due + row.allocated;

        return `
            <tr class="border-t border-border" data-alloc-row data-bill="${esc(String(row.id))}"
                data-ceiling="${ceiling}">
                <td class="px-2 py-2 text-[0.8125rem]">
                    <span class="font-mono font-medium">${esc(row.doc_no ?? `#${row.id}`)}</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">${esc(formatDate(row.date))}</span>
                </td>
                <td class="px-2 py-2 text-right font-mono text-[0.8125rem] text-muted-foreground">
                    ${esc(formatMoney(fromPaise(ceiling)))}
                </td>
                <td class="px-2 py-2 text-right">
                    ${editable
                        ? `<input type="text" inputmode="decimal" data-alloc-amount
                                  class="field-input w-28 text-right font-mono"
                                  aria-label="Amount against ${esc(row.doc_no ?? `#${row.id}`)}"
                                  placeholder="0.00"
                                  value="${row.allocated ? esc(fromPaise(row.allocated)) : ''}">`
                        : `<span class="font-mono text-[0.8125rem]">${
                            row.allocated ? esc(formatMoney(fromPaise(row.allocated))) : '—'
                        }</span>`}
                </td>
            </tr>`;
    }).join('');

    panel.innerHTML = `
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h4 class="section-label">What it settles</h4>
            <span class="text-xs text-muted-foreground">Oldest first</span>
        </div>

        <div class="overflow-hidden rounded-[10px] border border-border">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="px-2 py-2 font-semibold">${esc(noun === 'invoice' ? 'Invoice' : 'Bill')}</th>
                        <th class="px-2 py-2 text-right font-semibold">Can take</th>
                        <th class="px-2 py-2 text-right font-semibold">Against it</th>
                    </tr>
                </thead>
                <tbody>${body}</tbody>
            </table>
        </div>

        <p class="mt-2 text-[0.8125rem]" data-alloc-summary></p>

        ${editable ? `
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm" data-alloc-save>Save what it settles</button>
                <button type="button" class="btn btn-secondary btn-sm" data-alloc-oldest>Apply oldest first</button>
            </div>` : ''}

        <p class="mt-2 text-xs text-muted-foreground">
            Pointing money at a different ${esc(noun)} writes no ledger entry and moves no balance — the
            money arrived where it arrived. It only records which ${esc(noun)} the workshop considers it to
            have discharged, which is why this is the one thing about a posted document that can simply be
            corrected. Total ${esc(formatMoney(fromPaise(total)))}.
        </p>`;

    refreshAllocationSummary();
}

/** What the typed amounts add up to, against what the settlement actually holds. */
function refreshAllocationSummary() {
    const summary = el('[data-alloc-summary]');

    if (!summary || !drawer.allocation) return;

    const total = toPaise(drawer.txn.total);
    const allocated = allocationRows().reduce((sum, row) => sum + row.paise, 0);
    const over = allocated > total;
    const spare = total - allocated;

    summary.textContent = over
        ? `Over by ${formatMoney(fromPaise(allocated - total))} — the total pointed at bills cannot exceed `
            + `what was ${drawer.txn.type === 'receipt' ? 'taken' : 'paid'}.`
        : spare === 0
            ? 'Every rupee is accounted for.'
            : `${formatMoney(fromPaise(spare))} left on account.`;

    summary.className = `mt-2 text-[0.8125rem] font-medium ${over ? 'text-rose-600' : 'text-muted-foreground'}`;

    const save = el('[data-alloc-save]');

    if (save) save.disabled = over || allocated === 0;
}

/** The typed rows, in paise, blank ones dropped. */
function allocationRows() {
    return $$('[data-alloc-row]', root).map((row) => ({
        id: row.dataset.bill,
        paise: toPaise($('[data-alloc-amount]', row)?.value ?? '0'),
    })).filter((row) => row.paise > 0);
}

async function saveAllocation(oldestFirst = false) {
    const rows = allocationRows();

    /*
    | An empty `allocations` array means "oldest first" to the server, not
    | "allocate nothing" — there is no useful reading of a request to do nothing
    | at all, and `AllocateSettlementRequest` says so. So a cleared grid is
    | refused here rather than sent, where it would silently do the opposite of
    | what it looks like.
    */
    if (!oldestFirst && !rows.length) {
        toast('Set an amount against at least one bill, or use “Apply oldest first”.', 'error');

        return;
    }

    try {
        const response = await auth.call(`/transactions/${drawer.id}/allocate`, {
            method: 'POST',
            body: oldestFirst
                ? {}
                : { allocations: rows.map((row) => ({ bill_transaction_id: Number(row.id), amount: fromPaise(row.paise) })) },
        });

        toast(response.message ?? 'Payment allocated.');

        await loadAllocation();

        /*
        | The list behind this drawer is deliberately *not* refetched. An
        | allocation writes no entry and moves no balance, so nothing on the
        | settlement's own row changed — what did change is which of the party's
        | bills are open, which is Sales' and Purchase's rows, and `data-bus`
        | already marked those stale from the write itself.
        */
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | The three acts a document in this drawer still permits
 | ---------------------------------------------------------------------- */

async function reverseDocument() {
    const txn = drawer.txn;

    const ok = await confirmAction({
        title: `Reverse ${documentLabel(txn)}?`,
        body: 'A mirroring entry is posted, cancelling this one. Both documents stay on the record — '
            + 'nothing is erased, which is what makes the correction auditable. Anything this money was '
            + 'pointed at becomes open again.',
        confirmLabel: 'Reverse it',
    });

    if (!ok) return;

    try {
        const response = await auth.call(`/transactions/${txn.id}/reverse`, { method: 'POST' });

        toast(response.message ?? 'Reversing entry posted.');

        await loadDocument();
        await refreshSectionList();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function postDraft() {
    const ok = await confirmAction({
        title: `Post ${documentLabel(drawer.txn)}?`,
        body: 'Posting writes the lines to the ledger. A posted entry can never be edited or deleted — a '
            + 'mistake is corrected with a reversing entry, which keeps both on the record.',
        confirmLabel: 'Post it',
        tone: 'primary',
    });

    if (!ok) return;

    try {
        const response = await auth.call(`/transactions/${drawer.id}/post`, { method: 'POST' });

        toast(response.message ?? 'Entry posted.');

        await loadDocument();
        await refreshSectionList();
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function discardDraft() {
    const ok = await confirmAction({
        title: `Discard ${documentLabel(drawer.txn)}?`,
        body: 'Nothing was ever written to the ledger, so there is nothing to reverse. The draft is deleted.',
        confirmLabel: 'Discard draft',
    });

    if (!ok) return;

    try {
        await auth.call(`/transactions/${drawer.id}`, { method: 'DELETE' });

        toast('Draft discarded.');
        hideModal('#txn-drawer');

        await refreshSectionList();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/**
 * Bring the list this drawer was opened from up to date.
 *
 * Only where one is actually held (§2A.7): somebody who reached the drawer from
 * a list has one, but the guard costs nothing and keeps this honest if that ever
 * stops being true.
 */
async function refreshSectionList() {
    const section = sections[drawer.section];

    if (section?.workspace?.hasList()) await section.reload();
}

/* -------------------------------------------------------------------------
 | Receipt and Payment — one factory, called twice
 | ---------------------------------------------------------------------- */

/**
 * @param {'receipt'|'payment'} direction
 */
function settlementSection(direction) {
    const section = sections[direction];
    const receipt = direction === 'receipt';
    const role = receipt ? 'customer' : 'vendor';
    const noun = receipt ? 'receipt' : 'payment';

    /*
    | Closed over, never module-level. Both directions are built from this
    | function, and anything shared between the two calls would belong to
    | whichever ran last — the two lists would start reading each other's rows,
    | which is the failure `pages/counterparty.js` records for Customers and
    | Vendors.
    */
    const state = {
        search: '',
        status: '',
        from: '',
        to: '',
        page: 1,
        total: null,

        /** This document's name for itself, until it is posted — M17. */
        clientRef: null,
    };

    const inForm = (selector) => $(selector, section.form);
    const inList = (selector) => $(selector, section.list);

    const form = $(`#${direction}-form`, section.form);

    let picker = null;
    let payments = null;

    /* --- the list ----------------------------------------------------- */

    function query() {
        const params = new URLSearchParams();

        params.append('types[]', direction);

        if (state.search) params.set('search', state.search);
        if (state.status) params.set('status', state.status);
        if (state.from) params.set('from', state.from);
        if (state.to) params.set('to', state.to);

        params.set('per_page', PAGE_SIZE);
        params.set('page', state.page);

        return params;
    }

    async function load() {
        inList('[data-txn-body]').innerHTML = tableMessage(SETTLEMENT_COLUMNS, 'Loading…');

        try {
            const payload = await auth.call(`/transactions?${query()}`);

            render(payload.data, payload.meta);
        } catch (error) {
            state.total = null;

            inList('[data-txn-body]').innerHTML = error.code === 'NO_WORKSPACE'
                ? tableMessage(SETTLEMENT_COLUMNS, 'Your account administers the platform rather than a '
                    + 'single workshop, so it has no books of its own.')
                : tableMessage(SETTLEMENT_COLUMNS, error.message, 'error');
        }
    }

    /** Refetch from page one — what every filter change means. */
    const refetch = debounce(async () => {
        state.page = 1;
        await load();
    }, 250);

    function render(rows, meta) {
        inList('[data-txn-body]').innerHTML = rows.length
            ? rows.map(renderRow).join('')
            : tableMessage(
                SETTLEMENT_COLUMNS,
                filtered()
                    ? `No ${noun}s match these filters.`
                    : receipt
                        ? 'Nothing collected yet. A receipt appears the moment money is taken.'
                        : 'Nothing paid out yet. A payment appears the moment a supplier is settled.',
            );

        const pagination = meta?.pagination ?? {};

        state.total = pagination.total ?? null;

        inList('[data-txn-summary]').textContent = pagination.total
            ? `${rows.length} of ${pagination.total}.`
            : '';

        inList('[data-page-prev]').disabled = (pagination.current_page ?? 1) <= 1;
        inList('[data-page-next]').disabled = !pagination.has_more;

        // §2A.4 — the count rides on the Show control, so the form says how much
        // is behind it without anybody having to switch.
        section.workspace?.refresh();
    }

    function filtered() {
        return Boolean(state.search || state.status || state.from || state.to);
    }

    function renderRow(row) {
        // §2A.8 — a receipt written while the list was detached carries the
        // flash with it, so the eye finds it the first time somebody does look.
        const flash = section.workspace?.isNew(row.id) ? ' row-new' : '';

        return `
            <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
                data-txn="${row.id}" tabindex="0" role="link"
                aria-label="Open ${esc(documentLabel(row))}">

                <td class="table-cell w-36">${reference(row)}</td>
                <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>
                <td class="table-cell max-w-48 truncate text-[0.8125rem]">
                    ${row.party?.name
                        ? esc(row.party.name)
                        : '<span class="text-muted-foreground">—</span>'}
                </td>
                <td class="table-cell text-[0.8125rem]">
                    ${row.notes ? esc(row.notes) : '<span class="text-muted-foreground">No note</span>'}
                </td>
                <td class="table-cell w-32 text-[0.8125rem] text-muted-foreground">${esc(paidBy(row))}</td>
                <td class="table-cell w-32 text-right font-mono text-[0.8125rem] font-semibold">
                    ${esc(formatMoney(row.total))}
                </td>
                <td class="table-cell w-28">${badge(row.status_label, lifecycleTone(row.status))}</td>
            </tr>`;
    }

    /* --- writing one -------------------------------------------------- */

    /**
     * What the browser can answer without asking the server.
     *
     * Shape only, and never the whole of it (§6.1): that the party holds the
     * role this direction requires, that the date is inside the open books and
     * that the split adds up to the entries are all decided server-side, where
     * they cannot be skipped.
     */
    function validate(split) {
        const errors = {};

        if (!picker.id()) {
            errors.party_id = [receipt
                ? 'Choose the customer the money came from.'
                : 'Choose the supplier the money went to.'];
        }

        if (!form.elements.date.value) {
            errors.date = ['Give the date the money moved.'];
        }

        if (!split.length) {
            errors.payments = ['Say how the money moved — enter at least one amount.'];

            return errors;
        }

        const missing = split.findIndex((line) => {
            const mode = modes.find((option) => option.value === line.mode);

            return mode?.requires_reference && !line.reference;
        });

        if (missing !== -1) {
            const mode = modes.find((option) => option.value === split[missing].mode);

            errors.payments = [`Line ${missing + 1} is a ${mode.label.toLowerCase()}, so it needs its `
                + `${mode.reference_label.toLowerCase()}.`];
        }

        return Object.keys(errors).length ? errors : null;
    }

    /** Everything the next entry needs blank, and a fresh reference for it. */
    function resetForm() {
        clearFormErrors(form);

        picker.set(null);
        inForm(`#${direction}-notes`).value = '';
        payments.reset();

        /*
        | The date is deliberately *not* cleared. Somebody entering the day's
        | takings is writing several against one date, and a form that reset it
        | would make them retype it every time — the same judgement the expense
        | form and the bill document already make.
        */

        // A fresh document deserves a fresh reference, or the posted receipt's
        // idempotency key would follow the next one in and the server would
        // answer with the first document instead of writing the second (M17).
        state.clientRef = crypto.randomUUID();
    }

    /**
     * Say where the money went, and offer to change it.
     *
     * The form has just been cleared under §2A.8, so this is the only place the
     * operator can see what the oldest-first default decided — and a toast would
     * be gone before they had finished reading the amount.
     */
    function paintOutcome(response) {
        const outcome = inForm('[data-settlement-outcome]');
        const id = response.data?.id;
        const allocation = response.meta?.allocations;
        const bills = allocation?.bills ?? [];
        const spare = allocation ? toPaise(allocation.unallocated) : null;

        const applied = bills.length
            ? `Applied to ${bills.map((bill) => bill.doc_no ?? `#${bill.id}`).join(', ')}.`
            : 'Nothing of theirs was open, so it sits on their account.';

        const left = spare !== null && spare > 0
            ? ` ${formatMoney(fromPaise(spare))} left on account.`
            : '';

        outcome.innerHTML = `
            <span class="font-semibold">${esc(response.data?.doc_no ?? `#${id}`)}</span>
            recorded. ${esc(applied)}${esc(left)}
            ${id
                ? `<button type="button" class="ml-1 font-semibold underline underline-offset-2"
                           data-open-outcome="${esc(String(id))}">Change what it settles</button>`
                : ''}`;

        outcome.classList.remove('hidden');
    }

    async function submit(event) {
        event.preventDefault();

        clearFormErrors(form);

        // The last receipt's outcome goes the moment another is attempted. Left
        // up beside a validation error it reads as though *this* one posted.
        inForm('[data-settlement-outcome]').classList.add('hidden');

        const split = payments.value();
        const errors = validate(split);

        if (errors) {
            showFormErrors(form, { fields: errors, message: 'Check the highlighted fields.' });

            return;
        }

        setSubmitting(form, true, 'Recording…');

        try {
            const response = await auth.call(`/transactions/${direction}`, {
                method: 'POST',
                body: {
                    date: form.elements.date.value,
                    notes: inForm(`#${direction}-notes`).value.trim() || null,
                    post: true,
                    client_ref: state.clientRef,
                    party_id: picker.id(),
                    payments: split,
                },
            });

            /*
            | §2A.8 — a successful entry stays on the form, clears it for the next
            | one and returns focus to the first field. Somebody entering the
            | day's takings writes several in a row, and being thrown to a list
            | after each would mean several trips back.
            */
            if (response.data?.id) section.workspace?.flagNew(response.data.id);

            resetForm();
            paintOutcome(response);
            picker.focus();

            // Only where a list is actually held (§2A.7), so somebody who only
            // ever writes receipts never pays for one by posting.
            if (section.workspace?.hasList()) refetch();
        } catch (error) {
            showFormErrors(form, error);
        } finally {
            setSubmitting(form, false, receipt ? 'Record the receipt' : 'Record the payment');
        }
    }

    /* --- wiring -------------------------------------------------------- */

    function bindList() {
        inList('[data-filter-search]').addEventListener('input', (event) => {
            state.search = event.target.value.trim();
            refetch();
        });

        ['status', 'from', 'to'].forEach((field) => {
            inList(`[data-filter-${field}]`).addEventListener('change', (event) => {
                state[field] = event.target.value;
                refetch();
            });
        });

        inList('[data-clear-filters]').addEventListener('click', () => {
            Object.assign(state, { search: '', status: '', from: '', to: '', page: 1 });

            inList('[data-filter-search]').value = '';
            ['status', 'from', 'to'].forEach((hook) => {
                inList(`[data-filter-${hook}]`).value = '';
            });

            load();
        });

        const open = (event) => {
            const row = event.target.closest('[data-txn]');

            if (row) openDrawer(row.dataset.txn, direction);
        };

        inList('[data-txn-body]').addEventListener('click', open);
        inList('[data-txn-body]').addEventListener('keydown', (event) => {
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

    section.reload = load;

    section.mount = async () => {
        form.elements.date.value = new Date().toISOString().slice(0, 10);
        state.clientRef = crypto.randomUUID();

        picker = mountPartyPicker(inForm('[data-party-host]'), {
            role,
            label: receipt ? 'Customer' : 'Supplier',
        });

        /*
        | The split *is* the amount here — there is no document total to measure
        | it against — which is what `settlesADocument: false` says. The three
        | state chips would have nothing to mean and the "left on account"
        | summary would be describing a document that does not exist. M22's staff
        | advance mounts it the same way.
        */
        payments = mountPaymentRows(inForm('[data-settlement-payments]'), {
            modes,
            settlesADocument: false,
            heading: receipt ? 'How the money came in' : 'How the money went out',
            // The component states the split back — "5,000.00 collected." — and
            // the default verb is the sale's. A payment that told somebody it
            // had been collected would name the wrong direction in the one
            // sentence about which way the money went.
            verb: receipt ? 'collected' : 'paid out',
        });

        form.addEventListener('submit', submit);

        inForm('[data-settlement-outcome]').addEventListener('click', (event) => {
            const open = event.target.closest('[data-open-outcome]');

            if (open) openDrawer(open.dataset.openOutcome, direction);
        });

        bindList();

        section.workspace = mountWorkspace(section.root, {
            // A key of its own, never `journal` — see the Escape note above.
            key: `journal:${direction}`,
            title: receipt ? 'Receipts' : 'Payments',
            formSubtitle: receipt
                ? 'Money collected from a customer, whether or not it pays one particular invoice.'
                : 'Money paid out to a supplier, whether or not it settles one particular bill.',
            listSubtitle: (count) => (count === null
                ? `Every ${noun} recorded, newest first.`
                : `${count} ${noun}${count === 1 ? '' : 's'}, newest first.`),
            createLabel: receipt ? 'Record a receipt' : 'Record a payment',
            count: () => state.total,
            canCreate: can('WRITE', 'TRANSACTIONS'),
            onShowList: load,

            /*
            | The rows are a copy of what has been posted, and this section is
            | not the only thing that posts one: a sale collected at the counter,
            | a reversal, an import. See `data-bus.js` — the announcement is made
            | in `auth-client` so a new write site cannot forget to make it.
            */
            refreshOn: ['transactions'],

            // §2A.8 — back on the form, the counterparty is where the next entry
            // starts. The date is kept from the last one; see `resetForm`.
            onShowForm: () => picker?.focus(),
        });
    };
}

/* -------------------------------------------------------------------------
 | The journal voucher — the books' own correction mechanism
 | ---------------------------------------------------------------------- */

function voucherSection() {
    const section = sections.journal;

    const state = {
        search: '',
        status: '',
        from: '',
        to: '',
        page: 1,
        total: null,
        clientRef: null,
    };

    const inForm = (selector) => $(selector, section.form);
    const inList = (selector) => $(selector, section.list);

    const form = $('#voucher-form', section.form);

    let picker = null;

    /* --- the grid ------------------------------------------------------ */

    function accountOptions(selected = '') {
        const groups = {};

        accounts.forEach((account) => {
            (groups[account.type_label] ??= []).push(account);
        });

        return Object.entries(groups).map(([label, group]) => `
            <optgroup label="${esc(label)}">
                ${group.map((account) => `
                    <option value="${account.id}" ${String(account.id) === String(selected) ? 'selected' : ''}>
                        ${esc(account.code)} · ${esc(account.name)}
                    </option>`).join('')}
            </optgroup>`).join('');
    }

    function lineRow() {
        return `
            <tr class="border-t border-border" data-line>
                <td class="px-3 py-2">
                    <select class="field-input" data-account aria-label="Account">
                        <option value="">Choose an account…</option>
                        ${accountOptions()}
                    </select>
                </td>
                <td class="px-3 py-2">
                    <input type="text" inputmode="decimal" class="field-input text-right font-mono"
                           data-debit aria-label="Debit" placeholder="0.00">
                </td>
                <td class="px-3 py-2">
                    <input type="text" inputmode="decimal" class="field-input text-right font-mono"
                           data-credit aria-label="Credit" placeholder="0.00">
                </td>
                <td class="px-3 py-2">
                    <input type="text" class="field-input" data-memo aria-label="Memo" placeholder="Optional">
                </td>
                <td class="px-3 py-2 text-right">
                    <button type="button" class="btn btn-ghost btn-icon" data-remove-line
                            title="Remove line" aria-label="Remove line">×</button>
                </td>
            </tr>`;
    }

    function columnTotal(selector) {
        return $$('[data-line]', inForm('[data-voucher-lines]'))
            .reduce((sum, row) => sum + toPaise($(selector, row).value), 0);
    }

    /**
     * Live balance feedback.
     *
     * The server refuses an unbalanced entry, and finding that out on submit
     * means retyping a voucher — which is the whole reason this is here rather
     * than left to the round trip.
     */
    function refreshTotals() {
        const debit = columnTotal('[data-debit]');
        const credit = columnTotal('[data-credit]');
        const note = inForm('[data-balance-note]');

        inForm('[data-total-debit]').textContent = formatMoney(fromPaise(debit));
        inForm('[data-total-credit]').textContent = formatMoney(fromPaise(credit));

        if (debit === 0 && credit === 0) {
            note.textContent = '';
            note.className = 'px-3 py-2 text-[0.8125rem]';

            return;
        }

        const balanced = debit === credit;

        note.textContent = balanced
            ? 'Balanced'
            : `Out by ${formatMoney(fromPaise(Math.abs(debit - credit)))}`;
        note.className = `px-3 py-2 text-[0.8125rem] font-semibold ${
            balanced ? 'text-emerald-600' : 'text-rose-600'
        }`;
    }

    function collectLines() {
        return $$('[data-line]', inForm('[data-voucher-lines]'))
            .map((row) => ({
                account_id: $('[data-account]', row).value,
                debit: $('[data-debit]', row).value.trim(),
                credit: $('[data-credit]', row).value.trim(),
                memo: $('[data-memo]', row).value.trim(),
            }))
            // A row with nothing on it is one nobody has filled in yet, not an
            // error — the two the form opens with are exactly that.
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

        if (!form.elements.date.value) errors.date = ['Choose a date for the entry.'];

        if (lines.length < 2) {
            return {
                ...errors,
                lines: ['A voucher needs at least two lines — one account to debit and one to credit.'],
            };
        }

        const bad = lines.findIndex((line) => !line.account_id
            || (isZeroAmount(line.debit) === isZeroAmount(line.credit)));

        if (bad !== -1) {
            errors.lines = [`Line ${bad + 1} needs an account and an amount in exactly one of the `
                + 'two columns.'];

            return errors;
        }

        const debit = lines.reduce((sum, line) => sum + toPaise(line.debit), 0);
        const credit = lines.reduce((sum, line) => sum + toPaise(line.credit), 0);

        if (debit !== credit) {
            errors.lines = [`The two sides are out by ${formatMoney(fromPaise(Math.abs(debit - credit)))}. `
                + 'A voucher posts only when they are equal.'];
        }

        return Object.keys(errors).length ? errors : null;
    }

    function resetForm() {
        clearFormErrors(form);

        picker.set(null);
        inForm('#voucher-notes').value = '';
        inForm('[data-voucher-lines]').innerHTML = lineRow() + lineRow();

        refreshTotals();

        // As on the settlement form: the date is kept, the reference is not.
        state.clientRef = crypto.randomUUID();
    }

    async function submit(event) {
        event.preventDefault();

        clearFormErrors(form);

        // As on the settlement form: the last voucher's line does not survive an
        // attempt at another one.
        inForm('[data-voucher-outcome]').classList.add('hidden');

        const lines = collectLines();
        const errors = validate(lines);

        if (errors) {
            showFormErrors(form, {
                fields: errors,
                message: errors.lines?.[0] ?? 'Check the highlighted fields.',
            });

            return;
        }

        setSubmitting(form, true, 'Posting…');

        try {
            const response = await auth.call('/transactions/journal', {
                method: 'POST',
                body: {
                    date: form.elements.date.value,
                    notes: inForm('#voucher-notes').value.trim() || null,
                    post: true,
                    client_ref: state.clientRef,
                    party_id: picker.id(),
                    lines,
                },
            });

            if (response.data?.id) section.workspace?.flagNew(response.data.id);

            const outcome = inForm('[data-voucher-outcome]');

            outcome.textContent = `${response.data?.doc_no ?? `#${response.data?.id}`} posted — `
                + `${formatMoney(response.data?.total ?? '0')} across ${lines.length} lines.`;
            outcome.classList.remove('hidden');

            resetForm();
            form.elements.date.focus();

            if (section.workspace?.hasList()) refetch();
        } catch (error) {
            showFormErrors(form, error);
        } finally {
            setSubmitting(form, false, 'Post the voucher');
        }
    }

    /* --- the list ------------------------------------------------------ */

    function query() {
        const params = new URLSearchParams();

        params.append('types[]', 'journal');

        if (state.search) params.set('search', state.search);
        if (state.status) params.set('status', state.status);
        if (state.from) params.set('from', state.from);
        if (state.to) params.set('to', state.to);

        params.set('per_page', PAGE_SIZE);
        params.set('page', state.page);

        return params;
    }

    async function load() {
        inList('[data-txn-body]').innerHTML = tableMessage(VOUCHER_COLUMNS, 'Loading…');

        try {
            const payload = await auth.call(`/transactions?${query()}`);

            render(payload.data, payload.meta);
        } catch (error) {
            state.total = null;

            inList('[data-txn-body]').innerHTML = error.code === 'NO_WORKSPACE'
                ? tableMessage(VOUCHER_COLUMNS, 'Your account administers the platform rather than a '
                    + 'single workshop, so it has no books of its own.')
                : tableMessage(VOUCHER_COLUMNS, error.message, 'error');
        }
    }

    const refetch = debounce(async () => {
        state.page = 1;
        await load();
    }, 250);

    function render(rows, meta) {
        inList('[data-txn-body]').innerHTML = rows.length
            ? rows.map(renderRow).join('')
            : tableMessage(
                VOUCHER_COLUMNS,
                state.search || state.status || state.from || state.to
                    ? 'No vouchers match these filters.'
                    : 'No vouchers yet. Most workshops write few — a depreciation charge, a write-off, a '
                        + 'correction nothing else can express.',
            );

        const pagination = meta?.pagination ?? {};

        state.total = pagination.total ?? null;

        inList('[data-txn-summary]').textContent = pagination.total
            ? `${rows.length} of ${pagination.total}.`
            : '';

        inList('[data-page-prev]').disabled = (pagination.current_page ?? 1) <= 1;
        inList('[data-page-next]').disabled = !pagination.has_more;

        section.workspace?.refresh();
    }

    function renderRow(row) {
        const flash = section.workspace?.isNew(row.id) ? ' row-new' : '';

        return `
            <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60${flash}"
                data-txn="${row.id}" tabindex="0" role="link"
                aria-label="Open ${esc(documentLabel(row))}">

                <td class="table-cell w-36">${reference(row)}</td>
                <td class="table-cell w-28 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.date))}</td>
                <td class="table-cell text-[0.8125rem]">
                    ${row.notes ? esc(row.notes) : '<span class="text-muted-foreground">No narration</span>'}
                </td>
                <td class="table-cell max-w-40 truncate text-[0.8125rem] text-muted-foreground">
                    ${row.party?.name ? esc(row.party.name) : '—'}
                </td>
                <td class="table-cell w-32 text-right font-mono text-[0.8125rem] font-semibold">
                    ${esc(formatMoney(row.total))}
                </td>
                <td class="table-cell w-28">${badge(row.status_label, lifecycleTone(row.status))}</td>
            </tr>`;
    }

    /* --- wiring -------------------------------------------------------- */

    function bindList() {
        inList('[data-filter-search]').addEventListener('input', (event) => {
            state.search = event.target.value.trim();
            refetch();
        });

        ['status', 'from', 'to'].forEach((field) => {
            inList(`[data-filter-${field}]`).addEventListener('change', (event) => {
                state[field] = event.target.value;
                refetch();
            });
        });

        inList('[data-clear-filters]').addEventListener('click', () => {
            Object.assign(state, { search: '', status: '', from: '', to: '', page: 1 });

            inList('[data-filter-search]').value = '';
            ['status', 'from', 'to'].forEach((hook) => {
                inList(`[data-filter-${hook}]`).value = '';
            });

            load();
        });

        const open = (event) => {
            const row = event.target.closest('[data-txn]');

            if (row) openDrawer(row.dataset.txn, 'journal');
        };

        inList('[data-txn-body]').addEventListener('click', open);
        inList('[data-txn-body]').addEventListener('keydown', (event) => {
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

    section.reload = load;

    section.mount = async () => {
        /*
        | The chart, fetched here rather than at boot: this is the only section
        | that needs it, and a workshop that only takes receipts never pays for
        | it (§2.5, §7.2). Awaited, because the grid cannot be drawn without it.
        */
        await loadAccounts();

        form.elements.date.value = new Date().toISOString().slice(0, 10);
        state.clientRef = crypto.randomUUID();

        /*
        | Roleless, and deliberately: a journal does not decide whether its
        | counterparty is a customer or a supplier, so the picker offers no "what
        | do they owe" line and no "+ Add" — quick-party never asks which role a
        | record is, the module it was opened from decides, and this one has not.
        */
        picker = mountPartyPicker(inForm('[data-voucher-party-host]'), {
            role: null,
            label: 'Counterparty (optional)',
        });

        inForm('[data-voucher-lines]').innerHTML = lineRow() + lineRow();
        refreshTotals();

        inForm('[data-add-line]').addEventListener('click', () => {
            inForm('[data-voucher-lines]').insertAdjacentHTML('beforeend', lineRow());
        });

        inForm('[data-voucher-lines]').addEventListener('input', refreshTotals);

        inForm('[data-voucher-lines]').addEventListener('click', (event) => {
            if (!event.target.closest('[data-remove-line]')) return;

            // Never below two: a voucher with one line cannot balance, and an
            // empty grid gives somebody nothing to type into.
            if ($$('[data-line]', inForm('[data-voucher-lines]')).length > 2) {
                event.target.closest('[data-line]').remove();
                refreshTotals();
            }
        });

        form.addEventListener('submit', submit);

        bindList();

        section.workspace = mountWorkspace(section.root, {
            key: 'journal:voucher',
            title: 'Journal vouchers',
            formSubtitle: 'The one screen that writes ledger entries directly — a depreciation charge, a '
                + 'write-off, a correction nothing else can express.',
            listSubtitle: (count) => (count === null
                ? 'Every voucher posted, newest first.'
                : `${count} voucher${count === 1 ? '' : 's'}, newest first.`),
            createLabel: 'Write a voucher',
            count: () => state.total,
            canCreate: can('WRITE', 'TRANSACTIONS'),
            onShowList: load,
            refreshOn: ['transactions'],
            onShowForm: () => form.elements.date.focus(),
        });
    };
}

/* -------------------------------------------------------------------------
 | Sections
 | ---------------------------------------------------------------------- */

/**
 * Show a section, mounting it the first time it is asked for.
 *
 * The lazy mount is §2.5 applied inside a module: a workshop that only ever
 * takes receipts never pays for the voucher grid's chart of accounts, and
 * opening the module fetches the payment modes and nothing else.
 */
async function openSection(key) {
    if (!sections[key]) return;

    activeSection = key;

    Object.entries(sections).forEach(([name, section]) => {
        section.root.hidden = name !== key;
    });

    $$('[data-txn-tab]', root).forEach((tab) => {
        tab.setAttribute('aria-selected', String(tab.dataset.txnTab === key));
    });

    if (sections[key].opened) return;

    sections[key].opened = true;

    try {
        await sections[key].mount();
    } catch (error) {
        sections[key].opened = false;
        toast(error.message ?? 'That section could not be opened.', 'error');
    }
}

function requestedTab() {
    const tab = moduleParams().get('tab');

    return tab && sections[tab] ? tab : null;
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initJournal() {
    root = $('[data-txn-section="receipt"]').closest('[data-module-root]');

    ['receipt', 'payment', 'journal'].forEach((key) => {
        const sectionRoot = $(`[data-txn-section="${key}"]`, root);

        sections[key] = {
            root: sectionRoot,
            // Held while both surfaces are still in the document. After
            // `mountWorkspace` one of them is detached and `document` cannot
            // reach it — which is exactly when a save wants to update the list.
            form: $('[data-ws-form]', sectionRoot),
            list: $('[data-ws-list]', sectionRoot),
            workspace: null,
            opened: false,
            mount: async () => {},
            reload: async () => {},
        };
    });

    // Fetched before anything mounts: the section the module lands on is a form
    // whose split rows take the modes by value at mount, and chips built from an
    // empty list stay empty however often they are repainted.
    await loadModes();

    settlementSection('receipt');
    settlementSection('payment');
    voucherSection();

    $('[data-txn-tabs]', root).addEventListener('click', (event) => {
        const tab = event.target.closest('[data-txn-tab]');

        if (tab && tab.dataset.txnTab !== activeSection) openSection(tab.dataset.txnTab);
    });

    el('[data-drawer-actions]').addEventListener('click', (event) => {
        if (event.target.closest('[data-drawer-reverse]')) reverseDocument();
        if (event.target.closest('[data-drawer-post]')) postDraft();
        if (event.target.closest('[data-drawer-discard]')) discardDraft();
    });

    el('[data-drawer-body]').addEventListener('click', (event) => {
        if (event.target.closest('[data-alloc-save]')) saveAllocation(false);
        if (event.target.closest('[data-alloc-oldest]')) saveAllocation(true);
    });

    el('[data-drawer-body]').addEventListener('input', (event) => {
        if (event.target.closest('[data-alloc-amount]')) refreshAllocationSummary();
    });

    /*
    | §2A.9, one press at a time — but the shell asks for `journal`, and each
    | section's workspace registered under a key of its own. Without this the
    | last-mounted section would answer for all three, and a press on the voucher
    | list would swap the receipt form.
    |
    | Returning false lets the shell take the next step out to the grid.
    */
    registerEscape('journal', () => {
        const section = sections[activeSection];

        if (!section?.workspace || section.workspace.mode() !== 'list') return false;
        if (!can('WRITE', 'TRANSACTIONS')) return false;

        section.workspace.showForm();

        return true;
    });

    /*
    | The section a deep link asked for, or Receipt — the one done most (§2A.5).
    |
    | `?tab=` is spent once acted on: surviving a refresh or a Back would reopen
    | a section somebody has just navigated away from.
    */
    const requested = requestedTab();

    await openSection(requested ?? 'receipt');

    if (requested) clearModuleParams();
}
