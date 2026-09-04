<?php

namespace App\Exceptions\Workshop;

use App\Enums\WorkshopJobStatus;
use App\Exceptions\ApiException;

/**
 * A job asked to do something its state does not allow — M19.
 *
 * The refusals here are all one refusal wearing different clothes: the status
 * pipeline means something, and a screen that let a delivered motor go back to
 * `received` would make every figure derived from it — how long repairs take,
 * how many are outstanding — quietly untrue.
 *
 * Each message names the state the job is actually in, because the commonest
 * cause of hitting one of these is two people working the same job card and one
 * of them looking at a screen loaded ten minutes ago.
 */
class InvalidJobStateException extends ApiException
{
    /**
     * A move the pipeline does not permit — `delivered` back to `in_progress`,
     * `received` straight to `ready`, anything at all out of `cancelled`.
     */
    public static function illegalTransition(
        string $jobNo,
        WorkshopJobStatus $from,
        WorkshopJobStatus $to,
    ): self {
        $allowed = $from->nextStates();

        return new self(
            message: $allowed === []
                ? sprintf(
                    '%s is %s, which is where a job finishes — it cannot be moved to %s.',
                    $jobNo,
                    strtolower($from->label()),
                    strtolower($to->label()),
                )
                : sprintf(
                    '%s is %s, so it can only move to %s — not to %s.',
                    $jobNo,
                    strtolower($from->label()),
                    collect($allowed)->map(fn (WorkshopJobStatus $next) => strtolower($next->label()))
                        ->join(', ', ' or '),
                    strtolower($to->label()),
                ),
            status: 422,
            errorCode: 'JOB_TRANSITION_INVALID',
            details: [
                'job_no' => $jobNo,
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(fn (WorkshopJobStatus $next) => $next->value, $allowed),
            ],
        );
    }

    /**
     * Billing a job that has had nothing done to it, or one that was abandoned.
     *
     * The cancelled half is the brief's scenario 10, and it is the one that
     * matters: a job nobody authorised must never be able to produce an invoice,
     * whatever parts were optimistically listed on it while the estimate was
     * still being argued about.
     */
    public static function notBillable(string $jobNo, WorkshopJobStatus $status): self
    {
        return new self(
            message: $status === WorkshopJobStatus::Cancelled
                ? sprintf('%s was cancelled, so there is nothing on it to bill.', $jobNo)
                : sprintf(
                    '%s is %s — no work has been done on it yet, so there is nothing to bill.',
                    $jobNo,
                    strtolower($status->label()),
                ),
            status: 422,
            errorCode: 'JOB_NOT_BILLABLE',
            details: ['job_no' => $jobNo, 'status' => $status->value],
        );
    }

    /**
     * A bill asked for on a job whose parts have all been invoiced already.
     *
     * Refused rather than posted as an empty invoice, for the reason an empty
     * credit note is: a document number issued out of the series for no reason
     * is a gap somebody has to explain later.
     */
    public static function nothingToBill(string $jobNo): self
    {
        return new self(
            message: sprintf(
                'Everything on %s has already been billed. Add what else was fitted, or open the '
                .'invoice that covers it.',
                $jobNo,
            ),
            status: 422,
            errorCode: 'JOB_NOTHING_TO_BILL',
            details: ['job_no' => $jobNo],
        );
    }

    /**
     * Editing the parts or the estimate on a job that is finished.
     *
     * A delivered motor is with the customer and a cancelled one was never
     * worked on; in both cases the job card is a record of what happened, and a
     * record that could still be edited is not one.
     */
    public static function finished(string $jobNo, WorkshopJobStatus $status): self
    {
        return new self(
            message: sprintf(
                '%s is %s. Its job card is a record of what happened now, so it cannot be changed.',
                $jobNo,
                strtolower($status->label()),
            ),
            status: 422,
            errorCode: 'JOB_FINISHED',
            details: ['job_no' => $jobNo, 'status' => $status->value],
        );
    }

    /**
     * Deleting a job that has been billed.
     *
     * The invoice records where it came from, and an invoice pointing at nothing
     * is an invoice nobody can explain. A job that turned out to be a mistake is
     * cancelled, which leaves it visible — the same choice the ledger makes
     * everywhere else.
     */
    public static function billed(string $jobNo, int $bills): self
    {
        return new self(
            message: sprintf(
                '%s has %d %s raised against it and cannot be deleted. Cancel it instead — the '
                .'invoice has to keep the job that explains it.',
                $jobNo,
                $bills,
                $bills === 1 ? 'bill' : 'bills',
            ),
            status: 409,
            errorCode: 'JOB_IN_USE',
            details: ['job_no' => $jobNo, 'bills' => $bills],
        );
    }

    /**
     * A part that is not on this job, or one that has already been invoiced.
     */
    public static function partNotRemovable(string $jobNo, int $partId): self
    {
        return new self(
            message: sprintf(
                'That part has already been billed on %s, so it cannot be taken off the job. '
                .'Credit it on the invoice instead.',
                $jobNo,
            ),
            status: 422,
            errorCode: 'JOB_PART_BILLED',
            details: ['job_no' => $jobNo, 'part_id' => $partId],
        );
    }
}
