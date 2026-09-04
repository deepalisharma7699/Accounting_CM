<?php

namespace App\Enums;

/**
 * Where a bill stands against what has been paid on it — M16.
 *
 * Derived on every read, never stored. The inputs are the document's total, its
 * own at-counter payments and the allocations pointed at it; see
 * {@see \App\Services\Accounting\BillService::settlementFor()}. A column holding
 * this would be a column that could disagree with the receipts behind it, which
 * is the failure every derived figure in this application exists to avoid.
 *
 * ## Why overdue is one of these rather than a flag beside them
 *
 * Because that is how it is read. A bills list has one Status column, and the
 * question it answers is "what should I do about this one" — for which "partial,
 * and forty days old" and "partial, entered yesterday" are different answers.
 * Overdue therefore *replaces* unpaid and partial once the workshop's terms have
 * run out, rather than sitting alongside them. The amounts are still on the row,
 * so nothing is lost by the substitution.
 */
enum PaymentStatus: string
{
    /** Nothing has been settled against it. */
    case Unpaid = 'unpaid';

    /** Some has, and some is left. */
    case Partial = 'partial';

    /** Settled in full. */
    case Paid = 'paid';

    /**
     * Something is still due and the workshop's payment terms have run out.
     *
     * Only reachable where the tenant has set `payment_due_days`. A workshop
     * that has not said what its terms are never sees this, which is correct —
     * an ageing computed against terms nobody agreed is a number that would only
     * mislead.
     */
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partial',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
        };
    }

    /**
     * True when money is still owed on the document — which is the filter a
     * "show me what is outstanding" toggle actually means, and the reason it
     * cannot simply be `status != paid`: that would be true of a bill nobody
     * needs to chase.
     */
    public function isOutstanding(): bool
    {
        return $this !== self::Paid;
    }

    /**
     * The colour family a badge should take, published so the API and every
     * screen agree on which statuses are alarming — §38's one status-badge
     * helper, decided here rather than in each front-end file.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Partial => 'warning',
            self::Overdue => 'danger',
            self::Unpaid => 'neutral',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
