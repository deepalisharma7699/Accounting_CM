<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Models\ChartOfAccount;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Posting\PaymentSplit;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\SettlesThroughPaymentModes;
use App\Services\Accounting\Posting\StatesItsOwnTotal;
use App\Support\Money;

/**
 * Template F — a running cost: rent, electricity, a courier, the tea.
 *
 * ```
 * Dr <an expense account>   the amount before tax
 * Dr GST Input             where the tax is claimable
 * Cr Cash / Bank / UPI     however it was paid
 * ```
 *
 * The simplest thing in the product that is still a real document, and
 * deliberately the last posting template built: by the time it lands the engine
 * has already been proved by a settlement, a stock adjustment and a bill, so
 * there is nothing left for it to discover.
 *
 * ## Why the account is chosen and not derived
 *
 * Every other template knows which accounts it moves — a sale credits Sales
 * because that is what a sale *is*. An expense does not: rent and electricity and
 * a courier are the same shape of event against different accounts, and which
 * one is the whole of the information. M3 already lets a workshop add "Rent" and
 * "Electricity" to their chart; this is what makes those accounts usable.
 *
 * It must be an **expense** account, and that is checked rather than trusted. An
 * expense posted to Sundry Debtors would read as a customer owing money, and the
 * mistake would only surface when somebody tried to collect it.
 *
 * ## Why the GST is an amount and not a rate
 *
 * Because that is what is printed on the receipt in the person's hand. A rate
 * would have to be applied to a base they would then have to work backwards to,
 * and the two would disagree by a paisa on half the bills in the drawer.
 *
 * Optional, and its absence is meaningful rather than a default: a tea bill has
 * no claimable tax, and a workshop below the registration threshold has none on
 * anything. Where it is absent the whole amount is the expense — which is the
 * correct treatment, because unclaimable tax genuinely is part of what the thing
 * cost.
 *
 * ## Why an expense has no stock and no bill lines
 *
 * Anything bought *to sell or to fit* is a purchase — template C — and it either
 * becomes inventory or becomes cost of goods. This is the other kind of money: it
 * costs the workshop to be open. Keeping the two apart is what lets a P&L
 * separate gross margin from overheads, which is the only reason either figure is
 * worth having.
 */
class ExpenseTemplate implements PostingTemplate, SettlesThroughPaymentModes, StatesItsOwnTotal
{
    public function __construct(
        protected readonly ChartOfAccountService $accounts,
        protected readonly ChartOfAccountRepositoryInterface $chart,
    ) {}

    public function type(): TransactionType
    {
        return TransactionType::Expense;
    }

    /**
     * @param  array{account_id?: int|null, amount?: string|float|int|null, gst_amount?: string|float|int|null, payments?: array<int, array<string, mixed>>, notes?: string|null}  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidJournalException
     */
    public function build(array $input): array
    {
        $amount = Money::of($input['amount'] ?? 0);

        if (! $amount->isPositive()) {
            throw InvalidJournalException::expenseAmountRequired();
        }

        $gst = Money::ofNullable($input['gst_amount'] ?? null) ?? Money::zero();

        if ($gst->isNegative()) {
            throw InvalidJournalException::expenseTaxInvalid();
        }

        $account = $this->resolveAccount($input['account_id'] ?? null);

        $lines = [
            PostingLine::debit($account->id, $amount, $input['notes'] ?? null),
        ];

        if (! $gst->isZero()) {
            $lines[] = PostingLine::debit(
                $this->accounts->system(SystemAccount::GstInput)->id,
                $gst,
                'GST input',
            );
        }

        // One line per way the money moved, never merged — a cheque and a
        // transfer both land in Bank, and the voucher has to be able to say what
        // the workshop actually did. M6's rule, applied unchanged.
        foreach ($this->splitsFrom($input) as $split) {
            $lines[] = PostingLine::credit(
                $this->accounts->system($split->account())->id,
                $split->amount,
                $split->memo(),
            );
        }

        return $lines;
    }

    /**
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
            $splits[] = $row instanceof PaymentSplit
                ? $row
                : PaymentSplit::fromInput((array) $row, $index + 1);
        }

        return $splits;
    }

    /**
     * The amount plus its claimable tax — what the receipt says, and what the
     * split has to equal.
     *
     * @param  array<string, mixed>  $input
     */
    public function documentTotal(array $input): Money
    {
        return Money::of($input['amount'] ?? 0)
            ->plus(Money::ofNullable($input['gst_amount'] ?? null) ?? Money::zero());
    }

    /**
     * The expense account this cost belongs to, defaulted and then checked.
     *
     * Defaulted to Misc Expense because "we spent money and I do not want to
     * think about the category right now" is a real and reasonable state, and
     * refusing the entry over it would push people into not recording the spend
     * at all. Checked for *type*, because that is the part that cannot be
     * corrected by a rename later.
     *
     * @throws InvalidJournalException
     */
    private function resolveAccount(mixed $accountId): ChartOfAccount
    {
        if ($accountId === null || $accountId === '' || (int) $accountId === 0) {
            return $this->accounts->system(SystemAccount::MiscExpense);
        }

        $account = $this->chart->findById((int) $accountId)
            ?? throw InvalidJournalException::unknownAccount(1, (int) $accountId);

        if ($account->type !== AccountType::Expense) {
            throw InvalidJournalException::expenseAccountWrongType($account->name, $account->type->label());
        }

        return $account;
    }
}
