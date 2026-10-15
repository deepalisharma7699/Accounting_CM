import { esc, formatDate, formatMoney } from '../ui';

/**
 * The invoices one person is credited with, as rows — M22.
 *
 * Two drawers list them: the employee drawer on the Staff card and the
 * person drawer on Insights → People. Both read `GET /staff/{id}/work`, so both
 * render its rows from here rather than each keeping a copy of the markup.
 *
 * `link` makes each row a button carrying `data-sale-doc`, which a host turns
 * into the Sales deep link (`#sales?doc=`). Off by default, because the Staff
 * drawer reads its rows as a summary and never offered one.
 */
export function workInvoiceRows(invoices, { link = false } = {}) {
    return invoices.map((invoice) => {
        const inner = `
            <div class="min-w-0">
                <div class="truncate text-[0.8125rem] font-medium text-foreground">
                    ${esc(invoice.doc_no ?? `#${invoice.id}`)}
                    ${invoice.trades?.length
                        ? `<span class="badge bg-accent text-accent-foreground">
                               ${esc(invoice.trades.join(', '))}
                           </span>`
                        : ''}
                </div>
                <div class="truncate text-[0.75rem] text-muted-foreground">
                    ${esc(formatDate(invoice.date))}${invoice.party ? ` · ${esc(invoice.party)}` : ''}
                </div>
            </div>
            <div class="shrink-0 text-[0.875rem] font-semibold text-foreground">
                ${esc(formatMoney(invoice.total))}
            </div>`;

        const classes = 'flex w-full items-baseline justify-between gap-3 border-b border-muted py-2 text-left last:border-0';

        return link
            ? `<button type="button" class="${classes} hover:bg-secondary/50" data-sale-doc="${esc(String(invoice.id))}">${inner}</button>`
            : `<div class="${classes}">${inner}</div>`;
    }).join('');
}
