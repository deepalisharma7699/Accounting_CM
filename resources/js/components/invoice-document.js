import { $, esc, formatDate, formatMoney, isZeroAmount } from '../ui';

/**
 * The invoice, painted.
 *
 * One renderer for both copies of the document — the workshop's print copy and
 * the customer's page at `/i/{token}`. Neither host owns it and neither may fork
 * it: the two copies of an invoice have to be the same document, and the way to
 * guarantee that is for there to be one piece of code that draws it.
 *
 * The markup it fills is `partials/invoice-document.blade.php`. Everything here
 * writes into a host inside that partial and nothing reaches for `document`, so
 * it works on a node that is not attached — which is exactly what the print copy
 * is until the moment it is printed.
 *
 * ## What is not here
 *
 * No cost, no margin, no "sold below cost", no ledger entries, no stock
 * movements. Not because they are filtered out — because the payload this draws
 * from never carries them. See `InvoiceDocumentService`.
 */

/* -------------------------------------------------------------------------
 | Small pieces
 | ---------------------------------------------------------------------- */

/*
 * Every figure on the sheet goes through one of these two, and both escape.
 *
 * `formatMoney` groups the digits of a string it is handed without parsing it,
 * so it is a pass-through for anything that is not a number — and this document
 * is rendered from a payload that reaches the page over the wire. Escaping here
 * rather than at each call site is what stops the next column somebody adds from
 * being the one that forgot.
 */
const num = (amount) => esc(formatMoney(amount));
const money = (amount) => `₹${num(amount)}`;

/**
 * A label above a value, the way a document sets them.
 *
 * Returns nothing at all for an empty value rather than a label over a dash: a
 * printed invoice with "GSTIN —" on it looks like a form somebody failed to
 * fill in, where an absent line simply reads as a workshop that has no GSTIN.
 */
function field(label, value) {
    return value
        ? `<p class="invoice-field"><span>${esc(label)}</span>${esc(value)}</p>`
        : '';
}

/**
 * The round-off line's sign, spelled out.
 *
 * Same rule as the bill document's running panel, and written out for the same
 * reason: `formatMoney`'s `sign` option withholds the `+` on an amount under a
 * rupee, and a round-off is always under a rupee.
 */
function signedPaise(amount) {
    const value = Number(amount) || 0;

    return `${value < 0 ? '−' : '+'}${num(Math.abs(value).toFixed(2))}`;
}

/* -------------------------------------------------------------------------
 | The sections
 | ---------------------------------------------------------------------- */

