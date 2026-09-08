import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, downloadCsv, esc, formatDate,
    formatMoney, isZeroAmount, setSubmitting, showFormErrors, showModal, toast,
} from '../ui';
import { adoptForm, mountWorkspace } from '../workspace';

/**
 * Accounting — C5. The chart of accounts, and the proof that the books balance.
 *
 * ```
 * card → CREATE ACCOUNT            ← always lands here (§2A.1, §2A.5)
 *      → "Show list (37)"          → the books, in two views over one period
 *           ├── Chart of accounts  — every account, grouped, what it stands at
 *           └── Trial balance      — with the reconciliation stated, not implied
 *      → row → drawer (level 2)    → the running statement, the CSV, Edit
 *      → confirm (level 3)
 * ```
 *
 * ## Why this is one module and not two
 *
 * Accounting and Ledger were separate cards answering one question at two zoom
 * levels — what an account stands at — and the old code said so itself: this
 * drawer showed ten entries because "the full statement is the Ledger screen's
 * job". Two cards would have needed two period pickers and two trial-balance
 * renderers, and the second copy of each is the one that drifts (§4.4, §5.1).
 * So the `ledger` key is gone from the registry and its screen with it; what it
 * could do that nothing else could — the **trial balance** — is the second view
 * here.
 *
 * ## One fetch, two views
 *
 * `GET /ledger/trial-balance` over the chosen period is the whole of both. The
 * trial balance renders its rows; the chart renders the same figures against the
 * accounts they belong to, with an account the period never touched standing at
 * zero. There is deliberately no second arithmetic and no per-account balance
 * request — a chart of forty accounts would be forty round trips for a column.
 *
 * ## Two grants, and what is *removed*
 *
 * The module is gated on `READ:ACCOUNTS`; every figure on it needs `READ:LEDGER`
 * as well, and neither implies the other. What the caller may not read is taken
 * out of the DOM rather than blanked — see {@link applyGrantVisibility}. A
 * column of dashes reads as "every account is at zero", which is a claim about
 * the books rather than about the reader's permissions.
 *
 * ## The filters narrow the chart and never the trial balance
 *
 * This is the part of the screen that would be wrong in a way that looks right.
 * A trial balance's totals come from the server, over every account with
 * movement in the period — including archived ones, which still hold whatever
 * was posted to them. Filter its rows in the browser and the columns stop adding
 * up to the figures beneath them, with nothing on screen saying so. So the
 * search box and the archived select belong to the chart and are hidden on the
 * other view; the period is the one control both share.
 *
 * ## Why the chart is fetched whole, archived accounts included
 *
 * `is_active` is a filter the index endpoint accepts, and it is deliberately not
 * used. **An archived account still owns its code.** Ask for the active ones and
 * the form's next-free-code suggestion cannot see the rest, so it offers a
 * number the server then refuses — a 422 on a field the screen had filled in
 * itself. A chart of accounts is bounded and small, so one request holds all of
 * it and the archived select narrows what is drawn.
 */

/** A chart of accounts is small by nature: the whole of it, in one request. */
const CHART_PAGE_SIZE = 200;

/** The window of movement the drawer shows before somebody asks for the file. */
const STATEMENT_WINDOW = 10;

/** The API's ceiling on `per_page`, and the guard on how many pages a CSV walks. */
const STATEMENT_PAGE_SIZE = 200;
const STATEMENT_PAGE_LIMIT = 25;

/** Canonical statement order, not alphabetical. */
const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense'];

const state = {
    view: 'chart',

    search: '',
    isActive: '1',
    from: '',
    to: '',

    /** `{ asset: {label, code_range, normal_balance, is_balance_sheet}, … }` */
    types: {},

    accounts: [],
    total: null,

    /*
    | account id -> its trial-balance row. Absent for an account the period never
    | touched, which is why every read goes through `balanceOf` rather than
    | indexing this directly: "no row" means zero, and that is the one case where
    | a zero is the honest answer rather than a guess.
    */
    balances: {},
    trial: null,
    balanceError: null,

    /** Collapsed groups on the chart, by type. Everything starts open. */
    collapsed: {},

    canLedger: false,

    /** The account the drawer is open on, and where its statement has got to. */
    current: null,
    entryPage: 1,
};

let root = null;
let formRoot = null;
let listRoot = null;
let form = null;
let workspace = null;

/** The id being edited in the drawer, or null while the form is the create surface. */
let editing = null;

/**
 * Has the chart been fetched at all?
 *
 * Not the same question as "is the list held". The *form* needs the chart too —
 * a free code cannot be suggested without knowing which are taken — so it is
 * fetched on the first type chosen as well as on the first Show, and whichever
 * happens first pays for it.
 */
let chartLoaded = false;

/*
| Each surface's own node, held from mount.
|
| §2A.2 keeps exactly one of the form and the list attached, so for half the
| module's life the other is not a descendant of `document` at all and every
| lookup into it comes back null. Querying a node works detached; querying
| `document` for it does not.
*/
const inForm = (selector) => $(selector, formRoot);
const inList = (selector) => $(selector, listRoot);

/** The drawer, which lives outside both surfaces and is always attached. */
const el = (selector) => $(selector, root);

/* -------------------------------------------------------------------------
 | Money
 |
 | Amounts are decimal strings from the API and stay strings the whole way
 | through. Anything that has to be added is added in whole paise as integers —
 | `Number('0.10')` is a binary float, and a column of those totals a paisa out
 | from the ledger it claims to be reporting.
 | ---------------------------------------------------------------------- */

function toPaise(amount) {
    const text = String(amount ?? '0').trim();

    if (!/^-?\d+(\.\d+)?$/.test(text)) return 0;

    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace(/^-/, '').split('.');
    const paise = Number(whole) * 100 + Number((fraction + '00').slice(0, 2));

    return negative ? -paise : paise;
}

