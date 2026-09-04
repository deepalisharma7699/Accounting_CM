import { esc, formatDate, formatMoney, isZeroAmount } from '../ui';

/**
 * The four statements — M12, rendered inside the insight module.
 *
 * The day book, the profit & loss, the GST summary and the parked-draft
 * worklist. These moved here from `pages/reports.js` when Insights and Reports
 * became one card; **the reports themselves did not change**, and neither did
 * the endpoints behind them. `GET /reports/*` is still what answers, because
 * re-exposing a statement under a second URL is a second thing to keep in step
 * and the second one always drifts.
 *
 * ## Why they are strings rather than DOM writes
 *
 * The old page module wrote straight into `#report-rows` and friends through
 * `document`. That worked while it owned the whole screen; inside a tabbed
 * module it would not, because the workspace detaches the surface it is not
 * showing and `document.querySelector` finds nothing in a detached tree — the
 * failure CLAUDE.md records against reaching for `document` from a module. So
 * each renderer here returns markup and the controller decides where it lands.
 *
 * ## Two of them state whether they can be trusted
 *
 * The day book says whether the books balance; the GST summary says whether the
 * bill lines and the tax accounts agree. Both are stated **even when they are
 * fine**, because a reader has to be able to tell "nothing to see" from "we did
 * not check". Neither is ever repaired here — this module reads, it does not
 * fix, and surfacing a difference is what keeps a manual correction a decision
 * rather than a surprise on a return.
 */

/* -------------------------------------------------------------------------
 | Shared furniture
 | ---------------------------------------------------------------------- */

function stats(tiles) {
    return `
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            ${tiles.map(({ label, value, note, tone }) => `
                <div class="stat-tile flex-col items-start gap-1">
                    <span class="text-[0.6875rem] font-semibold uppercase tracking-wide text-muted-foreground">${esc(label)}</span>
                    <span class="block font-mono text-[1.375rem] font-bold leading-tight ${tone ?? 'text-foreground'}">${esc(value)}</span>
                    ${note ? `<span class="text-[0.75rem] text-muted-foreground">${esc(note)}</span>` : ''}
                </div>`).join('')}
        </div>`;
}

function banner(ok, okText, badText) {
    return ok
        ? `<div class="surface mb-4 border-emerald-200 bg-emerald-50/60 px-4 py-3 text-[0.8125rem] text-emerald-800">${okText}</div>`
        : `<div class="surface mb-4 border-amber-200 bg-amber-50 px-4 py-3 text-[0.8125rem] text-amber-900">${badText}</div>`;
}

/**
 * The table frame every statement shares, with its own summary line and pager.
 *
 * The pager is rendered even when there is one page, but disabled — a control
 * that appears and disappears as the data changes moves everything under it.
 */
function sheet({ head, body, foot = '', summary = '', pagination = null }) {
    return `
        <div class="surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] border-collapse">
                    <thead>${head}</thead>
                    <tbody>${body}</tbody>
                    <tfoot>${foot}</tfoot>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <p class="text-[0.8125rem] text-muted-foreground">${summary}</p>
                ${pagination ? pager(pagination) : ''}
            </div>
        </div>`;
}

function pager(pagination) {
    const page = pagination.current_page ?? 1;

    return `
        <div class="flex items-center gap-2">
            <span class="text-[0.75rem] text-muted-foreground">Page ${page} of ${pagination.last_page ?? 1}</span>
            <button type="button" class="btn btn-secondary btn-sm" data-page="prev" ${page <= 1 ? 'disabled' : ''}>Previous</button>
            <button type="button" class="btn btn-secondary btn-sm" data-page="next" ${pagination.has_more ? '' : 'disabled'}>Next</button>
        </div>`;
}

function head(columns) {
    return `
        <tr class="border-b border-border bg-secondary/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
            ${columns.map(([label, extra = '']) => `<th class="px-4 py-3 font-semibold ${extra}" scope="col">${esc(label)}</th>`).join('')}
        </tr>`;
}

function emptyRow(colspan, message) {
    return `<tr><td class="px-4 py-10 text-center text-[0.8125rem] text-muted-foreground" colspan="${colspan}">${esc(message)}</td></tr>`;
}

/* -------------------------------------------------------------------------
 | The day book
 | ---------------------------------------------------------------------- */

