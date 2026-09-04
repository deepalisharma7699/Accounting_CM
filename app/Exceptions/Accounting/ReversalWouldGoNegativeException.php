<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * Reversing a purchase would take more off the shelf than is still on it.
 *
 * The gap {@see \App\Services\Accounting\PostingEngine::assertStockAvailable()}
 * deliberately leaves open. A reversal is exempt from the negative-stock refusal
 * for a good reason — a known error must never become permanent on the grounds
 * that the shelf has moved since — but "exempt from the refusal" was being read
 * as "exempt from being told", and a purchase of ten reversed after seven had
 * left by another route posted a position of minus seven with nothing said.
 *
 * So the exemption stays and the silence goes. The refusal is *recoverable*:
 * whoever is reversing can say they understand the position will go negative and
 * the same request goes through. That is the shape M17 already chose for
 * {@see InsufficientStockException} — a rule with a way through, named in the
 * message, so nobody works around it by not recording the correction.
 *
 * Purchase documents only. A sale reversal puts stock *back*, an adjustment is
 * the tool for repairing a negative position, and neither can produce this.
 */
class ReversalWouldGoNegativeException extends ApiException
{
    /**
     * @param  array<int, array{variant: string, available: string, unit: string, requested: string, shortfall: string}>  $shortfalls
     */
    public static function forDocument(string $document, array $shortfalls): self
    {
        $first = $shortfalls[0];

        // The commonest case by a wide margin is one item, and it gets a sentence
        // that says the number somebody has to act on. The rest are counted
        // rather than listed: a toast reciting nine variants is a toast nobody
        // reads, and `details` carries the full list for the screen that can.
        $summary = count($shortfalls) === 1
            ? sprintf(
                '%s of %s has already left the shelf, and only %s %s is left.',
                $first['shortfall'],
                $first['variant'],
                $first['available'],
                $first['unit'],
            )
            : sprintf(
                '%s of the items on it have already left the shelf — %s is the shortest, with %s %s left against %s to take back.',
                count($shortfalls),
                $first['variant'],
                $first['available'],
                $first['unit'],
                $first['requested'],
            );

        return self::of(
            sprintf(
                "Can't reverse %s — %s Send back only what is still here with a debit note, ".
                'or confirm that you want the stock to go negative.',
                $document,
                $summary,
            ),
            $document,
            $shortfalls,
        );
    }

    /**
     * The same refusal, for a correction rather than a cancellation.
     *
     * A separate sentence rather than a shared one, because the act being
     * refused is different and so is the way out of it: nothing has been
     * reversed — the whole revision rolled back — and the fix is to correct the
     * bill to a quantity the shelf can still account for.
     *
     * @param  array<int, array{variant: string, available: string, unit: string, requested: string, shortfall: string}>  $shortfalls
     */
    public static function forRevision(string $document, array $shortfalls): self
    {
        $first = $shortfalls[0];

        return self::of(
            sprintf(
                'That correction to %s would leave %s at %s %s. %s has already gone out against the '.
                'original bill, so the corrected quantity cannot be lower than what was issued. Nothing '.
                'has been changed — raise a debit note for the difference instead, or confirm that you '.
                'want the stock to go negative.',
                $document,
                $first['variant'],
                $first['available'],
                $first['unit'],
                count($shortfalls) === 1 ? 'More' : sprintf('More of %s items', count($shortfalls)),
            ),
            $document,
            $shortfalls,
        );
    }

    /**
     * @param  array<int, array{variant: string, available: string, unit: string, requested: string, shortfall: string}>  $shortfalls
     */
    private static function of(string $message, string $document, array $shortfalls): self
    {
        return new self(
            message: $message,
            status: 422,
            errorCode: 'REVERSAL_WOULD_GO_NEGATIVE',
            details: [
                'document' => $document,
                'shortfalls' => $shortfalls,
                // The name of the flag that gets past this, so the client does
                // not have to hard-code a string it cannot see the source of.
                'acknowledge_with' => 'acknowledge_negative_stock',
            ],
        );
    }
}
