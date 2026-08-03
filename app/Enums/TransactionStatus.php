<?php

namespace App\Enums;

/**
 * Where a transaction sits between "being written" and "in the books".
 *
 * The distinction that matters is `draft` versus everything else: **journal
 * entries exist only for a posted transaction**. A draft holds its intended
 * lines on the transaction row itself, so nothing in the ledger, no report and
 * no trial balance has to remember to exclude it. That is a structural
 * guarantee rather than a filter somebody could forget to write.
 */
enum TransactionStatus: string
{
    /**
     * Written but not authorised. Nothing has reached the ledger; the intended
     * lines are held on the transaction and may still be edited or discarded.
     */
    case Draft = 'draft';

    /**
     * In the books. Immutable from this moment: the date, the amounts and the
     * accounts can never change again, because a report run yesterday must
     * still produce yesterday's numbers.
     */
    case Posted = 'posted';

    /**
     * Posted, then cancelled by a reversing transaction. Its entries remain —
     * deleting them would rewrite history — and the reversal's mirrored
     * entries cancel them out, so the trial balance still reconciles.
     */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    /**
     * Does this status mean the transaction's lines are in the ledger?
     */
    public function isInTheBooks(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Only a draft can be edited or discarded. A posted transaction is
     * corrected with a reversing entry instead.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