function dayBook(rows, meta) {
    const totals = meta.totals ?? {};

    const body = rows.length
        // One block per voucher: a header row naming the event, then its lines.
        // That is what makes it a day book rather than a list of transactions —
        // the question it answers is "what did we actually do", not "what does
        // this account hold".
        ? rows.map((txn) => {
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
                    <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">${isZeroAmount(line.debit) ? '' : esc(formatMoney(line.debit))}</td>
                    <td class="table-cell w-32 text-right font-mono text-[0.8125rem]">${isZeroAmount(line.credit) ? '' : esc(formatMoney(line.credit))}</td>
                </tr>`).join('');

            return header + lines;
        }).join('')
        : emptyRow(5, 'Nothing was posted in this period.');

    const days = Object.keys(meta.days ?? {}).length;

    return stats([
        { label: 'Vouchers', value: meta.pagination?.total ?? rows.length },
        { label: 'Total debits', value: formatMoney(totals.debit ?? '0') },
        { label: 'Total credits', value: formatMoney(totals.credit ?? '0') },
    ])
    // The one number that matters. If the two sides differ, everything else on
    // the page is suspect — so it is stated rather than left to be inferred.
    + banner(
        totals.is_balanced,
        `<span class="font-semibold">The books balance.</span> Debits and credits both total ${esc(formatMoney(totals.debit ?? '0'))}.`,
        '<span class="font-semibold">The books do not balance.</span> This should be impossible — please report it before entering anything further.',
    )
    + sheet({
        head: head([['Date'], ['Voucher'], ['Particulars'], ['Debit', 'text-right'], ['Credit', 'text-right']]),
        body,
        summary: rows.length
            ? `${rows.length} voucher${rows.length === 1 ? '' : 's'} across ${days} day${days === 1 ? '' : 's'} on this page.`
            : '',
        pagination: meta.pagination,
    });
}

/* -------------------------------------------------------------------------
 | Profit and loss
 | ---------------------------------------------------------------------- */

function profitAndLoss(data, meta) {
    const totals = meta.totals ?? {};

    const section = (title, rows, total, emphasis = false) => `
        <tr class="border-t border-border bg-secondary/30">
            <td class="table-cell text-[0.8125rem] font-semibold uppercase tracking-wide text-muted-foreground" colspan="3">${esc(title)}</td>
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
            <td class="table-cell w-40 text-right font-mono text-[0.8125rem] ${emphasis ? 'font-semibold' : ''}">${esc(formatMoney(total, { sign: true }))}</td>
        </tr>`;

    return stats([
        { label: 'Revenue', value: formatMoney(totals.revenue ?? '0') },
        { label: 'Gross margin', value: formatMoney(totals.gross_margin ?? '0', { sign: true }), note: 'Revenue less the cost of what was sold' },
        { label: 'Overheads', value: formatMoney(totals.overheads ?? '0'), note: 'What it costs to be open' },
        {
            label: 'Net',
            value: formatMoney(totals.net ?? '0', { sign: true }),
            tone: (totals.net ?? '0').startsWith('-') ? 'text-rose-700' : 'text-emerald-700',
        },
    ])
    + sheet({
        head: head([['Account'], ['', 'text-right'], ['Amount', 'text-right']]),
        body: [
            section('Income', data.income ?? [], totals.revenue ?? '0'),
            // Kept apart from overheads on purpose. A workshop with an 8% gross
            // margin has a pricing problem; one with a 40% margin that still
            // loses money has a rent problem, and a statement that added the two
            // together would say neither.
            section('Cost of sales', data.cost_of_sales ?? [], totals.cost_of_sales ?? '0'),
            section('Overheads', data.overheads ?? [], totals.overheads ?? '0', true),
        ].join(''),
        foot: `
            <tr class="border-t-2 border-border bg-secondary/30 text-sm font-semibold">
                <td class="px-4 py-3 text-right text-muted-foreground" colspan="2">Net for the period</td>
                <td class="px-4 py-3 text-right font-mono">${esc(formatMoney(totals.net ?? '0', { sign: true }))}</td>
            </tr>`,
    });
}

/* -------------------------------------------------------------------------
 | GST
 | ---------------------------------------------------------------------- */

function gst(data, meta) {
    const net = meta.net_payable ?? '0';
    const owing = !net.startsWith('-');
    const rec = meta.reconciliation ?? {};

    const side = (title, block) => `
        <tr class="border-t border-border bg-secondary/30">
            <td class="table-cell text-[0.8125rem] font-semibold uppercase tracking-wide text-muted-foreground" colspan="6">${esc(title)}</td>
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

    return stats([
        { label: 'Output tax charged', value: formatMoney(data.output?.tax ?? '0') },
        { label: 'Input tax paid', value: formatMoney(data.input?.tax ?? '0') },
        {
            label: owing ? 'Net payable' : 'Net in credit',
            value: formatMoney(net, { sign: true }),
            note: owing ? 'On these documents alone' : 'Bought more than was sold',
            tone: owing ? 'text-foreground' : 'text-emerald-700',
        },
    ])
    // Non-zero means tax reached an account with no bill line behind it — almost
    // always a manual journal, which M4 deliberately allows because it is the
    // correction mechanism for everything else. Surfaced so it stays a decision
    // rather than a surprise on a return.
    + banner(
        rec.agrees,
        '<span class="font-semibold">The bills and the tax accounts agree.</span> Every rupee of tax has a document behind it.',
        `<span class="font-semibold">The tax accounts hold more than the bills account for.</span>
         Output differs by ${esc(formatMoney(rec.output_difference ?? '0', { sign: true }))},
         input by ${esc(formatMoney(rec.input_difference ?? '0', { sign: true }))}.
         Almost always a manual journal — worth checking before this is filed.`,
    )
    + sheet({
        head: head([['Rate'], ['Taxable', 'text-right'], ['CGST', 'text-right'], ['SGST', 'text-right'], ['IGST', 'text-right'], ['Tax', 'text-right']]),
        body: side('Output — tax charged on sales', data.output ?? {}) + side('Input — tax paid on purchases', data.input ?? {}),
        summary: 'Rates come from the bill lines, which are the only place Phase 1 records how the tax split into CGST, SGST and IGST.',
    });
}

/* -------------------------------------------------------------------------
 | Parked drafts
 | ---------------------------------------------------------------------- */

function drafts(rows, meta) {
    const totals = meta.totals ?? {};

    const body = rows.length
        ? rows.map(({ transaction: txn, age_in_days: age, is_stale: stale, reason }) => `
            <tr class="border-t border-border ${stale ? 'bg-amber-50/40' : ''}">
                <td class="table-cell w-32 whitespace-nowrap text-[0.8125rem]">${esc(formatDate(txn.date))}</td>
                <td class="table-cell w-40 text-[0.8125rem]">${esc(txn.type_label)}</td>
                <td class="table-cell text-[0.8125rem]">
                    <span class="font-medium">${esc(txn.notes || txn.party?.name || `Draft #${txn.id}`)}</span>
                    <div class="text-xs text-muted-foreground">#${txn.id}${txn.created_by ? ` · ${esc(txn.created_by)}` : ''}</div>
                </td>
                <td class="table-cell w-24 text-right font-mono text-[0.8125rem]">${age}d</td>
                <td class="table-cell w-36 text-right font-mono text-[0.8125rem]">${esc(formatMoney(txn.total))}</td>
                <td class="table-cell w-72 text-[0.8125rem] ${stale ? 'text-amber-800' : 'text-muted-foreground'}">${esc(reason ?? 'Waiting to be authorised')}</td>
            </tr>`).join('')
        : emptyRow(6, 'Nothing is parked. Every transaction started has been authorised.');

    return stats([
        { label: 'Parked drafts', value: totals.count ?? 0 },
        {
            label: 'Gone stale',
            value: totals.stale ?? 0,
            note: `Older than ${meta.stale_after_days ?? 14} days`,
            tone: (totals.stale ?? 0) > 0 ? 'text-amber-700' : 'text-foreground',
        },
        { label: 'Value on this page', value: formatMoney(totals.value ?? '0'), note: 'Re-priced when each one posts' },
    ])
    + sheet({
        head: head([['Started'], ['Type'], ['Particulars'], ['Age', 'text-right'], ['Total', 'text-right'], ['Status']]),
        body,
        summary: rows.length
            ? 'A parked draft is re-priced and re-costed at the moment it posts, so these totals are what was intended rather than what will land.'
            : '',
        pagination: meta.pagination,
    });
}

/* -------------------------------------------------------------------------
 | The registry
 | ---------------------------------------------------------------------- */

/**
 * Which statements exist, and what each one needs.
 *
 * `paged` says whether the controller should keep a page number for it, and
 * `periodless` marks the draft worklist — a draft is outstanding work rather
 * than an event, and hiding the one from three months ago because the picker
 * says "this month" would defeat the purpose of a worklist.
 */
export const STATEMENTS = {
    'day-book': { render: dayBook, paged: true, periodless: false },
    'profit-and-loss': { render: profitAndLoss, paged: false, periodless: false },
    gst: { render: gst, paged: false, periodless: false },
    drafts: { render: drafts, paged: true, periodless: true },
};