function paiseToAmount(paise) {
    const sign = paise < 0 ? '-' : '';
    const absolute = Math.abs(paise);

    return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

/**
 * What an account stands at over the chosen period, as `{amount, side}`.
 *
 * Null — not zero — when there are no figures to read, so a caller can tell
 * "nothing posted" from "not yours to see" and render each differently.
 */
function balanceOf(account) {
    if (!state.canLedger || state.trial === null) return null;

    const row = state.balances[account.id];

    return row
        ? { amount: row.balance, side: row.balance_side }
        : { amount: '0.00', side: account.normal_balance };
}

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

/** The period, as the two read endpoints both take it. */
function period() {
    const params = new URLSearchParams();

    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    return params;
}

function periodLabel() {
    if (!state.from && !state.to) return 'over the whole of the books';
    if (state.from && state.to) return `between ${formatDate(state.from)} and ${formatDate(state.to)}`;

    return state.from ? `from ${formatDate(state.from)}` : `up to ${formatDate(state.to)}`;
}

/** Type metadata comes from the server, so the code bands are never copied here. */
async function loadTypes() {
    if (Object.keys(state.types).length) return;

    const { data } = await auth.call('/accounts/types');

    state.types = Object.fromEntries(data.map((type) => [type.value, type]));
}

/** The whole chart, archived accounts included — see the note in the header. */
async function loadAccounts() {
    const payload = await auth.call(`/accounts?per_page=${CHART_PAGE_SIZE}`);

    state.accounts = payload.data;
    state.total = payload.data.length;
    chartLoaded = true;
}

/** Fetch the chart if nothing has yet, and say nothing if it fails. */
async function ensureChart() {
    if (chartLoaded) return;

    try {
        await loadAccounts();
    } catch {
        // The suggestion is a convenience; the band is on the hint either way,
        // and the server is the authority on both (§6.1).
    }
}

/**
 * Every figure on the screen, in one request.
 *
 * A failure here is not a failure of the module: the chart still reads and says
 * so where the balances would have been, rather than the whole screen going red
 * because one of two calls did.
 */
async function loadBalances() {
    if (!state.canLedger) return;

    try {
        const payload = await auth.call(`/ledger/trial-balance?${period()}`);

        state.trial = payload;
        state.balances = Object.fromEntries(payload.data.map((row) => [row.account.id, row]));
        state.balanceError = null;
    } catch (error) {
        state.trial = null;
        state.balances = {};
        state.balanceError = error;
    }
}

/** The list's one load: the chart, and the figures over it. */
async function load() {
    paintLoading();

    try {
        await loadAccounts();
    } catch (error) {
        state.accounts = [];
        state.total = null;

        paintChartFailure(failureText(error));
        workspace?.refresh();

        return;
    }

    await loadBalances();

    render();
}

/** What every change to a filter or the period means. */
const refetch = debounce(load, 250);

/**
 * A platform super-admin holds every grant and belongs to no workshop, so they
 * can reach this module and there is nothing to show them. That is a situation,
 * not a mistake on their part.
 */
function failureText(error) {
    return error.code === 'NO_WORKSPACE'
        ? 'Your account administers the platform rather than a single workshop, so it has no books of '
          + 'its own. Open a workshop from the workspaces list to see its accounts.'
        : error.message;
}

/* -------------------------------------------------------------------------
 | Shared rendering
 | ---------------------------------------------------------------------- */

/** One dot colour per type, so a group reads as its own block at a glance. */
const TYPE_TINT = {
    asset: { bg: 'bg-blue-50', text: 'text-blue-600' },
    liability: { bg: 'bg-rose-50', text: 'text-rose-500' },
    equity: { bg: 'bg-purple-50', text: 'text-purple-600' },
    income: { bg: 'bg-emerald-50', text: 'text-emerald-600' },
    expense: { bg: 'bg-amber-50', text: 'text-amber-500' },
};

const tintOf = (type) => TYPE_TINT[type] ?? { bg: 'bg-muted', text: 'text-muted-foreground' };

function typeLabel(account) {
    return account.type_label ?? state.types[account.type]?.label ?? account.type;
}

function statusBadge(isActive) {
    return isActive
        ? '<span class="badge bg-emerald-50 text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-500"></span>Active</span>'
        : '<span class="badge bg-muted text-muted-foreground"><span class="size-1.5 rounded-full bg-muted-foreground"></span>Archived</span>';
}

/** A balance as "12,340.00 Dr", an em-dash when flat, nothing when unreadable. */
function balanceCell(account) {
    const balance = balanceOf(account);

    if (balance === null) return '';

    if (isZeroAmount(balance.amount)) return '<span class="text-muted-foreground">—</span>';

    return `${esc(formatMoney(balance.amount))}
            <span class="ml-1 text-[0.6875rem] font-normal text-muted-foreground">${
                balance.side === 'debit' ? 'Dr' : 'Cr'
            }</span>`;
}

function stateBlock(text, tone = 'muted') {
    return `<div class="surface px-4 py-12 text-center text-sm ${
        tone === 'error' ? 'text-rose-600' : 'text-muted-foreground'
    }">${esc(text)}</div>`;
}

function stateRow(colspan, text, tone = 'muted') {
    return `<tr><td colspan="${colspan}" class="px-4 py-12 text-center text-sm ${
        tone === 'error' ? 'text-rose-600' : 'text-muted-foreground'
    }">${esc(text)}</td></tr>`;
}

function paintLoading() {
    inList('[data-chart-groups]').innerHTML = stateBlock('Reading the chart of accounts…');
    inList('[data-chart-tiles]').innerHTML = '';

    const body = inList('[data-trial-body]');

    if (body) body.innerHTML = stateRow(5, 'Working out the trial balance…');
}

function paintChartFailure(text) {
    inList('[data-chart-groups]').innerHTML = stateBlock(text, 'error');
    inList('[data-chart-tiles]').innerHTML = '';
    inList('[data-chart-summary]').textContent = '';

    const body = inList('[data-trial-body]');

    if (body) {
        body.innerHTML = stateRow(5, text, 'error');
        inList('[data-trial-foot]').innerHTML = '';
        inList('[data-trial-summary]').textContent = '';
        inList('[data-reconciliation]').innerHTML = '';
    }
}

function render() {
    renderChart();
    renderTrial();
    workspace?.refresh();
}

/* -------------------------------------------------------------------------
 | View 1 — the chart of accounts
 |
 | Five collapsible blocks, which is how an accountant reads one. A group's
 | total is the sum of its accounts' balances in paise; summing a column of
 | balances is only meaningful *within* a type, so there is deliberately no grand
 | total — assets plus expenses is not a number anybody wants.
 | ---------------------------------------------------------------------- */

/** The chart the archived select leaves — the population the tiles count. */
function chartAccounts() {
    if (state.isActive === '') return state.accounts;

    const wanted = state.isActive === '1';

    return state.accounts.filter((account) => account.is_active === wanted);
}

/**
 * The accounts the search leaves as well, in statement order.
 *
 * Searching reaches the code as well as the name: somebody looking for "4002"
 * is after an account by its number, and the number is the one thing about an
 * account that never changes.
 */
function visibleAccounts() {
    const needle = state.search.trim().toLowerCase();

    return chartAccounts()
        .filter((account) => !needle
            || account.name.toLowerCase().includes(needle)
            || String(account.code).includes(needle)
            || (account.description ?? '').toLowerCase().includes(needle))
        .sort((a, b) => TYPE_ORDER.indexOf(a.type) - TYPE_ORDER.indexOf(b.type)
            || String(a.code).localeCompare(String(b.code)));
}

/**
 * A group's position, in paise, signed against the type's own normal side.
 *
 * **Not the sum of the balance column, and this is the one that looks right and
 * is not.** A row shows an absolute figure and the side it fell out on, which is
 * what a reader wants of one account. Add that column up and an overdrawn bank —
 * a credit sitting in a block of debits — is *added* to the workshop's assets
 * rather than netted out of them, so a shop with ₹72,950 in the till and a bank
 * ₹40,000 down reports assets of ₹1,12,950 instead of ₹32,950.
 *
 * `signed_balance` is the server's answer to exactly this question: positive
 * where an account stands on its own normal side, negative where it does not.
 * There is no second arithmetic here (§4.4).
 */
function groupTotalPaise(accounts) {
    return accounts.reduce((total, account) => {
        const row = state.balances[account.id];

        return row ? total + toPaise(row.signed_balance) : total;
    }, 0);
}

/** That position as "1,34,464.00 Dr" — on the other side when it went negative. */
function groupTotal(type, accounts) {
    const paise = groupTotalPaise(accounts);
    const amount = formatMoney(paiseToAmount(Math.abs(paise)));

    // A group that nets to nothing is on neither side, and "0.00 Cr" would be
    // claiming one.
    if (paise === 0) return amount;

    const normal = state.types[type]?.normal_balance ?? 'debit';

    return `${amount} ${(normal === 'debit') === (paise > 0) ? 'Dr' : 'Cr'}`;
}

function renderChart() {
    const host = inList('[data-chart-groups]');
    const rows = visibleAccounts();
    const population = chartAccounts();

    /*
    | Each group is carried with the whole of its type beside it. A group header
    | states a *total*, and a total of whatever a search happened to leave is not
    | an accounting figure — so the header needs to know it is looking at a
    | subset, and says how many of how many instead.
    */
    const grouped = TYPE_ORDER
        .map((type) => [
            type,
            rows.filter((account) => account.type === type),
            population.filter((account) => account.type === type),
        ])
        .filter(([, accounts]) => accounts.length);

    renderChartTiles();

    if (!grouped.length) {
        host.innerHTML = stateBlock(state.accounts.length
            ? 'No accounts match this search and filter.'
            : 'Nothing on this chart yet. A workshop is seeded with fifteen accounts the moment it is '
              + 'provisioned, so an empty chart usually means the books belong to another workshop.');
        inList('[data-chart-summary]').textContent = '';

        return;
    }

    host.innerHTML = grouped
        .map(([type, accounts, whole]) => renderChartGroup(type, accounts, whole))
        .join('');

    inList('[data-chart-summary]').textContent = rows.length === state.accounts.length
        ? `${rows.length} account${rows.length === 1 ? '' : 's'} across ${grouped.length} type${
            grouped.length === 1 ? '' : 's'}.`
        : `Showing ${rows.length} of ${state.accounts.length} accounts.`;
}

function renderChartTiles() {
    const host = inList('[data-chart-tiles]');
    const showTotals = state.canLedger && state.trial !== null;
    const population = chartAccounts();

    host.innerHTML = TYPE_ORDER.map((type) => {
        const accounts = population.filter((account) => account.type === type);

        if (!accounts.length) return '';

        const meta = state.types[type] ?? {};
        const tint = tintOf(type);

        return `
            <div class="stat-tile !gap-2.5 !p-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-[7px] ${tint.bg} ${tint.text}">
                    ${iconDot}
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-[0.6875rem] text-muted-foreground">
                        ${esc(meta.label ?? type)} · ${accounts.length}
                    </span>
                    <span class="block truncate text-[0.84375rem] font-bold text-foreground">
                        ${showTotals
                            ? esc(groupTotal(type, accounts))
                            : `${accounts.length} account${accounts.length === 1 ? '' : 's'}`}
                    </span>
                </span>
            </div>`;
    }).join('');
}

function renderChartGroup(type, accounts, whole) {
    const meta = state.types[type] ?? {};
    const tint = tintOf(type);
    const [low, high] = meta.code_range ?? [];
    const open = !state.collapsed[type];
    const mayWrite = can('WRITE', 'ACCOUNTS');
    const partial = accounts.length !== whole.length;
    const showBalances = state.canLedger && state.trial !== null;

    // A row's own balance is a fact about that row; a group *total* over
    // whatever a search happened to leave is not a figure anybody wants. So the
    // header states one only when it is over the whole group.
    const showTotal = showBalances && !partial;

    const rows = accounts.map((account) => `
        <div class="flex cursor-pointer items-center gap-4 border-b border-muted px-5 py-3 transition
                    last:border-b-0 hover:bg-secondary/60 ${account.is_active ? '' : 'opacity-60'}${
                        workspace?.isNew(account.id) ? ' row-new' : ''}"
             data-account="${account.id}" tabindex="0" role="button"
             aria-label="Open ${esc(account.name)}">

            <span class="flex w-6 shrink-0 justify-center">
                <span class="h-4 w-px bg-border"></span>
            </span>

            <span class="flex min-w-0 flex-1 items-center gap-2">
                <span class="truncate text-[0.8125rem] font-medium text-secondary-foreground">
                    ${esc(account.name)}
                </span>
                ${account.is_system ? iconLock : ''}
                ${account.is_active ? '' : '<span class="badge bg-muted text-muted-foreground">Archived</span>'}
            </span>

            <code class="shrink-0 rounded bg-muted px-2 py-0.5 font-mono text-[0.6875rem] text-muted-foreground">${
                esc(String(account.code))
            }</code>

            ${showBalances ? `
                <span class="w-32 shrink-0 text-right font-mono text-[0.8125rem] font-semibold text-foreground">
                    ${balanceCell(account)}
                </span>` : ''}
        </div>`).join('');

    return `
        <section class="surface overflow-hidden rounded-[14px]">
            <button type="button" class="flex w-full items-center gap-3 px-5 py-3.5 text-left transition
                                         hover:bg-secondary/60"
                    data-group="${type}" aria-expanded="${open}">
                <span class="grid size-8 shrink-0 place-items-center rounded-[8px] ${tint.bg} ${tint.text}">
                    ${iconDot}
                </span>

                <span class="flex-1">
                    <span class="block text-sm font-bold text-foreground">${esc(meta.label ?? type)}</span>
                    <span class="block text-[0.71875rem] text-muted-foreground">
                        ${partial
                            ? `${accounts.length} of ${whole.length} accounts`
                            : `${whole.length} account${whole.length === 1 ? '' : 's'}`}
                        ${low ? ` · band ${low}–${high}` : ''}
                        ${showTotal ? ` · ${esc(groupTotal(type, whole))}` : ''}
                    </span>
                </span>

                <span class="text-muted-foreground ${open ? '' : '-rotate-90'} transition-transform">
                    ${iconChevronDown}
                </span>
            </button>

            ${open ? `
                <div class="border-t border-muted">
                    ${rows}
                    ${mayWrite && !state.search ? `
                        <div class="border-t border-muted px-5 py-2.5">
                            <button type="button" data-add-to="${type}"
                                    class="flex items-center gap-1.5 text-[0.78125rem] font-medium text-primary
                                           transition hover:text-primary/80">
                                ${iconPlus}
                                Add an account to ${esc(meta.label ?? type)}
                            </button>
                        </div>` : ''}
                </div>` : ''}
        </section>`;
}

/* -------------------------------------------------------------------------
 | View 2 — the trial balance
 | ---------------------------------------------------------------------- */

function renderTrial() {
    const body = inList('[data-trial-body]');

    // Removed for a caller without READ:LEDGER, along with the switch that would
    // have reached it.
    if (!body) return;

    if (state.trial === null) {
        const text = state.balanceError
            ? failureText(state.balanceError)
            : 'The trial balance has not been read yet.';

        body.innerHTML = stateRow(5, text, 'error');
        inList('[data-trial-foot]').innerHTML = '';
        inList('[data-trial-summary]').textContent = '';
        inList('[data-reconciliation]').innerHTML = '';

        return;
    }

    const rows = state.trial.data;
    const meta = state.trial.meta;

    body.innerHTML = rows.length
        ? rows.map((row) => `
            <tr class="cursor-pointer border-t border-border transition hover:bg-secondary/60"
                data-account="${row.account.id}" tabindex="0" role="button"
                aria-label="Open the ledger for ${esc(row.account.name)}">
                <td class="table-cell">
                    <span class="font-mono text-[0.8125rem] text-muted-foreground">${esc(String(row.account.code))}</span>
                    <span class="ml-2 font-medium">${esc(row.account.name)}</span>
                    ${row.account.is_active ? '' : '<span class="badge ml-2 bg-muted text-muted-foreground">Archived</span>'}
                </td>
                <td class="table-cell w-32 text-[0.8125rem] text-muted-foreground">${esc(row.account.type_label)}</td>
                <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">${esc(formatMoney(row.debit))}</td>
                <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">${esc(formatMoney(row.credit))}</td>
                <td class="table-cell w-40 text-right font-mono text-[0.8125rem] font-semibold">
                    ${isZeroAmount(row.balance)
                        ? '—'
                        : `${esc(formatMoney(row.balance))} <span class="ml-1 text-xs font-normal text-muted-foreground">${
                            row.balance_side === 'debit' ? 'Dr' : 'Cr'}</span>`}
                </td>
            </tr>`).join('')
        // Correct rather than empty: a workshop that has posted nothing has a
        // trial balance of 0 = 0.
        : stateRow(5, 'Nothing has been posted in this period, so every account stands at zero.');

    inList('[data-trial-foot]').innerHTML = `
        <tr class="border-t-2 border-border bg-secondary/30 text-sm font-semibold">
            <td class="px-4 py-3 text-right text-muted-foreground" colspan="2">Totals</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(meta.totals.debit))}</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(meta.totals.credit))}</td>
            <td class="px-4 py-3 text-right font-mono">
                ${esc(formatMoney(meta.balances.debit))}
                <span class="text-xs font-normal text-muted-foreground">Dr</span>
                / ${esc(formatMoney(meta.balances.credit))}
                <span class="text-xs font-normal text-muted-foreground">Cr</span>
            </td>
        </tr>`;

    inList('[data-trial-summary]').textContent = rows.length
        ? `${rows.length} account${rows.length === 1 ? '' : 's'} with movement ${periodLabel()}. `
          + 'An account nothing was posted to in this period is not listed; it is on the chart, at zero.'
        : '';

    renderReconciliation(meta);
}

/**
 * The one figure that matters, stated rather than left to be worked out.
 *
 * If the two sides differ, everything else on this screen is suspect — so it is
 * said in words above the table instead of being inferred from two columns a
 * reader would have to compare themselves.
 */
function renderReconciliation(meta) {
    inList('[data-reconciliation]').innerHTML = meta.is_balanced
        ? `<div class="surface flex flex-wrap items-center gap-x-2 gap-y-1 border-emerald-200 bg-emerald-50/60
                       px-4 py-3 text-[0.8125rem] text-emerald-800">
               <span class="font-semibold">The books balance.</span>
               <span>
                   Debits and credits both total ${esc(formatMoney(meta.totals.debit))} ${esc(periodLabel())}.
               </span>
           </div>`
        : `<div class="surface flex flex-wrap items-center gap-x-2 gap-y-1 border-rose-200 bg-rose-50
                       px-4 py-3 text-[0.8125rem] text-rose-700">
               <span class="font-semibold">The books do not balance.</span>
               <span>
                   Debits exceed credits by ${esc(formatMoney(meta.difference))}. The posting engine refuses
                   an unbalanced entry, so this should be impossible — please report it before entering
                   anything further.
               </span>
           </div>`;
}

/* -------------------------------------------------------------------------
 | Icons
 | ---------------------------------------------------------------------- */

const svg = (paths, size = 16) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;

const iconLock = `<span class="shrink-0 text-border" title="System account">${
    svg('<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 11)}</span>`;
const iconChevronDown = svg('<path d="m6 9 6 6 6-6"/>', 15);
const iconPlus = svg('<path d="M5 12h14"/><path d="M12 5v14"/>', 13);
const iconDot = svg('<circle cx="12" cy="12" r="7"/>', 13);
const iconCheck = svg('<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>', 15);

/* -------------------------------------------------------------------------
 | One account — level 2
 |
 | The running statement over the period the list is set to, the details behind
 | it, and the acts a chart of accounts permits: rename it, renumber it, or take
 | it out of the pickers. There is no delete, and there is no route for one — an
 | account that has been posted to must survive or its journal entries lose the
 | name that explains them.
 | ---------------------------------------------------------------------- */

async function openDrawer(id) {
    const account = state.accounts.find((row) => String(row.id) === String(id));

    if (!account) return;

    state.current = account;
    state.entryPage = 1;

    closeEdit();

    el('#account-drawer-title').textContent = account.name;
    el('[data-drawer-subtitle]').textContent =
        `${account.code} · ${typeLabel(account)}${account.is_system ? ' · System account' : ''}`;
    el('[data-drawer-status]').innerHTML = statusBadge(account.is_active);

    const balance = balanceOf(account);

    el('[data-drawer-body]').innerHTML = `
        ${balance === null ? '' : `
            <div class="mb-5 rounded-[12px] border border-border bg-secondary/40 px-4 py-3.5">
                <p class="text-[0.6875rem] uppercase tracking-wide text-muted-foreground">
                    Balance ${esc(periodLabel())}
                </p>
                <p class="mt-1 font-mono text-[22px] font-bold leading-none text-foreground">
                    ${esc(formatMoney(balance.amount))}
                    <span class="text-sm font-normal text-muted-foreground">${
                        balance.side === 'debit' ? 'Dr' : 'Cr'}</span>
                </p>
            </div>`}

        <h4 class="section-label mb-2">Account</h4>
        <dl class="mb-5 space-y-2 text-[0.8125rem]">
            ${detail('Name', account.name)}
            ${detail('Type', typeLabel(account))}
            ${detail('Code', String(account.code), 'font-mono')}
            ${detail('Increases on', account.normal_balance === 'debit' ? 'Debit' : 'Credit')}
            ${detail('Statement', account.is_balance_sheet ? 'Balance sheet' : 'Profit & loss')}
            ${detail('Last updated', formatDate(account.updated_at))}
            ${detail('System account', account.is_system ? 'Yes' : 'No')}
            ${account.description ? detail('What belongs in it', account.description) : ''}
        </dl>

        ${state.canLedger ? `
            <h4 class="section-label mb-2">Running statement</h4>
            <div data-statement class="text-[0.8125rem] text-muted-foreground">Loading entries…</div>`
        : ''}`;

    paintDrawerActions(account);

    showModal(el('#account-drawer'));

    if (state.canLedger) loadStatement();
}

function detail(label, value, extraClass = '') {
    return `
        <div class="flex items-start justify-between gap-4">
            <dt class="shrink-0 text-muted-foreground">${esc(label)}</dt>
            <dd class="text-right font-medium text-foreground ${extraClass}">${esc(value ?? '—')}</dd>
        </div>`;
}

const SYSTEM_ARCHIVE_REASON =
    'The posting engine finds this account by an internal key, so archiving it would break the templates '
    + 'that post to it.';

/**
 * The drawer's footer.
 *
 * A system account's archive control is **disabled with its reason beside it**
 * rather than hidden: archiving one would break a template rather than tidy a
 * list, and that is the answer to a question somebody is asking right here. A
 * grant the caller does not hold is the other case and the control is absent,
 * because there is no answer to give.
 */
function paintDrawerActions(account) {
    const parts = [];
    const locked = can('UPDATE', 'ACCOUNTS') && account.is_system;

    if (can('UPDATE', 'ACCOUNTS')) {
        parts.push('<button type="button" class="btn btn-secondary btn-sm" data-edit-account>Edit</button>');

        parts.push(account.is_system
            ? `<button type="button" class="btn btn-secondary btn-sm" disabled
                       title="${esc(SYSTEM_ARCHIVE_REASON)}">Archive</button>`
            : `<button type="button" class="btn btn-secondary btn-sm" data-toggle-archived>${
                account.is_active ? 'Archive' : 'Restore'}</button>`);
    }

    if (state.canLedger) {
        parts.push('<button type="button" class="btn btn-secondary btn-sm" data-statement-csv>Statement CSV</button>');
    }

    parts.push('<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>');

    // Last, and on its own line: the controls stay together on one row, and the
    // reason sits under the control it explains rather than between two of them.
    if (locked) {
        parts.push(`<p class="w-full text-[0.71875rem] text-muted-foreground">${esc(SYSTEM_ARCHIVE_REASON)}</p>`);
    }

    el('[data-drawer-actions]').innerHTML = parts.join('');
}

/**
 * The window of movement on this account, oldest first with a running balance.
 *
 * A page rather than the whole ledger: the drawer answers "what has been
 * happening here", and the whole of it is the CSV below it.
 */
async function loadStatement() {
    const account = state.current;
    const host = el('[data-statement]');

    if (!account || !host) return;

    const params = period();

    params.set('per_page', STATEMENT_WINDOW);
    params.set('page', state.entryPage);

    try {
        const payload = await auth.call(`/ledger/accounts/${account.id}?${params}`);

        // Still the drawer we started for? A fast second click would otherwise
        // paint one account's entries under another's name.
        if (state.current?.id !== account.id) return;

        renderStatement(payload);
    } catch (error) {
        if (state.current?.id !== account.id) return;

        host.innerHTML = `<p class="py-3 text-rose-600">${esc(failureText(error))}</p>`;
    }
}

function renderStatement(payload) {
    const host = el('[data-statement]');
    const entries = payload.data;
    const meta = payload.meta;
    const pagination = meta.pagination ?? {};

    if (!entries.length) {
        host.innerHTML = `<p class="py-3">Nothing was posted to this account ${esc(periodLabel())}.</p>`;

        return;
    }

    host.innerHTML = `
        <div class="overflow-hidden rounded-[10px] border border-border">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[420px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-secondary/40 text-left">
                            <th class="px-3 py-2 text-[0.6875rem] font-semibold text-muted-foreground">Date</th>
                            <th class="px-3 py-2 text-[0.6875rem] font-semibold text-muted-foreground">Particulars</th>
                            <th class="px-3 py-2 text-right text-[0.6875rem] font-semibold text-muted-foreground">Debit</th>
                            <th class="px-3 py-2 text-right text-[0.6875rem] font-semibold text-muted-foreground">Credit</th>
                            <th class="px-3 py-2 text-right text-[0.6875rem] font-semibold text-muted-foreground">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-muted">
                        <tr class="bg-secondary/20 text-[0.71875rem] italic text-muted-foreground">
                            <td class="px-3 py-2" colspan="4">Balance brought forward</td>
                            <td class="px-3 py-2 text-right font-mono not-italic">${esc(formatMoney(meta.opening_balance))}</td>
                        </tr>
                        ${entries.map((entry) => `
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap text-[0.71875rem] text-muted-foreground">
                                    ${esc(formatDate(entry.date))}
                                </td>
                                <td class="px-3 py-2 text-[0.75rem] text-foreground">
                                    ${esc(entry.transaction?.notes || entry.memo || 'Journal entry')}
                                    <span class="block text-[0.6875rem] text-muted-foreground">
                                        #${esc(String(entry.transaction_id))}${
                                            entry.transaction?.status === 'reversed' ? ' · reversed' : ''}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-[0.71875rem]">
                                    ${isZeroAmount(entry.debit) ? '' : esc(formatMoney(entry.debit))}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-[0.71875rem]">
                                    ${isZeroAmount(entry.credit) ? '' : esc(formatMoney(entry.credit))}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-[0.71875rem] font-semibold text-foreground">
                                    ${esc(formatMoney(entry.running_balance))}
                                </td>
                            </tr>`).join('')}
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-border bg-secondary/30 text-[0.71875rem] font-semibold">
                            <td class="px-3 py-2 text-right text-muted-foreground" colspan="2">Closing</td>
                            <td class="px-3 py-2 text-right font-mono">${esc(formatMoney(meta.period.debit))}</td>
                            <td class="px-3 py-2 text-right font-mono">${esc(formatMoney(meta.period.credit))}</td>
                            <td class="px-3 py-2 text-right font-mono">
                                ${esc(formatMoney(meta.closing_balance))}
                                <span class="ml-1 font-normal text-muted-foreground">${
                                    meta.normal_balance === 'debit' ? 'Dr' : 'Cr'}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <span class="text-[0.71875rem]">
                ${esc(String(pagination.total ?? entries.length))} entr${
                    (pagination.total ?? entries.length) === 1 ? 'y' : 'ies'} ${esc(periodLabel())}
            </span>

            ${(pagination.last_page ?? 1) > 1 ? `
                <span class="flex items-center gap-1">
                    <button type="button" class="btn btn-secondary btn-sm" data-entries-page="prev"
                            ${(pagination.current_page ?? 1) <= 1 ? 'disabled' : ''}>Previous</button>
                    <span class="px-1 text-[0.71875rem] text-muted-foreground">
                        ${esc(String(pagination.current_page ?? 1))} / ${esc(String(pagination.last_page))}
                    </span>
                    <button type="button" class="btn btn-secondary btn-sm" data-entries-page="next"
                            ${pagination.has_more ? '' : 'disabled'}>Next</button>
                </span>` : ''}
        </div>`;
}

