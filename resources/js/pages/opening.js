import auth from '../auth-client';
import { onChange } from '../data-bus';
import { can } from '../permissions';
import {
    $, confirmAction, esc, formatDate, formatMoney, setSubmitting, showFormErrors,
    clearFormErrors, tableMessage, toast,
} from '../ui';
import { mountWorkspace } from '../workspace';

/**
 * Getting a running workshop's existing position into the books — M11.
 *
 * The screen is built around one rule: **nothing is posted until the owner has
 * seen exactly what will be.** Checking a file and posting it are two separate
 * buttons, and the second one stays disabled until the first has run against
 * the text currently in the box — so an edit made after a preview cannot be
 * committed on the strength of the preview it invalidated.
 *
 * ## The §2A flow
 *
 * The module opens on the declaration (§2A.1) and every import ever run sits
 * behind one switch control beside the heading. The *position* travels with the
 * form rather than with the list: it is what somebody about to declare their
 * whole financial history needs in front of them, and it is what they want to
 * see the instant they commit.
 *
 * ## One call, two surfaces
 *
 * `GET /opening-balances` answers with the position *and* the receipts, so the
 * list costs nothing beyond the call the form already makes — §2A.7's rule that
 * a module never used to write should not pay for a list is satisfied by there
 * being no second request, rather than by deferring one.
 *
 * ## Everything is scoped to its surface
 *
 * §2A.2 keeps exactly one of the form and the list attached, so
 * `document.querySelector` finds nothing in the other — which is precisely when
 * a post wants to bring the receipts up to date. Both roots are held at mount
 * and every lookup goes through them; querying a node works detached.
 */

const state = {
    /** The preview the enabled "post" button belongs to, or null. */
    checked: null,
    /** The exact text that preview was run against. */
    checkedCsv: '',
    meta: null,
    /** The receipts, as last fetched. Null until the first call comes back. */
    history: null,
};

/**
 * Is what we hold current?
 *
 * The position is a copy of the books and the go-live date is a copy of a
 * setting on another screen, so both go behind the moment anything is posted or
 * the workshop's settings are saved (§3.7). False only *marks* — nothing is
 * fetched until the module is next actually looked at (§7.2).
 */
let held = false;

let workspace = null;
let formRoot = null;
let listRoot = null;
let openingForm = null;

/** Scoped lookups — the surface each node belongs to, never the document. */
const inForm = (selector) => $(selector, formRoot);
const inList = (selector) => $(selector, listRoot);

/* -------------------------------------------------------------------------
 | Position
 | ---------------------------------------------------------------------- */

/** The call in flight, so two callers asking at once make one request. */
let loading = null;

/**
 * The workshop's position and its receipts, in one call.
 *
 * Both surfaces are painted whether or not either is on screen: a held node
 * takes a repaint perfectly well detached, which is what keeps the receipts
 * current for the next Show without a second request.
 *
 * Shared rather than re-entrant, because three things ask for it — boot, the
 * first Show and coming back after a write — and two of them can land in the
 * same tick.
 */
function load() {
    loading ||= fetchPosition().finally(() => { loading = null; });

    return loading;
}

async function fetchPosition() {
    try {
        const { data, meta } = await auth.call('/opening-balances');

        held = true;
        state.history = meta?.history ?? [];

        renderPosition(data);
        renderHistory(state.history);

        return data;
    } catch (error) {
        inList('#history-rows').innerHTML = error.code === 'NO_WORKSPACE'
            ? tableMessage(6, 'Your account administers the platform rather than a single workshop, so it has no books to open.')
            : tableMessage(6, error.message, 'error');

        return null;
    }
}

function renderPosition(position) {
    inForm('#stat-stake').textContent = formatMoney(position.owners_stake, { sign: true });
    inForm('#stat-posted').textContent = position.opening_transactions;

    inForm('#stat-books-start').textContent = position.books_start_date
        ? formatDate(position.books_start_date)
        : 'Not set';

    const date = inForm('#opening-date');
    if (!date.value) date.value = position.default_date ?? '';

    renderReconciliation(position.trial_balance, position.has_opening_balances);
}

/**
 * The trial balance, stated whether or not it is interesting.
 *
 * It always reconciles — every opening line is posted against Opening Balance
 * Equity — and saying so plainly is the point: somebody about to declare their
 * whole financial history needs to know that getting a figure wrong misstates
 * the books rather than breaking them.
 */
