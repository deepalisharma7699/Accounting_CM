<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\TransactionType;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\InvoiceDocumentService;
use App\Services\Inventory\ItemVariantService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A sale's labour drawn as one "Labour charges" row on the customer's copy —
 * how the invoice is drawn, never what it records.
 */
class CombinedLabourTest extends TestCase
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
            ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);
    }

    private function customer(): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }

    private function line(ItemVariant $variant, string $price, string $quantity = '1'): array
    {
        return ['item_id' => $variant->item_id, 'variant_id' => $variant->id, 'quantity' => $quantity, 'unit_price' => $price];
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function sell(array $items, bool $combine): Transaction
    {
        return $this->actingForTenant($this->tenant, fn () => $this->engine()->postComposed(TransactionType::Sale, [
            'date' => now()->toDateString(),
            'party_id' => $this->customer()->id,
            'items' => $items,
            'combine_labour' => $combine,
        ], $this->owner));
    }

    /** @return array<string, mixed> */
    private function documentOf(Transaction $sale): array
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => app(InvoiceDocumentService::class)->for(Transaction::query()->findOrFail($sale->id)),
        );
    }

    /** A bearing off the shelf, two tasks, and a rewind that consumes copper. */
    private function motorRepair(): array
    {
        $bearing = $this->variantFor($this->tenant, 'part');
        $copper = $this->variantFor($this->tenant, 'bulk_material');
        $this->receiveStock($this->tenant, $bearing, '5', '150.00');
        $this->receiveStock($this->tenant, $copper, '20', '700.00');

        $rewind = $this->serviceVariantFor($this->tenant, '5000.00');
        $this->actingForTenant($this->tenant, fn () => app(ItemVariantService::class)->update($rewind, [
            'components' => [['component_variant_id' => $copper->id, 'quantity' => '2.5']],
        ]));

        return [
            $this->line($bearing, '250.00'),
            $this->line($this->serviceVariantFor($this->tenant, '100.00'), '100.00'),
            $this->line($rewind, '5000.00'),
            $this->line($this->serviceVariantFor($this->tenant, '200.00'), '200.00'),
        ];
    }

    #[Test]
    public function the_tasks_print_as_one_row_and_the_invoice_comes_to_the_same(): void
    {
        $items = $this->motorRepair();

        $combined = $this->documentOf($this->sell($items, true));
        $itemised = $this->documentOf($this->sell($items, false));

        $this->assertSame(
            ['1', '2', '3'],
            array_map(fn ($row) => (string) $row['line_no'], $combined['lines']),
        );

        // Where the first task stood; the rewind consumed copper, so it is not
        // labour, and keeps its own row.
        [$bearing, $labour, $rewind] = $combined['lines'];
        $this->assertSame('Labour charges', $labour['description']);
        $this->assertStringNotContainsString('Labour charges', $rewind['description']);

        // ₹100 + ₹200 at 18%, added up rather than worked out again.
        $this->assertSame('300.00', $labour['taxable_value']);
        $this->assertSame('300.00', $labour['unit_price']);
        $this->assertSame('27.00', $labour['cgst_amount']);
        $this->assertSame('27.00', $labour['sgst_amount']);
        $this->assertSame('354.00', $labour['line_total']);
        $this->assertSame('998719', $labour['hsn_sac']);

        // The document is drawn differently and comes to exactly the same.
        $this->assertCount(4, $itemised['lines']);
        $this->assertSame($itemised['totals'], $combined['totals']);
        $this->assertSame(
            $combined['totals']['taxable'],
            Money::sum(array_map(fn ($row) => Money::of($row['taxable_value']), $combined['lines']))->amount(),
        );

        // And the counter's estimate is drawn the same way.
        $this->assertCount(3, $combined['estimate']['lines']);
        $this->assertSame('Labour charges', $combined['estimate']['lines'][1]['description']);
        $this->assertSame('354.00', $combined['estimate']['lines'][1]['amount']);
        $this->assertSame('354.00', $combined['estimate']['lines'][1]['rate']);
    }

    #[Test]
    public function the_books_keep_every_task_whichever_way_it_is_printed(): void
    {
        $sale = $this->sell($this->motorRepair(), true);

        $this->assertCount(4, $this->actingForTenant($this->tenant, fn () => $sale->fresh()->lines));
        $this->assertTrue($this->actingForTenant($this->tenant, fn () => $sale->fresh()->combine_labour));
    }

    #[Test]
    public function two_gst_rates_make_two_rows_because_a_row_carries_one(): void
    {
        $a = $this->serviceVariantFor($this->tenant, '100.00');
        $b = $this->serviceVariantFor($this->tenant, '200.00');
        $c = $this->serviceVariantFor($this->tenant, '300.00');
        $d = $this->serviceVariantFor($this->tenant, '400.00');

        $this->actingForTenant($this->tenant, function () use ($c, $d) {
            $c->item->update(['gst_rate' => '12.00']);
            $d->item->update(['gst_rate' => '12.00']);
        });

        $document = $this->documentOf($this->sell([
            $this->line($a, '100.00'), $this->line($c, '300.00'), $this->line($b, '200.00'), $this->line($d, '400.00'),
        ], true));

        $this->assertCount(2, $document['lines']);
        $this->assertSame(['18.00', '12.00'], array_column($document['lines'], 'gst_rate'));
        $this->assertSame(['300.00', '700.00'], array_column($document['lines'], 'taxable_value'));
    }

    #[Test]
    public function a_lone_task_keeps_its_own_name(): void
    {
        $bearing = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $bearing, '5', '150.00');
        $task = $this->serviceVariantFor($this->tenant, '100.00');

        $document = $this->documentOf($this->sell([
            $this->line($bearing, '250.00'), $this->line($task, '100.00'),
        ], true));

        $this->assertCount(2, $document['lines']);
        $this->assertNotSame('Labour charges', $document['lines'][1]['description']);
    }

    #[Test]
    public function the_counter_sends_it_and_a_purchase_cannot_carry_it(): void
    {
        $a = $this->serviceVariantFor($this->tenant, '100.00');
        $b = $this->serviceVariantFor($this->tenant, '200.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->customer()->id,
                'combine_labour' => true,
                'items' => [$this->line($a, '100.00'), $this->line($b, '200.00')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.combine_labour', true);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/'.$response->json('data.id').'/invoice')
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.description', 'Labour charges');

        // Unsent, the invoice is itemised — what every caller that predates the
        // box has always got.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->customer()->id,
                'items' => [$this->line($a, '100.00'), $this->line($b, '200.00')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.combine_labour', false);

        $vendor = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Vendor->value],
            'state_code' => '27',
        ]));
        $part = $this->variantFor($this->tenant, 'part');

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $vendor->id,
                'items' => [$this->line($part, '100.00')],
            ])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/transactions/'.$draft->json('data.id'), ['combine_labour' => true])
            ->assertStatus(422);
    }

    #[Test]
    public function a_parked_sale_keeps_the_choice_until_it_is_posted(): void
    {
        $a = $this->serviceVariantFor($this->tenant, '100.00');
        $b = $this->serviceVariantFor($this->tenant, '200.00');

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $this->customer()->id,
                'combine_labour' => true,
                'items' => [$this->line($a, '100.00'), $this->line($b, '200.00')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.combine_labour', true);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/'.$draft->json('data.id').'/post')
            ->assertOk()
            ->assertJsonPath('data.combine_labour', true);
    }
}