/* -------------------------------------------------------------------------
 | Create, and correct
 |
 | One form node for both. `adoptForm()` moves it between the level-1 create
 | surface and the drawer, and the blocks marked `data-form-chrome` decide which
 | frame is shown. A module that rendered the same fields twice would have two
 | sets of ids, two submit handlers and two places for a validation rule to be
 | added to only one of (§4.4, §5.1).
 | ---------------------------------------------------------------------- */

/**
 * The lowest unused code in a type's band, so the common case needs no thought.
 * Steps by 10 to leave room for related accounts to sit together.
 */
function suggestCode(type) {
    const meta = state.types[type];

    if (!meta) return '';

    const [low, high] = meta.code_range;
    const taken = new Set(state.accounts.map((account) => Number(account.code)));

    for (let code = low + 10; code <= high; code += 10) {
        if (!taken.has(code)) return String(code);
    }

    return '';
}

function applyTypeHints(type, { suggest = false } = {}) {
    const meta = state.types[type];
    const typeHint = $('[data-type-hint]', form);
    const codeHint = $('[data-code-hint]', form);

    if (!meta) {
        typeHint.textContent = 'Decides which side increases the account.';
        codeHint.textContent = 'Four digits, inside the band for the chosen type.';

        return;
    }

    const [low, high] = meta.code_range;

    typeHint.textContent = `Increases on the ${meta.normal_balance === 'credit' ? 'credit' : 'debit'} side · ${
        meta.is_balance_sheet ? 'Balance sheet' : 'Profit & loss'}`;
    codeHint.textContent = `Must be between ${low} and ${high}.`;

    if (suggest && !form.elements.code.value) form.elements.code.value = suggestCode(type);
}

