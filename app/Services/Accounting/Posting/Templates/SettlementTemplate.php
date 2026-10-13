<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Posting\PaymentSplit;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\SettlesThroughPaymentModes;
use App\Support\Money;

/**
 * The shared shape of templates D and E: money moving between a control account
 * and one or more settlement accounts.
 *
 * A payment and a receipt are the same document read in opposite directions —
 * one control-account line for the whole amount, and one settlement line per way
 * the money actually moved. Writing that twice would mean two places for the
 * same rounding, memo and balance decisions to drift apart, so the direction is
 * the only thing the two subclasses declare.
 *
 * ## Why there is no GST here
 *
 * Deliberately, and structurally rather than by omission: neither
 * {@see SystemAccount::GstOutput} nor {@see SystemAccount::GstInput} is reachable
 * from this class. Tax is a property of the *invoice* — it was charged when the
 * bill was raised, and the liability to the department arose then. Recognising it
 * again when the invoice is paid would double-count it, and recognising it *only*
 * when the invoice is paid would make an unpaid invoice's tax invisible. Either
 * way the GST return would be wrong.
 *
 * ## Why the split lines are not merged
 *
 * A cheque and a bank transfer both settle through the Bank account, and this
 * produces two lines on it rather than one. That is correct: they are two
 * movements, and the memo distinguishes them ("Cheque · 402317"). Merging them
 * would make the voucher unable to say what the workshop actually did, and the
 * balance check is indifferent — several lines on one account are still one sum.
 *
 * ## Settling for less — the discount
 *
 * A customer who owes ₹1,250 on an invoice and pays ₹1,200 "to close it" has
 * been let off fifty rupees. The invoice is posted and its GST already declared,
 * so the fifty cannot come off the sale any more; it is written off here, as
 * its own line to {@see discountAccount()}, and the control account is cleared
 * by the whole ₹1,250. The settlement's total is therefore what it cleared off
 * the party — money plus discount — which is what M16 allocates against the
 * invoice and why the invoice then reads as settled.
 *
 * The money lines stay exactly the money: a ₹50 discount is never a payment
 * mode, so the cash book, the collection figures and the bank reconciliation
 * never see it.
 */
abstract class SettlementTemplate implements PostingTemplate, SettlesThroughPaymentModes
{
    public function __construct(
        protected readonly ChartOfAccountService $accounts,
    ) {}

    /**
     * The control account carrying the counterparty's side: Payables for a
     * payment, Receivables for a receipt.
     */
    abstract protected function controlAccount(): SystemAccount;

    /**
     * The side the control account is written on.
     *
     * Debit for a payment — settling a liability reduces it. Credit for a
     * receipt — collecting on a debt reduces the asset.
     */
    abstract protected function controlSide(): BalanceSide;

    /**
     * Discount Allowed on a receipt, Discount Received on a payment — or null
     * for a settlement that is not against anybody's invoice. A staff advance
     * is money handed over, and there is nothing to let anybody off.
     */
    protected function discountAccount(): ?SystemAccount
    {
        return null;
    }

    /**
     * What this settlement lets the party off, or null where it pays in full.
     *
     * Public so the engine can check the split against the total on the same
     * reading the lines were built from.
     *
     * @param  array<string, mixed>  $input
     */
    public function discountFrom(array $input): ?Money
    {
        if ($this->discountAccount() === null) {
            return null;
        }

        $discount = Money::ofNullable($input['discount'] ?? null);

        return $discount !== null && $discount->isPositive() ? $discount : null;
    }

    /**
     * @param  array{payments?: array<int, array<string, mixed>>, notes?: string|null}  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidJournalException
     */
    public function build(array $input): array
    {
        $splits = $this->splitsFrom($input);
        $discount = $this->discountFrom($input);
        // What the party's account is cleared by: the money, and whatever they
        // were let off on top of it.
        $total = PaymentSplit::total($splits)->plus($discount ?? Money::zero());

        // One control-account line for the whole settlement, not one per mode: a
        // party owes a single amount, and splitting their side of it across three
        // rows because the money arrived three ways makes a statement unreadable
        // for no gain.
        $lines = [
            PostingLine::on(
                $this->controlSide(),
                $this->accounts->system($this->controlAccount())->id,
                $total,
                $input['notes'] ?? null,
            ),
        ];

        $settlementSide = $this->controlSide()->opposite();

        foreach ($splits as $split) {
            $lines[] = PostingLine::on(
                $settlementSide,
                $this->accounts->system($split->account())->id,
                $split->amount,
                $split->memo(),
            );
        }

        if ($discount !== null) {
            $lines[] = PostingLine::on(
                $settlementSide,
                $this->accounts->system($this->discountAccount())->id,
                $discount,
                'Settled for less',
            );
        }

        return $lines;
    }

    /**
     * The splits a batch will carry, validated.
     *
     * Public so the engine can record the same objects it posted rather than
     * re-reading the payload and risking a second interpretation of it.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, PaymentSplit>
     *
     * @throws InvalidJournalException
     */
    public function splitsFrom(array $input): array
    {
        $rows = array_values((array) ($input['payments'] ?? []));

        if ($rows === []) {
            throw InvalidJournalException::paymentSplitRequired(strtolower($this->type()->label()));
        }

        $splits = [];

        foreach ($rows as $index => $row) {
            // Already a value object when the engine is re-reading a batch it
            // composed; an array when it arrived from a client or a stored draft.
            $splits[] = $row instanceof PaymentSplit
                ? $row
                : PaymentSplit::fromInput((array) $row, $index + 1);
        }

        return $splits;
    }
}
