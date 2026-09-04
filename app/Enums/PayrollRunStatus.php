<?php

namespace App\Enums;

/**
 * Where a month's payroll stands — M22.
 *
 * **There is no draft.** That is the shape of the module rather than an omission,
 * and it is worth saying why: a parked payroll sheet is a set of figures computed
 * from an attendance register that keeps changing under it. Somebody would open a
 * fortnight-old draft, see ₹52,000, post it, and pay a month that three
 * subsequent absences had already made wrong — with the stale figure looking
 * exactly as authoritative as a fresh one.
 *
 * So a run is computed on demand, checked on screen, and either posted or
 * abandoned. The row in `payroll_runs` is written at the moment it posts, and it
 * is a record of what was paid rather than of what somebody intended to pay. The
 * preview is free and can be re-run all day; only the posting is a fact.
 *
 * A reversal is the correction: it cancels the ledger entries and frees the
 * month, so the run can be computed again against the attendance as it now
 * stands. See {@see \App\Services\Staff\PayrollService::reverse()}.
 */
enum PayrollRunStatus: string
{
    /** Posted to the ledger. Salaries paid, advances recovered. */
    case Posted = 'posted';

    /**
     * Cancelled by a reversal. The entries are still in the books alongside
     * their mirror image — nothing is deleted from a ledger — but the month is
     * free to be run again.
     */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Posted => 'bg-emerald-50 text-emerald-700',
            self::Reversed => 'bg-muted text-secondary-foreground',
        };
    }

    /** Whether this run still counts as the month's payroll. */
    public function isLive(): bool
    {
        return $this === self::Posted;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
