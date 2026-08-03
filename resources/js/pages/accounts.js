import auth from '../auth-client';
import { can } from '../permissions';
import {
    $, $$, clearFormErrors, confirmAction, debounce, esc, formatDate,
    formatMoney, hideModal, isZeroAmount, setSubmitting, showFormErrors,
    showModal, toast,
} from '../ui';

/**
 * The Accounting screen: three views of the books behind one tab strip.
 *
 *   Ledger Accounts   — every ledger and what it stands at
 *   Journal Entries   — the entries that put it there
 *   Chart of Accounts — the structure those ledgers are arranged into
 *
 * The chart is small by nature — the system accounts plus whatever the workshop
 * has added — so it is fetched whole once and the first and third tabs are both
 * rendered from that one copy in memory. Only the journal paginates, because
 * only the journal grows without bound.
 *
 * Three tabs, three grants. The page itself is READ:ACCOUNTS; balances need
 * READ:LEDGER and the journal needs READ:TRANSACTIONS, and neither follows from
 * the first. What a caller cannot read is removed rather than blanked — see
 * {@link applyGrantVisibility}.
 */

const CHART_PAGE_SIZE = 200;
const JOURNAL_PAGE_SIZE = 25;

/** Canonical statement order, not alphabetical. */
const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense'];

/** Which pills the Ledger Accounts tab offers, and the tile that jumps to each. */
const PL_TYPES = ['income', 'expense'];

const state = {
    tab: 'ledger',

    search: '',
    isActive: '1',
    side: '',

    ledgerPill: 'all',
    journalPill: 'all',
    from: '',
    to: '',
    journalPage: 1,

    types: {},          // { asset: {label, code_range, normal_balance, …} }
    accounts: [],
    /*
    | account id -> the trial-balance row for it. Absent for an account nothing
    | has been posted to, which is why every read of this goes through
    | `balanceOf` rather than indexing it directly: "no row" means zero, and it
    | is the one case where a zero is the honest answer rather than a guess.
    */
    balances: {},
    balancesFailed: false,

    transactions: [],
    journalPagination: null,
    journalCounts: null,

    // Collapsed groups on the chart tab, by type. Everything starts open.
    collapsed: {},

    canLedger: false,
    canTransactions: false,
};

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
 * What an account stands at, as {amount, side}.
 *
 * Null — not zero — when balances have not been read, so a caller can tell
 * "nothing posted" from "not yours to see" and render each differently.
 */
function balanceOf(account) {
    if (!state.canLedger || state.balancesFailed) return null;

    const row = state.balances[account.id];

    return row
        ? { amount: row.balance, side: row.balance_side }
        : { amount: '0.00', side: account.normal_balance };
}

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

/** Type metadata comes from the server so the code bands are never duplicated here. */
async function loadTypes() {
    if (Object.keys(state.types).length) return;

    const { data } = await auth.call('/accounts/types');

    state.types = Object.fromEntries(data.map((type) => [type.value, type]));
}

async function loadAccounts() {
    const params = new URLSearchParams({ per_page: CHART_PAGE_SIZE });

    if (state.isActive !== '') params.set('is_active', state.isActive);

    const payload = await auth.call(`/accounts?${params}`);

    state.accounts = payload.data;
}

/**
 * Balances for every account, in one request rather than one per row.
 *
 * A failure here is not a failure of the page: the chart still reads, and the
 * balance column says so rather than the whole screen going red.
 */
async function loadBalances() {
    if (!state.canLedger) return;

    try {
        const payload = await auth.call('/ledger/trial-balance');

        state.balances = Object.fromEntries(payload.data.map((row) => [row.account.id, row]));
        state.balancesFailed = false;
    } catch {
        state.balances = {};
        state.balancesFailed = true;
    }
}

async function loadJournal() {
    if (!state.canTransactions) return;

    const body = $('#journal-body');

    body.innerHTML = rowMessage(8, 'Loading journal entries…');

    const params = new URLSearchParams({
        per_page: JOURNAL_PAGE_SIZE,
        page: state.journalPage,
        sort: 'date',
        direction: 'desc',
    });

    if (state.search) params.set('search', state.search);
    if (state.journalPill !== 'all') params.set('source', state.journalPill);
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    try {
        const payload = await auth.call(`/transactions?${params}`);

        state.transactions = payload.data;
        state.journalPagination = payload.meta?.pagination ?? null;

        renderJournal();
    } catch (error) {
        state.transactions = [];
        body.innerHTML = rowMessage(8, failureText(error), 'error');
        $('#journal-summary').textContent = '';
        $('#journal-pager').innerHTML = '';
    }
}

/**
 * The four figures above the journal. Unfiltered on purpose — they count the
 * workshop's books rather than the current search, and a badge that shrank as
 * somebody typed would be answering a different question from the one it looks
 * like it is answering.
 */
async function loadJournalCounts() {
    if (!state.canTransactions) return;

    try {
        const { data } = await auth.call('/transactions/counts');

        state.journalCounts = data;
        renderJournalStats();
    } catch {
        // The tiles keep their em-dashes. A zero here would be a claim about an
        // empty workshop that nothing has checked.
    }
}

/* -------------------------------------------------------------------------
 | Shared rendering
 | ---------------------------------------------------------------------- */

function rowMessage(colspan, text, tone = 'muted') {
    const color = tone === 'error' ? 'text-rose-600' : 'text-muted-foreground';

    return `<tr><td colspan="${colspan}" class="px-4 py-12 text-center text-sm ${color}">${esc(text)}</td></tr>`;
}

/**
 * A platform super-admin holds every grant and belongs to no workshop, so they
 * can reach this page by typing the URL and there is nothing to show them. That
 * is a situation, not a mistake on their part.
 */
function failureText(error) {
    return error.code === 'NO_WORKSPACE'
        ? 'Your account administers the platform rather than a single workshop, so it has no books of its own. '
          + 'Open a workshop from the workspaces list to see its accounts.'
        : error.message;
}

const TYPE_BADGE = {
    asset: 'bg-blue-50 text-blue-700',
    liability: 'bg-rose-50 text-rose-600',
    equity: 'bg-purple-50 text-purple-700',
    income: 'bg-emerald-50 text-emerald-700',
    expense: 'bg-amber-50 text-amber-600',
};

const TYPE_TINT = {
    asset: { bg: 'bg-blue-50', text: 'text-blue-600' },
    liability: { bg: 'bg-rose-50', text: 'text-rose-500' },
    equity: { bg: 'bg-purple-50', text: 'text-purple-600' },
    income: { bg: 'bg-emerald-50', text: 'text-emerald-600' },
    expense: { bg: 'bg-amber-50', text: 'text-amber-500' },
};

