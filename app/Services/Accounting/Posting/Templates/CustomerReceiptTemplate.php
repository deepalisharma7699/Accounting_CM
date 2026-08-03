<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;

/**
 * Template E — money collected from a customer.
 *
 * ```
 * Dr Cash in Hand (1010)        per payment mode
 * Dr Bank Account (1020)
 * Dr UPI / Wallet (1030)
 *   Cr Sundry Debtors (1400)    the whole amount
 * ```
 *
 * The mirror of template D. Debiting the settlement account raises an asset — the
 * money has arrived — and crediting Receivables reduces another, because the
 * customer's debt is discharged. One asset becomes another, which is why a
 * receipt changes the workshop's net worth by nothing at all: the profit was
 * recognised when the invoice was raised.
 *
 * **Overpayment leaves a credit balance** rather than being refused. A customer
 * who pays ₹6,000 against a ₹5,000 invoice shows a receivable of −₹1,000: the
 * money is in the bank and it is theirs, so the books have to say so. Pushing it
 * onto the payable side instead would claim a supplier relationship that does not
 * exist — the decision M5 already took, applied here.
 */
class CustomerReceiptTemplate extends SettlementTemplate
{
    public function type(): TransactionType
    {
        return TransactionType::Receipt;
    }

    protected function controlAccount(): SystemAccount
    {
        return SystemAccount::Receivables;
    }

    protected function controlSide(): BalanceSide
    {
        return BalanceSide::Credit;
    }
}
