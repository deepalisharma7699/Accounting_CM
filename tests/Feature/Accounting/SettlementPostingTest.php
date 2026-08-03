<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\PaymentMode;
use App\Enums\SystemAccount;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TransactionPayment;
use App\Services\Accounting\Posting\PaymentSplit;
use App\Services\Accounting\Posting\PostingBatch;
use App\Services\Accounting\Posting\PostingLine;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Payments and receipts — templates D and E.
 *
 * These are the first transactions in the product with a business document
 * behind them rather than a hand-written voucher, and they are deliberately the
 * simplest: money moving, no GST, no stock. What they prove is that the engine
 * built in M4 and the party positions derived in M5 hold up when a *template*
 * produces the lines instead of a person — and that a settlement adds no
 * reporting code of its own, because the outstanding figures were already right.
 */
class SettlementPostingTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function party(string $name, PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => $name,
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
        ]));
    }

    private function customer(string $name = 'Alpha Motors'): Party
    {
        return $this->party($name, PartyRole::Customer);
    }

    private function vendor(string $name = 'Bharat Copper'): Party
    {
        return $this->party($name, PartyRole::Vendor);
    }

    /** A credit sale, so the customer has something to pay. */
    private function invoice(Party $party, string $amount, ?string $date = null): void
    {
        $this->actingForTenant($this->tenant, fn () => $this->engine()->post($this->batchFor(
            $this->tenant,
            [
                [SystemAccount::Receivables, 'debit', $amount],
                [SystemAccount::Sales, 'credit', $amount],
            ],
            $date,
            party: $party,
        )));
    }

    /** A credit purchase, so the workshop owes the supplier something. */
    private function bill(Party $party, string $amount, ?string $date = null): void
    {
        $this->actingForTenant($this->tenant, fn () => $this->engine()->post($this->batchFor(
            $this->tenant,
            [
                [SystemAccount::Inventory, 'debit', $amount],
                [SystemAccount::Payables, 'credit', $amount],
            ],
            $date,
            party: $party,
        )));
    }

    /* ---------------------------------------------------------------------
     | The outstanding position
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_receipt_reduces_the_customers_outstanding_by_exactly_the_amount(): void
    {
        $customer = $this->customer();

        $this->invoice($customer, '5000.00');
        $this->assertSame('5000.00', $this->positionOf($this->tenant, $customer)['receivable']);

        $this->receiveFrom($this->tenant, $customer, [['cash', '2000.00']]);

        $position = $this->positionOf($this->tenant, $customer);

        $this->assertSame('3000.00', $position['receivable'], 'The receipt must reduce the receivable by its amount and nothing else.');
        $this->assertSame('0.00', $position['payable'], 'Collecting from a customer says nothing about owing them money.');
        $this->assertSame('3000.00', $position['net']);

        $this->assertBooksBalance($this->tenant, 'after a receipt');
    }

    #[Test]
    public function a_payment_reduces_the_vendors_payable_by_exactly_the_amount(): void
    {
        $vendor = $this->vendor();

        $this->bill($vendor, '12000.00');
        $this->assertSame('12000.00', $this->positionOf($this->tenant, $vendor)['payable']);

        $this->payVendor($this->tenant, $vendor, [['bank', '12000.00', 'NEFT-88213']]);

        $position = $this->positionOf($this->tenant, $vendor);

        $this->assertSame('0.00', $position['payable'], 'Paying the bill in full settles it.');
        $this->assertSame('0.00', $position['receivable']);

        $this->assertBooksBalance($this->tenant, 'after a payment');
    }

    /**
     * The point M5 made and this module inherits: the position is a sum over the
     * same rows the settlement wrote, so there is nothing to keep in step. This
     * test exists to prove M6 added no second path that could get it wrong.
     */
    #[Test]
    public function a_settlement_adds_no_reporting_code_the_position_simply_follows(): void
    {
        $party = $this->party('Verma Traders', PartyRole::Customer, PartyRole::Vendor);

        $this->invoice($party, '40000.00');
        $this->bill($party, '38000.00');

        $this->receiveFrom($this->tenant, $party, [['upi', '15000.00', 'UPI-773']]);
        $this->payVendor($this->tenant, $party, [['cash', '8000.00']]);

        $position = $this->positionOf($this->tenant, $party);

        // Both sides reported separately, never netted into one number: they are
        // settled on different terms.
        $this->assertSame('25000.00', $position['receivable']);
        $this->assertSame('30000.00', $position['payable']);
        $this->assertSame('-5000.00', $position['net']);

        // And the control accounts agree, because they are the same rows.
        $this->assertSame('25000.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
        $this->assertSame('30000.00', $this->balanceOf($this->tenant, SystemAccount::Payables));

        $this->assertBooksBalance($this->tenant, 'after a mixed run of settlements');
    }

    /* ---------------------------------------------------------------------
     | The split
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_split_payment_posts_one_control_line_and_one_line_per_mode(): void
    {
        $vendor = $this->vendor();
        $this->bill($vendor, '5000.00');

        $payment = $this->payVendor($this->tenant, $vendor, [
            ['cash', '2000.00'],
            ['upi', '3000.00'],
        ]);

        $this->actingForTenant($this->tenant, fn () => $payment->load('entries.account'));

        $this->assertCount(3, $payment->entries, 'One debit to Payables, and one credit per mode.');
        $this->assertSame('5000.00', $payment->total);

        $payables = $this->accountFor($this->tenant, SystemAccount::Payables);
        $control = $payment->entries->firstWhere('account_id', $payables->id);

        $this->assertNotNull($control);
        $this->assertSame('5000.00', $control->debit, 'The party owes one amount, however many ways it was paid.');

        // The three lines balance, which is the whole invariant M4 exists for.
        $this->assertTrue(
            $payment->entries->sum(fn ($entry) => $entry->debitMoney()->minor())
            === $payment->entries->sum(fn ($entry) => $entry->creditMoney()->minor())
        );

        $this->assertBooksBalance($this->tenant, 'after a split payment');
    }

    #[Test]
    public function cash_bank_and_upi_ledgers_each_move_independently(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '9000.00');

        $this->receiveFrom($this->tenant, $customer, [
            ['cash', '1000.00'],
            ['bank', '3000.00', 'NEFT-1'],
            ['upi', '5000.00', 'UPI-2'],
        ]);

        $this->assertSame('1000.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('3000.00', $this->balanceOf($this->tenant, SystemAccount::Bank));
        $this->assertSame('5000.00', $this->balanceOf($this->tenant, SystemAccount::Upi));

        $this->assertBooksBalance($this->tenant, 'after a three-way receipt');
    }

    /**
     * A cheque and a bank transfer share the Bank account, so the ledger cannot
     * tell them apart — which is exactly why the mode is recorded, and why the
     * two are two lines rather than one merged one.
     */
    #[Test]
    public function a_cheque_settles_through_the_bank_account_and_stays_distinguishable(): void
    {
        $vendor = $this->vendor();
        $this->bill($vendor, '7000.00');

        $payment = $this->payVendor($this->tenant, $vendor, [
            ['bank', '3000.00', 'NEFT-4471'],
            ['cheque', '4000.00', '402317'],
        ]);

        $bank = $this->accountFor($this->tenant, SystemAccount::Bank);

        // Negative, and correctly so: Bank is debit-normal and this money left.
        $this->assertSame('-7000.00', $this->balanceOf($this->tenant, SystemAccount::Bank));

        $this->actingForTenant($this->tenant, fn () => $payment->load(['entries', 'payments']));

        $this->assertCount(
            2,
            $payment->entries->where('account_id', $bank->id),
            'Two movements, two lines — merging them would lose what the workshop actually did.'
        );

        $this->assertSame(
            ['bank', 'cheque'],
            $payment->payments->map(fn (TransactionPayment $row) => $row->mode->value)->all()
        );
        $this->assertSame('402317', $payment->payments->last()->reference);
    }

    #[Test]
    public function a_cheque_without_its_number_is_refused(): void
    {
        $vendor = $this->vendor();

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessageMatches('/cheque number/i');

        $this->payVendor($this->tenant, $vendor, [['cheque', '4000.00']]);
    }

    #[Test]
    public function the_settlement_rows_record_the_mode_and_reference_the_ledger_cannot(): void
    {
        $customer = $this->customer();

        $receipt = $this->receiveFrom($this->tenant, $customer, [
            ['cash', '250.50'],
            ['upi', '749.50', 'UPI-99001'],
        ]);

        $this->actingForTenant($this->tenant, fn () => $receipt->load('payments'));

        $this->assertCount(2, $receipt->payments);
        $this->assertSame([1, 2], $receipt->payments->pluck('line_no')->all());
        $this->assertSame('250.50', $receipt->payments->first()->amount);
        $this->assertSame(PaymentMode::Upi, $receipt->payments->last()->mode);
        $this->assertSame('UPI-99001', $receipt->payments->last()->reference);

        // The split equals the settlement, by construction — the template builds
        // the control line from this very sum.
        $this->assertSame(
            '1000.00',
            Money::sum($receipt->payments->map(fn (TransactionPayment $row) => $row->amountMoney()))->amount()
        );
    }

    /* ---------------------------------------------------------------------
     | GST
     |-------------------------------------------------------------------- */

    /**
     * The invariant this module states most loudly. Tax was charged when the bill
     * was raised and the liability arose then; recognising it again on payment
     * would double-count it, and recognising it *only* on payment would make an
     * unpaid invoice's tax invisible. Either way the GST return is wrong.
     */
    #[Test]
    public function a_settlement_never_touches_gst(): void
    {
        $customer = $this->customer();
        $vendor = $this->vendor();

        $gstAccounts = [
            $this->accountFor($this->tenant, SystemAccount::GstInput)->id,
            $this->accountFor($this->tenant, SystemAccount::GstOutput)->id,
        ];

        $receipt = $this->receiveFrom($this->tenant, $customer, [['cash', '5900.00']]);
        $payment = $this->payVendor($this->tenant, $vendor, [['bank', '2360.00', 'NEFT-2']]);

        $this->actingForTenant($this->tenant, function () use ($receipt, $payment, $gstAccounts) {
            foreach ([$receipt, $payment] as $transaction) {
                foreach ($transaction->load('entries')->entries as $entry) {
                    $this->assertNotContains(
                        $entry->account_id,
                        $gstAccounts,
                        'Tax lives on the invoice, never on the settlement of it.'
                    );
                }
            }
        });

        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::GstInput));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::GstOutput));
    }

    /* ---------------------------------------------------------------------
     | Overpayment
     |-------------------------------------------------------------------- */

    /**
     * The decision M5 took, applied here: the money is in the bank and it is
     * theirs, so the books say so. Refusing would only mean it went unrecorded.
     */
    #[Test]
    public function overpayment_leaves_a_credit_balance_rather_than_being_refused(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '5000.00');

        $this->receiveFrom($this->tenant, $customer, [['upi', '6000.00', 'UPI-1']]);

        $position = $this->positionOf($this->tenant, $customer);

        $this->assertSame('-1000.00', $position['receivable'], 'A customer in credit shows a negative receivable.');
        $this->assertSame('0.00', $position['payable'], 'Pushing it onto the payable side would claim a supplier relationship that does not exist.');

        $this->assertBooksBalance($this->tenant, 'after an overpayment');
    }

    #[Test]
    public function paying_a_vendor_more_than_is_owed_leaves_them_holding_an_advance(): void
    {
        $vendor = $this->vendor();
        $this->bill($vendor, '3000.00');

        $this->payVendor($this->tenant, $vendor, [['bank', '5000.00', 'NEFT-3']]);

        $this->assertSame('-2000.00', $this->positionOf($this->tenant, $vendor)['payable']);
        $this->assertBooksBalance($this->tenant, 'after overpaying a supplier');
    }

    /* ---------------------------------------------------------------------
     | The party
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_settlement_must_name_a_party(): void
    {
        $this->expectException(InvalidJournalException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            PostingBatch::of(
                type: TransactionType::Receipt,
                date: now()->toDateString(),
                lines: [
                    PostingLine::debit($this->accountFor($this->tenant, SystemAccount::Cash)->id, Money::of('100.00')),
                    PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Receivables)->id, Money::of('100.00')),
                ],
                payments: [PaymentSplit::of(PaymentMode::Cash, Money::of('100.00'))],
            )
        ));
    }

    /**
     * Debiting Sundry Creditors *is* the claim "we owed this business money", so
     * paying a customer-only party would invent a supplier relationship — and
     * leave a position on a record nobody would think to look at.
     */
    #[Test]
    public function a_payment_to_a_party_who_is_not_a_vendor_is_refused(): void
    {
        $customer = $this->customer('Walk-in Customer');

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessageMatches('/not marked as a vendor/i');

        $this->payVendor($this->tenant, $customer, [['cash', '500.00']]);
    }

    #[Test]
    public function a_receipt_from_a_party_who_is_not_a_customer_is_refused(): void
    {
        $vendor = $this->vendor('Scrap Only Supplier');

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessageMatches('/not marked as a customer/i');

        $this->receiveFrom($this->tenant, $vendor, [['cash', '500.00']]);
    }

    #[Test]
    public function a_party_holding_both_roles_can_be_paid_and_received_from(): void
    {
        $party = $this->party('Both Ways Traders', PartyRole::Customer, PartyRole::Vendor);

        $this->receiveFrom($this->tenant, $party, [['cash', '1000.00']]);
        $this->payVendor($this->tenant, $party, [['cash', '400.00']]);

        $this->assertSame('-1000.00', $this->positionOf($this->tenant, $party)['receivable']);
        $this->assertSame('-400.00', $this->positionOf($this->tenant, $party)['payable']);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_settlement_cannot_name_another_workshops_party(): void
    {
        $other = Tenant::factory()->create();
        $stranger = $this->actingForTenant($other, fn () => Party::factory()->create([
            'name' => 'Stranger Motors',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->expectException(InvalidJournalException::class);

        $this->receiveFrom($this->tenant, $stranger, [['cash', '100.00']]);
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_settlement_writes_nothing_to_the_ledger_or_to_the_settlement_table(): void
    {
        $customer = $this->customer();

        $draft = $this->receiveFrom($this->tenant, $customer, [['cash', '750.00']], post: false);

        $this->assertSame(TransactionStatus::Draft, $draft->status);

        $this->actingForTenant($this->tenant, function () use ($draft) {
            $this->assertSame(0, $draft->entries()->count(), 'A draft is absent from the ledger, not filtered out of it.');
            $this->assertSame(0, $draft->payments()->count(), 'And absent from the settlement table for the same reason.');
        });

        // Its intent is held on the row instead.
        $this->assertSame('cash', $draft->draft_payments[0]['mode']);
        $this->assertSame('750.00', $draft->draft_payments[0]['amount']);

        $this->assertSame('0.00', $this->positionOf($this->tenant, $customer)['receivable']);
    }

    #[Test]
    public function posting_a_draft_settlement_writes_both_the_entries_and_the_split(): void
    {
        $customer = $this->customer();
        $draft = $this->receiveFrom($this->tenant, $customer, [['cheque', '1500.00', '773001']], post: false);

        $posted = $this->actingForTenant($this->tenant, fn () => $this->engine()->postDraft($draft->fresh()));

        $this->assertSame(TransactionStatus::Posted, $posted->status);
        $this->assertNull($posted->draft_payments, 'The intent is cleared in the same statement that posts it.');
        $this->assertNull($posted->draft_lines);

        $this->assertCount(2, $posted->entries);
        $this->assertCount(1, $posted->payments);
        $this->assertSame('773001', $posted->payments->first()->reference);

        $this->assertSame('-1500.00', $this->positionOf($this->tenant, $customer)['receivable']);
        $this->assertBooksBalance($this->tenant, 'after posting a draft receipt');
    }

    /* ---------------------------------------------------------------------
     | Reversal
     |-------------------------------------------------------------------- */

    #[Test]
    public function reversing_a_receipt_returns_the_money_and_keeps_the_mode_on_the_record(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '5000.00');

        $receipt = $this->receiveFrom($this->tenant, $customer, [['cheque', '5000.00', '402317']]);

        $reversal = $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($receipt->fresh()));

        $this->assertSame(TransactionStatus::Reversed, $receipt->fresh()->status);
        $this->assertSame($receipt->id, $reversal->reverses_id);
        $this->assertSame($customer->id, $reversal->party_id, 'The correction lands on the same statement as the mistake.');

        // A bounced cheque puts the debt back exactly where it was.
        $this->assertSame('5000.00', $this->positionOf($this->tenant, $customer)['receivable']);
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Bank));

        // And the voucher can still say which cheque it was, which is the only
        // moment anybody reads it.
        $this->actingForTenant($this->tenant, fn () => $reversal->load('payments'));

        $this->assertCount(1, $reversal->payments);
        $this->assertSame(PaymentMode::Cheque, $reversal->payments->first()->mode);
        $this->assertSame('402317', $reversal->payments->first()->reference);

        $this->assertBooksBalance($this->tenant, 'after reversing a receipt');
    }

    /**
     * Archiving means "no new business with them"; it cannot mean "this error is
     * permanent". Nor can removing a role after the fact — the same reasoning,
     * and the reason the role check is skipped on a reversal.
     */
    #[Test]
    public function a_receipt_can_be_reversed_after_the_customer_role_is_removed(): void
    {
        $party = $this->party('Reclassified Traders', PartyRole::Customer);
        $receipt = $this->receiveFrom($this->tenant, $party, [['cash', '900.00']]);

        $this->actingForTenant($this->tenant, function () use ($party) {
            app(\App\Services\Accounting\PartyService::class)
                ->update($party->id, ['roles' => [PartyRole::Vendor->value], 'is_active' => false]);
        });

        $reversal = $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($receipt->fresh()));

        $this->assertSame($receipt->id, $reversal->reverses_id);
        $this->assertSame('0.00', $this->positionOf($this->tenant, $party->fresh())['receivable']);
        $this->assertBooksBalance($this->tenant, 'after reversing against a reclassified party');
    }

    /* ---------------------------------------------------------------------
     | The engine's own guards
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_settlement_composed_by_hand_cannot_disagree_with_its_own_split(): void
    {
        $customer = $this->customer();

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessageMatches('/payment split totals/i');

        // Balanced entries, but the settlement rows would claim ₹4,000 moved
        // while the ledger says ₹5,000. Impossible through a template; refused
        // anyway, because M11's importer and M15's agent compose batches directly.
        $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            PostingBatch::of(
                type: TransactionType::Receipt,
                date: now()->toDateString(),
                lines: [
                    PostingLine::debit($this->accountFor($this->tenant, SystemAccount::Cash)->id, Money::of('5000.00')),
                    PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Receivables)->id, Money::of('5000.00')),
                ],
                partyId: $customer->id,
                payments: [PaymentSplit::of(PaymentMode::Cash, Money::of('4000.00'))],
            )
        ));
    }

    #[Test]
    public function a_settlement_with_no_split_is_refused(): void
    {
        $customer = $this->customer();

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessageMatches('/how the money moved/i');

        $this->actingForTenant($this->tenant, fn () => $this->engine()->compose(TransactionType::Receipt, [
            'date' => now()->toDateString(),
            'party_id' => $customer->id,
            'payments' => [],
        ]));
    }

    #[Test]
    public function a_zero_settlement_line_is_refused(): void
    {
        $customer = $this->customer();

        $this->expectException(InvalidJournalException::class);

        $this->receiveFrom($this->tenant, $customer, [['cash', '0.00']]);
    }

    #[Test]
    public function an_unknown_payment_mode_is_refused(): void
    {
        $customer = $this->customer();

        $this->expectException(InvalidJournalException::class);
        $this->expectExceptionMessageMatches('/does not know/i');

        $this->receiveFrom($this->tenant, $customer, [['bitcoin', '100.00']]);
    }

    /**
     * The paise question, on the path that will carry most of the product's real
     * money. `0.1 + 0.2 !== 0.3` in binary floating point, so a split summed as
     * floats would refuse a settlement that is demonstrably correct.
     */
    #[Test]
    public function a_split_of_awkward_paise_amounts_balances_exactly(): void
    {
        $customer = $this->customer();

        $receipt = $this->receiveFrom($this->tenant, $customer, [
            ['cash', '0.10'],
            ['upi', '0.20'],
        ]);

        $this->assertSame('0.30', $receipt->total, 'Ten paise plus twenty paise is thirty paise, not 0.30000000000000004.');
        $this->assertSame('-0.30', $this->positionOf($this->tenant, $customer)['receivable']);

        $this->assertBooksBalance($this->tenant, 'after a receipt of thirty paise in two tenders');
    }

    /**
     * A hundred settlements, all of them balanced sets, in one workshop. The
     * trial balance is the only assertion that matters.
     */
    #[Test]
    public function the_books_still_reconcile_after_a_hundred_mixed_settlements(): void
    {
        $customer = $this->customer();
        $vendor = $this->vendor();
        $modes = PaymentMode::values();

        for ($i = 1; $i <= 50; $i++) {
            $mode = $modes[$i % count($modes)];
            $reference = $mode === PaymentMode::Cheque->value ? "CHQ-{$i}" : null;

            $this->receiveFrom($this->tenant, $customer, [[$mode, sprintf('%d.%02d', $i, $i % 100), $reference]]);
            $this->payVendor($this->tenant, $vendor, [[$mode, sprintf('%d.%02d', $i * 2, $i % 100), $reference]]);
        }

        $this->assertBooksBalance($this->tenant, 'after a hundred settlements');
    }

    /* ---------------------------------------------------------------------
     | Tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function settlement_rows_are_scoped_to_their_workshop(): void
    {
        $other = Tenant::factory()->create();

        $mine = $this->customer('Mine');
        $theirs = $this->actingForTenant($other, fn () => Party::factory()->create([
            'name' => 'Theirs',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->receiveFrom($this->tenant, $mine, [['cash', '111.00']]);
        $this->receiveFrom($other, $theirs, [['cash', '222.00']]);

        $mineRows = $this->actingForTenant($this->tenant, fn () => TransactionPayment::all());
        $theirRows = $this->actingForTenant($other, fn () => TransactionPayment::all());

        $this->assertSame(['111.00'], $mineRows->pluck('amount')->all());
        $this->assertSame(['222.00'], $theirRows->pluck('amount')->all());

        $this->assertBooksBalance($this->tenant);
        $this->assertBooksBalance($other);
    }
}