function typeBadge(account) {
    const label = account.type_label ?? state.types[account.type]?.label ?? account.type;

    return `<span class="badge ${TYPE_BADGE[account.type] ?? 'bg-muted text-secondary-foreground'}">${esc(label)}</span>`;
}

function statusBadge(isActive) {
    return isActive
        ? '<span class="badge bg-emerald-50 text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-500"></span>Active</span>'
        : '<span class="badge bg-muted text-muted-foreground"><span class="size-1.5 rounded-full bg-muted-foreground"></span>Archived</span>';
}

const TXN_STATUS_BADGE = {
    posted: 'bg-emerald-50 text-emerald-700',
    draft: 'bg-amber-50 text-amber-600',
    reversed: 'bg-rose-50 text-rose-600',
};

const TXN_STATUS_DOT = {
    posted: 'bg-emerald-500',
    draft: 'bg-amber-500',
    reversed: 'bg-rose-500',
};

function txnStatusBadge(transaction) {
    const tone = TXN_STATUS_BADGE[transaction.status] ?? 'bg-muted text-secondary-foreground';
    const dot = TXN_STATUS_DOT[transaction.status] ?? 'bg-muted-foreground';

    return `<span class="badge ${tone}"><span class="size-1.5 rounded-full ${dot}"></span>${esc(transaction.status_label)}</span>`;
}

const SOURCE_BADGE = {
    manual: 'bg-blue-50 text-blue-700',
    import: 'bg-purple-50 text-purple-700',
    ai: 'bg-purple-50 text-purple-700',
};

function sourceBadge(transaction) {
    const tone = SOURCE_BADGE[transaction.source] ?? 'bg-muted text-secondary-foreground';

    return `<span class="badge ${tone}">${esc(transaction.source_label)}</span>`;
}

/** A balance as "12,340.00 Dr", or an em-dash when the account is flat. */
function balanceCell(account) {
    const balance = balanceOf(account);

    if (balance === null) return '';

    if (isZeroAmount(balance.amount)) {
        return '<span class="text-muted-foreground">—</span>';
    }

    return `${esc(formatMoney(balance.amount))}
            <span class="ml-1 text-[0.6875rem] font-normal text-muted-foreground">${balance.side === 'debit' ? 'Dr' : 'Cr'}</span>`;
}

/* -------------------------------------------------------------------------
 | Filtering
 | ---------------------------------------------------------------------- */

/**
 * The accounts the current search and filters leave, in statement order.
 *
 * Searching reaches the code as well as the name: somebody looking for "4002"
 * is after an account by its number, and the number is the one thing that never
 * changes.
 */
function visibleAccounts({ pill = state.ledgerPill } = {}) {
    const needle = state.search.trim().toLowerCase();

    return state.accounts
        .filter((account) => {
            if (state.side && account.normal_balance !== state.side) return false;

            if (pill === 'pl') {
                if (!PL_TYPES.includes(account.type)) return false;
            } else if (pill !== 'all' && account.type !== pill) {
                return false;
            }

            if (!needle) return true;

            return account.name.toLowerCase().includes(needle)
                || String(account.code).includes(needle)
                || (account.description ?? '').toLowerCase().includes(needle);
        })
        .sort((a, b) => TYPE_ORDER.indexOf(a.type) - TYPE_ORDER.indexOf(b.type)
            || String(a.code).localeCompare(String(b.code)));
}

/* -------------------------------------------------------------------------
 | Tab 1 — Ledger Accounts
 | ---------------------------------------------------------------------- */

function renderLedgerStats() {
    const count = (predicate) => state.accounts.filter(predicate).length;

    $('#stat-ledgers').textContent = state.accounts.length;
    $('#stat-assets').textContent = count((a) => a.type === 'asset');
    $('#stat-liabilities').textContent = count((a) => a.type === 'liability');
    $('#stat-pl').textContent = count((a) => PL_TYPES.includes(a.type));

    $$('[data-stat-filter]').forEach((tile) => {
        tile.classList.toggle('stat-tile-on', tile.dataset.statFilter === state.ledgerPill);
    });
}

function renderLedger() {
    const body = $('#ledger-body');
    const rows = visibleAccounts();
    const columns = state.canLedger && !state.balancesFailed ? 6 : 5;

    renderLedgerStats();

    $$('#ledger-pills [data-pill]').forEach((pill) => {
        pill.setAttribute('aria-pressed', String(pill.dataset.pill === state.ledgerPill));
    });

    if (!rows.length) {
        body.innerHTML = rowMessage(columns, state.accounts.length
            ? 'No accounts match these filters.'
            : 'This workshop has no chart of accounts yet.');
        $('#ledger-summary').textContent = '';

        return;
    }

    body.innerHTML = rows.map((account) => `
        <tr class="cursor-pointer transition hover:bg-secondary/60 ${account.is_active ? '' : 'opacity-60'}"
            data-ledger="${account.id}" tabindex="0" role="button"
            aria-label="Open ${esc(account.name)}">

            <td class="table-cell">
                <div class="flex items-center gap-2">
                    <span class="font-semibold">${esc(account.name)}</span>
                    ${account.is_system ? iconLock : ''}
                </div>
                <div class="mt-0.5 font-mono text-[0.6875rem] text-muted-foreground">${esc(account.code)}</div>
            </td>

            <td class="table-cell">${typeBadge(account)}</td>

            ${state.canLedger && !state.balancesFailed ? `
                <td class="table-cell w-40 text-right font-mono text-[0.8125rem] font-semibold whitespace-nowrap">
                    ${balanceCell(account)}
                </td>` : ''}

            <td class="table-cell w-36 whitespace-nowrap text-[0.78125rem] text-muted-foreground">
                ${esc(formatDate(account.updated_at))}
            </td>

            <td class="table-cell w-28">${statusBadge(account.is_active)}</td>

            <td class="table-cell w-14">
                <div class="flex justify-end">
                    <button type="button" class="btn btn-ghost btn-icon" data-menu="${account.id}"
                            aria-haspopup="menu" aria-expanded="false" aria-label="Actions">
                        ${iconMore}
                    </button>
                </div>
            </td>
        </tr>`).join('');

    $('#ledger-summary').textContent = rows.length === state.accounts.length
        ? `${rows.length} account${rows.length === 1 ? '' : 's'}.`
        : `Showing ${rows.length} of ${state.accounts.length} accounts.`;
}

/* -------------------------------------------------------------------------
 | Tab 2 — Journal Entries
 | ---------------------------------------------------------------------- */

function renderJournalStats() {
    const counts = state.journalCounts;

    if (!counts) return;

    /*
    | All four come from the status breakdown, which is what the endpoint
    | actually publishes — it counts by type and by status, not by source. A
    | tile fed from a key that is never sent would show a zero nothing had
    | counted.
    */
    const statuses = counts.statuses ?? {};
    const total = Object.values(statuses).reduce((sum, n) => sum + n, 0);

    $('#stat-entries').textContent = total;
    $('#stat-posted').textContent = statuses.posted ?? 0;
    $('#stat-drafts').textContent = statuses.draft ?? 0;
    $('#stat-reversed').textContent = statuses.reversed ?? 0;
}