function renderReconciliation(trial, hasOpening) {
    const host = inForm('#reconciliation');

    host.classList.remove('hidden');

    host.innerHTML = trial.is_balanced
        ? `<div class="surface flex flex-wrap items-center gap-2 border-emerald-200 bg-emerald-50/60 px-4 py-3 text-[0.8125rem] text-emerald-800">
               <span class="font-semibold">The books balance.</span>
               Debits and credits both total ${esc(formatMoney(trial.debit))}.
               ${hasOpening ? '' : 'Nothing has been declared yet, so every account stands at zero.'}
           </div>`
        : `<div class="surface flex items-center gap-2 border-rose-200 bg-rose-50 px-4 py-3 text-[0.8125rem] text-rose-700">
               <span class="font-semibold">The books do not balance.</span>
               Debits exceed credits by ${esc(formatMoney(trial.difference))}. This should be impossible —
               please report it before declaring anything further.
           </div>`;
}

function renderHistory(imports) {
    const body = inList('#history-rows');

    if (!imports.length) {
        body.innerHTML = tableMessage(6, 'No opening balances have been imported yet.');
    } else {
        body.innerHTML = imports.map((row) => {
            // §2A.8 — the import just posted is flagged rather than flashed, so
            // the highlight happens whenever the receipts are next looked at
            // and not at a moment nobody was watching.
            const flash = workspace?.isNew(row.id) ? ' row-new' : '';

            return `
        <tr class="border-t border-border${flash}">
            <td class="table-cell w-40 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(row.created_at ?? row.date))}</td>
            <td class="table-cell">
                <span class="font-medium">${esc(row.filename || 'Typed in')}</span>
                ${row.items_created || row.parties_created ? `
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        created ${row.items_created} item${row.items_created === 1 ? '' : 's'},
                        ${row.parties_created} part${row.parties_created === 1 ? 'y' : 'ies'}
                    </div>` : ''}
            </td>
            <td class="table-cell w-24 text-right font-mono text-[0.8125rem]">${row.imported}</td>
            <td class="table-cell w-36 text-right font-mono text-[0.8125rem] text-muted-foreground">
                ${row.already_declared || '—'}
            </td>
            <td class="table-cell w-40 text-right font-mono text-[0.8125rem]">${esc(formatMoney(row.declared_total))}</td>
            <td class="table-cell w-40 text-[0.8125rem] text-muted-foreground">${esc(row.created_by ?? '—')}</td>
        </tr>`;
        }).join('');
    }

    // The count rides on the Show control (§2A.4), so the form says how much is
    // behind it without anybody having to switch to find out.
    workspace?.refresh();
}

/* -------------------------------------------------------------------------
 | The column guide
 | ---------------------------------------------------------------------- */

/**
 * Built from the server's answer rather than written into the markup, so the
 * instructions cannot drift from the rules the parser and the resolver apply.
 */
async function loadMeta() {
    try {
        const { data } = await auth.call('/opening-balances/meta');

        state.meta = data;

        inForm('#column-guide').innerHTML = `
            <p class="font-medium text-foreground">Each row says what it declares.</p>
            <ul class="mt-1.5 space-y-1 text-muted-foreground">
                ${data.kinds.map((kind) => `
                    <li><code class="font-mono text-foreground">${esc(kind.value)}</code> — ${esc(kind.label.toLowerCase())}${
                        kind.needs_party ? ', naming the party' : ''
                    }</li>`).join('')}
            </ul>
            <p class="mt-2.5 text-muted-foreground">
                Columns: ${data.columns.map((c) => `<code class="font-mono text-foreground">${esc(c)}</code>`).join(', ')}.
                A column this product has no use for is ignored rather than refused.
            </p>
            <p class="mt-2 text-muted-foreground">
                An item the catalogue does not have yet needs a <code class="font-mono text-foreground">type</code>,
                and its <code class="font-mono text-foreground">variant</code> written as
                ${data.item_types.map((t) => `<span class="whitespace-nowrap">${esc(t.label.toLowerCase())}: <code class="font-mono text-foreground">${esc(t.variant_format)}</code></span>`).join(' · ')}.
                The type fixes the unit every quantity is counted in and can never be changed afterwards,
                which is why it cannot be guessed.
            </p>`;
    } catch {
        // The guide is help text. Its absence must not stop somebody who
        // already knows the format from using the screen.
        inForm('#column-guide').innerHTML = '';
    }
}

/* -------------------------------------------------------------------------
 | Preview
 | ---------------------------------------------------------------------- */

function payload() {
    return {
        csv: inForm('#opening-csv').value,
        date: inForm('#opening-date').value || null,
        filename: inForm('#opening-filename').value || null,
    };
}