/** Empty the form for the next entry — §2A.8. */
function resetForm({ type = '' } = {}) {
    clearFormErrors(form);
    form.reset();

    form.elements.id.value = '';
    form.elements.type.value = type;
    form.elements.type.disabled = false;
    form.elements.code.disabled = false;

    $('[data-account-system-note]', form).classList.remove('flex');
    $('[data-account-system-note]', form).classList.add('hidden');

    applyTypeHints(type, { suggest: Boolean(type) });
}

function openEdit(account) {
    editing = account.id;

    clearFormErrors(form);

    form.elements.id.value = account.id;
    form.elements.name.value = account.name;
    form.elements.description.value = account.description ?? '';
    form.elements.code.value = account.code;
    form.elements.type.value = account.type;

    /*
    | Type is immutable for every account, system or not: reclassifying would
    | move every journal entry already posted against it onto a different
    | financial statement. The code is fixed for system accounts only, and both
    | are *disabled with the note above them* rather than removed — the reason
    | belongs where the question is asked.
    */
    form.elements.type.disabled = true;
    form.elements.code.disabled = account.is_system;

    const note = $('[data-account-system-note]', form);

    note.classList.toggle('hidden', !account.is_system);
    note.classList.toggle('flex', account.is_system);

    applyTypeHints(account.type);

    adoptForm(form, el('[data-account-edit-slot]'), { chrome: 'modal' });

    el('[data-account-edit-slot]').classList.remove('hidden');
    el('[data-drawer-body]').classList.add('hidden');
    el('[data-drawer-actions]').innerHTML =
        '<button type="button" class="btn btn-secondary btn-sm ml-auto" data-modal-close>Close</button>';
}

