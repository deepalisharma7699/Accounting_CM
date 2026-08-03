import auth from '../auth-client';
import {
    $, esc, formatDate, formatMoney, isZeroAmount, tableMessage,
} from '../ui';

/**
 * Reading the books.
 *
 * One page, two views: the trial balance over every account, and one account's
 * ledger with a running balance. They are the same question at two zoom levels,
 * so choosing an account switches between them rather than navigating away —
 * and the account picker is the only control that changes.
 */

const PAGE_SIZE = 50;

const state = {
    accountId: '',
    from: '',
    to: '',
    page: 1,
    accounts: [],
};

/* -------------------------------------------------------------------------
 | Data
 | ---------------------------------------------------------------------- */

async function loadAccounts() {
    if (state.accounts.length) return;

    const { data } = await auth.call('/accounts?per_page=200');

    state.accounts = data;

    const picker = $('#filter-account');

    const groups = {};
    data.forEach((account) => (groups[account.type_label] ??= []).push(account));

    picker.insertAdjacentHTML('beforeend', Object.entries(groups).map(([label, accounts]) => `
        <optgroup label="${esc(label)}">
            ${accounts.map((account) => `
                <option value="${account.id}">${esc(account.code)} · ${esc(account.name)}</option>
            `).join('')}
        </optgroup>`).join(''));
}

function period() {
    const params = new URLSearchParams();

    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);

    return params;
}

async function load() {
    return state.accountId ? loadAccountLedger() : loadTrialBalance();
}

/* -------------------------------------------------------------------------
 | Trial balance
 | ---------------------------------------------------------------------- */

async function loadTrialBalance() {
    $('#ledger-head').innerHTML = `
        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th class="px-4 py-3 font-semibold">Account</th>
            <th class="px-4 py-3 font-semibold">Type</th>
            <th class="px-4 py-3 text-right font-semibold">Debits</th>
            <th class="px-4 py-3 text-right font-semibold">Credits</th>
            <th class="px-4 py-3 text-right font-semibold">Balance</th>
        </tr>`;

    $('#ledger-rows').innerHTML = tableMessage(5, 'Working out the trial balance…');
    $('#ledger-foot').innerHTML = '';
    $('#ledger-pager').classList.add('hidden');

    try {
        const payload = await auth.call(`/ledger/trial-balance?${period()}`);

        renderTrialBalance(payload.data, payload.meta);
    } catch (error) {
        showError(error, 5);
    }
}

function renderTrialBalance(rows, meta) {
    const body = $('#ledger-rows');

    if (!rows.length) {
        // Correct rather than empty: a workshop that has posted nothing has a
        // trial balance of 0 = 0.
        body.innerHTML = tableMessage(5, 'Nothing has been posted yet, so every account stands at zero.');
    } else {
        body.innerHTML = rows.map((row) => `
            <tr class="border-t border-border cursor-pointer transition hover:bg-secondary/60"
                data-account="${row.account.id}" tabindex="0" role="link"
                aria-label="Open the ledger for ${esc(row.account.name)}">
                <td class="table-cell">
                    <span class="font-mono text-[0.8125rem] text-muted-foreground">${esc(row.account.code)}</span>
                    <span class="ml-2 font-medium">${esc(row.account.name)}</span>
                </td>
                <td class="table-cell w-32 text-[0.8125rem] text-muted-foreground">${esc(row.account.type_label)}</td>
                <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">${esc(formatMoney(row.debit))}</td>
                <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">${esc(formatMoney(row.credit))}</td>
                <td class="table-cell w-40 text-right font-mono text-[0.8125rem] font-semibold">
                    ${isZeroAmount(row.balance) ? '—' : `${esc(formatMoney(row.balance))} <span class="ml-1 text-xs font-normal text-muted-foreground">${row.balance_side === 'debit' ? 'Dr' : 'Cr'}</span>`}
                </td>
            </tr>`).join('');
    }

    $('#ledger-foot').innerHTML = `
        <tr class="border-t-2 border-border bg-secondary/30 text-sm font-semibold">
            <td class="px-4 py-3 text-right text-muted-foreground" colspan="2">Totals</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(meta.totals.debit))}</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(meta.totals.credit))}</td>
            <td class="px-4 py-3 text-right font-mono">
                ${esc(formatMoney(meta.balances.debit))} <span class="text-xs font-normal text-muted-foreground">Dr</span>
                / ${esc(formatMoney(meta.balances.credit))} <span class="text-xs font-normal text-muted-foreground">Cr</span>
            </td>
        </tr>`;

    renderReconciliation(meta);

    $('#ledger-summary').textContent = rows.length
        ? `${rows.length} account${rows.length === 1 ? '' : 's'} with movement${periodLabel()}.`
        : '';
}

