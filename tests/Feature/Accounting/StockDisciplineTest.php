<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M17 over HTTP — the brief's §36 scenarios 8 and 9, and the §12 confirmation.
 *
 * Scenario 8: the operator taps Save twice. Scenario 9: the operator bills more
 * than the shelf holds. Both are ordinary counter mistakes rather than exotic
 * ones, and what is under test is that neither of them costs the workshop
 * anything.
 */
class StockDisciplineTest extends TestCase
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
            ['DELETE', 'TRANSACTIONS'], ['READ', 'LEDGER'], ['READ', 'PARTIES'],
            ['READ', 'ITEMS'], ['READ', 'STOCK'], ['READ', 'WORKSPACE'], ['UPDATE', 'WORKSPACE'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part');
    }

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    private function allowNegativeStock(bool $allowed): void
    {
        $this->actingForTenant(
            $this->tenant,
            fn () => $this->tenant->forceFill(['allow_negative_stock' => $allowed])->save()
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function salePayload(Party $customer, string $quantity, array $extra = []): array
    {
        return array_merge([
            'date' => now()->toDateString(),
            'post' => true,
            'party_id' => $customer->id,
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => $quantity,
                'unit_price' => '600.00',
            ]],
        ], $extra);
    }

    /* ---------------------------------------------------------------------
     | §36 scenario 9 — more than the shelf holds
     |-------------------------------------------------------------------- */

    #[Test]
    public function billing_more_than_the_shelf_holds_is_refused_in_the_brief_s_own_words(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '5', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '6'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT')
            ->assertJsonPath('error.details.available', '5')
            ->assertJsonPath('error.details.unit', 'pc');
    }

    #[Test]
    public function a_refused_bill_writes_nothing(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '5', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '6'))
            ->assertStatus(422);

        // Not a single half-write: no invoice, no movement, no number taken.
        $this->assertSame('5.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertSame(0, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('type', 'sale')->count()
        ));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function two_lines_of_the_same_part_are_counted_together(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '5', '400.00');

        // Three and three is six, and a shelf of five is short — which neither
        // line can tell on its own.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [
                    ['variant_id' => $this->bearing->id, 'quantity' => '3', 'unit_price' => '600.00'],
                    ['variant_id' => $this->bearing->id, 'quantity' => '3', 'unit_price' => '600.00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');
    }

    #[Test]
    public function the_setting_lets_a_workshop_bill_ahead_of_its_paperwork(): void
    {
        $this->allowNegativeStock(true);

        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '5', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '6'))
            ->assertCreated();

        $this->assertSame('-1.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertBooksBalance($this->tenant);
    }

    /**
     * A draft is not a commitment, so it is not refused. Parking an unfinished
     * bill until the supplier's invoice arrives is exactly what a draft is for —
     * and refusing it would be the failure M8 warned about, arrived at from the
     * other direction.
     */
    #[Test]
    public function a_draft_may_be_saved_for_stock_that_has_not_arrived(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '6', ['post' => false]))
            ->assertCreated()
            ->assertJsonPath('data.is_draft', true)
            ->json('data.id');

        // Posting it is where the rule bites.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$draft}/post")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');

        // And the draft survives the refusal, so the purchase can be entered and
        // the same document posted afterwards.
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$draft}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
    }

    /**
     * A stock take is the workshop asserting what is physically on the shelf.
     * It is the authority the books answer to, and the only tool for repairing a
     * position that has already gone negative — so it is exempt.
     */
    #[Test]
    public function a_stock_adjustment_is_never_refused(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '5', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/stock-adjustment', [
                'date' => now()->toDateString(),
                'post' => true,
                'adjustments' => [['variant_id' => $this->bearing->id, 'quantity' => '-8']],
            ])
            ->assertCreated();

        $this->assertSame('-3.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
    }

    #[Test]
    public function the_workspace_reports_and_accepts_the_setting(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.settings.allow_negative_stock', false);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['allow_negative_stock' => true])
            ->assertOk()
            ->assertJsonPath('data.settings.allow_negative_stock', true);
    }

    /* ---------------------------------------------------------------------
     | §36 scenario 8 — the operator taps Save twice
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_repeated_submission_returns_the_first_bill_rather_than_a_second(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $ref = (string) Str::uuid();
        $payload = $this->salePayload($customer, '2', ['client_ref' => $ref]);

        $first = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $payload)
            ->assertCreated()
            ->json('data');

        // The stalled request, retried.
        $second = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $payload)
            // 200, not 201: nothing was created this time, and the status code is
            // how a client tells the two apart.
            ->assertOk()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['doc_no'], $second['doc_no']);
        $this->assertSame($ref, $second['client_ref']);

        // One invoice, one number, one movement of two bearings.
        $this->assertSame(1, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('type', 'sale')->count()
        ));
        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function two_genuinely_different_bills_are_both_recorded(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        // The same basket twice, from two different documents — a customer
        // buying two of the same thing on two visits. A fresh reference is what
        // says "this is a new bill", which is why the client generates one per
        // document rather than per request.
        foreach ([Str::uuid(), Str::uuid()] as $ref) {
            $this->withHeaders($this->authHeader($this->owner))
                ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '2', [
                    'client_ref' => (string) $ref,
                ]))
                ->assertCreated();
        }

        $this->assertSame(2, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('type', 'sale')->count()
        ));
        $this->assertSame('6.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
    }

    #[Test]
    public function a_repeated_receipt_is_not_allocated_twice(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $bill = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '2'))
            ->assertCreated()
            ->json('data.id');

        $ref = (string) Str::uuid();

        $payload = [
            'date' => now()->toDateString(),
            'post' => true,
            'client_ref' => $ref,
            'party_id' => $customer->id,
            'payments' => [['mode' => 'cash', 'amount' => '500.00']],
        ];

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $payload)
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', $payload)
            ->assertOk();

        // ₹500 collected once, and pointed at the invoice once.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$bill}")
            ->assertOk()
            ->assertJsonPath('data.paid', '500.00');
    }

    #[Test]
    public function a_reference_from_one_workshop_cannot_reach_another_s_document(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $ref = (string) Str::uuid();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', $this->salePayload($customer, '2', ['client_ref' => $ref]))
            ->assertCreated();

        [$other, $stranger] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['READ', 'LEDGER'],
        ]);

        $theirCustomer = $this->actingForTenant($other, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));

        // The same UUID, in a different workshop. It creates their own document
        // rather than resolving to ours — the unique index is per tenant.
        $this->withHeaders($this->authHeader($stranger))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'client_ref' => $ref,
                'party_id' => $theirCustomer->id,
                'payments' => [['mode' => 'cash', 'amount' => '100.00']],
            ])
            ->assertCreated();
    }

    /* ---------------------------------------------------------------------
     | §12 — the confirmation screen
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_preview_prices_a_bill_without_posting_it(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '600.00']],
            ])
            ->assertOk()
            ->assertJsonPath('data.totals.taxable', '1200.00')
            ->assertJsonPath('data.totals.total', '1416.00')
            ->assertJsonPath('data.can_post', true)
            ->assertJsonPath('data.stock', []);

        // Nothing was written, which is the entire point of a separate verb.
        $this->assertSame(0, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->where('type', 'sale')->count()
        ));
    }

    #[Test]
    public function the_preview_agrees_with_the_bill_that_is_then_posted(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '400.00');

        $items = [
            ['variant_id' => $this->bearing->id, 'quantity' => '3', 'unit_price' => '617.35'],
            ['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '99.99', 'discount' => '9.99'],
        ];

        $preview = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'party_id' => $customer->id,
                'items' => $items,
            ])
            ->assertOk()
            ->json('data');

        $posted = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => $items,
            ])
            ->assertCreated()
            ->json('data');

        // To the paise, on deliberately awkward figures — which is why the
        // confirmation is the server's arithmetic rather than the browser's.
        $this->assertSame($preview['totals']['total'], $posted['total']);
        $this->assertSame($preview['lines'][0]['line_total'], $posted['items'][0]['line_total']);
        $this->assertSame($preview['lines'][1]['taxable_value'], $posted['items'][1]['taxable_value']);
    }

    #[Test]
    public function the_preview_reports_every_shortfall_rather_than_the_first(): void
    {
        $second = $this->variantFor($this->tenant, 'part');
        $customer = $this->party(PartyRole::Customer);

        $this->receiveStock($this->tenant, $this->bearing, '1', '400.00');
        $this->receiveStock($this->tenant, $second, '2', '250.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'party_id' => $customer->id,
                'items' => [
                    ['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00'],
                    ['variant_id' => $second->id, 'quantity' => '5', 'unit_price' => '300.00'],
                ],
            ])
            ->assertOk();

        // Both, not just the first — somebody correcting a six-line bill should
        // not have to fix it one refusal at a time.
        $response->assertJsonCount(2, 'data.stock')
            ->assertJsonPath('data.can_post', false)
            ->assertJsonPath('data.stock.0.message', 'Only 1 pc available in stock.')
            ->assertJsonPath('data.stock.1.available', '2');

        // And the totals are still there, because the operator needs to see what
        // the bill would come to while they are deciding what to do about it.
        $response->assertJsonPath('data.totals.taxable', '3900.00');
    }

    #[Test]
    public function the_preview_still_reports_shortfalls_when_the_workshop_allows_them(): void
    {
        $this->allowNegativeStock(true);

        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '1', '400.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'sale',
                'party_id' => $customer->id,
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '600.00']],
            ])
            ->assertOk()
            // Allowed is not the same as unremarked: the bill will post, and
            // somebody should still see that the shelf disagrees.
            ->assertJsonCount(1, 'data.stock')
            ->assertJsonPath('data.can_post', true);
    }

    #[Test]
    public function a_purchase_preview_has_no_shortfalls_to_report(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/preview', [
                'type' => 'purchase',
                'party_id' => $vendor->id,
                'items' => [['variant_id' => $this->bearing->id, 'quantity' => '10', 'unit_price' => '400.00']],
            ])
            ->assertOk()
            // Buying puts stock *on* the shelf. There is no such thing as not
            // having enough room.
            ->assertJsonPath('data.stock', [])
            ->assertJsonPath('data.can_post', true);
    }
}
