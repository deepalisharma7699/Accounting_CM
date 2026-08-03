<?php

namespace App\Enums;

use App\Services\Accounting\Posting\PaymentSplit;

/**
 * How money actually moved: notes over the counter, a bank transfer, a UPI
 * collection, a cheque.
 *
 * Each mode names the asset account the money moves through, which is the whole
 * reason this enum exists. A payment is not "money left the business" in the
 * abstract — it left a *particular* account, and the workshop reconciles that
 * account against a passbook or a cash box. A single "Cash/Bank" account would
 * make every one of those reconciliations impossible.
 *
 * One transaction may split across several modes: ₹2,000 from the till and
 * ₹3,000 by UPI is one receipt with two settlement lines. See
 * {@see PaymentSplit}.
 *
 * @see PartyRole for the other half of a settlement — which control account the
 *      counterparty's side of it lands in.
 */
enum PaymentMode: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Upi = 'upi';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank transfer',
            self::Upi => 'UPI / Wallet',
            self::Cheque => 'Cheque',
        };
    }

    /**
     * The asset account this mode settles through.
     *
     * **A cheque settles into the bank account, not into an account of its
     * own.** That is a deliberate choice and the only mode where the mapping is
     * not one-to-one. A "Cheques in Hand" account is only correct alongside a
     * clearing workflow — deposit, clear, bounce — and Phase 1 has none. Without
     * one, every cheque ever written would sit in that account for ever, and the
     * bank balance the owner reconciles against their passbook would be
     * permanently short by the total of them. Folding the cheque into Bank makes
     * the bank balance right on the day the cheque is written, which is one day
     * early rather than permanently wrong.
     *
     * The mode is still recorded on the settlement row, so "which cheque was
     * that" remains answerable — see `transaction_payments.reference`.
     */
    public function settlementAccount(): SystemAccount
    {
        return match ($this) {
            self::Cash => SystemAccount::Cash,
            self::Bank, self::Cheque => SystemAccount::Bank,
            self::Upi => SystemAccount::Upi,
        };
    }

    /**
     * Whether a reference is required rather than merely useful.
     *
     * A cheque without its number cannot be chased when it bounces, matched
     * against a bank statement, or stopped. Cash has nothing to reference, and a
     * UPI or NEFT reference is worth capturing but is recoverable from the
     * statement, so neither is forced.
     */
    public function requiresReference(): bool
    {
        return $this === self::Cheque;
    }

    /**
     * What the reference field is called for this mode, so a form asks for the
     * thing the user is holding rather than for "reference".
     */
    public function referenceLabel(): string
    {
        return match ($this) {
            self::Cash => 'Note (optional)',
            self::Bank => 'NEFT / IMPS reference',
            self::Upi => 'UPI reference',
            self::Cheque => 'Cheque number',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