function renderJournal() {
    const body = $('#journal-body');

    $$('#journal-pills [data-pill]').forEach((pill) => {
        pill.setAttribute('aria-pressed', String(pill.dataset.pill === state.journalPill));
    });

    if (!state.transactions.length) {
        body.innerHTML = rowMessage(8, 'No journal entries match these filters.');
        $('#journal-summary').textContent = '';
        $('#journal-pager').innerHTML = '';

        return;
    }

    body.innerHTML = state.transactions.map((transaction) => {
        /*
        | A posted transaction is balanced by construction, so its total is both
        | its debit and its credit — that is what "balanced" means, and showing
        | the same figure twice is the columns doing their job rather than a
        | duplication. A draft has reached nothing, so both columns say so.
        */
        const amount = transaction.is_draft ? null : transaction.total;

        return `
        <tr class="cursor-pointer transition hover:bg-secondary/60"
            data-journal="${transaction.id}" tabindex="0" role="button"
            aria-label="Open journal entry ${transaction.id}">

            <td class="table-cell w-28 font-semibold text-primary">#${transaction.id}</td>

            <td class="table-cell w-32 whitespace-nowrap text-[0.78125rem] text-muted-foreground">
                ${esc(formatDate(transaction.date))}
            </td>

            <td class="table-cell">
                <div class="font-medium">${esc(transaction.notes || transaction.type_label)}</div>
                <div class="mt-0.5 text-[0.78125rem] text-muted-foreground">
                    ${esc(transaction.type_label)}${transaction.party ? ` · ${esc(transaction.party.name)}` : ''}
                </div>
            </td>

            <td class="table-cell w-36 text-right font-mono text-[0.8125rem] font-semibold whitespace-nowrap">
                ${amount === null ? '<span class="text-muted-foreground">—</span>' : esc(formatMoney(amount))}
            </td>

            <td class="table-cell w-36 text-right font-mono text-[0.8125rem] font-semibold whitespace-nowrap">
                ${amount === null ? '<span class="text-muted-foreground">—</span>' : esc(formatMoney(amount))}
            </td>

            <td class="table-cell w-28">${txnStatusBadge(transaction)}</td>

            <td class="table-cell w-32">${sourceBadge(transaction)}</td>

            <td class="table-cell w-14">
                <div class="flex justify-end">${iconChevron}</div>
            </td>
        </tr>`;
    }).join('');

    const pagination = state.journalPagination;
    const total = pagination?.total ?? state.transactions.length;

    $('#journal-summary').textContent =
        `Showing ${state.transactions.length} of ${total} entr${total === 1 ? 'y' : 'ies'}.`;

    $('#journal-pager').innerHTML = pagination && pagination.last_page > 1
        ? `<button type="button" class="btn btn-secondary btn-sm" data-page="prev"
                   ${pagination.current_page <= 1 ? 'disabled' : ''}>Previous</button>
           <span class="px-2 text-[0.78125rem] text-muted-foreground">
               ${pagination.current_page} / ${pagination.last_page}
           </span>
           <button type="button" class="btn btn-secondary btn-sm" data-page="next"
                   ${pagination.has_more ? '' : 'disabled'}>Next</button>`
        : '';
}

/* -------------------------------------------------------------------------
 | Tab 3 — Chart of Accounts
 | ---------------------------------------------------------------------- */

/**
 * The chart as five collapsible blocks, which is how an accountant reads one.
 *
 * The group total is the sum of its accounts' balances in paise. Summing a
 * column of balances is only meaningful *within* a type — every account in a
 * group falls on the same side — so there is deliberately no grand total here:
 * assets plus expenses is not a number anybody wants.
 */
function renderCoa() {
    const host = $('#coa-groups');
    const rows = visibleAccounts({ pill: 'all' });

    const grouped = TYPE_ORDER
        .map((type) => [type, rows.filter((account) => account.type === type)])
        .filter(([, accounts]) => accounts.length);

    renderCoaTiles();

    if (!grouped.length) {
        host.innerHTML = `<div class="surface px-4 py-12 text-center text-sm text-muted-foreground">
                              ${esc(state.accounts.length ? 'No accounts match these filters.' : 'This workshop has no chart of accounts yet.')}
                          </div>`;
        $('#coa-summary').textContent = '';

        return;
    }

    host.innerHTML = grouped.map(([type, accounts]) => renderCoaGroup(type, accounts)).join('');

    $('#coa-summary').textContent = rows.length === state.accounts.length
        ? `${rows.length} account${rows.length === 1 ? '' : 's'} across ${grouped.length} type${grouped.length === 1 ? '' : 's'}.`
        : `Showing ${rows.length} of ${state.accounts.length} accounts.`;
}

function groupTotalPaise(accounts) {
    return accounts.reduce((total, account) => {
        const balance = balanceOf(account);

        return balance === null ? total : total + toPaise(balance.amount);
    }, 0);
}

function renderCoaTiles() {
    const host = $('#coa-tiles');

    if (!host) return;

    host.innerHTML = TYPE_ORDER.map((type) => {
        const accounts = state.accounts.filter((account) => account.type === type);

        if (!accounts.length) return '';

        const meta = state.types[type] ?? {};
        const tint = TYPE_TINT[type];

        return `
            <div class="stat-tile !gap-2.5 !p-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-[7px] ${tint.bg} ${tint.text}">
                    ${iconDot}
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-[0.6875rem] text-muted-foreground">${esc(meta.label ?? type)}</span>
                    <span class="block truncate text-[0.84375rem] font-bold text-foreground">
                        ${esc(formatMoney(paiseToAmount(groupTotalPaise(accounts))))}
                    </span>
                </span>
            </div>`;
    }).join('');
}

