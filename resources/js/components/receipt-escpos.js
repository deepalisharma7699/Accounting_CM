import { formatDate, formatMoney, isZeroAmount } from '../ui';

/**
 * A document as ESC/POS — the bytes a thermal printer is sent through the print
 * agent, with the paper cut at the end.
 *
 * The estimate or the GST invoice, from the **same payload** the sheet is
 * painted from (InvoiceDocumentService). This file decides where things go on a
 * 48- or 32-column roll and nothing else: every figure is printed as the server
 * stated it. It adds no tax and totals nothing, so a thermal copy cannot
 * disagree with the A4 copy of the same sale — and the agent, which only
 * forwards bytes, never needs to change when a layout does.
 *
 * Text mode is ASCII. The rupee sign is not in a receipt printer's code page,
 * so amounts go out as plain figures under an "Rs." column heading, and any
 * other character the printer cannot draw is replaced rather than sent as
 * garbage. Item and customer names are English, which is what this workshop
 * confirmed; Devanagari would need a raster image instead.
 */

const ESC = 0x1b;
const GS = 0x1d;

/** Characters per line on each roll, in the printer's standard font. */
const COLUMNS = { 80: 48, 58: 32 };

/**
 * What a printer's code page cannot draw, in the words it can. Spacing is kept
 * exactly: by the time a line reaches the printer its padding *is* the layout.
 */
function printable(text) {
    return String(text ?? '')
        .replace(/₹/g, 'Rs.')
        .replace(/[‘’]/g, "'")
        .replace(/[“”]/g, '"')
        .replace(/[–—−]/g, '-')
        .replace(/…/g, '...')
        .replace(/[^\x20-\x7e]/g, '?');
}

/** A value from the document, made printable and folded onto one line, before it is laid out. */
function ascii(text) {
    return printable(String(text ?? '').replace(/\s+/g, ' ').trim());
}

const money = (amount) => formatMoney(amount);

/**
 * The receipt, built a line at a time and turned into bytes at the end.
 */
class Receipt {
    constructor(width) {
        this.width = width;
        this.bytes = [ESC, 0x40]; // initialise: clears any mode left by the last job
    }

    raw(...codes) {
        this.bytes.push(...codes);

        return this;
    }

    text(line = '') {
        for (const char of printable(line)) this.bytes.push(char.charCodeAt(0));
        this.bytes.push(0x0a);

        return this;
    }

    align(where) {
        return this.raw(ESC, 0x61, { left: 0, center: 1, right: 2 }[where]);
    }

    bold(on) {
        return this.raw(ESC, 0x45, on ? 1 : 0);
    }

    /** Double height and width, for the one or two things read from across a counter. */
    large(on) {
        return this.raw(GS, 0x21, on ? 0x11 : 0x00);
    }

    rule(char = '-') {
        return this.text(char.repeat(this.width));
    }

    /** Words wrapped to the roll, never split mid-word unless one is longer than a line. */
    wrap(text, indent = '') {
        const room = this.width - indent.length;
        let line = '';

        for (const word of ascii(text).split(' ')) {
            if (word.length > room) {
                if (line) this.text(indent + line);
                for (let i = 0; i < word.length; i += room) this.text(indent + word.slice(i, i + room));
                line = '';
            } else if ((line ? `${line} ${word}` : word).length > room) {
                this.text(indent + line);
                line = word;
            } else {
                line = line ? `${line} ${word}` : word;
            }
        }

        if (line) this.text(indent + line);

        return this;
    }

    /** A label on the left and a figure on the right of one line. */
    pair(left, right) {
        // The left side keeps its leading spaces — that is the indent that
        // nests a line's figures under its name.
        const l = printable(left).replace(/\s+$/, '');
        const r = ascii(right);
        const gap = this.width - l.length - r.length;

        if (gap >= 1) return this.text(l + ' '.repeat(gap) + r);

        // Too long for one line: the label wraps, keeping its indent, and the
        // figure keeps its edge on the line below.
        const indent = l.match(/^ */)[0];

        return this.wrap(l, indent).text(r.padStart(this.width));
    }

    /** Feed past the cutter and cut, leaving a small hinge so the slip does not drop. */
    cut() {
        return this.raw(ESC, 0x64, 4, GS, 0x56, 0x42, 0x00);
    }

    toBytes() {
        return new Uint8Array(this.bytes);
    }
}

function lineItems(receipt, lines, describe) {
    lines.forEach((line) => {
        receipt.wrap(`${line.line_no}. ${line.description ?? ''}`);
        if (line.memo) receipt.wrap(line.memo, '   ');
        describe(line);
    });
}

const quantity = (line) => `${line.quantity}${line.unit_symbol ? ` ${line.unit_symbol}` : ''}`;

/* -------------------------------------------------------------------------
 | The estimate
 | ---------------------------------------------------------------------- */