async function preview(event) {
    event.preventDefault();

    clearFormErrors(openingForm);
    invalidate();

    const body = payload();

    if (!body.csv.trim()) {
        toast('Paste the rows you want to declare first.', 'error');

        return;
    }

    setSubmitting(openingForm, true, 'Checking…');

    try {
        const { data, meta } = await auth.call('/opening-balances/preview', {
            method: 'POST',
            body,
        });

        state.checked = { rows: data, summary: meta.summary };
        state.checkedCsv = body.csv;

        renderPreview(data, meta.summary);
    } catch (error) {
        showFormErrors(openingForm, error);
        inForm('#preview-panel').classList.add('hidden');
    } finally {
        setSubmitting(openingForm, false);
    }
}

function renderPreview(rows, summary) {
    inForm('#preview-panel').classList.remove('hidden');

    inForm('#preview-summary').textContent = [
        `${summary.ready} to post`,
        summary.skipped ? `${summary.skipped} already declared` : null,
        summary.errors ? `${summary.errors} needing attention` : null,
    ].filter(Boolean).join(' · ');

    inForm('#preview-totals').innerHTML = [
        ['Stock', summary.stock_value],
        ['Customers owe', summary.receivable_total],
        ['Owed to suppliers', summary.payable_total],
        ['Other accounts', summary.other_total],
        ["Owner's stake", summary.owners_stake],
    ].map(([label, amount], index) => `
        <div class="bg-card px-4 py-3">
            <span class="block text-xs uppercase tracking-wide text-muted-foreground">${esc(label)}</span>
            <span class="mt-1 block font-mono text-[0.9375rem] ${index === 4 ? 'font-semibold text-foreground' : 'text-secondary-foreground'}">
                ${esc(formatMoney(amount, { sign: index === 4 }))}
            </span>
        </div>`).join('');

    inForm('#preview-rows').innerHTML = rows.map((row) => `
        <tr class="border-t border-border ${row.outcome === 'error' ? 'bg-rose-50/50' : ''}">
            <td class="table-cell w-16 font-mono text-[0.8125rem] text-muted-foreground">${row.line_no}</td>
            <td class="table-cell w-36 text-[0.8125rem]">${esc(row.kind_label)}</td>
            <td class="table-cell text-[0.8125rem]">
                <span class="font-medium">${esc(row.name ?? '—')}</span>
                ${row.variant ? `<span class="text-muted-foreground"> · ${esc(row.variant)}</span>` : ''}
            </td>
            <td class="table-cell text-[0.8125rem]">
                ${row.resolved ? esc(row.resolved) : '<span class="text-muted-foreground">—</span>'}
                ${creationNote(row)}
                ${row.confidence && row.confidence < 100
                    ? `<div class="mt-0.5 text-xs text-amber-700">matched at ${row.confidence}% — check this is the same one</div>`
                    : ''}
            </td>
            <td class="table-cell w-28 text-right font-mono text-[0.8125rem]">${esc(row.quantity ?? '')}</td>
            <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">
                ${row.amount ? esc(formatMoney(row.amount)) : ''}
                ${row.side === 'credit' ? '<span class="ml-1 text-xs font-normal text-muted-foreground">Cr</span>' : ''}
            </td>
            <td class="table-cell w-64 text-[0.8125rem]">${outcome(row)}</td>
        </tr>`).join('');

    const post = inForm('#import-opening');

    // Enabled only when there is something to post and nothing to fix. A
    // half-imported opening balance is harder to unpick than none, so the
    // server refuses the lot too — this is so nobody has to find that out by
    // pressing the button.
    post.disabled = summary.errors > 0 || summary.ready === 0;
}

function creationNote(row) {
    if (!row.creates?.length) return '';

    const words = row.creates.map((what) => (what.startsWith('role:')
        ? `marks them a ${what.slice(5)}`
        : `new ${what}`));

    return `<div class="mt-0.5 text-xs text-primary">${esc(words.join(' · '))}</div>`;
}

function outcome(row) {
    if (row.outcome === 'ready') {
        return '<span class="text-emerald-700">Will post</span>';
    }

    const tone = row.outcome === 'error' ? 'text-rose-700' : 'text-muted-foreground';

    return `<span class="${tone}">${esc(row.reason ?? '')}</span>`;
}

/**
 * Any edit after a preview takes the "post" button away again.
 *
 * Without this, somebody could check a file, correct a figure, and commit the
 * version they had *not* looked at — which is precisely the mistake the preview
 * exists to prevent.
 */
function invalidate() {
    state.checked = null;
    state.checkedCsv = '';
    inForm('#import-opening').disabled = true;
    inForm('#preview-panel').classList.add('hidden');
}

/* -------------------------------------------------------------------------
 | Import
 | ---------------------------------------------------------------------- */