function renderCoaGroup(type, accounts) {
    const meta = state.types[type] ?? {};
    const tint = TYPE_TINT[type];
    const [low, high] = meta.code_range ?? [];
    const open = !state.collapsed[type];
    const mayWrite = can('WRITE', 'ACCOUNTS');
    const showTotal = state.canLedger && !state.balancesFailed;

    const rows = accounts.map((account) => `
        <div class="flex items-center gap-4 border-b border-muted px-5 py-3 transition last:border-b-0 hover:bg-secondary/60
                    ${account.is_active ? '' : 'opacity-60'}">
            <span class="flex w-6 shrink-0 justify-center">
                <span class="h-4 w-px bg-border"></span>
            </span>

            <span class="flex min-w-0 flex-1 items-center gap-2">
                <button type="button" class="truncate text-left text-[0.8125rem] font-medium text-secondary-foreground
                                             transition hover:text-primary"
                        data-ledger="${account.id}">${esc(account.name)}</button>
                ${account.is_system ? iconLock : ''}
                ${account.is_active ? '' : '<span class="badge bg-muted text-muted-foreground">Archived</span>'}
            </span>

            <code class="shrink-0 rounded bg-muted px-2 py-0.5 font-mono text-[0.6875rem] text-muted-foreground">${esc(account.code)}</code>

            ${showTotal ? `
                <span class="w-28 shrink-0 text-right font-mono text-[0.8125rem] font-semibold text-foreground">
                    ${balanceCell(account)}
                </span>` : ''}

            <span class="flex w-8 shrink-0 items-center justify-end">
                ${account.is_system || !can('UPDATE', 'ACCOUNTS')
                    ? ''
                    : `<button type="button" class="btn btn-ghost btn-icon" data-edit="${account.id}"
                               title="Edit account" aria-label="Edit ${esc(account.name)}">${iconPencil}</button>`}
            </span>
        </div>`).join('');

    return `
        <section class="surface overflow-hidden rounded-[14px]">
            <button type="button" class="flex w-full items-center gap-3 px-5 py-3.5 text-left transition hover:bg-secondary/60"
                    data-group="${type}" aria-expanded="${open}">
                <span class="grid size-8 shrink-0 place-items-center rounded-[8px] ${tint.bg} ${tint.text}">
                    ${iconDot}
                </span>

                <span class="flex-1">
                    <span class="block text-sm font-bold text-foreground">${esc(meta.label ?? type)}</span>
                    <span class="block text-[0.71875rem] text-muted-foreground">
                        ${accounts.length} account${accounts.length === 1 ? '' : 's'}
                        ${low ? ` · band ${low}–${high}` : ''}
                        ${showTotal ? ` · ${esc(formatMoney(paiseToAmount(groupTotalPaise(accounts))))}` : ''}
                    </span>
                </span>

                <span class="text-muted-foreground ${open ? '' : '-rotate-90'} transition-transform">${iconChevronDown}</span>
            </button>

            ${open ? `
                <div class="border-t border-muted">
                    ${rows}
                    ${mayWrite && !state.search ? `
                        <div class="border-t border-muted px-5 py-2.5">
                            <button type="button" class="flex items-center gap-1.5 text-[0.78125rem] font-medium text-primary
                                                         transition hover:text-primary/80"
                                    data-add-to="${type}">
                                ${iconPlus}
                                Add account to ${esc(meta.label ?? type)}
                            </button>
                        </div>` : ''}
                </div>` : ''}
        </section>`;
}

/* -------------------------------------------------------------------------
 | Icons
 | ---------------------------------------------------------------------- */

const svg = (paths, size = 16) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;

const iconLock = `<span class="shrink-0 text-border" title="System account">${svg('<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 11)}</span>`;
const iconMore = svg('<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>', 15);
const iconChevron = `<span class="text-border">${svg('<path d="m9 18 6-6-6-6"/>', 14)}</span>`;
const iconChevronDown = svg('<path d="m6 9 6 6 6-6"/>', 15);
const iconPencil = svg('<path d="M21.17 6.83a2.83 2.83 0 0 0-4-4L3.5 16.5 2 22l5.5-1.5z"/><path d="m15 5 4 4"/>', 13);
const iconPlus = svg('<path d="M5 12h14"/><path d="M12 5v14"/>', 13);
const iconDot = svg('<circle cx="12" cy="12" r="7"/>', 13);
const iconEye = svg('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>', 13);
const iconDownload = svg('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>', 13);
const iconArchive = svg('<rect x="2" y="4" width="20" height="5" rx="1"/><path d="M4 9v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>', 13);
const iconRestore = svg('<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>', 13);

/* -------------------------------------------------------------------------
 | The row menu
 |
 | Opened as a fixed layer on the body rather than inside the row: the table
 | scrolls sideways on a narrow screen, and a container that scrolls on one axis
 | clips the other — an absolutely positioned menu would be cut off exactly
 | where the last row's menu opens.
 | ---------------------------------------------------------------------- */

