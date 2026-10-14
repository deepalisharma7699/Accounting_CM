<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The number printed on a vendor's own bill — optional, recorded on a purchase
 * and on nothing else.
 */
class VendorInvoiceNumberTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $bearing;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part');
    }

    private function party(PartyRole $role): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [$role->value],
            'state_code' => '27',
        ]));
    }

    /** @param  array<string, mixed>  $extra */
    private function bill(string $direction, Party $party, array $extra = [], bool $post = true)
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$direction}", array_merge([
                'date' => now()->toDateString(),
                'post' => $post,
                'party_id' => $party->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '2',
                    'unit_price' => '150.00',
                ]],
            ], $extra));
    }

    private function stored(int $id): ?string
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->findOrFail($id)->vendor_invoice_no,
        );
    }

    #[Test]
    public function a_purchase_records_the_vendors_invoice_number(): void
    {
        $id = $this->bill('purchase', $this->party(PartyRole::Vendor), ['vendor_invoice_no' => '  INV-2291  '])
            ->assertCreated()
            ->assertJsonPath('data.vendor_invoice_no', 'INV-2291')
            ->json('data.id');

        $this->assertSame('INV-2291', $this->stored($id));
    }

    #[Test]
    public function it_is_optional(): void
    {
        $id = $this->bill('purchase', $this->party(PartyRole::Vendor))
            ->assertCreated()
            ->assertJsonPath('data.vendor_invoice_no', null)
            ->json('data.id');

        $this->assertNull($this->stored($id));
    }

    #[Test]
    public function it_is_refused_past_fifty_characters(): void
    {
        $this->bill('purchase', $this->party(PartyRole::Vendor), ['vendor_invoice_no' => str_repeat('9', 51)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['vendor_invoice_no']]]]);
    }

    #[Test]
    public function a_sale_does_not_carry_one(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '10', '100.00');

        $id = $this->bill('sale', $this->party(PartyRole::Customer), ['vendor_invoice_no' => 'INV-1'])
            ->assertCreated()
            ->json('data.id');

        $this->assertNull($this->stored($id));
    }

    #[Test]
    public function a_draft_keeps_it_through_to_posting(): void
    {
        $id = $this->bill('purchase', $this->party(PartyRole::Vendor), ['vendor_invoice_no' => 'D-77'], post: false)
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('D-77', $this->stored($id));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/post")
            ->assertOk();

        $this->assertSame('D-77', $this->stored($id));
    }

    #[Test]
    public function a_correction_carries_the_number_it_is_sent(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $id = $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'INV-5'])->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/revise", [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'vendor_invoice_no' => 'INV-5A',
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '3', 'unit_price' => '150.00']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.vendor_invoice_no', 'INV-5A');
    }

    #[Test]
    public function the_purchase_list_finds_a_bill_by_it(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $wanted = $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'GST/ZX-4410'])->json('data.id');
        $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'OTHER-1']);

        $ids = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/transactions?search=ZX-4410')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$wanted], $ids);
    }

    #[Test]
    public function the_list_answers_whether_a_vendors_number_is_already_in(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $other = $this->party(PartyRole::Vendor);
        $wanted = $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'INV-9'])->json('data.id');
        $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'INV-99']);
        $this->bill('purchase', $other, ['vendor_invoice_no' => 'INV-9']);

        $ids = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson("/api/v1/transactions?type=purchase&party_id={$vendor->id}&vendor_invoice_no=inv-9")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$wanted], $ids);
    }

    #[Test]
    public function saving_the_same_vendors_number_twice_warns_but_posts(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'INV-42'])
            ->assertCreated()
            ->assertJsonMissingPath('meta.warnings');

        $codes = collect(
            $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'INV-42'])
                ->assertCreated()
                ->json('meta.warnings')
        )->pluck('code')->all();

        $this->assertContains('DUPLICATE_VENDOR_INVOICE', $codes);
    }

    #[Test]
    public function another_vendors_same_number_is_not_a_duplicate(): void
    {
        $this->bill('purchase', $this->party(PartyRole::Vendor), ['vendor_invoice_no' => '101']);

        $codes = collect(
            $this->bill('purchase', $this->party(PartyRole::Vendor), ['vendor_invoice_no' => '101'])
                ->assertCreated()
                ->json('meta.warnings') ?? []
        )->pluck('code')->all();

        $this->assertNotContains('DUPLICATE_VENDOR_INVOICE', $codes);
    }

    #[Test]
    public function a_correction_is_not_a_duplicate_of_the_bill_it_replaces(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $id = $this->bill('purchase', $vendor, ['vendor_invoice_no' => 'INV-7'])->json('data.id');

        $codes = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->postJson("/api/v1/transactions/{$id}/revise", [
                    'date' => now()->toDateString(),
                    'post' => true,
                    'party_id' => $vendor->id,
                    'vendor_invoice_no' => 'INV-7',
                    'items' => [['variant_id' => $this->bearing->id, 'quantity' => '3', 'unit_price' => '150.00']],
                ])
                ->assertCreated()
                ->json('meta.warnings') ?? []
        )->pluck('code')->all();

        $this->assertNotContains('DUPLICATE_VENDOR_INVOICE', $codes);
    }
}
