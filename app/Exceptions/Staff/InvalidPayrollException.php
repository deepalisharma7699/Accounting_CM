<?php

namespace App\Exceptions\Staff;

use App\Exceptions\ApiException;

/**
 * A payroll run that is not a payroll run — M22.
 *
 * The staff module's counterpart to {@see \App\Exceptions\Accounting\InvalidJournalException}:
 * each named constructor carries its own code so a client can explain the
 * specific problem rather than repeating "invalid payroll", and each message
 * says what to do rather than only what is wrong.
 */
class InvalidPayrollException extends ApiException
{
    /**
     * Nobody earned anything, so there is nothing to post.
     *
     * The commonest cause by a long way is a month where nobody's attendance was
     * marked and everybody is on a daily wage — an unmarked day is not paid on
     * that basis, so the whole sheet computes to zero. The message says so,
     * because "nothing to pay" on a month people plainly worked is otherwise a
     * baffling answer.
     */
    public static function nothingToPay(): self
    {
        return new self(
            message: 'This month adds up to nothing, so there is no payroll to post. Check the attendance '.
                'sheet — staff on a daily wage are paid only for the days that are actually marked.',
            status: 422,
            errorCode: 'PAYROLL_NOTHING_TO_PAY',
        );
    }

    public static function negativeRecovery(): self
    {
        return new self(
            message: 'An advance recovery cannot be negative. To give somebody more money, pay a new advance.',
            status: 422,
            errorCode: 'PAYROLL_RECOVERY_INVALID',
            details: ['field' => 'advance_recovered'],
        );
    }

    /**
     * Recovering more than was earned would end the payslip with the employee
     * owing the workshop money, which a payroll run cannot express. The rest of
     * the advance simply stays outstanding.
     */
    public static function recoveryExceedsGross(string $recovered, string $gross): self
    {
        return new self(
            message: sprintf(
                'You cannot recover %s from a month worth %s — a payslip cannot end with the employee owing '.
                'money. Recover what this month covers; the rest stays outstanding and comes off next time.',
                $recovered,
                $gross,
            ),
            status: 422,
            errorCode: 'PAYROLL_RECOVERY_EXCEEDS_GROSS',
            details: ['field' => 'advance_recovered', 'recovered' => $recovered, 'gross' => $gross],
        );
    }

    /**
     * Recovery plus what was handed over does not equal what was earned.
     *
     * The engine's balance assertion would catch this too, but it can only say
     * that debits do not equal credits. This says by how much and which half is
     * short, at the moment somebody is looking at the sheet that produced it.
     */
    public static function doesNotSettle(string $gross, string $recovered, string $paid): self
    {
        return new self(
            message: sprintf(
                'This run does not settle: %s was earned, %s recovered against advances and %s paid out. '.
                'The payment has to cover the difference exactly.',
                $gross,
                $recovered,
                $paid,
            ),
            status: 422,
            errorCode: 'PAYROLL_DOES_NOT_SETTLE',
            details: ['gross' => $gross, 'advance_recovered' => $recovered, 'paid' => $paid],
        );
    }

    /**
     * A month that already has a live run.
     *
     * Refused rather than allowed a second time, because the second run pays
     * everybody twice and nothing about the first would look wrong afterwards.
     * The way forward is named: reverse the one that is in the way.
     */
    public static function monthAlreadyRun(int $runId, string $period, ?string $docNo): self
    {
        return new self(
            message: sprintf(
                '%s has already been paid, on %s. Running it again would pay everybody twice — reverse that '.
                'run first if it was wrong, and this month is free again.',
                $period,
                $docNo ?? "voucher #{$runId}",
            ),
            status: 409,
            errorCode: 'PAYROLL_MONTH_ALREADY_RUN',
            details: ['run_id' => $runId, 'period' => $period, 'doc_no' => $docNo],
        );
    }

    /**
     * A run that is already reversed. Reversing it again would write a second
     * mirror of entries that have already been cancelled.
     */
    public static function alreadyReversed(int $runId): self
    {
        return new self(
            message: 'That payroll run has already been reversed. The month is free to be run again.',
            status: 409,
            errorCode: 'PAYROLL_ALREADY_REVERSED',
            details: ['run_id' => $runId],
        );
    }

    /**
     * A month somebody is trying to run before it has happened.
     *
     * Not pedantry: a run posted for next month computes every daily-wage
     * employee at zero and every monthly one at a full salary for days nobody
     * has worked yet, and it takes the month out of circulation so the real run
     * is refused when it comes round.
     */
    public static function monthNotStarted(string $period): self
    {
        return new self(
            message: sprintf(
                '%s has not started yet. Payroll is run for a month that has been worked, not one that is '.
                'still ahead.',
                $period,
            ),
            status: 422,
            errorCode: 'PAYROLL_MONTH_NOT_STARTED',
            details: ['field' => 'period', 'period' => $period],
        );
    }
}