function head(invoice) {
    const { workshop, document: doc } = invoice;

    return `
        <div class="invoice-issuer">
            <p class="invoice-issuer-name">${esc(workshop.name)}</p>
            ${workshop.address ? `<p class="invoice-issuer-line">${esc(workshop.address)}</p>` : ''}
            ${field('GSTIN', workshop.gstin)}
            ${field('State code', workshop.state_code)}
        </div>

        <div class="invoice-title">
            <p class="invoice-heading">${esc(doc.heading)}</p>
            <p class="invoice-docno">${esc(doc.doc_no ?? `#${doc.id}`)}</p>
            <p class="invoice-date">${esc(formatDate(doc.date))}</p>
        </div>`;
}

function parties(invoice) {
    const { customer, totals } = invoice;

    // "Billed to" is omitted entirely rather than left blank. A counter sale to
    // somebody who did not give a name is a complete invoice with no counterparty
    // — inventing "Cash Customer" would put a party on a document that has none.
    const billed = customer === null
        ? ''
        : `
        <div class="invoice-party">
            <p class="invoice-party-label">Billed to</p>
            <p class="invoice-party-name">${esc(customer.name)}</p>
            ${customer.address ? `<p class="invoice-issuer-line">${esc(customer.address)}</p>` : ''}
            ${field('GSTIN', customer.gstin)}
            ${field('Phone', customer.phone)}
        </div>`;

    return `
        ${billed}
        <div class="invoice-party invoice-party-right">
            <p class="invoice-party-label">Place of supply</p>
            <p class="invoice-party-name">${totals.inter_state ? 'Inter-state' : 'Intra-state'}</p>
            <p class="invoice-issuer-line">
                ${totals.inter_state ? 'IGST charged' : 'CGST + SGST charged'}
            </p>
            ${field('State code', customer?.state_code)}
        </div>`;
}

/**
 * Which columns this particular invoice has.
 *
 * Decided from the document rather than fixed, because a fixed set means a
 * column of zeroes on most invoices: a workshop that never discounts would
 * print an empty Discount column on every bill it ever issues, and a column of
 * nothing is a column somebody has to read past.
 */
function columnsFor(invoice) {
    const discounted = invoice.lines.some((line) => !isZeroAmount(line.discount_amount));

    const columns = [
        { key: 'no', label: '#', align: 'left' },
        { key: 'description', label: 'Description', align: 'left' },
        { key: 'hsn', label: 'HSN/SAC', align: 'left' },
        { key: 'quantity', label: 'Qty', align: 'right' },
        { key: 'rate', label: 'Rate', align: 'right' },
    ];

    if (discounted) columns.push({ key: 'discount', label: 'Discount', align: 'right' });

    columns.push({ key: 'taxable', label: 'Taxable', align: 'right' });
    columns.push({ key: 'gst', label: 'GST', align: 'right' });

    if (invoice.totals.inter_state) {
        columns.push({ key: 'igst', label: 'IGST', align: 'right' });
    } else {
        columns.push({ key: 'cgst', label: 'CGST', align: 'right' });
        columns.push({ key: 'sgst', label: 'SGST', align: 'right' });
    }

    columns.push({ key: 'amount', label: 'Amount', align: 'right' });

    return columns;
}

function columnsMarkup(columns) {
    return `<tr>${columns
        .map((column) => `<th class="${column.align === 'right' ? 'text-right' : 'text-left'}">${esc(column.label)}</th>`)
        .join('')}</tr>`;
}

function rowsMarkup(invoice, columns) {
    if (invoice.lines.length === 0) {
        return `<tr><td colspan="${columns.length}" class="invoice-empty">
            This document has no lines.
        </td></tr>`;
    }

    const cell = {
        no: (line) => esc(String(line.line_no ?? '')),
        description: (line) => `${esc(line.description ?? '')}${
            line.memo ? `<span class="invoice-memo">${esc(line.memo)}</span>` : ''
        }`,
        hsn: (line) => esc(line.hsn_sac ?? ''),
        // The unit travels with the quantity and nowhere else. "4" on its own is
        // four of something, and on a bill that carries both metres of winding
        // wire and bearings, four of something is not an answer.
        quantity: (line) => `${esc(line.quantity)} ${esc(line.unit_symbol ?? '')}`.trim(),
        rate: (line) => num(line.unit_price),
        discount: (line) => (isZeroAmount(line.discount_amount) ? '' : num(line.discount_amount)),
        taxable: (line) => num(line.taxable_value),
        gst: (line) => `${esc(line.gst_rate)}%`,
        igst: (line) => num(line.igst_amount),
        cgst: (line) => num(line.cgst_amount),
        sgst: (line) => num(line.sgst_amount),
        amount: (line) => num(line.line_total),
    };

    return invoice.lines.map((line) => `<tr>${columns
        .map((column) => `<td class="${column.align === 'right' ? 'text-right num' : ''}">${
            cell[column.key](line)
        }</td>`)
        .join('')}</tr>`).join('');
}

function totalsMarkup(invoice) {
    const totals = invoice.totals;

    const row = (label, value, className = '') =>
        `<div class="${className}"><dt>${esc(label)}</dt><dd>${value}</dd></div>`;

    const tax = totals.inter_state
        ? row('IGST', money(totals.igst))
        : row('CGST', money(totals.cgst)) + row('SGST', money(totals.sgst));

    return [
        // Gross and Discount appear only when something was discounted. On an
        // invoice with no discount they would restate the taxable value twice.
        isZeroAmount(totals.discount) ? '' : row('Subtotal', money(totals.gross)),
        isZeroAmount(totals.discount) ? '' : row('Discount', `− ${money(totals.discount)}`),
        row('Taxable value', money(totals.taxable)),
        tax,
        isZeroAmount(totals.round_off) ? '' : row('Round off', signedPaise(totals.round_off)),
        row('Total', money(totals.total), 'invoice-total'),
    ].join('');
}

/**
 * The left-hand column under the lines: what was handed over, and any note.
 */
function asideMarkup(invoice) {
    const received = invoice.received.length === 0 ? '' : `
        <div class="invoice-received">
            <p class="invoice-party-label">Received with this document</p>
            ${invoice.received.map((payment) => `
                <p class="invoice-received-row">
                    <span>${esc(payment.mode_label)}${
                        payment.reference ? ` · ${esc(payment.reference)}` : ''
                    }</span>
                    <span class="num">${money(payment.amount)}</span>
                </p>`).join('')}
        </div>`;

    const notes = invoice.document.notes
        ? `<div class="invoice-notes">
               <p class="invoice-party-label">Notes</p>
               <p>${esc(invoice.document.notes)}</p>
           </div>`
        : '';

    return received + notes;
}

/**
 * The running position — paid, credited back, and what is left.
 *
 * Null on a credit note, and correctly so: a credit note settles nothing on its
 * own. What it did to the invoice's balance shows on the invoice.
 */
function settlementMarkup(invoice) {
    const settlement = invoice.settlement;

    if (settlement === null) return '';

    const credited = isZeroAmount(settlement.credited)
        ? ''
        : `<div><dt>Returned</dt><dd>${money(settlement.credited)}</dd></div>`;

    return `
        <dl class="invoice-settlement" data-status="${esc(settlement.status)}">
            <div><dt>Paid</dt><dd>${money(settlement.paid)}</dd></div>
            ${credited}
            <div class="invoice-due"><dt>${
                isZeroAmount(settlement.due) ? 'Settled' : 'Amount due'
            }</dt><dd>${money(settlement.due)}</dd></div>
            <div class="invoice-settlement-note"><dt>Status</dt><dd>${
                esc(settlement.status_label)
            }${
                settlement.due_date && !isZeroAmount(settlement.due)
                    ? ` · due ${esc(formatDate(settlement.due_date))}`
                    : ''
            }</dd></div>
        </dl>`;
}

/* -------------------------------------------------------------------------
 | The one entry point
 | ---------------------------------------------------------------------- */

/**
 * Paint one invoice into a mounted copy of `partials/invoice-document.blade.php`.
 *
 * @param {Element} root     the `[data-invoice-document]` element, attached or not
 * @param {object}  invoice  the array from InvoiceDocumentService, as JSON
 */
export function renderInvoice(root, invoice) {
    if (!root || !invoice) return;

    const columns = columnsFor(invoice);

    $('[data-invoice-head]', root).innerHTML = head(invoice);
    $('[data-invoice-parties]', root).innerHTML = parties(invoice);
    $('[data-invoice-columns]', root).innerHTML = columnsMarkup(columns);
    $('[data-invoice-rows]', root).innerHTML = rowsMarkup(invoice, columns);
    $('[data-invoice-aside]', root).innerHTML = asideMarkup(invoice);
    $('[data-invoice-totals]', root).innerHTML = totalsMarkup(invoice);
    $('[data-invoice-words]', root).textContent = invoice.totals.in_words;
    $('[data-invoice-settlement]', root).innerHTML = settlementMarkup(invoice);

    // Said on the document rather than assumed. A workshop that prints these and
    // files them wants the reason there is no signature on the page to be on the
    // page, not in a conversation at the counter.
    $('[data-invoice-foot]', root).textContent =
        'This is a computer-generated document and does not require a signature.';
}
