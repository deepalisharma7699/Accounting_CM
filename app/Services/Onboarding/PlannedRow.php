<?php

namespace App\Services\Onboarding;

use App\Enums\BalanceSide;
use App\Support\Money;
use App\Support\Quantity;

/**
 * One declaration, after the resolver has worked out what it refers to.
 *
 * The unit the preview screen renders and the unit the importer posts, and
 * deliberately the same object for both: a preview that was assembled by
 * different code from the commit is a preview that can be right about something
 * the commit gets wrong, which is the one thing a go-live screen must not do.
 *
 * Three outcomes, and each of them is a decision rather than a failure mode:
 *
 *   * **ready** — resolved, and it will post.
 *   * **skipped** — the thing it declares already has an opening balance. Not an
 *     error: it is what a second run of the same file looks like, and it is why
 *     re-importing cannot double anything.
 *   * **error** — nobody can act on it as it stands. One of these refuses the
 *     whole import, because a half-imported opening balance is harder to unpick
 *     than none at all.
 */
final class PlannedRow
{
    /**
     * The things a row can bring into existence, named rather than counted as
     * booleans so the preview can say "3 new items, 4 new variants, 1 new
     * customer" from one loop.
     */
    public const CREATES_ITEM = 'item';

    public const CREATES_VARIANT = 'variant';

    public const CREATES_PARTY = 'party';

    /**
     * @param  int  $lineNo  1-based, and the line of the *file* rather than of
     *                       the plan — so "row 14" means row 14 of the
     *                       spreadsheet the user is looking at.
     */
    private function __construct(
        public readonly int $lineNo,
        public readonly OpeningRow $row,
        public readonly RowOutcome $outcome,
        public readonly ?string $reason = null,
        /** What it resolved to, in words: "Ball Bearing · 6204", "Sharma Motors". */
        public readonly ?string $resolved = null,
        public readonly ?int $itemId = null,
        public readonly ?int $variantId = null,
        public readonly ?int $partyId = null,
        public readonly ?int $accountId = null,
        public readonly ?Quantity $quantity = null,
        public readonly ?Money $unitCost = null,
        /**
         * What the row is worth, on whichever side its kind puts it.
         *
         * Nullable rather than defaulting to zero, because a row that could not
         * be resolved has no amount at all — and a zero here would total into
         * the preview's figures as though the row had been read and found to be
         * worth nothing. See {@see amount()}.
         */
        private readonly ?Money $amount = null,
        /**
         * The side the named account opens on, where the row chose one rather
         * than taking the account's normal side. Null everywhere else.
         */
        public readonly ?BalanceSide $side = null,
        /**
         * Records this row will bring into existence.
         *
         * Surfaced rather than done quietly: an item invented from a
         * spreadsheet cell has a name and nothing else, and the owner has to
         * know how many of those the file is about to add before they agree
         * to it.
         *
         * @var array<int, string>
         */
        public readonly array $creates = [],
        /** How close the match was, where it was fuzzy — nothing to show at 100. */
        public readonly ?int $confidence = null,
    ) {}

    /**
     * @param  array<int, string>  $creates
     */
    public static function ready(
        int $lineNo,
        OpeningRow $row,
        string $resolved,
        Money $amount,
        ?int $itemId = null,
        ?int $variantId = null,
        ?int $partyId = null,
        ?int $accountId = null,
        ?Quantity $quantity = null,
        ?Money $unitCost = null,
        ?BalanceSide $side = null,
        array $creates = [],
        ?int $confidence = null,
    ): self {
        return new self(
            lineNo: $lineNo,
            row: $row,
            outcome: RowOutcome::Ready,
            resolved: $resolved,
            itemId: $itemId,
            variantId: $variantId,
            partyId: $partyId,
            accountId: $accountId,
            quantity: $quantity,
            unitCost: $unitCost,
            amount: $amount,
            side: $side,
            creates: $creates,
            confidence: $confidence,
        );
    }

    public static function skipped(int $lineNo, OpeningRow $row, string $resolved, string $reason): self
    {
        return new self(
            lineNo: $lineNo,
            row: $row,
            outcome: RowOutcome::Skipped,
            reason: $reason,
            resolved: $resolved,
        );
    }

    public static function error(int $lineNo, OpeningRow $row, string $reason): self
    {
        return new self(
            lineNo: $lineNo,
            row: $row,
            outcome: RowOutcome::Error,
            reason: $reason,
        );
    }

    public function isReady(): bool
    {
        return $this->outcome === RowOutcome::Ready;
    }

    public function isError(): bool
    {
        return $this->outcome === RowOutcome::Error;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === RowOutcome::Skipped;
    }

    /**
     * What this row declares. Zero for anything that is not going to post, so a
     * caller totalling a plan does not have to branch on the outcome.
     */
    public function amount(): Money
    {
        return $this->amount ?? Money::zero();
    }

    /**
     * Which way this row moves the workshop's own account — an asset declared,
     * or a liability.
     *
     * The kind decides it for three of the four; a `balance` row carries the
     * answer itself, because a bank account overdrawn at go-live is a real thing
     * and refusing to record it would be refusing a true fact.
     */
    public function opensOnDebit(): bool
    {
        return ($this->side ?? $this->row->kind->side() ?? BalanceSide::Debit) === BalanceSide::Debit;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_no' => $this->lineNo,
            'kind' => $this->row->kind->value,
            'kind_label' => $this->row->kind->label(),
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,

            // What the file said, echoed back so the preview reads beside the
            // spreadsheet rather than instead of it.
            'name' => $this->row->name ?? $this->row->account,
            'variant' => $this->row->variant,

            'resolved' => $this->resolved,
            'confidence' => $this->confidence,
            'creates' => $this->creates,

            'quantity' => $this->quantity?->trimmed(),
            'unit_cost' => $this->unitCost?->amount(),
            'amount' => $this->amount?->amount(),
            'side' => $this->isReady() ? ($this->opensOnDebit() ? 'debit' : 'credit') : null,

            'party_id' => $this->partyId,
            'variant_id' => $this->variantId,
            'account_id' => $this->accountId,
        ];
    }
}