function closeMenus() {
    $$('[data-row-menu]').forEach((menu) => menu.remove());
    $$('[data-menu]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
}

function openMenu(button, accountId) {
    const account = state.accounts.find((row) => String(row.id) === String(accountId));

    if (!account) return;

    closeMenus();

    const entries = [{ label: 'View ledger', icon: iconEye, action: 'open' }];

    if (state.canLedger) {
        entries.push({ label: 'Download statement', icon: iconDownload, action: 'statement' });
    }

    if (can('UPDATE', 'ACCOUNTS')) {
        entries.push({ label: 'Edit account', icon: iconPencil, action: 'edit' });

        // System accounts keep no archive entry at all: the posting engine
        // resolves them by key, so archiving one would break a template rather
        // than tidy a list.
        if (!account.is_system) {
            entries.push(account.is_active
                ? { label: 'Archive account', icon: iconArchive, action: 'archive' }
                : { label: 'Restore account', icon: iconRestore, action: 'restore' });
        }
    }

    const menu = document.createElement('div');

    menu.className = 'row-menu';
    menu.dataset.rowMenu = '';
    menu.setAttribute('role', 'menu');

    menu.innerHTML = entries.map((entry) => `
        <button type="button" role="menuitem" class="row-menu-item"
                data-action="${entry.action}" data-id="${account.id}">
            ${entry.icon}
            ${entry.label}
        </button>`).join('');

    // Measured off-screen first: the height decides whether it opens down or up,
    // and asking for it before the browser has laid it out returns zero.
    menu.style.position = 'fixed';
    menu.style.visibility = 'hidden';
    document.body.append(menu);

    const rect = button.getBoundingClientRect();
    const height = menu.offsetHeight;
    const below = window.innerHeight - rect.bottom;

    menu.style.top = below < height + 8
        ? `${Math.max(8, rect.top - height - 4)}px`
        : `${rect.bottom + 4}px`;

    menu.style.left = `${Math.max(8, rect.right - menu.offsetWidth)}px`;
    menu.style.right = 'auto';
    menu.style.visibility = 'visible';

    button.setAttribute('aria-expanded', 'true');
}

/* -------------------------------------------------------------------------
 | The ledger drawer
 | ---------------------------------------------------------------------- */

let openLedgerAccount = null;

async function openLedgerDrawer(accountId) {
    const account = state.accounts.find((row) => String(row.id) === String(accountId));

    if (!account) return;

    openLedgerAccount = account;

    const balance = balanceOf(account);

    $('#ledger-drawer-title').textContent = account.name;
    $('#ledger-drawer-subtitle').textContent =
        `${account.code} · ${account.type_label}${account.is_system ? ' · System account' : ''}`;
    $('#ledger-drawer-status').innerHTML = statusBadge(account.is_active);

    $('#ledger-drawer-edit').classList.toggle('hidden', !can('UPDATE', 'ACCOUNTS'));
    $('#ledger-drawer-statement').classList.toggle('hidden', !state.canLedger);

    $('#ledger-drawer-body').innerHTML = `
        ${balance === null ? '' : `
            <div class="mb-5 rounded-[12px] border border-border bg-secondary/40 px-4 py-3.5">
                <p class="text-[0.6875rem] uppercase tracking-wide text-muted-foreground">Current balance</p>
                <p class="mt-1 font-mono text-[22px] font-bold leading-none text-foreground">
                    ${esc(formatMoney(balance.amount))}
                    <span class="text-sm font-normal text-muted-foreground">${balance.side === 'debit' ? 'Dr' : 'Cr'}</span>
                </p>
            </div>`}

        <h4 class="section-label mb-2">Ledger details</h4>
        <dl class="mb-5 space-y-2 text-[0.8125rem]">
            ${detail('Account name', account.name)}
            ${detail('Account type', account.type_label)}
            ${detail('Account code', account.code, 'font-mono')}
            ${detail('Increases on', account.normal_balance === 'debit' ? 'Debit' : 'Credit')}
            ${detail('Statement', account.is_balance_sheet ? 'Balance sheet' : 'Profit & loss')}
            ${detail('Last updated', formatDate(account.updated_at))}
            ${detail('System account', account.is_system ? 'Yes' : 'No')}
            ${account.description ? detail('Description', account.description) : ''}
        </dl>

        ${state.canLedger ? `
            <h4 class="section-label mb-2">Running ledger</h4>
            <div id="drawer-ledger-rows" class="text-[0.8125rem] text-muted-foreground">Loading entries…</div>`
        : ''}`;

    showModal('#ledger-drawer');

    if (state.canLedger) loadDrawerLedger(account);
}

function detail(label, value, extraClass = '') {
    return `
        <div class="flex items-start justify-between gap-4">
            <dt class="shrink-0 text-muted-foreground">${esc(label)}</dt>
            <dd class="text-right font-medium text-foreground ${extraClass}">${esc(value ?? '—')}</dd>
        </div>`;
}

/**
 * The last page of movement on this account, newest first.
 *
 * A window rather than the whole ledger: the drawer answers "what has been
 * happening here", and the full statement is the Ledger screen's job.
 */
async function loadDrawerLedger(account) {
    const host = $('#drawer-ledger-rows');

    if (!host) return;

    try {
        const payload = await auth.call(`/ledger/accounts/${account.id}?per_page=10`);
        const entries = payload.data;

        // Still the drawer we started for? A fast second click would otherwise
        // paint one account's entries under another's name.
        if (openLedgerAccount?.id !== account.id) return;

        if (!entries.length) {
            host.innerHTML = '<p class="py-3">Nothing has been posted to this account yet.</p>';

            return;
        }

        host.innerHTML = `
            <div class="overflow-hidden rounded-[10px] border border-border">
                <table class="w-full border-collapse">
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
                        ${entries.map((entry) => `
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap text-[0.71875rem] text-muted-foreground">
                                    ${esc(formatDate(entry.date))}
                                </td>
                                <td class="px-3 py-2 text-[0.75rem] text-foreground">
                                    ${esc(entry.transaction?.notes || entry.memo || 'Journal entry')}
                                    <span class="block text-[0.6875rem] text-muted-foreground">#${entry.transaction_id}</span>
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
                </table>
            </div>

            <div class="mt-2 flex items-center justify-between">
                <span class="text-[0.71875rem]">
                    Closing balance ${esc(formatMoney(payload.meta.closing_balance))}
                    ${payload.meta.normal_balance === 'debit' ? 'Dr' : 'Cr'}
                </span>
                ${(payload.meta.pagination?.total ?? 0) > entries.length
                    ? `<a href="/ledger" class="text-[0.71875rem] font-medium text-primary hover:underline">
                           See all ${payload.meta.pagination.total} entries
                       </a>`
                    : ''}
            </div>`;
    } catch (error) {
        if (openLedgerAccount?.id !== account.id) return;

        host.innerHTML = `<p class="py-3 text-rose-600">${esc(failureText(error))}</p>`;
    }
}

/* -------------------------------------------------------------------------
 | The journal drawer
 | ---------------------------------------------------------------------- */

async function openJournalDrawer(transactionId) {
    $('#journal-drawer-title').textContent = `Journal entry #${transactionId}`;
    $('#journal-drawer-subtitle').textContent = '';
    $('#journal-drawer-status').innerHTML = '';
    $('#journal-drawer-open').href = `/journal?open=${transactionId}`;
    $('#journal-drawer-body').innerHTML =
        '<p class="text-[0.8125rem] text-muted-foreground">Loading entry…</p>';

    showModal('#journal-drawer');

    try {
        const { data } = await auth.call(`/transactions/${transactionId}`);

        renderJournalDrawer(data);
    } catch (error) {
        $('#journal-drawer-body').innerHTML =
            `<p class="text-[0.8125rem] text-rose-600">${esc(failureText(error))}</p>`;
    }
}

function renderJournalDrawer(transaction) {
    const lines = transaction.lines ?? [];

    const debitPaise = lines.reduce((total, line) => total + toPaise(line.debit), 0);
    const creditPaise = lines.reduce((total, line) => total + toPaise(line.credit), 0);
    const balanced = debitPaise === creditPaise;

    $('#journal-drawer-title').textContent = `Journal entry #${transaction.id}`;
    $('#journal-drawer-subtitle').textContent =
        `${transaction.type_label} · ${formatDate(transaction.date)}`;
    $('#journal-drawer-status').innerHTML = txnStatusBadge(transaction);

    $('#journal-drawer-body').innerHTML = `
        <h4 class="section-label mb-2">Journal information</h4>
        <dl class="mb-5 space-y-2 text-[0.8125rem]">
            ${detail('Journal number', `#${transaction.id}`)}
            ${detail('Date', formatDate(transaction.date))}
            ${detail('Type', transaction.type_label)}
            ${detail('Source', transaction.source_label)}
            ${detail('Status', transaction.status_label)}
            ${transaction.party ? detail('Party', transaction.party.name) : ''}
            ${transaction.created_by ? detail('Entered by', transaction.created_by) : ''}
            ${transaction.notes ? detail('Notes', transaction.notes) : ''}
            ${transaction.reverses_id ? detail('Reverses', `#${transaction.reverses_id}`) : ''}
            ${transaction.reversal_id ? detail('Reversed by', `#${transaction.reversal_id}`) : ''}
        </dl>

        <h4 class="section-label mb-2">Debit &amp; credit entries</h4>

        ${lines.length ? `
            <div class="overflow-hidden rounded-[10px] border border-border">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-secondary/40 text-left">
                            <th class="px-3 py-2 text-[0.6875rem] font-semibold text-muted-foreground">Account</th>
                            <th class="px-3 py-2 text-right text-[0.6875rem] font-semibold text-muted-foreground">Debit</th>
                            <th class="px-3 py-2 text-right text-[0.6875rem] font-semibold text-muted-foreground">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-muted">
                        ${lines.map((line) => `
                            <tr>
                                <td class="px-3 py-2 text-[0.75rem] text-foreground">
                                    ${esc(line.account?.name ?? accountName(line.account_id))}
                                    ${line.memo ? `<span class="block text-[0.6875rem] text-muted-foreground">${esc(line.memo)}</span>` : ''}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-[0.71875rem]">
                                    ${isZeroAmount(line.debit) ? '' : esc(formatMoney(line.debit))}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-[0.71875rem]">
                                    ${isZeroAmount(line.credit) ? '' : esc(formatMoney(line.credit))}
                                </td>
                            </tr>`).join('')}
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-border bg-secondary/30 font-semibold">
                            <td class="px-3 py-2 text-right text-[0.71875rem] text-muted-foreground">Total</td>
                            <td class="px-3 py-2 text-right font-mono text-[0.71875rem]">${esc(formatMoney(paiseToAmount(debitPaise)))}</td>
                            <td class="px-3 py-2 text-right font-mono text-[0.71875rem]">${esc(formatMoney(paiseToAmount(creditPaise)))}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-2 flex items-center gap-1.5 text-[0.71875rem] ${balanced ? 'text-emerald-700' : 'text-rose-600'}">
                ${balanced
                    ? 'Entry is balanced — debit equals credit.'
                    : 'This entry does not balance. It should be impossible; please report it.'}
            </p>`
        : `<p class="text-[0.8125rem] text-muted-foreground">
               ${transaction.is_draft
                   ? 'This draft has no lines yet. Nothing has reached the ledger.'
                   : 'No lines were returned for this entry.'}
           </p>`}`;
}

/** A line's account name, for the rare payload that sends only the id. */
function accountName(id) {
    return state.accounts.find((account) => account.id === id)?.name ?? `Account #${id}`;
}

/* -------------------------------------------------------------------------
 | Export
 | ---------------------------------------------------------------------- */

function csvCell(value) {
    const text = String(value ?? '');

    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function download(filename, rows) {
    const csv = rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
    // The BOM is what makes Excel open a UTF-8 CSV as UTF-8 rather than as the
    // system codepage, which is where rupee signs turn into mojibake.
    const blob = new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.click();

    URL.revokeObjectURL(url);
}

/**
 * Export what is on screen — the rows the current tab, search and filters have
 * narrowed to. Anything else would hand somebody a file that disagrees with the
 * table they were looking at when they asked for it.
 */
function exportCurrentTab() {
    const stamp = new Date().toISOString().slice(0, 10);

    if (state.tab === 'journal') {
        if (!state.transactions.length) {
            toast('Nothing to export on this tab.', 'info');

            return;
        }

        download(`journal-entries-${stamp}.csv`, [
            ['Journal ID', 'Date', 'Particulars', 'Type', 'Party', 'Debit', 'Credit', 'Status', 'Source'],
            ...state.transactions.map((transaction) => [
                transaction.id,
                transaction.date,
                transaction.notes ?? '',
                transaction.type_label,
                transaction.party?.name ?? '',
                transaction.is_draft ? '' : transaction.total,
                transaction.is_draft ? '' : transaction.total,
                transaction.status_label,
                transaction.source_label,
            ]),
        ]);

        return;
    }

    const rows = visibleAccounts({ pill: state.tab === 'coa' ? 'all' : state.ledgerPill });

    if (!rows.length) {
        toast('Nothing to export on this tab.', 'info');

        return;
    }

    const withBalance = state.canLedger && !state.balancesFailed;

    download(`${state.tab === 'coa' ? 'chart-of-accounts' : 'ledger-accounts'}-${stamp}.csv`, [
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
                account.type_label,
                account.normal_balance === 'debit' ? 'Debit' : 'Credit',
                ...(withBalance ? [balance?.amount ?? '', balance?.side === 'debit' ? 'Dr' : 'Cr'] : []),
                account.is_active ? 'Active' : 'Archived',
                account.is_system ? 'Yes' : 'No',
                account.updated_at ?? '',
            ];
        }),
    ]);
}

