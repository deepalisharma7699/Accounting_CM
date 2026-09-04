<?php

namespace App\Enums;

/**
 * The accounts the posting engine knows by name.
 *
 * This enum is the contract between business logic and the chart of accounts.
 * A posting template says "debit SystemAccount::Cogs", never "debit the account
 * called 'COGS'" and never "debit account id 12" — names are editable by the
 * workshop and ids differ per tenant, so either would be a bug waiting for a
 * rename.
 *
 * The case value is stored in `chart_of_accounts.system_key`, which is what
 * lookups resolve on. The visible `code()` is display and reporting only, so a
 * workshop's accountant could renumber their chart one day without touching a
 * line of engine code.
 *
 * Adding a case here means adding a seeded account. Removing one is a breaking
 * change for every tenant already provisioned.
 */
enum SystemAccount: string
{
    /* Assets — 1000-1999 */
    case Cash = 'cash_in_hand';
    case Bank = 'bank_account';
    case Upi = 'upi_wallet';
    case Inventory = 'inventory';
    case GstInput = 'gst_input';
    case Receivables = 'sundry_debtors';
    case StaffAdvance = 'staff_advance';

    /* Liabilities — 2000-2999 */
    case Payables = 'sundry_creditors';
    case GstOutput = 'gst_output';

    /* Equity — 3000-3999 */
    case OpeningBalanceEquity = 'opening_balance_equity';

    /* Income — 4000-4999 */
    case Sales = 'sales';
    case ServiceIncome = 'service_income';

    /* Expenses — 5000-5999 */
    case Cogs = 'cogs';
    case MiscExpense = 'misc_expense';
    case SalaryExpense = 'salary_expense';
    case RoundOff = 'round_off';

    public function code(): string
    {
        return match ($this) {
            self::Cash => '1010',
            self::Bank => '1020',
            self::Upi => '1030',
            self::Inventory => '1200',
            self::GstInput => '1300',
            self::Receivables => '1400',
            self::StaffAdvance => '1500',

            self::Payables => '2100',
            self::GstOutput => '2200',

            self::OpeningBalanceEquity => '3000',

            self::Sales => '4000',
            self::ServiceIncome => '4100',

            self::Cogs => '5000',
            self::MiscExpense => '5100',
            self::SalaryExpense => '5200',
            /*
            | The very top of the expense band, and not 5300 or 5900, which is
            | worth saying why.
            |
            | Every other seeded account sits at the bottom of its band, so a
            | workshop adding one of its own reaches for the next round number
            | up — the chart screen's "add an account" form literally offers
            | `5300` as its placeholder. Claiming any of those would collide
            | with the workshops that followed the suggestion, and the collision
            | surfaces as a failure to provision rather than as a message
            | anybody could act on.
            |
            | 5999 is the one number in the band nobody types by choice.
            */
            self::RoundOff => '5999',
        };
    }

    public function accountName(): string
    {
        return match ($this) {
            self::Cash => 'Cash in Hand',
            self::Bank => 'Bank Account',
            self::Upi => 'UPI / Wallet',
            self::Inventory => 'Inventory',
            self::GstInput => 'GST Input',
            self::Receivables => 'Sundry Debtors (Receivables)',
            self::StaffAdvance => 'Staff Advance',

            self::Payables => 'Sundry Creditors (Payables)',
            self::GstOutput => 'GST Output',

            self::OpeningBalanceEquity => 'Opening Balance Equity',

            self::Sales => 'Sales',
            self::ServiceIncome => 'Service Income',

            self::Cogs => 'COGS',
            self::MiscExpense => 'Misc Expense',
            self::SalaryExpense => 'Salary Expense',
            self::RoundOff => 'Round Off',
        };
    }

    public function type(): AccountType
    {
        return match ($this) {
            self::Cash,
            self::Bank,
            self::Upi,
            self::Inventory,
            self::GstInput,
            self::Receivables,
            self::StaffAdvance => AccountType::Asset,

            self::Payables,
            self::GstOutput => AccountType::Liability,

            self::OpeningBalanceEquity => AccountType::Equity,

            self::Sales,
            self::ServiceIncome => AccountType::Income,

            self::Cogs,
            self::MiscExpense,
            self::SalaryExpense,
            /*
            | An expense that spends most of its life with a credit balance,
            | which is correct rather than odd. Rounding up gains the workshop
            | paise and rounding down loses them, in roughly equal measure, and
            | the account is the running net of the two. Filing it under income
            | would be worse: it is not revenue, nobody earned it, and a P&L
            | reader looking for "what did the rounding cost us this year" looks
            | among the expenses.
            */
            self::RoundOff => AccountType::Expense,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cash => 'Notes and coins held at the workshop.',
            self::Bank => 'Money in the current account, including cheques once cleared.',
            self::Upi => 'UPI and wallet collections.',
            self::Inventory => 'Value of stock on hand, at weighted average cost.',
            self::GstInput => 'GST paid on purchases and claimable against output tax.',
            self::Receivables => 'Money customers owe the workshop.',
            self::StaffAdvance => 'Advances paid to staff, recovered from salary.',

            self::Payables => 'Money the workshop owes its suppliers.',
            self::GstOutput => 'GST charged on sales and owed to the department.',

            self::OpeningBalanceEquity => 'Absorbs opening stock, payables and receivables at go-live.',

            self::Sales => 'Revenue from goods: motors and parts.',
            self::ServiceIncome => 'Revenue from labour: repairs and rewinding.',

            self::Cogs => 'Cost of the stock consumed by a sale or a job.',
            self::RoundOff => 'The paise dropped or added when a bill is taken to the nearest rupee.',
            self::MiscExpense => 'Day-to-day running costs.',
            self::SalaryExpense => 'Staff salaries.',
        };
    }

    /**
     * Reserved for a later phase: seeded so the ledger structure is complete
     * before anything posts to it.
     *
     * **Empty as of M22**, and left in place rather than deleted because that is
     * the whole point of it. Staff Advance and Salary Expense were seeded from
     * the first day and sat unused for eleven modules; the staff module then
     * posted to them without a migration, without a backfill, and without a
     * workshop having to do anything. The next account somebody foresees should
     * arrive the same way, and this is where it says so.
     *
     * @return bool
     */
    public function isDeferredToLaterPhase(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
