<?php

namespace App\Services\Accounting;

use App\Enums\TransactionStatus;
use App\Exceptions\Accounting\InvoiceNotShareableException;
use App\Models\InvoiceShare;
use App\Models\Transaction;
use App\Repositories\Contracts\InvoiceShareRepositoryInterface;

/**
 * Publishing one invoice, and taking it back.
 *
 * The whole of the feature's authority lives here rather than in the controller,
 * because "may this document be published" is a business rule and the public
 * route has to be able to ask the same question the API does.
 *
 * ## Why the link has no expiry
 *
 * A customer keeps an invoice. They show it back at the counter six months
 * later when the motor comes in again, they forward it to whoever pays their
 * bills, and they open it from a message thread long after they were sent it. A
 * link that quietly stopped working would be indistinguishable, to them, from
 * the workshop having deleted the record — and the workshop would hear about it
 * as "your system is broken" rather than as "that link expired".
 *
 * So the lifetime is a decision somebody makes, not a clock: the link works
 * until it is revoked. Revocation is one call, it is immediate, and it is on
 * the record.
 */
class InvoiceShareService
{
    public function __construct(
        private readonly InvoiceShareRepositoryInterface $shares,
    ) {}

    /**
     * The link that currently opens this invoice, or null if it is not shared.
     */
    public function liveFor(Transaction $transaction): ?InvoiceShare
    {
        return $this->shares->liveFor($transaction);
    }

    /**
     * Publish the document, or hand back the link it already has.
     *
     * Idempotent on purpose, and it matters at a counter: somebody who taps
     * Share twice — because the first tap did not look like it did anything —
     * must get the same URL both times. Minting a second would leave the first
     * live and unrevocable from the screen, since the screen only ever shows one.
     *
     * @throws InvoiceNotShareableException
     */
    public function issue(Transaction $transaction, ?int $userId): InvoiceShare
    {
        $this->assertShareable($transaction);

        return $this->shares->liveFor($transaction)
            ?? $this->shares->create($transaction, InvoiceShare::freshToken(), $userId);
    }

    /**
     * End the sharing. Returns how many links were closed — nought when the
     * document was not shared, which is not an error: "make sure this is not
     * public" has been satisfied either way.
     *
     * Every live link, not merely the one the screen knows about. {@see issue()}
     * keeps there being one, but a race between two counter staff could leave
     * two, and revoking "the latest" would then take a link away while leaving
     * the invoice just as public as it was. Revoke means revoke.
     */
    public function revoke(Transaction $transaction, ?int $userId): int
    {
        $live = $this->shares->allLiveFor($transaction);

        foreach ($live as $share) {
            $this->shares->revoke($share, $userId);
        }

        return $live->count();
    }

    /**
     * Resolve a public token to the share behind it, across every workshop.
     *
     * Null for a token that never existed and for one that has been revoked —
     * the caller must not be able to tell those apart, and the public route
     * answers 404 to both. A distinct "this link was revoked" page would confirm
     * that a guessed token was once real.
     */
    public function resolve(string $token): ?InvoiceShare
    {
        return $this->shares->findLiveByToken($token);
    }

    /**
     * Whether the document may be read through a link *right now*.
     *
     * Asked again on every public read, not only when the link was issued, and
     * that is what un-publishes a reversed invoice. A document can be shared on
     * Tuesday and cancelled on Thursday; the share row knows nothing about it,
     * and hooking revocation into the reversal path would put the rule in two
     * places for the one that gets forgotten to be the one that matters.
     *
     * Checking on read means the link simply stops working the moment the
     * document stops standing, without anything having to remember.
     */
    public function isShareable(Transaction $transaction): bool
    {
        return $transaction->type->isCustomerDocument()
            && $transaction->status === TransactionStatus::Posted;
    }

    /**
     * @throws InvoiceNotShareableException
     */
    private function assertShareable(Transaction $transaction): void
    {
        if (! $transaction->type->isCustomerDocument()) {
            throw InvoiceNotShareableException::wrongType(
                (int) $transaction->id,
                $transaction->type->label(),
            );
        }

        // Posted and *only* posted. `isInTheBooks()` would let a reversed
        // document through, and a customer holding a link to a cancelled
        // invoice is the one outcome this rule exists to prevent.
        if ($transaction->status !== TransactionStatus::Posted) {
            throw InvoiceNotShareableException::notPosted(
                (int) $transaction->id,
                $transaction->status->label(),
            );
        }
    }
}
