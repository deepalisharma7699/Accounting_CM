<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M10 — template F, and the shortest checklist in the product: an expense with
 * and without claimable GST, paid from any mode.
 */
class ExpenseTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'ACCOUNTS'],
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $payments
     * @return array<string, mixed>
     */
    private function expense(string $amount, ?string $gst = null, ?int $accountId = null, array $payments = [['cash', '0']]): array
    {
        $total = $gst === null
            ? $amount
            : number_format((float) $amount + (float) $gst, 2, '.', '');

        if ($payments === [['cash', '0']]) {
            $payments = [['cash', $total]];
        }

        return [
            'date' => now()->toDateString(),
            'post' => true,
            'account_id' => $accountId,
            'amount' => $amount,
            'gst_amount' => $gst,
            'payments' => array_map(fn (array $split) => [
                'mode' => $split[0],
                'amount' => $split[1],
                'reference' => $split[2] ?? null,
            ], $payments),
        ];
    }

    #[Test]
    public function an_expense_with_no_claimable_tax_posts_two_lines(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('450.00'))
            ->assertCreated()
            ->assertJsonPath('data.total', '450.00')
            ->assertJsonCount(2, 'data.lines');

        $this->assertSame('450.00', $this->balanceOf($this->tenant, SystemAccount::MiscExpense));
        // A negative cash balance is what the ledger says when nobody has put
        // any in yet — correct, and not this test's business.
        $this->assertSame('-450.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::GstInput));

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_expense_with_claimable_tax_splits_it_into_gst_input(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('1000.00', '180.00'))
            ->assertCreated()
            // The receipt says ₹1,180, and that is what the transaction is worth.
            ->assertJsonPath('data.total', '1180.00')
            ->assertJsonCount(3, 'data.lines');

        $this->assertSame('1000.00', $this->balanceOf($this->tenant, SystemAccount::MiscExpense));
        $this->assertSame('180.00', $this->balanceOf($this->tenant, SystemAccount::GstInput));

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_expense_can_be_booked_to_any_expense_account_the_workshop_has_added(): void
    {
        $rent = $this->actingForTenant($this->tenant, fn () => ChartOfAccount::factory()->create([
            'code' => '5300',
            'name' => 'Rent',
            'type' => AccountType::Expense,
            'system_key' => null,
        ]));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('12000.00', accountId: $rent->id))
            ->assertCreated();

        $this->assertSame('12000.00', $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledger()->balanceFor($rent->refresh())->amount(),
        ));

        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::MiscExpense));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_expense_cannot_be_booked_to_an_account_that_is_not_an_expense(): void
    {
        $debtors = $this->accountFor($this->tenant, SystemAccount::Receivables);

        // Not the kind of mistake that surfaces: rent posted to Sundry Debtors
        // reads as a customer owing money, and nobody finds out until somebody
        // tries to collect it.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('500.00', accountId: $debtors->id))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXPENSE_ACCOUNT_WRONG_TYPE');
    }

    #[Test]
    public function an_expense_can_be_paid_from_any_mode_and_split_across_several(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('5000.00', payments: [
                ['cash', '2000.00'],
                ['upi', '2000.00', 'UPI-4417'],
                ['cheque', '1000.00', '402317'],
            ]))
            ->assertCreated();

        $this->assertSame('-2000.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('-2000.00', $this->balanceOf($this->tenant, SystemAccount::Upi));
        // A cheque settles through Bank — M6's decision, unchanged.
        $this->assertSame('-1000.00', $this->balanceOf($this->tenant, SystemAccount::Bank));

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_cheque_still_needs_its_number(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('500.00', payments: [['cheque', '500.00']]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYMENT_REFERENCE_REQUIRED');
    }

    #[Test]
    public function the_split_must_equal_what_the_receipt_says(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('1000.00', '180.00', payments: [['cash', '1000.00']]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYMENT_SPLIT_MISMATCH');
    }

    #[Test]
    public function an_expense_moves_no_stock_and_carries_no_items(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/expense', $this->expense('300.00'))
            ->assertCreated();

        $this->assertNull($response->json('data.movements'));
        $this->assertNull($response->json('data.items'));
    }
}
