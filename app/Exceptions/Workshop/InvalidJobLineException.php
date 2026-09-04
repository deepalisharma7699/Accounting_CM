<?php

namespace App\Exceptions\Workshop;

use App\Exceptions\ApiException;

/**
 * A part or an estimate line that does not name anything the workshop can fit —
 * M19.
 *
 * Deliberately its own class rather than reusing the ledger's
 * {@see \App\Exceptions\Accounting\InvalidJournalException}. Nothing here is
 * about a journal: no entries are being composed, nothing is being posted, and a
 * `JOURNAL_*` error code on a job card would send whoever reads the log looking
 * in the wrong module. The refusals are also *earlier* than the ledger's — they
 * land while the fitter is writing the part onto the job, which is hours or days
 * before anything reaches the books, and that is the point of having them here
 * at all.
 *
 * The one that earns its place is {@see needsVariant()}. It is the same refusal
 * the bill engine makes, made a fortnight sooner: a stocked family with no
 * specification cannot be billed, so catching it at the counter beats catching
 * it after the motor has gone out.
 */
class InvalidJobLineException extends ApiException
{
    public static function unknownItem(int $itemId): self
    {
        return new self(
            message: sprintf('There is no item #%d in this workshop.', $itemId),
            status: 422,
            errorCode: 'JOB_LINE_ITEM_UNKNOWN',
            details: ['item_id' => $itemId],
        );
    }

    public static function unknownVariant(int $variantId): self
    {
        return new self(
            message: sprintf('There is no item variant #%d in this workshop.', $variantId),
            status: 422,
            errorCode: 'JOB_LINE_VARIANT_UNKNOWN',
            details: ['variant_id' => $variantId],
        );
    }

    /**
     * A line naming neither. Refused rather than guessed at, because the guess
     * would be a part nobody chose sitting on a customer's invoice.
     */
    public static function itemRequired(): self
    {
        return new self(
            message: 'Each line has to name an item or a specification.',
            status: 422,
            errorCode: 'JOB_LINE_ITEM_REQUIRED',
        );
    }

    /**
     * A stocked family named without saying which one.
     *
     * Stock is counted per variant, so "a bearing" leaves the position of every
     * size unknowable — which is the whole reason the catalogue has variants.
     */
    public static function needsVariant(string $itemName): self
    {
        return new self(
            message: sprintf(
                '%s is stocked, so the job needs the exact one — stock is counted per specification.',
                $itemName,
            ),
            status: 422,
            errorCode: 'JOB_LINE_NEEDS_VARIANT',
            details: ['item' => $itemName],
        );
    }

    /**
     * Half a bearing.
     *
     * Whether a fraction means anything is the unit's business — 2.5 kg of
     * copper is ordinary, 2.5 bearings is a typo — so this is checked where the
     * unit is known, exactly as the stock ledger checks it.
     */
    public static function fractionalUnit(string $itemName, string $quantity, string $unitLabel): self
    {
        return new self(
            message: sprintf(
                '%s is counted in whole %s, so %s is not a quantity of one.',
                $itemName,
                strtolower($unitLabel),
                $quantity,
            ),
            status: 422,
            errorCode: 'JOB_LINE_FRACTIONAL_UNIT',
            details: ['item' => $itemName, 'quantity' => $quantity, 'unit' => $unitLabel],
        );
    }

    /**
     * Approving a quotation that was never written.
     */
    public static function noEstimate(string $jobNo): self
    {
        return new self(
            message: sprintf('There is no estimate on %s to approve. Price the work first.', $jobNo),
            status: 422,
            errorCode: 'JOB_ESTIMATE_MISSING',
            details: ['job_no' => $jobNo],
        );
    }
}
