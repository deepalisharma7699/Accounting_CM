<?php

namespace Tests\Unit;

use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The accounting rules encoded in the enums.
 *
 * Pure logic, no database — these are the definitions the entire posting
 * engine will be built on, so they are pinned down before anything depends on
 * them.
 */
class AccountTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('normalBalances')]
    public function it_knows_which_side_increases_each_type(AccountType $type, BalanceSide $expected): void
    {
        $this->assertSame($expected, $type->normalBalance());
    }

    /**
     * @return array<string, array{0: AccountType, 1: BalanceSide}>
     */
    public static function normalBalances(): array
    {
        return [
            'assets increase on the debit side' => [AccountType::Asset, BalanceSide::Debit],
            'expenses increase on the debit side' => [AccountType::Expense, BalanceSide::Debit],
            'liabilities increase on the credit side' => [AccountType::Liability, BalanceSide::Credit],
            'equity increases on the credit side' => [AccountType::Equity, BalanceSide::Credit],
            'income increases on the credit side' => [AccountType::Income, BalanceSide::Credit],
        ];
    }

    #[Test]
    public function the_debit_and_credit_sides_partition_the_five_types(): void
    {
        $debit = array_filter(AccountType::cases(), fn (AccountType $t) => $t->isDebitNormal());
        $credit = array_filter(AccountType::cases(), fn (AccountType $t) => ! $t->isDebitNormal());

        // Assets + Expenses = Liabilities + Equity + Income, which is why
        // exactly two of the five are debit-normal.
        $this->assertEqualsCanonicalizing(
            [AccountType::Asset, AccountType::Expense],
            array_values($debit)
        );
        $this->assertCount(3, $credit);
    }

    #[Test]
    public function balance_sheet_and_profit_and_loss_types_are_disjoint(): void
    {
        $balanceSheet = array_filter(AccountType::cases(), fn (AccountType $t) => $t->isBalanceSheet());
        $profitAndLoss = array_filter(AccountType::cases(), fn (AccountType $t) => ! $t->isBalanceSheet());

        $this->assertEqualsCanonicalizing(
            [AccountType::Asset, AccountType::Liability, AccountType::Equity],
            array_values($balanceSheet)
        );
        $this->assertEqualsCanonicalizing(
            [AccountType::Income, AccountType::Expense],
            array_values($profitAndLoss)
        );
    }

    #[Test]
    public function code_bands_do_not_overlap(): void
    {
        $seen = [];

        foreach (AccountType::cases() as $type) {
            [$low, $high] = $type->codeRange();

            for ($code = $low; $code <= $high; $code += 100) {
                $this->assertArrayNotHasKey($code, $seen, "code {$code} is claimed by two types");
                $seen[$code] = $type;
            }
        }

        $this->assertNotEmpty($seen);
    }

    #[Test]
    public function a_code_resolves_back_to_its_type(): void
    {
        $this->assertSame(AccountType::Asset, AccountType::forCode('1010'));
        $this->assertSame(AccountType::Liability, AccountType::forCode('2100'));
        $this->assertSame(AccountType::Equity, AccountType::forCode('3000'));
        $this->assertSame(AccountType::Income, AccountType::forCode('4000'));
        $this->assertSame(AccountType::Expense, AccountType::forCode('5000'));
    }

    #[Test]
    public function a_code_outside_every_band_resolves_to_nothing(): void
    {
        $this->assertNull(AccountType::forCode('9999'));
        $this->assertNull(AccountType::forCode('0100'));
        $this->assertNull(AccountType::forCode('ABCD'));
    }

    #[Test]
    public function the_opposite_of_a_side_is_the_other_side(): void
    {
        $this->assertSame(BalanceSide::Credit, BalanceSide::Debit->opposite());
        $this->assertSame(BalanceSide::Debit, BalanceSide::Credit->opposite());
    }

    /* ---------------------------------------------------------------------
     | The system account catalogue
     |-------------------------------------------------------------------- */

    #[Test]
    public function every_system_account_is_numbered_inside_its_types_band(): void
    {
        foreach (SystemAccount::cases() as $account) {
            $this->assertTrue(
                $account->type()->acceptsCode($account->code()),
                "{$account->value} ({$account->code()}) is outside the {$account->type()->value} band."
            );
        }
    }

    #[Test]
    public function system_account_codes_and_names_are_unique(): void
    {
        $codes = array_map(fn (SystemAccount $a) => $a->code(), SystemAccount::cases());
        $names = array_map(fn (SystemAccount $a) => $a->accountName(), SystemAccount::cases());

        // Both carry a unique index per tenant, so a collision here would fail
        // provisioning for every workshop at once.
        $this->assertSame($codes, array_unique($codes));
        $this->assertSame($names, array_unique($names));
    }

    #[Test]
    public function the_catalogue_covers_the_fifteen_accounts_the_prd_specifies(): void
    {
        $this->assertCount(15, SystemAccount::cases());

        // Two are reserved for payroll in Phase 2; seeding them now means that
        // phase needs no migration.
        $deferred = array_filter(
            SystemAccount::cases(),
            fn (SystemAccount $a) => $a->isDeferredToLaterPhase()
        );

        $this->assertEqualsCanonicalizing(
            [SystemAccount::StaffAdvance, SystemAccount::SalaryExpense],
            array_values($deferred)
        );
    }
}
