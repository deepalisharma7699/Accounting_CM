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
            self::SalaryExpense => AccountType::Expense,
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
            self::MiscExpense => 'Day-to-day running costs.',
            self::SalaryExpense => 'Staff salaries.',
        };
    }

    /**
     * Reserved for a later phase: seeded now so the ledger structure is
     * complete and Phase 2 needs no migration, but nothing in Phase 1 posts to
     * them.
     */
    public function isDeferredToLaterPhase(): bool
    {
        return match ($this) {
            self::StaffAdvance, self::SalaryExpense => true,
            default => false,
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
