<?php

namespace App\Enums;

/**
 * How much of a job has reached an invoice — M19.
 *
 * ## Why this is not a status
 *
 * Because {@see WorkshopJobStatus} is about the motor and this is about the
 * money, and neither implies the other — that enum says so where `Delivered` is
 * declared. A regular customer's motor goes home on Friday against an invoice
 * raised at the end of the month, and a job billed in advance sits on the shelf
 * until somebody comes for it. Folding billing into the pipeline would mean
 * either lying about where the motor is or lying about what has been charged
 * for, and a workshop reading either would stop trusting both.
 *
 * So it is a second signal, shown beside the status badge rather than instead of
 * it — which is also the answer to the complaint this was written for: a job
 * that had been invoiced looked, on the list, exactly like one that had not.
 *
 * ## Why it is derived and never stored
 *
 * The same rule the job's total follows, and a party's outstanding, and a
 * variant's quantity on hand: a stored flag agrees with the documents right up
 * until one of them is written without the other. This is read off the invoices
 * that point at the job and the parts that point at their invoice lines, so
 * reversing a bill moves it with nothing having to remember.
 */
enum JobBillingState: string
{
    /** Nothing standing against it. Every part on the card is still to bill. */
    case Unbilled = 'unbilled';

    /**
     * Invoiced, with work still on the card.
     *
     * The ordinary case for a long repair billed in two halves — an advance
     * against the estimate, the balance on collection. It is also what a bill
     * whose lines the operator rewrote leaves behind: the pairing between a part
     * and its invoice line no longer holds, so nothing is marked and the parts
     * stay visible. Both want the same thing said — there is more here to bill.
     */
    case PartBilled = 'part_billed';

    /** Everything on the card has reached an invoice. */
    case Billed = 'billed';

    public function label(): string
    {
        return match ($this) {
            self::Unbilled => 'Not billed',
            self::PartBilled => 'Part billed',
            self::Billed => 'Invoiced',
        };
    }

    /**
     * The badge colour family, decided here rather than in each screen that
     * renders one — the brief's §38, and the same four words
     * {@see WorkshopJobStatus::tone()} uses.
     *
     * `unbilled` is deliberately neutral rather than absent-of-tone: it is the
     * ordinary state of a motor still on the bench, not something wrong. And
     * `part_billed` is amber for the sense that word carries everywhere else
     * here — somebody has to come back to it.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Unbilled => 'neutral',
            self::PartBilled => 'warning',
            self::Billed => 'success',
        };
    }
}
