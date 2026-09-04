<?php

namespace App\Enums;

/**
 * The numbering series a posted transaction takes its document number from.
 *
 * One series per kind of document a workshop hands over or files: invoices are
 * counted separately from purchase bills, which are counted separately from
 * receipts. That separation is not cosmetic — GST requires a consecutive series
 * of invoice numbers, and a series shared with receipts would have holes in it
 * wherever a customer paid.
 *
 * The value is the prefix printed on the document, so `INV/26-27/1001` reads back
 * as "sales invoice, financial year 2026-27, number 1001".
 */
enum DocumentSeries: string
{
    /** Sales invoices — the series GST actually cares about. */
    case Invoice = 'INV';

    /** Purchase bills, as recorded. The number is the workshop's own, not the supplier's. */
    case Purchase = 'PUR';

    /** Money collected from a customer. */
    case Receipt = 'RCT';

    /** Money paid out to a supplier. */
    case Payment = 'PAY';

    /** Running costs — rent, electricity, a courier. */
    case Expense = 'EXP';

    /** Hand-written double-entry vouchers. */
    case Journal = 'JV';

    /** Stock-take corrections. */
    case Adjustment = 'ADJ';

    /** The go-live declaration. One or two per workshop, ever. */
    case Opening = 'OB';

    /**
     * A customer returned goods — M18.
     *
     * Its own series rather than a continuation of the invoice run, because GST
     * requires credit notes to be numbered separately from the invoices they
     * credit. It also means somebody holding both can tell them apart at a
     * glance, which is the more immediately useful half.
     */
    case CreditNote = 'CN';

    /** Goods sent back to a supplier — the mirror of a credit note. */
    case DebitNote = 'DN';

    /**
     * A workshop job card — M19. `JOB/26-27/41`.
     *
     * The one series here that does not belong to a transaction, and it is in
     * this enum anyway rather than getting a counter of its own, because the
     * problem is identical: two motors on two benches carrying one ticket number
     * is the same unrecoverable mess as two invoices carrying one number, and the
     * locking that makes that impossible already exists here.
     *
     * Note what it does *not* share with the others: a job number is assigned
     * when the motor is booked in, not when anything is posted. There is no draft
     * state for a job to be discarded from, so numbering it early leaves no gap.
     */
    case JobCard = 'JOB';

    /**
     * Money handed to an employee against a salary not yet earned — M22.
     *
     * Its own series because an advance is a slip somebody signed for, and "which
     * advance was that" is a question asked across a counter weeks later. It is
     * also the series a workshop counts when it wonders how much is out with the
     * staff — a shared counter with vendor payments would make that uncountable.
     */
    case StaffAdvance = 'ADV';

    /**
     * A month's payroll — `SAL/26-27/4`, one voucher for the whole run.
     *
     * `SAL` rather than `PAY`, which is already the vendor payment series. The
     * two are both "money went out" and they are counted separately for the same
     * reason receipts and invoices are: a workshop asking what it has spent on
     * wages this year should not have to subtract its suppliers out of the answer.
     */
    case Payroll = 'SAL';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Sales Invoice',
            self::Purchase => 'Purchase Bill',
            self::Receipt => 'Receipt',
            self::Payment => 'Payment Voucher',
            self::Expense => 'Expense Voucher',
            self::Journal => 'Journal Voucher',
            self::Adjustment => 'Stock Adjustment',
            self::Opening => 'Opening Balance',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::JobCard => 'Job Card',
            self::StaffAdvance => 'Staff Advance',
            self::Payroll => 'Payroll',
        };
    }

    /**
     * Render a number in this series: `INV/26-27/1001`.
     *
     * The financial year is part of the printed number rather than only of the
     * counter behind it, because the counter resets each April. Without it,
     * this year's 1001 and last year's would be the same string against two
     * different invoices — which is the one thing an invoice number may never
     * be.
     */
    public function format(string $financialYear, int $number): string
    {
        return sprintf('%s/%s/%d', $this->value, $financialYear, $number);
    }
}