/**
 * The one number that matters. If the two sides differ, everything else on the
 * page is suspect — so it is stated plainly rather than left to be inferred
 * from two columns a reader would have to compare themselves.
 */
function renderReconciliation(meta) {
    const host = $('#reconciliation');

    host.classList.remove('hidden');
    host.innerHTML = meta.is_balanced
        ? `<div class="surface flex items-center gap-2 border-emerald-200 bg-emerald-50/60 px-4 py-3 text-[0.8125rem] text-emerald-800">
               <span class="font-semibold">The books balance.</span>
               Debits and credits both total ${esc(formatMoney(meta.totals.debit))}.
           </div>`
        : `<div class="surface flex items-center gap-2 border-rose-200 bg-rose-50 px-4 py-3 text-[0.8125rem] text-rose-700">
               <span class="font-semibold">The books do not balance.</span>
               Debits exceed credits by ${esc(formatMoney(meta.difference))}. This should be impossible —
               please report it before entering anything further.
           </div>`;
}

/* -------------------------------------------------------------------------
 | One account
 | ---------------------------------------------------------------------- */

async function loadAccountLedger() {
    $('#ledger-head').innerHTML = `
        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th class="px-4 py-3 font-semibold">Date</th>
            <th class="px-4 py-3 font-semibold">Particulars</th>
            <th class="px-4 py-3 text-right font-semibold">Debit</th>
            <th class="px-4 py-3 text-right font-semibold">Credit</th>
            <th class="px-4 py-3 text-right font-semibold">Balance</th>
        </tr>`;

    $('#ledger-rows').innerHTML = tableMessage(5, 'Loading the ledger…');
    $('#ledger-foot').innerHTML = '';
    $('#ledger-pager').classList.remove('hidden');

    const params = period();
    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    try {
        const payload = await auth.call(`/ledger/accounts/${state.accountId}?${params}`);

        renderAccountLedger(payload.data, payload.meta);
    } catch (error) {
        showError(error, 5);
    }
}