/**
 * Give the form back to the create surface.
 *
 * Idempotent, and called from four places — Cancel, a save, the drawer closing
 * and Escape — because a form left behind in a hidden drawer is a create surface
 * with no fields on it, and nothing on screen would say why.
 */
function closeEdit() {
    if (editing === null) return;

    editing = null;

    /*
    | Before the move, while the drawer's own Save is still the visible submit
    | button. `setSubmitting` restores whichever one is on screen — releasing it
    | afterwards would leave "Saving…", disabled, on the create surface's button.
    */
    setSubmitting(form, false);

    adoptForm(form, inForm('[data-account-form-slot]'), { chrome: 'inline' });

    el('[data-account-edit-slot]').classList.add('hidden');
    el('[data-drawer-body]').classList.remove('hidden');

    resetForm();

    if (state.current) paintDrawerActions(state.current);
}

/**
 * Checked here as well as server-side, so the band is explained before a round
 * trip rather than after a 422. The server is still the authority (§6.1).
 */
function validate(editingAccount) {
    const errors = {};

    if (!editingAccount && !form.elements.type.value) {
        errors.type = ['Choose an account type.'];
    }

    if (!form.elements.code.disabled) {
        const code = form.elements.code.value.trim();

        if (!/^\d{4}$/.test(code)) {
            errors.code = ['An account code is exactly four digits.'];
        } else {
            const meta = state.types[form.elements.type.value];

            if (meta) {
                const [low, high] = meta.code_range;

                if (Number(code) < low || Number(code) > high) {
                    errors.code = [`A ${meta.label.toLowerCase()} account is numbered between ${low} and ${high}.`];
                }
            }
        }
    }

    const name = form.elements.name.value.trim();

    if (name.length < 2) errors.name = ['The account name must be at least 2 characters.'];
    else if (name.length > 120) errors.name = ['The account name may not exceed 120 characters.'];

    if (form.elements.description.value.trim().length > 255) {
        errors.description = ['The description may not exceed 255 characters.'];
    }

    return Object.keys(errors).length ? errors : null;
}

