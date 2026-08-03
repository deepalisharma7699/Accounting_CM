<?php

namespace App\Services\Accounting\Posting;

use App\Enums\PaymentMode;
use App\Enums\SystemAccount;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Support\Money;

/**
 * One way money moved as part of a settlement: an amount, a mode, and the
 * reference that identifies it.
 *
 * A payment or receipt carries a list of these — "₹2,000 from the till and
 * ₹3,000 by UPI" is one receipt with two splits. Held as a value object for the
 * same reason {@see PostingLine} is: a split that cannot be constructed
 * malformed cannot be posted malformed, and turning a client's payload into one
 * is the single place the ambiguity exists.
 *
 * Immutable, and carries no id — a PaymentSplit describes a settlement row, it
 * is not one.
 */
final class PaymentSplit
{
    private function __construct(
        public readonly PaymentMode $mode,
        public readonly Money $amount,
        public readonly ?string $reference = null,
    ) {}

    public static function of(PaymentMode $mode, Money $amount, ?string $reference = null): self
    {
        return new self($mode, $amount, $reference);
    }

    /**
     * Build a split from a client payload or a stored draft.
     *
     * @param  array<string, mixed>  $split
     * @param  int  $lineNumber  1-based, and only used to say which row is wrong.
     *
     * @throws InvalidJournalException
     */
    public static function fromInput(array $split, int $lineNumber): self
    {
        $mode = PaymentMode::tryFrom((string) ($split['mode'] ?? ''))
            ?? throw InvalidJournalException::unknownPaymentMode($lineNumber, (string) ($split['mode'] ?? ''));

        $amount = Money::of($split['amount'] ?? 0);

        // Zero settles nothing and a negative amount is the opposite
        // transaction written confusingly — the type already carries the
        // direction, so every stored amount is positive.
        if (! $amount->isPositive()) {
            throw InvalidJournalException::nonPositivePayment($lineNumber);
        }

        $reference = isset($split['reference']) && trim((string) $split['reference']) !== ''
            ? trim((string) $split['reference'])
            : null;

        // A cheque with no number cannot be matched against a bank statement,
        // chased when it bounces, or stopped. Checked here rather than in a form
        // request because the importer and the capture agent reach the engine
        // without passing through one.
        if ($reference === null && $mode->requiresReference()) {
            throw InvalidJournalException::missingPaymentReference($lineNumber, $mode);
        }

        return new self($mode, $amount, $reference);
    }

    /**
     * The account this split moves money through. Resolved from the mode, never
     * stored, so a workshop that renumbers its chart changes nothing here.
     */
    public function account(): SystemAccount
    {
        return $this->mode->settlementAccount();
    }

    /**
     * What the ledger line for this split says, so a voucher reads "Cheque
     * 402317" rather than repeating the amount that is already in the column.
     */
    public function memo(): string
    {
        return $this->reference === null
            ? $this->mode->label()
            : sprintf('%s · %s', $this->mode->label(), $this->reference);
    }

    /**
     * The total of a list of splits — which is, by construction, the amount of
     * the settlement: the template builds one ledger line per split and balances
     * them against a single control-account line of this total.
     *
     * @param  array<int, self>  $splits
     */
    public static function total(array $splits): Money
    {
        return Money::sum(array_map(fn (self $split) => $split->amount, $splits));
    }

    /**
     * The storable shape, used for a draft's `draft_payments` and accepted back
     * by {@see fromInput()} unchanged. Amounts are decimal strings, never floats.
     *
     * @return array{mode: string, amount: string, reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'amount' => $this->amount->amount(),
            'reference' => $this->reference,
        ];
    }
}