/* -------------------------------------------------------------------------
 | Create / edit
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
    const codeInput = $('#account-code');

    if (!meta) {
        $('#account-type-hint').textContent = 'Decides which side increases the account.';
        $('#account-code-hint').textContent = 'Four digits, inside the band for the chosen type.';

        return;
    }

    const [low, high] = meta.code_range;
    const side = meta.normal_balance === 'credit' ? 'credit' : 'debit';

    $('#account-type-hint').textContent =
        `Increases on the ${side} side · ${meta.is_balance_sheet ? 'Balance sheet' : 'Profit & loss'}`;
    $('#account-code-hint').textContent = `Must be between ${low} and ${high}.`;

    if (suggest && !codeInput.value) codeInput.value = suggestCode(type);
}

async function openForm(account = null, { type = '' } = {}) {
    const form = $('#account-form');
    const editing = account !== null;
    const locked = editing && account.is_system;

    await loadTypes();

    clearFormErrors(form);
    form.reset();

    $('#account-modal-title').textContent = editing ? 'Edit account' : 'New account';
    $('#account-system-note').classList.toggle('hidden', !locked);
    $('#account-system-note').classList.toggle('flex', locked);

    form.elements.id.value = editing ? account.id : '';
    form.elements.name.value = editing ? account.name : '';
    form.elements.description.value = editing ? (account.description ?? '') : '';
    form.elements.code.value = editing ? account.code : '';
    form.elements.type.value = editing ? account.type : type;

    // Type is immutable for every account, system or not: reclassifying would
    // move every journal entry already posted against it onto a different
    // financial statement. Code is fixed for system accounts only.
    form.elements.type.disabled = editing;
    form.elements.code.disabled = locked;

    applyTypeHints(editing ? account.type : type, { suggest: !editing && Boolean(type) });

    showModal('#account-modal');
}

function validate(form, editing) {
    const errors = {};

    if (!editing && !form.elements.type.value) {
        errors.type = ['Choose an account type.'];
    }

    const code = form.elements.code.value.trim();

    if (!form.elements.code.disabled) {
        if (!/^\d{4}$/.test(code)) {
            errors.code = ['An account code is exactly four digits.'];
        } else {
            // Checked here as well as server-side so the band is explained
            // before a round trip, not after a 409.
            const type = form.elements.type.value;
            const meta = state.types[type];

            if (meta) {
                const [low, high] = meta.code_range;

                if (Number(code) < low || Number(code) > high) {
                    errors.code = [`A ${type} account must be numbered between ${low} and ${high}.`];
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

    const form = event.target;
    const id = form.elements.id.value;
    const editing = id !== '';

    clearFormErrors(form);

    const errors = validate(form, editing);

    if (errors) {
        showFormErrors(form, { fields: errors, message: 'Please correct the highlighted fields.' });

        return;
    }

    const payload = {
        name: form.elements.name.value.trim(),
        description: form.elements.description.value.trim() || null,
    };

    // A disabled input is not submitted, and the server rejects a system
    // account's code outright — so it is only ever sent when editable.
    if (!form.elements.code.disabled) payload.code = form.elements.code.value.trim();
    if (!editing) payload.type = form.elements.type.value;

    setSubmitting(form, true);

    try {
        await auth.call(editing ? `/accounts/${id}` : '/accounts', {
            method: editing ? 'PATCH' : 'POST',
            body: payload,
        });

        hideModal('#account-modal');
        toast(editing ? 'Account updated.' : 'Account created.');

        await refreshChart();
    } catch (error) {
        showFormErrors(form, error);
    } finally {
        setSubmitting(form, false);
    }
}

/* -------------------------------------------------------------------------
 | Archive / restore
 | ---------------------------------------------------------------------- */

