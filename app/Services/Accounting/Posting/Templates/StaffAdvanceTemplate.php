<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;

/**
 * Template I — money handed to an employee against a salary not yet earned.
 *
 * ```
 * Dr Staff Advance (1500)       the whole amount
 *   Cr Cash in Hand (1010)      per payment mode
 *   Cr Bank Account (1020)
 *   Cr UPI / Wallet (1030)
 * ```
 *
 * ## Why this is an asset and not an expense
 *
 * Because the workshop is owed the money back. It comes back by being deducted
 * from the next payroll rather than by anybody paying it in, but that is a
 * detail of *how* it is recovered, not of whether it is owed. Booking it
 * straight to Salary Expense would report the cost twice — once in the month the
 * advance went out and again in the month the salary was run — and would leave
 * the workshop with no figure at all for what is currently out with its staff.
 *
 * It is the same shape as a vendor payment, and shares the same base class for
 * the same reason that one does: one control-account line for the whole amount,
 * one settlement line per way the money actually moved.
 *
 * ## Why the control side is a debit
 *
 * A payment debits Payables because settling a liability reduces it. This debits
 * Staff Advance because handing over the money *creates* an asset: the till goes
 * down and a claim on a future salary goes up. Both sides of a payment fall;
 * here one falls and one rises. That is the difference between paying a debt and
 * making a loan, and it is the whole reason this is not template D.
 *
 * ## What is not here
 *
 * **No party, and no GST.** The counterparty is an employee, who is a row in
 * `employees` rather than in `parties` — see `transactions.employee_id` — and
 * nothing about an advance is a taxable supply. Neither is reachable from this
 * class, which is what makes both structural rather than remembered.
 */
class StaffAdvanceTemplate extends SettlementTemplate
{
    public function type(): TransactionType
    {
        return TransactionType::StaffAdvance;
    }

    protected function controlAccount(): SystemAccount
    {
        return SystemAccount::StaffAdvance;
    }

    protected function controlSide(): BalanceSide
    {
        return BalanceSide::Debit;
    }
}
