import auth from '../auth-client';
import {
    $, $$, esc, formatDate, formatMoney, isZeroAmount, tableMessage,
} from '../ui';

/**
 * Reading the books at every zoom level — M12.
 *
 * Four reports on one page rather than four pages, because they are four
 * answers to one question — "how is the workshop doing" — and somebody who has
 * just seen a gross margin they did not expect wants the day book *without*
 * re-choosing the period they were looking at. The period survives the tab
 * switch; nothing else does.
 *
 * The trial balance is not here. It lives on the Ledger screen beside the
 * account it drills into, and a second copy would be a second thing to keep in
 * step.
 */

const PAGE_SIZE = 25;

const state = {
    report: 'day-book',
    period: 'this_financial_year',
    from: '',
    to: '',
    page: 1,
};

/* -------------------------------------------------------------------------
 | Fetching
 | ---------------------------------------------------------------------- */

function query() {
    const params = new URLSearchParams({ period: state.period });

    if (state.period === 'custom') {
        if (state.from) params.set('from', state.from);
        if (state.to) params.set('to', state.to);
    }

    params.set('per_page', PAGE_SIZE);
    params.set('page', state.page);

    return params;
}

async function load() {
    $$('#report-tabs [data-report]').forEach((tab) => {
        const active = tab.dataset.report === state.report;

        tab.classList.toggle('btn-secondary', active);
        tab.classList.toggle('btn-ghost', !active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    // The draft worklist has no period, deliberately: a draft is outstanding
    // work rather than an event, and hiding a three-month-old one because the
    // picker says "this month" would defeat the purpose of a worklist.
    const periodless = state.report === 'drafts';

    $('#filter-period').disabled = periodless;
    $$('[data-custom-dates]').forEach((el) => {
        el.classList.toggle('hidden', periodless || state.period !== 'custom');
    });

    $('#report-rows').innerHTML = tableMessage(6, 'Working it out…');
    $('#report-foot').innerHTML = '';
    $('#reconciliation').classList.add('hidden');

    try {
        const payload = await auth.call(`/reports/${state.report}?${query()}`);

        RENDERERS[state.report](payload.data, payload.meta ?? {});
        renderPeriod(payload.meta?.period);
        renderPager(payload.meta?.pagination);
    } catch (error) {
        showError(error);
    }
}

function renderPeriod(period) {
    $('#period-label').textContent = period
        ? `Covering ${period.label.toLowerCase()}.`
        : 'Everything not yet posted, whenever it was started.';
}

function renderPager(pagination) {
    const pager = $('#report-pager');

    if (!pagination) {
        pager.classList.add('hidden');

        return;
    }

    pager.classList.remove('hidden');
    $('#page-prev').disabled = (pagination.current_page ?? 1) <= 1;
    $('#page-next').disabled = !(pagination.has_more ?? false);
}

function showError(error) {
    $('#report-stats').innerHTML = '';
    $('#report-summary').textContent = '';
    $('#report-pager').classList.add('hidden');

    // A platform super-admin holds every grant and owns no books. Their request
    // is well formed; there is simply nothing to report on.
    $('#report-rows').innerHTML = error.code === 'NO_WORKSPACE'
        ? tableMessage(6, 'Your account administers the platform rather than a single workshop, so it has no books to report on.')
        : tableMessage(6, error.message, 'error');
}

/* -------------------------------------------------------------------------
 | Shared pieces
 | ---------------------------------------------------------------------- */

function stats(tiles) {
    $('#report-stats').innerHTML = tiles.map(({ label, value, note, tone }) => `
        <div class="surface px-4 py-3">
            <span class="block text-xs uppercase tracking-wide text-muted-foreground">${esc(label)}</span>
            <span class="mt-1 block font-mono text-xl font-semibold ${tone ?? 'text-foreground'}">${esc(value)}</span>
            ${note ? `<span class="mt-0.5 block text-xs text-muted-foreground">${esc(note)}</span>` : ''}
        </div>`).join('');
}

function head(columns) {
    $('#report-head').innerHTML = `
        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
            ${columns.map(([label, extra = '']) => `<th class="px-4 py-3 font-semibold ${extra}">${esc(label)}</th>`).join('')}
        </tr>`;
}

function banner(ok, okText, badText) {
    const host = $('#reconciliation');

    host.classList.remove('hidden');
    host.innerHTML = ok
        ? `<div class="surface flex items-center gap-2 border-emerald-200 bg-emerald-50/60 px-4 py-3 text-[0.8125rem] text-emerald-800">${okText}</div>`
        : `<div class="surface flex items-center gap-2 border-amber-200 bg-amber-50 px-4 py-3 text-[0.8125rem] text-amber-900">${badText}</div>`;
}

/* -------------------------------------------------------------------------
 | Day book
 | ---------------------------------------------------------------------- */

function renderDayBook(rows, meta) {
    head([
        ['Date'], ['Voucher'], ['Particulars'],
        ['Debit', 'text-right'], ['Credit', 'text-right'],
    ]);

    stats([
        { label: 'Vouchers', value: meta.pagination?.total ?? rows.length },
        { label: 'Total debits', value: formatMoney(meta.totals?.debit ?? '0') },
        { label: 'Total credits', value: formatMoney(meta.totals?.credit ?? '0') },
    ]);

    // The one number that matters. If the two sides differ, everything else on
    // the page is suspect — so it is stated rather than left to be inferred.
    banner(
        meta.totals?.is_balanced,
        `<span class="font-semibold">The books balance.</span> Debits and credits both total ${esc(formatMoney(meta.totals?.debit ?? '0'))}.`,
        '<span class="font-semibold">The books do not balance.</span> This should be impossible — please report it before entering anything further.',
    );

    if (!rows.length) {
        $('#report-rows').innerHTML = tableMessage(5, 'Nothing was posted in this period.');
        $('#report-summary').textContent = '';

        return;
    }

    // One block per voucher: a header row naming the event, then its lines.
    // That is what makes it a day book rather than a list of transactions —
    // the question it answers is "what did we actually do", not "what does this
    // account hold".
    $('#report-rows').innerHTML = rows.map((txn) => {
        const header = `
            <tr class="border-t border-border bg-secondary/20">
                <td class="table-cell w-32 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(txn.date))}</td>
                <td class="table-cell w-40 text-[0.8125rem]">
                    <span class="font-medium">${esc(txn.type_label)}</span>
                    <div class="text-xs text-muted-foreground">#${txn.id}${txn.source === 'manual' ? '' : ` · ${esc(txn.source_label)}`}</div>
                </td>
                <td class="table-cell text-[0.8125rem]" colspan="3">
                    <span class="font-medium">${esc(txn.notes || txn.party?.name || '—')}</span>
                    ${txn.status === 'reversed' ? '<span class="ml-2 text-xs text-rose-700">reversed</span>' : ''}
                    ${txn.reverses_id ? `<span class="ml-2 text-xs text-muted-foreground">reverses #${txn.reverses_id}</span>` : ''}
                </td>
            </tr>`;

        const lines = (txn.lines ?? []).map((line) => `
            <tr class="border-t border-border/60">
                <td class="table-cell"></td>
                <td class="table-cell"></td>
                <td class="table-cell text-[0.8125rem]">
                    <span class="font-mono text-xs text-muted-foreground">${esc(line.account?.code ?? '')}</span>
                    <span class="ml-2">${esc(line.account?.name ?? '—')}</span>
                    ${line.memo ? `<span class="ml-2 text-xs text-muted-foreground">${esc(line.memo)}</span>` : ''}
                </td>
                <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">
                    ${isZeroAmount(line.debit) ? '' : esc(formatMoney(line.debit))}
                </td>
                <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">
                    ${isZeroAmount(line.credit) ? '' : esc(formatMoney(line.credit))}
                </td>
            </tr>`).join('');

        return header + lines;
    }).join('');

    const days = Object.keys(meta.days ?? {}).length;

    $('#report-summary').textContent = `${rows.length} voucher${rows.length === 1 ? '' : 's'} across ${days} day${days === 1 ? '' : 's'} on this page.`;
}

/* -------------------------------------------------------------------------
 | Profit and loss
 | ---------------------------------------------------------------------- */

function renderProfitAndLoss(data, meta) {
    head([['Account'], ['', 'text-right'], ['Amount', 'text-right']]);

    const totals = meta.totals ?? {};

    stats([
        { label: 'Revenue', value: formatMoney(totals.revenue ?? '0') },
        {
            label: 'Gross margin',
            value: formatMoney(totals.gross_margin ?? '0', { sign: true }),
            note: 'Revenue less the cost of what was sold',
        },
        { label: 'Overheads', value: formatMoney(totals.overheads ?? '0'), note: 'What it costs to be open' },
        {
            label: 'Net',
            value: formatMoney(totals.net ?? '0', { sign: true }),
            tone: (totals.net ?? '0').startsWith('-') ? 'text-rose-700' : 'text-emerald-700',
        },
    ]);

    const section = (title, rows, total, emphasis = false) => `
        <tr class="border-t border-border bg-secondary/30">
            <td class="table-cell text-[0.8125rem] font-semibold uppercase tracking-wide text-muted-foreground" colspan="3">
                ${esc(title)}
            </td>
        </tr>
        ${rows.length
            ? rows.map((row) => `
                <tr class="border-t border-border">
                    <td class="table-cell text-[0.8125rem]">
                        <span class="font-mono text-xs text-muted-foreground">${esc(row.account.code)}</span>
                        <span class="ml-2">${esc(row.account.name)}</span>
                    </td>
                    <td class="table-cell"></td>
                    <td class="table-cell w-40 text-right font-mono text-[0.8125rem]">${esc(formatMoney(row.amount, { sign: true }))}</td>
                </tr>`).join('')
            : `<tr class="border-t border-border">
                   <td class="table-cell text-[0.8125rem] italic text-muted-foreground" colspan="3">Nothing in this period.</td>
               </tr>`}
        <tr class="border-t border-border">
            <td class="table-cell text-right text-[0.8125rem] font-semibold text-muted-foreground" colspan="2">${esc(title)} total</td>
            <td class="table-cell w-40 text-right font-mono text-[0.8125rem] ${emphasis ? 'font-semibold' : ''}">
                ${esc(formatMoney(total, { sign: true }))}
            </td>
        </tr>`;

    $('#report-rows').innerHTML = [
        section('Income', data.income ?? [], totals.revenue ?? '0'),
        // Kept apart from overheads on purpose. A workshop with an 8% gross
        // margin has a pricing problem; one with a 40% margin that still loses
        // money has a rent problem, and a statement that added the two together
        // would say neither.
        section('Cost of sales', data.cost_of_sales ?? [], totals.cost_of_sales ?? '0'),
        section('Overheads', data.overheads ?? [], totals.overheads ?? '0', true),
    ].join('');

    $('#report-foot').innerHTML = `
        <tr class="border-t-2 border-border bg-secondary/30 text-sm font-semibold">
            <td class="px-4 py-3 text-right text-muted-foreground" colspan="2">Net for the period</td>
            <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(totals.net ?? '0', { sign: true }))}</td>
        </tr>`;

    $('#report-summary').textContent = '';
}

/* -------------------------------------------------------------------------
 | GST
 | ---------------------------------------------------------------------- */

function renderGst(data, meta) {
    head([
        ['Rate'], ['Taxable', 'text-right'], ['CGST', 'text-right'],
        ['SGST', 'text-right'], ['IGST', 'text-right'], ['Tax', 'text-right'],
    ]);

    const net = meta.net_payable ?? '0';
    const owing = !net.startsWith('-');

    stats([
        { label: 'Output tax charged', value: formatMoney(data.output?.tax ?? '0') },
        { label: 'Input tax paid', value: formatMoney(data.input?.tax ?? '0') },
        {
            label: owing ? 'Net payable' : 'Net in credit',
            value: formatMoney(net, { sign: true }),
            note: owing ? 'On these documents alone' : 'Bought more than was sold',
            tone: owing ? 'text-foreground' : 'text-emerald-700',
        },
    ]);

    // Non-zero means tax reached an account with no bill line behind it —
    // almost always a manual journal, which M4 deliberately allows because it
    // is the correction mechanism for everything else. Surfaced so it stays a
    // decision rather than a surprise on a return.
    const rec = meta.reconciliation ?? {};

    banner(
        rec.agrees,
        '<span class="font-semibold">The bills and the tax accounts agree.</span> Every rupee of tax has a document behind it.',
        `<span class="font-semibold">The tax accounts hold more than the bills account for.</span>
         Output differs by ${esc(formatMoney(rec.output_difference ?? '0', { sign: true }))},
         input by ${esc(formatMoney(rec.input_difference ?? '0', { sign: true }))}.
         Almost always a manual journal — worth checking before this is filed.`,
    );

    const side = (title, block) => `
        <tr class="border-t border-border bg-secondary/30">
            <td class="table-cell text-[0.8125rem] font-semibold uppercase tracking-wide text-muted-foreground" colspan="6">
                ${esc(title)}
            </td>
        </tr>
        ${(block.rates ?? []).length
            ? block.rates.map((rate) => `
                <tr class="border-t border-border">
                    <td class="table-cell w-24 font-mono text-[0.8125rem]">${esc(rate.rate)}%</td>
                    <td class="table-cell text-right font-mono text-[0.8125rem]">${esc(formatMoney(rate.taxable))}</td>
                    <td class="table-cell text-right font-mono text-[0.8125rem]">${isZeroAmount(rate.cgst) ? '—' : esc(formatMoney(rate.cgst))}</td>
                    <td class="table-cell text-right font-mono text-[0.8125rem]">${isZeroAmount(rate.sgst) ? '—' : esc(formatMoney(rate.sgst))}</td>
                    <td class="table-cell text-right font-mono text-[0.8125rem]">${isZeroAmount(rate.igst) ? '—' : esc(formatMoney(rate.igst))}</td>
                    <td class="table-cell text-right font-mono text-[0.8125rem] font-semibold">${esc(formatMoney(rate.tax))}</td>
                </tr>`).join('')
            : `<tr class="border-t border-border">
                   <td class="table-cell text-[0.8125rem] italic text-muted-foreground" colspan="6">Nothing in this period.</td>
               </tr>`}
        <tr class="border-t border-border">
            <td class="table-cell text-[0.8125rem] font-semibold text-muted-foreground">Total</td>
            <td class="table-cell text-right font-mono text-[0.8125rem]">${esc(formatMoney(block.taxable ?? '0'))}</td>
            <td class="table-cell text-right font-mono text-[0.8125rem]">${esc(formatMoney(block.cgst ?? '0'))}</td>
            <td class="table-cell text-right font-mono text-[0.8125rem]">${esc(formatMoney(block.sgst ?? '0'))}</td>
            <td class="table-cell text-right font-mono text-[0.8125rem]">${esc(formatMoney(block.igst ?? '0'))}</td>
            <td class="table-cell text-right font-mono text-[0.8125rem] font-semibold">${esc(formatMoney(block.tax ?? '0'))}</td>
        </tr>`;

    $('#report-rows').innerHTML =
        side('Output — tax charged on sales', data.output ?? {})
        + side('Input — tax paid on purchases', data.input ?? {});

    $('#report-summary').textContent =
        'Rates come from the bill lines, which are the only place Phase 1 records how the tax split into CGST, SGST and IGST.';
}

/* -------------------------------------------------------------------------
 | Parked drafts
 | ---------------------------------------------------------------------- */

function renderDrafts(rows, meta) {
    head([
        ['Started'], ['Type'], ['Particulars'],
        ['Age', 'text-right'], ['Total', 'text-right'], ['Status'],
    ]);

    stats([
        { label: 'Parked drafts', value: meta.totals?.count ?? 0 },
        {
            label: 'Gone stale',
            value: meta.totals?.stale ?? 0,
            note: `Older than ${meta.stale_after_days ?? 14} days`,
            tone: (meta.totals?.stale ?? 0) > 0 ? 'text-amber-700' : 'text-foreground',
        },
        {
            label: 'Value on this page',
            value: formatMoney(meta.totals?.value ?? '0'),
            note: 'Re-priced when each one posts',
        },
    ]);

    if (!rows.length) {
        $('#report-rows').innerHTML = tableMessage(6, 'Nothing is parked. Every transaction started has been authorised.');
        $('#report-summary').textContent = '';

        return;
    }

    $('#report-rows').innerHTML = rows.map(({ transaction: txn, age_in_days: age, is_stale: stale, reason }) => `
        <tr class="border-t border-border ${stale ? 'bg-amber-50/40' : ''}">
            <td class="table-cell w-32 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(txn.date))}</td>
            <td class="table-cell w-40 text-[0.8125rem]">${esc(txn.type_label)}</td>
            <td class="table-cell text-[0.8125rem]">
                <span class="font-medium">${esc(txn.notes || txn.party?.name || `Draft #${txn.id}`)}</span>
                <div class="text-xs text-muted-foreground">#${txn.id}${txn.created_by ? ` · ${esc(txn.created_by)}` : ''}</div>
            </td>
            <td class="table-cell w-24 text-right font-mono text-[0.8125rem]">${age}d</td>
            <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">${esc(formatMoney(txn.total))}</td>
            <td class="table-cell w-72 text-[0.8125rem] ${stale ? 'text-amber-800' : 'text-muted-foreground'}">
                ${esc(reason ?? 'Waiting to be authorised')}
            </td>
        </tr>`).join('');

    $('#report-summary').textContent =
        'A parked draft is re-priced and re-costed at the moment it posts, so these totals are what was intended rather than what will land.';
}

const RENDERERS = {
    'day-book': renderDayBook,
    'profit-and-loss': renderProfitAndLoss,
    gst: renderGst,
    drafts: renderDrafts,
};

/* -------------------------------------------------------------------------
 | Boot
 | ---------------------------------------------------------------------- */

async function loadPeriods() {
    try {
        const { data } = await auth.call('/reports/meta');

        $('#filter-period').innerHTML = data.periods
            .map((p) => `<option value="${esc(p.value)}">${esc(p.label)}</option>`)
            .join('');

        $('#filter-period').value = state.period;
    } catch {
        // Without the presets the period picker stays empty and every report
        // falls back to all time, which is still a correct answer.
        $('#filter-period').innerHTML = '<option value="all">Everything so far</option>';
        state.period = 'all';
    }
}

export default async function initReports() {
    await loadPeriods();
    await load();

    $$('#report-tabs [data-report]').forEach((tab) => {
        tab.addEventListener('click', () => {
            state.report = tab.dataset.report;
            state.page = 1;
            load();
        });
    });

    $('#filter-period').addEventListener('change', (event) => {
        state.period = event.target.value;
        state.page = 1;
        load();
    });

    ['from', 'to'].forEach((field) => {
        $(`#filter-${field}`).addEventListener('change', (event) => {
            state[field] = event.target.value;
            state.period = 'custom';
            $('#filter-period').value = 'custom';
            state.page = 1;
            load();
        });
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
