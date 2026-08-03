<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The HTTP surface of payments and receipts.
 *
 * Two things are worth watching here beyond the happy path: that the two
 * endpoints stay separate — the URL, not a field, says which direction the money
 * went — and that everything the engine refuses comes back as an explanation
 * rather than a 500.
 */
class SettlementApiTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ['UPDATE', 'TRANSACTIONS'], ['DELETE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'ACCOUNTS'], ['READ', 'PARTIES'],
        ]);
    }

    private function party(string $name, PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => $name,
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $payments
     * @return array<string, mixed>
     */
    private function settlement(Party $party, bool $post = true, ?array $payments = null): array
    {
        return [
            'date' => now()->toDateString(),
            'notes' => 'Against invoice 118',
            'post' => $post,
            'party_id' => $party->id,
            'payments' => $payments ?? [['mode' => 'cash', 'amount' => '5000.00']],
        ];
    }

    /* ---------------------------------------------------------------------
     | Posting
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_owner_can_record_a_customer_receipt(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer))
            ->assertCreated()
            ->assertJsonPath('data.type', 'receipt')
            ->assertJsonPath('data.type_label', 'Customer Receipt')
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.party_id', $customer->id)
            // Decimal strings throughout, never JSON numbers.
            ->assertJsonPath('data.total', '5000.00')
            ->assertJsonPath('data.line_count', 2)
            ->assertJsonPath('data.payments.0.mode', 'cash')
            ->assertJsonPath('data.payments.0.amount', '5000.00');

        $this->assertSame('Cash', $response->json('data.payments.0.mode_label'));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_owner_can_record_a_split_vendor_payment(): void
    {
        $vendor = $this->party('Bharat Copper', PartyRole::Vendor);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/payment', $this->settlement($vendor, payments: [
                ['mode' => 'cash', 'amount' => '2000.00'],
                ['mode' => 'upi', 'amount' => '3000.00', 'reference' => 'UPI-4471'],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'payment')
            ->assertJsonPath('data.total', '5000.00')
            // One control line plus one per mode.
            ->assertJsonPath('data.line_count', 3)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.1.reference', 'UPI-4471');

        $this->assertSame('-2000.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('-3000.00', $this->balanceOf($this->tenant, SystemAccount::Upi));
        $this->assertBooksBalance($this->tenant);
    }

    /**
     * The endpoints are not interchangeable. Sending a payment payload to the
     * receipt route posts a receipt, which is why the direction lives in the URL
     * and never in a field a client could get the wrong way round without
     * noticing.
     */
    #[Test]
    public function the_direction_comes_from_the_route_not_from_the_payload(): void
    {
        $party = $this->party('Both Ways Traders', PartyRole::Customer, PartyRole::Vendor);

        $receipt = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($party))
            ->assertCreated();

        $payment = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/payment', $this->settlement($party))
            ->assertCreated();

        $this->assertSame('receipt', $receipt->json('data.type'));
        $this->assertSame('payment', $payment->json('data.type'));

        // Opposite effects on the same counterparty.
        $position = $this->positionOf($this->tenant, $party);
        $this->assertSame('-5000.00', $position['receivable']);
        $this->assertSame('-5000.00', $position['payable']);
    }

    /* ---------------------------------------------------------------------
     | Refusals
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_settlement_without_a_party_is_refused_by_validation(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);
        $payload = $this->settlement($customer);
        unset($payload['party_id']);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::count()));
    }

    #[Test]
    public function a_settlement_with_no_payment_lines_is_refused_by_validation(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer, payments: []))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function paying_a_party_who_is_not_a_vendor_is_refused_with_the_fix(): void
    {
        $customer = $this->party('Walk-in Customer', PartyRole::Customer);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/payment', $this->settlement($customer))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_ROLE_MISMATCH')
            ->assertJsonPath('error.details.required_role', 'vendor')
            // The message names the alternative rather than being a dead end.
            ->assertJsonFragment(['message' => 'Walk-in Customer is not marked as a vendor, so a vendor payment cannot be recorded against them. Add the vendor role to the party if that is what they are.']);

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::count()));
    }

    #[Test]
    public function a_cheque_without_its_number_is_refused_with_the_field_named(): void
    {
        $vendor = $this->party('Bharat Copper', PartyRole::Vendor);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/payment', $this->settlement($vendor, payments: [
                ['mode' => 'cheque', 'amount' => '4000.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYMENT_REFERENCE_REQUIRED')
            ->assertJsonPath('error.details.field', 'reference')
            ->assertJsonPath('error.details.line', 1);
    }

    #[Test]
    public function an_unknown_payment_mode_is_refused_by_validation(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer, payments: [
                ['mode' => 'barter', 'amount' => '100.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function a_settlement_cannot_name_another_workshops_party(): void
    {
        $other = Tenant::factory()->create();
        $stranger = $this->actingForTenant($other, fn () => Party::factory()->create([
            'name' => 'Stranger Motors',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($stranger))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_UNKNOWN');
    }

    #[Test]
    public function an_archived_party_cannot_be_settled_with(): void
    {
        $customer = $this->party('Retired Customer', PartyRole::Customer);
        $this->actingForTenant($this->tenant, fn () => $customer->update(['is_active' => false]));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_ARCHIVED');
    }

    /* ---------------------------------------------------------------------
     | Permissions and tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function recording_a_settlement_needs_the_write_grant(): void
    {
        [$tenant, $reader] = $this->tenantWithUser([['READ', 'TRANSACTIONS'], ['READ', 'PARTIES']]);

        $customer = $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'name' => 'Alpha Motors',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->withHeaders($this->authHeader($reader))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '100.00']],
            ])
            ->assertForbidden();
    }

    /**
     * A data-entry user captures the day's takings, which is exactly what a
     * receipt is. Reading the workshop's whole position stays a different
     * authority.
     */
    #[Test]
    public function a_data_entry_user_can_record_a_receipt(): void
    {
        [$tenant, $clerk] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['READ', 'PARTIES'],
        ], 'DATA_ENTRY_LIKE');

        $customer = $this->actingForTenant($tenant, fn () => Party::factory()->create([
            'name' => 'Counter Customer',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->withHeaders($this->authHeader($clerk))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'upi', 'amount' => '350.00', 'reference' => 'UPI-1']],
            ])
            ->assertCreated();

        // But the ledger stays closed to them.
        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/ledger/trial-balance')
            ->assertForbidden();

        $this->assertBooksBalance($tenant);
    }

    #[Test]
    public function a_settlement_is_invisible_to_another_workshop(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $created = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer))
            ->assertCreated();

        [, $stranger] = $this->tenantWithUser([['READ', 'TRANSACTIONS']]);

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/transactions/'.$created->json('data.id'))
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_settlement_can_be_parked_as_a_draft_and_posted_later(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer, post: false))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            // A draft reports its intended split in the same shape a posted one
            // reports its written rows, so a client renders one voucher.
            ->assertJsonPath('data.payments.0.mode', 'cash')
            ->assertJsonPath('data.payments.0.amount', '5000.00');

        $id = $draft->json('data.id');

        // Nothing has moved.
        $this->assertSame('0.00', $this->positionOf($this->tenant, $customer)['receivable']);
        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => TransactionPayment::count()));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame('-5000.00', $this->positionOf($this->tenant, $customer)['receivable']);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_draft_settlements_split_can_be_rewritten(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer, post: false))
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", [
                'payments' => [
                    ['mode' => 'cash', 'amount' => '1000.00'],
                    ['mode' => 'cheque', 'amount' => '2000.00', 'reference' => '402317'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.total', '3000.00')
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.1.reference', '402317');
    }

    /**
     * Sending journal lines for a payment draft would otherwise be silently
     * ignored — the payment template does not read them — and the caller would be
     * told their change was saved when nothing about it was.
     */
    #[Test]
    public function sending_journal_lines_for_a_settlement_draft_is_refused_rather_than_ignored(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer, post: false))
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", [
                'lines' => [
                    ['account_id' => $this->accountFor($this->tenant, SystemAccount::Cash)->id, 'debit' => '1.00'],
                    ['account_id' => $this->accountFor($this->tenant, SystemAccount::Sales)->id, 'credit' => '1.00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_LINES_NOT_ACCEPTED')
            ->assertJsonPath('error.details.expected', 'payments');
    }

    #[Test]
    public function sending_a_payment_split_for_a_journal_draft_is_refused(): void
    {
        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', [
                'date' => now()->toDateString(),
                'post' => false,
                'lines' => [
                    ['account_id' => $this->accountFor($this->tenant, SystemAccount::Cash)->id, 'debit' => '100.00'],
                    ['account_id' => $this->accountFor($this->tenant, SystemAccount::Sales)->id, 'credit' => '100.00'],
                ],
            ])
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", [
                'payments' => [['mode' => 'cash', 'amount' => '100.00']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_PAYMENTS_NOT_ACCEPTED');
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_meta_endpoint_publishes_the_new_types_and_the_payment_modes(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->assertOk();

        $types = collect($response->json('data.types'))->pluck('value')->all();

        // A client builds its filters from the server's answer rather than from a
        // hard-coded copy that drifts as each module adds a type.
        $this->assertContains('payment', $types);
        $this->assertContains('receipt', $types);

        $modes = collect($response->json('data.payment_modes'));

        $this->assertSame(['cash', 'bank', 'upi', 'cheque'], $modes->pluck('value')->all());
        $this->assertSame('Cheque number', $modes->firstWhere('value', 'cheque')['reference_label']);
        $this->assertTrue($modes->firstWhere('value', 'cheque')['requires_reference']);
        $this->assertFalse($modes->firstWhere('value', 'cash')['requires_reference']);

        // The settlement types do not take raw lines: their accounts are the
        // template's decision, not the user's.
        $this->assertFalse(collect($response->json('data.types'))->firstWhere('value', 'receipt')['accepts_raw_lines']);
    }

    #[Test]
    public function the_transaction_list_can_be_filtered_to_settlements(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', [
                'date' => now()->toDateString(),
                'post' => true,
                'lines' => [
                    ['account_id' => $this->accountFor($this->tenant, SystemAccount::Cash)->id, 'debit' => '10.00'],
                    ['account_id' => $this->accountFor($this->tenant, SystemAccount::Sales)->id, 'credit' => '10.00'],
                ],
            ])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?type=receipt')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'receipt');
    }

    #[Test]
    public function a_posted_settlement_cannot_be_edited_and_is_corrected_by_reversal(): void
    {
        $customer = $this->party('Alpha Motors', PartyRole::Customer);

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $this->settlement($customer))
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", [
                'payments' => [['mode' => 'cash', 'amount' => '1.00']],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TRANSACTION_IMMUTABLE');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/reverse", ['reason' => 'Cheque bounced'])
            ->assertCreated()
            ->assertJsonPath('data.reverses_id', $id)
            // The reversal carries the same modes: the money went back the way it
            // came, and the voucher must still say which way that was.
            ->assertJsonPath('data.payments.0.mode', 'cash');

        $this->assertSame('0.00', $this->positionOf($this->tenant, $customer)['receivable']);
        $this->assertBooksBalance($this->tenant, 'after reversing a receipt over the wire');
    }
}
