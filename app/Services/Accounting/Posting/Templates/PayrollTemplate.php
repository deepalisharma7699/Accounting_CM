<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Staff\InvalidPayrollException;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Posting\PaymentSplit;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\SettlesThroughPaymentModes;
use App\Support\Money;

/**
 * Template J — a month's salaries, posted as one voucher.
 *
 * ```
 * Dr Salary Expense (5200)      the month's gross, everybody together
 *   Cr Staff Advance (1500)     whatever was recovered from earlier advances
 *   Cr Cash in Hand (1010)      what actually went out
 *   Cr Bank Account (1020)
 *   Cr UPI / Wallet (1030)
 * ```
 *
 * ## Why one voucher for the whole run
 *
 * Because that is the event. A workshop pays its staff on the 7th, and the
 * ledger should say so once rather than nine times. Who got what is
 * `payroll_lines`, which is the payslip — the ledger holds the money and the run
 * holds the breakdown, and neither is a second copy of the other. A ledger with
 * one entry per employee would also be a ledger that leaks every wage in the
 * building to anybody holding READ:LEDGER, which is a different grant from
 * READ:STAFF and deliberately so.
 *
 * ## Why the recovery is a credit to Staff Advance
 *
 * Because it is the asset being collected, and this is the moment it comes back.
 * ₹5,000 advanced in August is a debit balance sitting against the employee's
 * name; September's payroll expenses the full ₹18,000 they earned and hands over
 * ₹13,000, and the ₹5,000 difference clears the advance rather than reducing the
 * cost of employing them. Netting it off the expense instead would understate
 * what the workshop spends on wages by exactly the amount it lends its staff.
 *
 * ## Why it is settled in full
 *
 * The gross must equal the recovery plus the payment split, and a run that does
 * not balance is refused rather than plugged. There is no salary-payable
 * liability in this product — a workshop that pays on the 7th dates the run the
 * 7th — and that is a deliberate omission rather than a gap: half a payables
 * ledger (accrued but no per-employee settlement, or settlement with no ageing)
 * is worse than none, which is the same judgement the purchase module makes
 * about landed cost. See `docs/staff-module.md`.
 *
 * ## What is not here
 *
 * **No party, no stock and no GST.** Salary is not a taxable supply, nothing
 * leaves a shelf, and the counterparties are employees rather than parties. None
 * of the three accounts involved is reachable from this class, which is what
 * makes all of that structural rather than remembered.
 */
class PayrollTemplate implements PostingTemplate, SettlesThroughPaymentModes
{
    public function __construct(
        protected readonly ChartOfAccountService $accounts,
    ) {}

    public function type(): TransactionType
    {
        return TransactionType::Payroll;
    }

    /**
     * @param  array{gross?: string|float|int|null, advance_recovered?: string|float|int|null, payments?: array<int, array<string, mixed>>, notes?: string|null}  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidJournalException|InvalidPayrollException
     */
    public function build(array $input): array
    {
        $gross = Money::of($input['gross'] ?? 0);

        if (! $gross->isPositive()) {
            throw InvalidPayrollException::nothingToPay();
        }

        $recovered = Money::ofNullable($input['advance_recovered'] ?? null) ?? Money::zero();

        if ($recovered->isNegative()) {
            throw InvalidPayrollException::negativeRecovery();
        }

        // Recovering more than was earned would leave the employee owing the
        // workshop out of a payslip, which is not something a payroll run can
        // do — the rest of the advance stays outstanding and comes off next
        // month. Refused here as well as capped in the service, because a
        // payload can reach the engine without going through a form.
        if ($recovered->compareTo($gross) > 0) {
            throw InvalidPayrollException::recoveryExceedsGross($recovered->amount(), $gross->amount());
        }

        $splits = $this->splitsFrom($input);
        $paid = PaymentSplit::total($splits);

        /*
        | The identity this voucher is: everything earned was either handed over
        | or set against what was already lent.
        |
        | Checked here rather than left to the engine's balance assertion,
        | although that would catch it too. The engine can only say "debits do
        | not equal credits"; this can say by how much and which of the two
        | halves is short, at the moment somebody is looking at the sheet that
        | produced them.
        */
        if (! $recovered->plus($paid)->equals($gross)) {
            throw InvalidPayrollException::doesNotSettle(
                $gross->amount(),
                $recovered->amount(),
                $paid->amount(),
            );
        }

        $lines = [
            PostingLine::debit(
                $this->accounts->system(SystemAccount::SalaryExpense)->id,
                $gross,
                $input['notes'] ?? null,
            ),
        ];

        // Absent rather than zero when nothing was recovered: a ₹0.00 line on
        // Staff Advance in a voucher nobody advanced anything against reads as a
        // mistake somebody has to rule out.
        if (! $recovered->isZero()) {
            $lines[] = PostingLine::credit(
                $this->accounts->system(SystemAccount::StaffAdvance)->id,
                $recovered,
                'Advance recovered',
            );
        }

        // One line per way the money moved, never merged — M6's rule, applied
        // unchanged. Cash from the till and a transfer for the two people with
        // bank accounts is one payroll with two settlement lines.
        foreach ($splits as $split) {
            $lines[] = PostingLine::credit(
                $this->accounts->system($split->account())->id,
                $split->amount,
                $split->memo(),
            );
        }

        return $lines;
    }

    /**
     * How the net was actually paid.
     *
     * **Not required to be non-empty**, unlike every other template that takes a
     * split, and that is deliberate: a month where every rupee earned had
     * already been advanced is a complete payroll that moves no cash at all.
     * What is enforced is the identity in {@see build()} — recovery plus split
     * equals gross — which refuses an empty split when there was something to
     * hand over, with a message that says how much.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, PaymentSplit>
     *
     * @throws InvalidJournalException
     */
    public function splitsFrom(array $input): array
    {
        $rows = array_values((array) ($input['payments'] ?? []));

        $splits = [];

        foreach ($rows as $index => $row) {
            // Already a value object when the engine is re-reading a batch it
            // composed; an array when it arrived from a client.
            $splits[] = $row instanceof PaymentSplit
                ? $row
                : PaymentSplit::fromInput((array) $row, $index + 1);
        }

        return $splits;
    }
}