function renderAccountLedger(entries, meta) {
    const body = $('#ledger-rows');
    const side = meta.normal_balance === 'debit' ? 'Dr' : 'Cr';

    const opening = `
        <tr class="border-t border-border bg-secondary/20 text-[0.8125rem] italic text-muted-foreground">
            <td class="table-cell" colspan="4">Balance brought forward</td>
            <td class="table-cell w-40 text-right font-mono not-italic">${esc(formatMoney(meta.opening_balance))}</td>
        </tr>`;

    const rows = entries.map((entry) => `
        <tr class="border-t border-border transition hover:bg-secondary/60">
            <td class="table-cell w-32 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(entry.date))}</td>
            <td class="table-cell">
                <span class="font-medium">${esc(entry.transaction?.notes || entry.memo || 'Journal entry')}</span>
                <div class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                    #${entry.transaction_id}${entry.memo && entry.transaction?.notes ? ` · ${esc(entry.memo)}` : ''}
                    ${entry.transaction?.status === 'reversed' ? ' · reversed' : ''}
                </div>
            </td>
            <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">
                ${isZeroAmount(entry.debit) ? '' : esc(formatMoney(entry.debit))}
            </td>
            <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">
                ${isZeroAmount(entry.credit) ? '' : esc(formatMoney(entry.credit))}
            </td>
            <td class="table-cell w-40 text-right font-mono text-[0.8125rem] font-semibold">
                ${esc(formatMoney(entry.running_balance))}
            </td>
        </tr>`).join('');

    body.innerHTML = entries.length
        ? opening + rows
        : tableMessage(5, 'No entries in this account for the chosen period.');

    $('#ledger-foot').innerHTML = `
        <tr class="border-t-2 border-border bg-secondary/30 text-sm font-semibold">
            <td class="px-4 py-3 text-right text-muted-foreground" colspan="2">Period totals</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(meta.period.debit))}</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(meta.period.credit))}</td>
            <td class="px-4 py-3 text-right font-mono">
                ${esc(formatMoney(meta.closing_balance))}
                <span class="ml-1 text-xs font-normal text-muted-foreground">${side}</span>
            </td>
        </tr>`;

    $('#reconciliation').classList.add('hidden');

    const total = meta.pagination?.total ?? entries.length;

    $('#ledger-summary').textContent =
        `${esc(meta.account.name)} · ${total} entr${total === 1 ? 'y' : 'ies'}${periodLabel()}`;

    $('#page-prev').disabled = (meta.pagination?.current_page ?? 1) <= 1;
    $('#page-next').disabled = !(meta.pagination?.has_more ?? false);
}

/* -------------------------------------------------------------------------
 | Shared
 | ---------------------------------------------------------------------- */

function periodLabel() {
    if (!state.from && !state.to) return '';
    if (state.from && state.to) return ` between ${formatDate(state.from)} and ${formatDate(state.to)}`;

    return state.from ? ` from ${formatDate(state.from)}` : ` up to ${formatDate(state.to)}`;
}

function showError(error, colspan) {
    $('#reconciliation').classList.add('hidden');
    $('#ledger-summary').textContent = '';

    // A platform super-admin holds every grant and owns no books. Their request
    // is well formed; there is simply nothing to show them.
    $('#ledger-rows').innerHTML = error.code === 'NO_WORKSPACE'
        ? tableMessage(colspan, 'Your account administers the platform rather than a single workshop, so it has no ledger of its own.')
        : tableMessage(colspan, error.message, 'error');
}

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

export default async function initLedger() {
    try {
        await loadAccounts();
    } catch {
        // Without the chart the account picker stays empty; the trial balance
        // below still loads and reports its own failure if it has one.
    }

    await load();

    $('#filter-account').addEventListener('change', (event) => {
        state.accountId = event.target.value;
        state.page = 1;
        load();
    });

    ['from', 'to'].forEach((field) => {
        $(`#filter-${field}`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.page = 1;
            load();
        });
    });

    $('#clear-period').addEventListener('click', () => {
        state.from = '';
        state.to = '';
        state.page = 1;
        $('#filter-from').value = '';
        $('#filter-to').value = '';
        load();
    });

    // Drill down from the trial balance into the account behind a figure.
    $('#ledger-rows').addEventListener('click', (event) => {
        const row = event.target.closest('[data-account]');
        if (!row) return;

        openAccount(row.dataset.account);
    });

    $('#ledger-rows').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;

        const row = event.target.closest('[data-account]');
        if (row) openAccount(row.dataset.account);
    });

    $('#page-prev').addEventListener('click', () => {
        if (state.page > 1) {
            state.page -= 1;
            load();
        }
    });

    $('#page-next').addEventListener('click', () => {
        state.page += 1;
        load();
    });
}

function openAccount(id) {
    state.accountId = id;
    state.page = 1;
    $('#filter-account').value = id;
    load();
}
