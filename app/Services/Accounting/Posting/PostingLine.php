<?php

namespace App\Services\Accounting\Posting;

use App\Enums\BalanceSide;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Support\Money;

/**
 * One intended ledger line, before it is written.
 *
 * Held as a side plus an amount rather than as a debit column and a credit
 * column, so a line that is somehow both — or neither — cannot be constructed
 * at all. Turning a client's `{debit, credit}` pair into one of these is the
 * only place that ambiguity is possible, and {@see fromInput()} refuses it
 * there.
 *
 * Immutable, and carries no id: a PostingLine is a description of an entry, not
 * an entry.
 */
final class PostingLine
{
    private function __construct(
        public readonly int $accountId,
        public readonly BalanceSide $side,
        public readonly Money $amount,
        public readonly ?string $memo = null,
    ) {}

    public static function debit(int $accountId, Money $amount, ?string $memo = null): self
    {
        return new self($accountId, BalanceSide::Debit, $amount, $memo);
    }

    public static function credit(int $accountId, Money $amount, ?string $memo = null): self
    {
        return new self($accountId, BalanceSide::Credit, $amount, $memo);
    }

    public static function on(BalanceSide $side, int $accountId, Money $amount, ?string $memo = null): self
    {
        return new self($accountId, $side, $amount, $memo);
    }

    /**
     * Build a line from a client payload or a stored draft.
     *
     * @param  array<string, mixed>  $line
     * @param  int  $lineNumber  1-based, and only used to say which line is wrong.
     *
     * @throws InvalidJournalException
     */
    public static function fromInput(array $line, int $lineNumber): self
    {
        $debit = Money::of($line['debit'] ?? 0);
        $credit = Money::of($line['credit'] ?? 0);

        // Exactly one column carries the amount. Both, or neither, is not a
        // line anyone can act on — and silently picking one would post a guess.
        if ($debit->isZero() === $credit->isZero()) {
            throw InvalidJournalException::ambiguousSide($lineNumber);
        }

        $side = $debit->isZero() ? BalanceSide::Credit : BalanceSide::Debit;
        $amount = $debit->isZero() ? $credit : $debit;

        $memo = isset($line['memo']) && trim((string) $line['memo']) !== ''
            ? trim((string) $line['memo'])
            : null;

        return new self((int) $line['account_id'], $side, $amount, $memo);
    }

    public function isDebit(): bool
    {
        return $this->side === BalanceSide::Debit;
    }

    /**
     * The amount as it belongs in the `debit` column — zero for a credit line.
     */
    public function debitAmount(): Money
    {
        return $this->isDebit() ? $this->amount : Money::zero();
    }

    public function creditAmount(): Money
    {
        return $this->isDebit() ? Money::zero() : $this->amount;
    }

    /**
     * The same line on the opposite side. A reversing transaction is nothing
     * more than every line of the original, mirrored.
     */
    public function mirrored(): self
    {
        return new self($this->accountId, $this->side->opposite(), $this->amount, $this->memo);
    }

    /**
     * The storable shape, used for a draft's `draft_lines` and accepted back by
     * {@see fromInput()} unchanged. Amounts are decimal strings, never floats.
     *
     * @return array{account_id: int, debit: string, credit: string, memo: string|null}
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'debit' => $this->debitAmount()->amount(),
            'credit' => $this->creditAmount()->amount(),
            'memo' => $this->memo,
        ];
    }
}