async function commit() {
    if (!state.checked || inForm('#opening-csv').value !== state.checkedCsv) {
        toast('Check the rows again — they have changed since the last look.', 'error');
        invalidate();

        return;
    }

    const { summary } = state.checked;

    const confirmed = await confirmAction({
        title: 'Post these opening balances?',
        body: `${summary.ready} declaration${summary.ready === 1 ? '' : 's'} will be posted, `
            + `leaving an owner's stake of ${formatMoney(summary.owners_stake, { sign: true })}. `
            + 'Opening balances go into the books like any other transaction: to change one afterwards '
            + 'you reverse it, which leaves both the mistake and the correction on the record.',
        confirmLabel: 'Post them',
        tone: 'primary',
    });

    if (!confirmed) return;

    const button = inForm('#import-opening');

    button.disabled = true;

    try {
        const response = await auth.call('/opening-balances', { method: 'POST', body: payload() });

        toast(response.message ?? 'Opening balances posted.');

        /*
        | §2A.8 — the declaration stays on the form with the box cleared, and the
        | receipt just written is flagged so it is highlighted the next time the
        | receipts are looked at. There is no focus to return: the next thing
        | anybody does here is paste another file, not type a field.
        */
        if (response.data?.id) workspace?.flagNew(response.data.id);

        inForm('#opening-csv').value = '';
        invalidate();

        await load();
    } catch (error) {
        toast(error.message, 'error');
        button.disabled = false;
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

const SAMPLE = `kind,name,variant,type,quantity,unit_cost,amount,account
stock,Ball Bearing,6204,part,10,120.00,,
stock,Copper Wire,22 SWG,bulk_material,40,720.00,,
stock,Induction Motor,5 HP / 3 ph / 1440 RPM,motor,2,8200.00,,
receivable,Sharma Motors,,,,,15000.00,
payable,Kohli Traders,,,,,32000.00,
balance,,,,,,40000.00,Cash in Hand`;

export default async function initOpening() {
    const root = $('[data-ws-form]').closest('[data-module-root]');

    formRoot = $('[data-ws-form]', root);
    listRoot = $('[data-ws-list]', root);
    openingForm = $('#opening-form', formRoot);

    /*
    | Declaring is the create act, so the module lands on it. Both grants,
    | because that is exactly what the endpoint asks for: landing somebody on a
    | form the server would refuse is worse than landing them on the receipts.
    */
    const canDeclare = can('UPDATE', 'WORKSPACE') && can('WRITE', 'TRANSACTIONS');

    workspace = mountWorkspace(root, {
        key: 'opening',
        title: 'Opening balances',
        formSubtitle: 'What the workshop already had on the day the books opened — stock on the shelf, money '
            + 'customers owed, money owed to suppliers, cash in the till. Declared once, before anything else '
            + 'is entered, because every figure this product reports is wrong by whatever was there already.',
        listSubtitle: (count) => (count === null
            ? 'Every declaration that has been posted.'
            : `${count} import${count === 1 ? '' : 's'}. A receipt for a decision, not a position — the trial `
                + 'balance on the declaration is what says whether the position is right.'),
        createLabel: 'Declare balances',
        count: () => state.history?.length ?? null,
        canCreate: canDeclare,
        // Both surfaces are painted by `load()`, so there is nothing left for a
        // first Show to fetch — unless the first call failed, which is the retry
        // this leaves open.
        onShowList: async () => { if (!held) await load(); },
    });

    /*
    | The position is a copy of the books, and the go-live date on it is a copy
    | of a setting on another screen. Marking only — the refetch waits for the
    | module to be looked at again, or a write in one module would be loading
    | another module's data (§7.2, `data-bus.js`).
    |
    | Subscribed here rather than through `refreshOn` because what goes behind is
    | on the *form*: `refreshOn` refreshes a list when the list is next shown,
    | and the tiles somebody reads before declaring would stay wrong.
    */
    onChange(['ledger'], () => { held = false; });

    root.addEventListener('module:shown', () => {
        // Back on screen after something wrote. Nothing to hand a rejection to:
        // `load()` paints its own failure into the receipts.
        if (!held) load().catch(() => {});
    });

    await Promise.all([loadMeta(), load()]);

    openingForm.addEventListener('submit', preview);
    inForm('#import-opening').addEventListener('click', commit);
    inForm('#opening-csv').addEventListener('input', invalidate);
    inForm('#opening-date').addEventListener('change', invalidate);

    inForm('#load-sample').addEventListener('click', () => {
        inForm('#opening-csv').value = SAMPLE;
        invalidate();
    });
}
