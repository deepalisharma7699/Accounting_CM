<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;

/**
 * Template D — money paid out to a supplier.
 *
 * ```
 * Dr Sundry Creditors (2100)    the whole amount
 *   Cr Cash in Hand (1010)      per payment mode
 *   Cr Bank Account (1020)
 *   Cr UPI / Wallet (1030)
 * ```
 *
 * Debiting Payables reduces a liability: the workshop owed ₹5,000 and now owes
 * nothing. Crediting the settlement account reduces an asset: the money has
 * left. Both sides fall, which is what settling a debt is.
 *
 * Because the debit lands in the payables control account and the transaction
 * carries a `party_id`, the vendor's `payable` drops by exactly this amount with
 * no reporting code of any kind — the party position is a sum over these same
 * rows. See parties-module.md.
 *
 * **Paying more than is owed leaves the vendor with a debit balance** rather than
 * being refused, following the same reasoning M5 settled for receipts: the money
 * has left the bank and the supplier is holding it, so the books must say so. An
 * advance to a supplier is a real thing, and refusing to record one would only
 * mean it went unrecorded.
 */
class VendorPaymentTemplate extends SettlementTemplate
{
    public function type(): TransactionType
    {
        return TransactionType::Payment;
    }

    protected function controlAccount(): SystemAccount
    {
        return SystemAccount::Payables;
    }

    protected function controlSide(): BalanceSide
    {
        return BalanceSide::Debit;
    }
}