function estimateReceipt(invoice, receipt) {
    const estimate = invoice.estimate;
    const customer = invoice.customer;

    receipt.align('center').bold(true).large(true).text('ESTIMATE').large(false).bold(false)
        .text(formatDate(estimate.date))
        .align('left');

    if (customer) {
        receipt.rule().bold(true).wrap(customer.name).bold(false);
        if (customer.phone) receipt.wrap(`Ph: ${customer.phone}`);
        if (customer.address) receipt.wrap(customer.address);
    }

    receipt.rule().pair('Item', 'Rs.').rule();

    lineItems(receipt, estimate.lines, (line) => {
        receipt.pair(`   ${quantity(line)} x ${money(line.rate)}`, money(line.amount));
        if (!isZeroAmount(line.discount)) receipt.wrap(`less ${money(line.discount)}`, '   ');
    });

    receipt.rule();

    if (!isZeroAmount(estimate.round_off)) {
        receipt.pair('Subtotal', money(estimate.subtotal)).pair('Round off', money(estimate.round_off));
    }

    receipt.bold(true).pair('TOTAL Rs.', money(estimate.total)).bold(false);

    paymentsAndWords(receipt, invoice, estimate.in_words);

    if (estimate.paid !== null) {
        receipt.pair('Paid', money(estimate.paid));
        if (!isZeroAmount(estimate.due)) receipt.bold(true).pair('Balance due', money(estimate.due)).bold(false);
    }

    receipt.rule().align('center').text('Estimate - not a tax invoice').align('left');
}

/* -------------------------------------------------------------------------
 | The GST invoice
 | ---------------------------------------------------------------------- */

function invoiceReceipt(invoice, receipt) {
    const { workshop, document: doc, customer, totals } = invoice;

    receipt.align('center').bold(true).large(true).wrap(workshop.name).large(false).bold(false);
    if (workshop.address) receipt.wrap(workshop.address);
    if (workshop.gstin) receipt.wrap(`GSTIN: ${workshop.gstin}`);

    receipt.rule().bold(true).text(doc.heading.toUpperCase()).bold(false)
        .wrap(`${doc.doc_no ?? `#${doc.id}`} ${formatDate(doc.date)}`)
        .align('left');

    if (customer) {
        receipt.rule().text('Billed to:').bold(true).wrap(customer.name).bold(false);
        if (customer.address) receipt.wrap(customer.address);
        if (customer.gstin) receipt.wrap(`GSTIN: ${customer.gstin}`);
        if (customer.phone) receipt.wrap(`Ph: ${customer.phone}`);
    }

    receipt.wrap(`Place of supply: ${totals.inter_state ? 'Inter-state (IGST)' : 'Intra-state (CGST+SGST)'}`);
    if (customer?.state_code) receipt.wrap(`State code: ${customer.state_code}`);

    receipt.rule().pair('Item', 'Rs.').rule();

    lineItems(receipt, invoice.lines, (line) => {
        if (line.hsn_sac) receipt.wrap(`HSN/SAC ${line.hsn_sac}`, '   ');
        receipt.wrap(`${quantity(line)} x ${money(line.unit_price)}`, '   ');
        if (!isZeroAmount(line.discount_amount)) receipt.wrap(`less ${money(line.discount_amount)}`, '   ');
        receipt.pair(`   Taxable ${money(line.taxable_value)} @${line.gst_rate}%`, money(line.line_total));
    });

    receipt.rule();

    if (!isZeroAmount(totals.discount)) {
        receipt.pair('Subtotal', money(totals.gross)).pair('Discount', `-${money(totals.discount)}`);
    }

    receipt.pair('Taxable value', money(totals.taxable));

    if (totals.inter_state) {
        receipt.pair('IGST', money(totals.igst));
    } else {
        receipt.pair('CGST', money(totals.cgst)).pair('SGST', money(totals.sgst));
    }

    if (!isZeroAmount(totals.round_off)) receipt.pair('Round off', money(totals.round_off));

    receipt.bold(true).pair('TOTAL Rs.', money(totals.total)).bold(false);

    paymentsAndWords(receipt, invoice, totals.in_words);

    const settlement = invoice.settlement;

    if (settlement) {
        receipt.pair('Paid', money(settlement.paid));
        if (!isZeroAmount(settlement.credited)) receipt.pair('Returned', money(settlement.credited));
        receipt.bold(true).pair(isZeroAmount(settlement.due) ? 'Settled' : 'Amount due', money(settlement.due))
            .bold(false);
    }

    receipt.rule().align('center').wrap('This is a computer-generated document and does not require a signature.')
        .align('left');
}

function paymentsAndWords(receipt, invoice, words) {
    receipt.wrap(words);

    if (invoice.received.length) {
        receipt.rule();
        invoice.received.forEach((payment) => {
            receipt.pair(
                `${payment.mode_label}${payment.reference ? ` ${payment.reference}` : ''}`,
                money(payment.amount),
            );
        });
    }

    if (invoice.document.notes) receipt.rule().wrap(invoice.document.notes);

    receipt.rule();
}

/* -------------------------------------------------------------------------
 | Entry points
 | ---------------------------------------------------------------------- */

/**
 * @param {object} invoice  the payload from GET /transactions/{id}/invoice
 * @param {'estimate'|'invoice'} as
 * @param {'80'|'58'} paper
 * @returns {Uint8Array}
 */
export function receiptBytes(invoice, as, paper) {
    const receipt = new Receipt(COLUMNS[paper] ?? COLUMNS[80]);

    if (as === 'estimate' && invoice.estimate) estimateReceipt(invoice, receipt);
    else invoiceReceipt(invoice, receipt);

    return receipt.cut().toBytes();
}

/** A short slip that proves the printer, the roll width and the cutter. */
export function testReceiptBytes(paper) {
    const receipt = new Receipt(COLUMNS[paper] ?? COLUMNS[80]);

    return receipt.align('center').bold(true).text('Printer test').bold(false)
        .text(`${COLUMNS[paper] ?? COLUMNS[80]} columns - ${paper} mm roll`)
        .align('left').rule()
        .text('1234567890'.repeat(5).slice(0, receipt.width))
        .rule()
        .align('center').text('If this line is centred and the')
        .text('paper was cut, printing works.')
        .align('left')
        .cut()
        .toBytes();
}