async function toggleArchived(id, name, currentlyActive) {
    if (currentlyActive) {
        const confirmed = await confirmAction({
            title: 'Archive account',
            body: `${name} will stop appearing when choosing an account. Nothing already posted to it changes — `
                + 'accounts are never deleted, so its history stays intact. You can restore it at any time.',
            confirmLabel: 'Archive account',
        });

        if (!confirmed) return;
    }

    try {
        await auth.call(`/accounts/${id}`, {
            method: 'PATCH',
            body: { is_active: !currentlyActive },
        });

        toast(currentlyActive ? 'Account archived.' : 'Account restored.');

        await refreshChart();
    } catch (error) {
        toast(error.message, 'error');
    }
}

/* -------------------------------------------------------------------------
 | Tabs
 | ---------------------------------------------------------------------- */

const SEARCH_PLACEHOLDER = {
    ledger: 'Search ledgers, account code…',
    journal: 'Search journal ID, notes…',
    coa: 'Search accounts…',
};

function switchTab(tab) {
    if (tab === state.tab) return;

    state.tab = tab;

    /*
    | The search box is cleared on a tab change rather than carried over. The
    | placeholder names what is being searched, and a term left in it would go on
    | filtering — silently, against a different kind of record.
    */
    state.search = '';
    $('#filter-search').value = '';
    $('#filter-search').placeholder = SEARCH_PLACEHOLDER[tab];

    $$('#accounting-tabs [data-tab]').forEach((button) => {
        button.setAttribute('aria-selected', String(button.dataset.tab === tab));
    });

    ['ledger', 'journal', 'coa'].forEach((name) => {
        $(`#panel-${name}`)?.classList.toggle('hidden', name !== tab);
    });

    applyToolbarVisibility();

    closePanels();

    if (tab === 'journal') {
        state.journalPage = 1;
        loadJournal();
    } else if (tab === 'coa') {
        renderCoa();
    } else {
        renderLedger();
    }
}

/** Re-read the chart and repaint whichever account view is open. */
async function refreshChart() {
    await Promise.all([loadAccounts(), loadBalances()]);

    if (state.tab === 'coa') renderCoa();
    else renderLedger();
}

function renderCurrentTab() {
    if (state.tab === 'journal') loadJournal();
    else if (state.tab === 'coa') renderCoa();
    else renderLedger();
}

/* -------------------------------------------------------------------------
 | Toolbar popovers
 | ---------------------------------------------------------------------- */

function closePanels() {
    $('#filter-panel')?.classList.add('hidden');
    $('#filter-toggle')?.setAttribute('aria-expanded', 'false');
    $('#period-panel')?.classList.add('hidden');
    $('#period-toggle')?.setAttribute('aria-expanded', 'false');
}

function renderFilterCount() {
    const active = (state.isActive === '1' ? 0 : 1) + (state.side ? 1 : 0);
    const badge = $('#filter-count');

    badge.textContent = active;
    badge.classList.toggle('hidden', active === 0);

    const periodActive = (state.from ? 1 : 0) + (state.to ? 1 : 0);
    const periodBadge = $('#period-count');

    if (periodBadge) {
        periodBadge.textContent = periodActive;
        periodBadge.classList.toggle('hidden', periodActive === 0);
    }
}

/* -------------------------------------------------------------------------
 | Grants
 | ---------------------------------------------------------------------- */

/**
 * Strip what this caller's grants do not cover.
 *
 * Removed rather than blanked, exactly as the catalogue does with stock: a
 * balance column full of dashes reads as "every account is at zero", which is a
 * claim about the books rather than about the reader's permissions.
 */
/**
 * Show the toolbar controls that mean something on the open tab.
 *
 * Both gates apply, and the grant is re-checked rather than inferred from
 * whatever `applyPermissionGates` left in the class list: a control hidden
 * because the wrong tab was open must come back when its tab opens, and a
 * control hidden because the caller lacks the grant must not.
 */
function applyToolbarVisibility() {
    $$('[data-panel-for]').forEach((element) => {
        const applies = element.dataset.panelFor.split(' ').includes(state.tab);
        const grant = element.dataset.requiresPermission;
        const [action, resource] = grant ? grant.split(':') : [];

        element.classList.toggle('hidden', !applies || (grant ? !can(action, resource) : false));
    });
}

