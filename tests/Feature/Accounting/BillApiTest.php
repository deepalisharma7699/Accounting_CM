<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The HTTP surface of bills.
 *
 * Two things are worth watching beyond the happy path: that the two endpoints
 * stay separate — the URL, not a field, says whether the workshop sold or bought
 * — and that a warning comes back as a *warning*, on a 201, rather than as a
 * refusal.
 */
class BillApiTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);
    }

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    #[Test]
    public function an_owner_can_record_a_sale_over_the_counter(): void
    {
        $motor = $this->variantFor($this->tenant, 'motor');
        $customer = $this->party(PartyRole::Customer);

        $this->receiveStock($this->tenant, $motor, '4', '8000.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'notes' => 'Counter sale',
                'post' => true,
                'party_id' => $customer->id,
                'items' => [
                    ['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '10000.00'],
                ],
                'payments' => [['mode' => 'cash', 'amount' => '11800.00']],
            ])
            ->assertCreated();

        $response->assertJsonPath('data.type', 'sale')
            ->assertJsonPath('data.total', '11800.00')
            ->assertJsonPath('data.items.0.taxable_value', '10000.00')
            ->assertJsonPath('data.items.0.cgst_amount', '900.00')
            ->assertJsonPath('data.items.0.sgst_amount', '900.00')
            ->assertJsonPath('data.items.0.igst_amount', '0.00')
            ->assertJsonPath('data.items.0.is_stock', true)
            ->assertJsonPath('data.movements.0.quantity', '-1.000');

        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_purchase_arrives_on_its_own_route(): void
    {
        $copper = $this->variantFor($this->tenant, 'bulk_material');
        $vendor = $this->party(PartyRole::Vendor);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [['variant_id' => $copper->id, 'quantity' => '10', 'unit_price' => '700.00']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'purchase')
            ->assertJsonPath('data.total', '8260.00')
            ->assertJsonPath('data.movements.0.quantity', '10.000')
            // Inventory is carried net of claimable tax.
            ->assertJsonPath('data.movements.0.value', '7000.00');
    }

    #[Test]
    public function selling_below_cost_comes_back_as_a_warning_on_a_created_response(): void
    {
        $motor = $this->variantFor($this->tenant, 'motor');
        $customer = $this->party(PartyRole::Customer);

        $this->receiveStock($this->tenant, $motor, '2', '9000.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '7000.00']],
            ])
            ->assertCreated();

        $this->assertSame('BILL_LINE_BELOW_COST', $response->json('meta.warnings.0.code'));
        $this->assertJsonPathIsBelowCost($response->json('data.items.0'));
    }

    private function assertJsonPathIsBelowCost(array $line): void
    {
        $this->assertTrue($line['below_cost']);
        $this->assertSame('9000.00', $line['cost']);
        $this->assertSame('-2000.00', $line['margin']);
    }

    #[Test]
    public function a_labour_line_reports_no_cost_rather_than_zero(): void
    {
        $labour = $this->serviceVariantFor($this->tenant);
        $customer = $this->party(PartyRole::Customer);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $labour->id, 'quantity' => '2', 'unit_price' => '750.00']],
            ])
            ->assertCreated();

        // Null, not "0.00": reporting a cost of nothing would claim a 100%
        // margin on the workshop's most valuable work.
        $this->assertNull($response->json('data.items.0.cost'));
        $this->assertNull($response->json('data.items.0.margin'));
        $this->assertFalse($response->json('data.items.0.below_cost'));
    }

    #[Test]
    public function showing_a_bill_returns_its_tax_summary_and_margin(): void
    {
        $motor = $this->variantFor($this->tenant, 'motor');
        $customer = $this->party(PartyRole::Customer);

        $this->receiveStock($this->tenant, $motor, '2', '8000.00');

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->assertJsonPath('meta.tax.taxable', '10000.00')
            ->assertJsonPath('meta.tax.tax', '1800.00')
            ->assertJsonPath('meta.tax.inter_state', false)
            ->assertJsonPath('meta.margin.cost', '8000.00')
            ->assertJsonPath('meta.margin.margin', '2000.00')
            ->assertJsonPath('meta.margin.margin_percent', '20.00');
    }

    #[Test]
    public function a_bill_needs_a_party_and_at_least_one_line(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['party_id', 'items']]]]);
    }

    #[Test]
    public function another_workshops_variant_cannot_be_billed(): void
    {
        [$other] = $this->tenantWithUser();
        $theirs = $this->variantFor($other, 'part');
        $customer = $this->party(PartyRole::Customer);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $theirs->id, 'quantity' => '1', 'unit_price' => '100.00']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_VARIANT_UNKNOWN');
    }

    #[Test]
    public function a_draft_bill_echoes_its_request_without_pretending_to_be_priced(): void
    {
        $motor = $this->variantFor($this->tenant, 'motor');
        $customer = $this->party(PartyRole::Customer);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
            ])
            ->assertCreated();

        $response->assertJsonPath('data.is_draft', true)
            ->assertJsonPath('data.items.0.variant_id', $motor->id)
            // Not yet computed, and saying so beats sending a zero that would be
            // read as "no tax".
            ->assertJsonPath('data.items.0.taxable_value', null)
            ->assertJsonPath('data.items.0.line_total', null);
    }

    #[Test]
    public function the_meta_endpoint_says_which_vocabulary_each_type_speaks(): void
    {
        $types = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/transactions/meta')
                ->assertOk()
                ->json('data.types')
        )->keyBy('value');

        $this->assertTrue($types['sale']['has_document_lines']);
        $this->assertTrue($types['sale']['moves_stock']);
        $this->assertTrue($types['sale']['accepts_payment_split']);
        // A bill accepts a split without requiring one — a sale on thirty-day
        // terms is a complete document.
        $this->assertFalse($types['sale']['requires_payment_split']);
        $this->assertSame('customer', $types['sale']['required_party_role']);

        $this->assertTrue($types['receipt']['requires_payment_split']);
        $this->assertFalse($types['journal']['moves_stock']);
    }

    #[Test]
    public function editing_a_bill_draft_with_journal_lines_is_refused_rather_than_ignored(): void
    {
        $labour = $this->serviceVariantFor($this->tenant);
        $customer = $this->party(PartyRole::Customer);

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $labour->id, 'quantity' => '1', 'unit_price' => '500.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", [
                'lines' => [
                    ['account_id' => 1, 'debit' => '10.00'],
                    ['account_id' => 2, 'credit' => '10.00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_LINES_NOT_ACCEPTED');
    }

    #[Test]
    public function recording_a_bill_needs_the_write_grant(): void
    {
        [, $reader] = $this->tenantWithUser([['READ', 'TRANSACTIONS']]);

        $this->withHeaders($this->authHeader($reader))
            ->postJson('/api/v1/transactions/sale', [])
            ->assertForbidden();
    }
}