async function submitForm(event) {
    event.preventDefault();

    const editingId = editing;

    clearFormErrors(form);

    const errors = validate(editingId !== null);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        description: form.elements.description.value.trim() || null,
    };

    // A disabled input is not submitted, and the server refuses a system
    // account's code outright — so it is only ever sent when it is editable.
    if (!form.elements.code.disabled) payload.code = form.elements.code.value.trim();
    if (editingId === null) payload.type = form.elements.type.value;

    setSubmitting(form, true, editingId === null ? 'Creating…' : 'Saving…');

    try {
        const response = await auth.call(editingId === null ? '/accounts' : `/accounts/${editingId}`, {
            method: editingId === null ? 'POST' : 'PATCH',
            body: payload,
        });

        if (editingId !== null) {
            closeEdit();
            toast('Account updated.');

            await refreshHeld();
            await reopenDrawer(editingId);

            return;
        }

        /*
        | §2A.8 — a clerk adding heads adds several in a row, so a create stays
        | on the form, clears it and puts the cursor back on the type. The new
        | row is flagged rather than shown: they never see the list in between.
        */
        const created = response.data;

        resetForm();
        form.elements.type.focus();

        workspace?.flagNew(created.id);

        paintOutcome(`
            <p><strong>${esc(created.code)} · ${esc(created.name)}</strong> is on the chart.</p>
            <button type="button" class="mt-1 font-semibold underline" data-outcome-account="${created.id}">
                Open the account
            </button>`);

        await refreshHeld();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

function paintOutcome(html) {
    const host = inForm('[data-account-outcome]');

    host.innerHTML = `<span class="mt-0.5 shrink-0">${iconCheck}</span><div>${html}</div>`;
    host.classList.remove('hidden');
    host.classList.add('flex');
}

/**
 * Bring whatever is held up to date after this module's own write.
 *
 * Two cases, because two things can be holding the chart. If the list has been
 * shown it is refetched with its figures; if only the form has ever needed it —
 * for a code suggestion — the chart is refreshed on its own, so the next
 * suggestion does not offer the number just used. A module nothing has fetched
 * yet fetches nothing here (§2A.7, §7.2).
 */
async function refreshHeld() {
    if (workspace?.hasList()) {
        await load();

        return;
    }

    if (chartLoaded) await loadAccounts().catch(() => {});
}

async function reopenDrawer(id) {
    try {
        const { data } = await auth.call(`/accounts/${id}`);

        // The list may not be held, so the drawer reads the record it was just
        // handed rather than looking for it among rows that were never fetched.
        const at = state.accounts.findIndex((row) => String(row.id) === String(id));

        if (at === -1) state.accounts.push(data);
        else state.accounts[at] = data;

        await openDrawer(id);
    } catch (error) {
        toast(failureText(error), 'error');
    }
}

/* -------------------------------------------------------------------------
 | Archive and restore
 |
 | There is no delete, and no route for one. An account that has been posted to
 | must survive or its journal entries lose the name that explains them, so
 | archiving takes it out of every picker and leaves its history intact.
 | ---------------------------------------------------------------------- */

async function toggleArchived(account) {
    if (account.is_active) {
        const confirmed = await confirmAction({
            title: 'Archive account',
            body: `${account.name} will stop appearing when choosing an account. Nothing already posted to `
                + 'it changes — accounts are never deleted, so its entries and every historical report keep '
                + 'its name. You can restore it at any time.',
            confirmLabel: 'Archive account',
        });

        if (!confirmed) return;
    }

    try {
        await auth.call(`/accounts/${account.id}`, {
            method: 'PATCH',
            body: { is_active: !account.is_active },
        });

        toast(account.is_active ? 'Account archived.' : 'Account restored.');

        await refreshHeld();
        await reopenDrawer(account.id);
    } catch (error) {
        toast(failureText(error), 'error');
    }
}

/* -------------------------------------------------------------------------
 | Export
 | ---------------------------------------------------------------------- */

const stamp = () => new Date().toISOString().slice(0, 10);

/** Whichever view is open, narrowed exactly as it is on screen. */
function exportCurrentView() {
    if (state.view === 'trial') {
        exportTrialBalance();

        return;
    }

    const rows = visibleAccounts();

    if (!rows.length) {
        toast('Nothing to export on this view.', 'info');

        return;
    }

    const withBalance = state.canLedger && state.trial !== null;

    downloadCsv(`chart-of-accounts-${stamp()}.csv`, [
        [
            'Code', 'Name', 'Type', 'Normal balance',
            ...(withBalance ? ['Balance', 'Side'] : []),
            'Status', 'System', 'Last updated',
        ],
        ...rows.map((account) => {
            const balance = balanceOf(account);

            return [
                account.code,
                account.name,
                typeLabel(account),
                account.normal_balance === 'debit' ? 'Debit' : 'Credit',
                ...(withBalance ? [balance.amount, balance.side === 'debit' ? 'Dr' : 'Cr'] : []),
                account.is_active ? 'Active' : 'Archived',
                account.is_system ? 'Yes' : 'No',
                account.updated_at ?? '',
            ];
        }),
    ]);
}

/** Totals included, because a trial balance without them is only a list. */
function exportTrialBalance() {
    if (state.trial === null) {
        toast('The trial balance has not been read, so there is nothing to export.', 'info');

        return;
    }

    const meta = state.trial.meta;

    downloadCsv(`trial-balance-${stamp()}.csv`, [
        ['Code', 'Name', 'Type', 'Debits', 'Credits', 'Balance', 'Side'],
        ...state.trial.data.map((row) => [
            row.account.code,
            row.account.name,
            row.account.type_label,
            row.debit,
            row.credit,
            row.balance,
            row.balance_side === 'debit' ? 'Dr' : 'Cr',
        ]),
        ['', 'Totals', '', meta.totals.debit, meta.totals.credit, '', ''],
        ['', 'Balances', '', meta.balances.debit, meta.balances.credit, '', ''],
    ]);
}

/**
 * One account's statement as a CSV — the whole of it, not the ten rows the
 * drawer happens to be showing. A statement that stopped at the tenth entry
 * would be a statement of nothing in particular.
 *
 * Walked a page at a time because `per_page` is capped at 200 server-side: the
 * single oversized request this replaced answered 422, so the control had never
 * produced a file at all.
 */
async function downloadStatement(account) {
    toast('Preparing the statement…', 'info');

    const entries = [];

    try {
        for (let page = 1; page <= STATEMENT_PAGE_LIMIT; page += 1) {
            const params = period();

            params.set('per_page', STATEMENT_PAGE_SIZE);
            params.set('page', page);

            // Sequential on purpose: the page after this one is only worth
            // asking for if this one says there is more.
            // eslint-disable-next-line no-await-in-loop
            const payload = await auth.call(`/ledger/accounts/${account.id}?${params}`);

            entries.push(...payload.data);

            if (!payload.meta.pagination?.has_more) break;

            if (page === STATEMENT_PAGE_LIMIT) {
                toast(`This account has more than ${STATEMENT_PAGE_LIMIT * STATEMENT_PAGE_SIZE} entries. `
                    + 'The file holds the oldest of them — narrow the period for the rest.', 'info');
            }
        }

        downloadCsv(`ledger-${account.code}-${stamp()}.csv`, [
            ['Date', 'Transaction', 'Particulars', 'Debit', 'Credit', 'Running balance'],
            ...entries.map((entry) => [
                entry.date,
                `#${entry.transaction_id}`,
                entry.transaction?.notes || entry.memo || 'Journal entry',
                entry.debit,
                entry.credit,
                entry.running_balance,
            ]),
        ]);
    } catch (error) {
        toast(failureText(error), 'error');
    }
}

/* -------------------------------------------------------------------------
 | The two views, and the controls that belong to each
 | ---------------------------------------------------------------------- */

function setView(view) {
    if (view === state.view) return;

    state.view = view;

    $$('[data-view]', listRoot).forEach((tab) => {
        tab.setAttribute('aria-selected', String(tab.dataset.view === view));
    });

    $$('[data-view-panel]', listRoot).forEach((panel) => {
        panel.classList.toggle('hidden', panel.dataset.viewPanel !== view);
    });

    applyViewVisibility();
}

/** Show the filters that mean something on the open view — see the file header. */
function applyViewVisibility() {
    $$('[data-view-for]', listRoot).forEach((element) => {
        element.classList.toggle('hidden', element.dataset.viewFor !== state.view);
    });
}

/* -------------------------------------------------------------------------
 | Grants
 |
 | Removed rather than blanked, exactly as the catalogue does with stock and
 | Insights does with the wage tile: a balance column full of dashes reads as
 | "every account is at zero", which is a claim about the books rather than about
 | the reader's permissions.
 | ---------------------------------------------------------------------- */

function applyGrantVisibility() {
    if (state.canLedger) return;

    $$('[data-ledger-only]', root).forEach((element) => element.remove());
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initAccounts() {
    root = $('[data-module-root="accounts"]');

    // Before `mountWorkspace`, which is what takes both surfaces out of the
    // document. After it, these lookups would find nothing.
    formRoot = $('[data-ws-form]', root);
    listRoot = $('[data-ws-list]', root);

    form = $('#account-form', formRoot);

    state.canLedger = can('READ', 'LEDGER');

    applyGrantVisibility();
    applyViewVisibility();

    try {
        await loadTypes();
    } catch {
        // Without the metadata the bands and labels fall back to the enum values
        // already in the markup; everything below still reads.
    }

    /* --- the form --------------------------------------------------------- */

    form.addEventListener('submit', submitForm);

    form.elements.type.addEventListener('change', async (event) => {
        const { value } = event.target;

        // The band is on the hint straight away; the free code needs the chart,
        // which is fetched here the first time somebody actually creates one.
        applyTypeHints(value);

        await ensureChart();

        // Still the type they chose? A second change while the chart was in
        // flight must not have its suggestion overwritten by the first.
        if (form.elements.type.value === value) applyTypeHints(value, { suggest: true });
    });

    inForm('[data-account-outcome]').addEventListener('click', (event) => {
        const opener = event.target.closest('[data-outcome-account]');

        if (opener) openDrawer(opener.dataset.outcomeAccount);
    });

    /* --- the list's controls ---------------------------------------------- */

    inList('[data-account-views]')?.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-view]');

        if (tab) setView(tab.dataset.view);
    });

    inList('[data-filter-search]').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();

        // Client-side: the whole chart is already held, and a request per
        // keystroke for forty rows would be a round trip for a filter.
        renderChart();
    }, 250));

    inList('[data-filter-status]').addEventListener('change', (event) => {
        state.isActive = event.target.value;

        // Client-side, like the search: the whole chart is already held, and it
        // has to be — see the note in the header about archived codes.
        renderChart();
    });

    ['from', 'to'].forEach((field) => {
        inList(`[data-filter-${field}]`)?.addEventListener('change', (event) => {
            state[field] = event.target.value;
            refetch();
        });
    });

    inList('[data-clear-period]')?.addEventListener('click', () => {
        state.from = '';
        state.to = '';
        inList('[data-filter-from]').value = '';
        inList('[data-filter-to]').value = '';
        load();
    });

    inList('[data-export]').addEventListener('click', exportCurrentView);

    /* --- the rows on both views ------------------------------------------- */

    const openRow = (event) => {
        const row = event.target.closest('[data-account]');

        if (row) openDrawer(row.dataset.account);
    };

    inList('[data-chart-groups]').addEventListener('click', (event) => {
        const addTo = event.target.closest('[data-add-to]');

        if (addTo) {
            workspace?.showForm();
            resetForm({ type: addTo.dataset.addTo });
            form.elements.name.focus();

            return;
        }

        const group = event.target.closest('[data-group]');

        if (group) {
            state.collapsed[group.dataset.group] = !state.collapsed[group.dataset.group];
            renderChart();

            return;
        }

        openRow(event);
    });

    inList('[data-chart-groups]').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (!event.target.closest('[data-account]')) return;

        event.preventDefault();
        openRow(event);
    });

    inList('[data-trial-body]')?.addEventListener('click', openRow);
    inList('[data-trial-body]')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (!event.target.closest('[data-account]')) return;

        event.preventDefault();
        openRow(event);
    });

    /* --- the drawer -------------------------------------------------------- */

    el('[data-drawer-actions]').addEventListener('click', (event) => {
        const account = state.current;

        if (!account) return;

        if (event.target.closest('[data-edit-account]')) openEdit(account);
        if (event.target.closest('[data-toggle-archived]')) toggleArchived(account);
        if (event.target.closest('[data-statement-csv]')) downloadStatement(account);
    });

    el('[data-drawer-body]').addEventListener('click', (event) => {
        const pager = event.target.closest('[data-entries-page]');

        if (!pager || pager.disabled) return;

        state.entryPage += pager.dataset.entriesPage === 'next' ? 1 : -1;
        loadStatement();
    });

    el('#account-drawer').addEventListener('click', (event) => {
        if (event.target.closest('[data-account-edit-cancel]')) {
            closeEdit();

            return;
        }

        // Beside `ui.js`'s own handler rather than instead of it: it hides the
        // drawer, and this takes the form back out of it first.
        if (event.target.closest('[data-modal-close]') || event.target.matches('[data-modal]')) {
            closeEdit();
        }
    });

    /*
    | Escape closes the drawer through `ui.js`, which knows nothing about the
    | form standing inside it. Without this the create surface would come back
    | empty, with nothing on screen saying where its fields went.
    */
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && editing !== null) closeEdit();
    });

    /* --- the flow ---------------------------------------------------------- */

    workspace = mountWorkspace(root, {
        key: 'accounts',
        title: 'Accounting',
        formSubtitle: 'Add an account to the chart — an expense head of your own, or anything the seeded '
            + 'chart does not cover.',
        /*
        | The second half is a promise about the trial balance, so it is only
        | made to somebody who can see one. Telling a caller without READ:LEDGER
        | that the proof is here would be pointing at the view that was removed
        | from under them.
        */
        listSubtitle: (count) => {
            const what = count === null ? 'The chart of accounts' : `${count} account${count === 1 ? '' : 's'}`;

            return state.canLedger
                ? `${what}, and the proof that the books balance.`
                : `${what} on the chart.`;
        },
        createLabel: 'Create account',
        count: () => state.total,
        canCreate: can('WRITE', 'ACCOUNTS'),
        onShowList: load,

        /*
        | Everything on the list is a copy of the books. `/accounts` announces
        | `ledger` and so does every posting, a payroll run, an opening balance
        | and the workshop settings that define the period — see `data-bus.js`,
        | where the announcement is made so a new write site cannot forget to.
        */
        refreshOn: ['ledger'],

        // §2A.8 — back on the form, the type is where the next account starts.
        onShowForm: () => form.elements.type.focus(),
    });
}