function applyGrantVisibility() {
    if (!state.canLedger) {
        $$('[data-ledger-only]').forEach((element) => element.remove());
    }

    if (!state.canTransactions) {
        $('#panel-journal')?.remove();
    }
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initAccounts() {
    state.canLedger = can('READ', 'LEDGER');
    state.canTransactions = can('READ', 'TRANSACTIONS');

    applyGrantVisibility();
    /*
    | Run once at boot as well as on every tab change. `applyPermissionGates`
    | has just un-hidden the period control for anyone holding
    | READ:TRANSACTIONS, and the page opens on the ledger tab, where a period
    | means nothing.
    */
    applyToolbarVisibility();

    try {
        await loadTypes();
    } catch {
        // Without type metadata the bands and labels fall back to the raw enum
        // values; the chart below still reads.
    }

    try {
        await loadAccounts();
    } catch (error) {
        $('#ledger-body').innerHTML = rowMessage(6, failureText(error), 'error');
        $('#new-account')?.classList.add('hidden');

        return;
    }

    await loadBalances();

    renderLedger();
    loadJournalCounts();

    /* --- toolbar --- */

    $('#filter-search').addEventListener('input', debounce((event) => {
        state.search = event.target.value.trim();

        if (state.tab === 'journal') state.journalPage = 1;

        renderCurrentTab();
    }, 350));

    $('#filter-toggle').addEventListener('click', (event) => {
        event.stopPropagation();

        const panel = $('#filter-panel');
        const opening = panel.classList.contains('hidden');

        closePanels();
        panel.classList.toggle('hidden', !opening);
        $('#filter-toggle').setAttribute('aria-expanded', String(opening));
    });

    $('#period-toggle')?.addEventListener('click', (event) => {
        event.stopPropagation();

        const panel = $('#period-panel');
        const opening = panel.classList.contains('hidden');

        closePanels();
        panel.classList.toggle('hidden', !opening);
        $('#period-toggle').setAttribute('aria-expanded', String(opening));
    });

    $('#filter-status').addEventListener('change', async (event) => {
        state.isActive = event.target.value;
        renderFilterCount();

        // Archived state is a server-side filter, so the chart is re-fetched
        // rather than filtered in place.
        await refreshChart();
    });

    $('#filter-side').addEventListener('change', (event) => {
        state.side = event.target.value;
        renderFilterCount();
        renderCurrentTab();
    });

    ['from', 'to'].forEach((field) => {
        $(`#filter-${field}`)?.addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.journalPage = 1;
            renderFilterCount();
            loadJournal();
        });
    });

    $('#clear-period')?.addEventListener('click', () => {
        state.from = '';
        state.to = '';
        state.journalPage = 1;
        $('#filter-from').value = '';
        $('#filter-to').value = '';
        renderFilterCount();
        loadJournal();
    });

    $('#export-csv').addEventListener('click', exportCurrentTab);
    $('#new-account')?.addEventListener('click', () => openForm());
    $('#add-ledger')?.addEventListener('click', () => openForm());

    /* --- tabs, pills, tiles --- */

    $('#accounting-tabs').addEventListener('click', (event) => {
        const button = event.target.closest('[data-tab]');

        if (button) switchTab(button.dataset.tab);
    });

    $('#ledger-pills').addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (!pill) return;

        state.ledgerPill = pill.dataset.pill;
        renderLedger();
    });

    $$('[data-stat-filter]').forEach((tile) => {
        tile.addEventListener('click', () => {
            // A second click on the tile that is already applied clears it,
            // so the tiles are a toggle rather than a one-way trip.
            state.ledgerPill = state.ledgerPill === tile.dataset.statFilter
                ? 'all'
                : tile.dataset.statFilter;

            renderLedger();
        });
    });

    $('#journal-pills')?.addEventListener('click', (event) => {
        const pill = event.target.closest('[data-pill]');

        if (!pill) return;

        state.journalPill = pill.dataset.pill;
        state.journalPage = 1;
        loadJournal();
    });

    $('#journal-pager')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');

        if (!button || button.disabled) return;

        state.journalPage += button.dataset.page === 'next' ? 1 : -1;
        loadJournal();
    });

    /* --- rows --- */

    $('#ledger-body').addEventListener('click', (event) => {
        const menuButton = event.target.closest('[data-menu]');

        if (menuButton) {
            event.stopPropagation();

            if (menuButton.getAttribute('aria-expanded') === 'true') closeMenus();
            else openMenu(menuButton, menuButton.dataset.menu);

            return;
        }

        const row = event.target.closest('[data-ledger]');

        if (row) openLedgerDrawer(row.dataset.ledger);
    });

    $('#ledger-body').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-ledger]');

        if (!row) return;

        event.preventDefault();
        openLedgerDrawer(row.dataset.ledger);
    });

    $('#journal-body')?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-journal]');

        if (row) openJournalDrawer(row.dataset.journal);
    });

    $('#journal-body')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-journal]');

        if (!row) return;

        event.preventDefault();
        openJournalDrawer(row.dataset.journal);
    });

    $('#coa-groups').addEventListener('click', async (event) => {
        const group = event.target.closest('[data-group]');
        const edit = event.target.closest('[data-edit]');
        const addTo = event.target.closest('[data-add-to]');
        const ledger = event.target.closest('[data-ledger]');

        if (edit) {
            const { data } = await auth.call(`/accounts/${edit.dataset.edit}`);

            openForm(data);

            return;
        }

        if (addTo) {
            openForm(null, { type: addTo.dataset.addTo });

            return;
        }

        if (ledger) {
            openLedgerDrawer(ledger.dataset.ledger);

            return;
        }

        if (group) {
            state.collapsed[group.dataset.group] = !state.collapsed[group.dataset.group];
            renderCoa();
        }
    });

    /* --- row menu actions --- */

    document.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-row-menu] [data-action]');

        if (!action) {
            closeMenus();

            // A click on the controls *inside* a popover is not a click away
            // from it — closing there would shut the panel under the select
            // somebody was reaching for.
            if (!event.target.closest('#filter-panel, #period-panel')) closePanels();

            return;
        }

        const { id } = action.dataset;
        const account = state.accounts.find((row) => String(row.id) === String(id));

        closeMenus();

        if (!account) return;

        if (action.dataset.action === 'open') openLedgerDrawer(id);
        if (action.dataset.action === 'statement') downloadStatement(account);

        if (action.dataset.action === 'edit') {
            const { data } = await auth.call(`/accounts/${id}`);

            openForm(data);
        }

        if (action.dataset.action === 'archive') toggleArchived(id, account.name, true);
        if (action.dataset.action === 'restore') toggleArchived(id, account.name, false);
    });

    window.addEventListener('scroll', closeMenus, { passive: true, capture: true });
    window.addEventListener('resize', closeMenus, { passive: true });

    /* --- drawer footer actions --- */

    $('#ledger-drawer-edit').addEventListener('click', async () => {
        if (!openLedgerAccount) return;

        const { data } = await auth.call(`/accounts/${openLedgerAccount.id}`);

        hideModal('#ledger-drawer');
        openForm(data);
    });

    $('#ledger-drawer-statement').addEventListener('click', () => {
        if (openLedgerAccount) downloadStatement(openLedgerAccount);
    });

    $('#account-form').addEventListener('submit', submitForm);

    $('#account-type').addEventListener('change', (event) => {
        applyTypeHints(event.target.value, { suggest: true });
    });

    renderFilterCount();
}

/**
 * One account's ledger as a CSV, fetched in full rather than from the ten rows
 * the drawer happens to be showing — a statement that stopped at the tenth entry
 * would be a statement of nothing in particular.
 */
async function downloadStatement(account) {
    toast('Preparing statement…', 'info');

    try {
        const payload = await auth.call(`/ledger/accounts/${account.id}?per_page=1000`);

        download(`ledger-${account.code}-${new Date().toISOString().slice(0, 10)}.csv`, [
            ['Date', 'Transaction', 'Particulars', 'Debit', 'Credit', 'Running balance'],
            ...payload.data.map((entry) => [
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
