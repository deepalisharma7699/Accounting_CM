<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Models\Employee;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\StaffDesignation;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A bill saved as a draft can be picked up again, changed, and posted.
 *
 * The form reads the draft back over `GET /transactions/{id}`, rewrites it with
 * `PATCH /transactions/{id}` and authorises it with `POST …/post`. Three things
 * are worth holding shut, because each fails quietly: the draft has to come back
 * with enough to refill the form — the line's name, unit, rate and tax basis,
 * the bill discount, the settled figure, who did the work — or the continued
 * bill posts at a figure nobody typed; an edit has to be able to *clear* a term,
 * because it is merged over the stored request and a key left out would carry
 * the old discount into the new bill; and the edit has to say everything the
 * first save could, or a percentage or an inclusive-of-tax rate is lost on the
 * way through.
 */
class DraftBillContinuationTest extends TestCase
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
            ['DELETE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
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

    /** @param  array<string, mixed>  $body */
    private function draft(string $direction, array $body): int
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$direction}", array_merge([
                'date' => now()->toDateString(),
                'post' => false,
            ], $body))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');
    }

    /** @param  array<string, mixed>  $body */
    private function rewrite(int $id, array $body): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", $body);
    }

    private function read(int $id): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk();
    }

    private function authorise(int $id): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/post");
    }

    /** @return array<string, mixed> */
    private function storedPayload(int $id): array
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->findOrFail($id)->draft_payload ?? [],
        );
    }

    /* ---------------------------------------------------------------------
     | Reading a draft back
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_comes_back_with_everything_the_form_needs_to_continue_it(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        $id = $this->draft('purchase', [
            'party_id' => $vendor->id,
            'vendor_invoice_no' => 'V-118',
            'bill_discount_percent' => '5',
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => '3',
                'unit_price' => '118.00',
                'discount_percent' => '10',
                'price_includes_tax' => true,
                'memo' => 'Front axle',
            ]],
        ]);

        $line = $this->read($id)
            ->assertJsonPath('data.vendor_invoice_no', 'V-118')
            ->assertJsonPath('data.bill_terms.bill_discount', null)
            ->assertJsonPath('data.bill_terms.bill_discount_percent', '5')
            ->assertJsonPath('data.bill_terms.settle_total', null)
            ->json('data.items.0');

        $this->assertSame($this->bearing->item->name, $line['description']);
        $this->assertSame($this->bearing->item_id, $line['item_id']);
        $this->assertSame('18.00', $line['gst_rate']);
        $this->assertNotNull($line['unit_symbol']);
        $this->assertSame('3', $line['quantity']);
        $this->assertSame('118.00', $line['unit_price']);
        $this->assertSame('10', $line['discount_percent']);
        $this->assertTrue($line['price_includes_tax']);
        $this->assertSame('Front axle', $line['memo']);
        // Still unpriced — a draft is taxed when it posts, not when it is read.
        $this->assertNull($line['taxable_value']);
    }

    #[Test]
    public function a_line_that_named_no_tax_basis_comes_back_on_the_items_own(): void
    {
        $this->actingForTenant($this->tenant, fn () => $this->bearing->item->update(['price_includes_tax' => true]));

        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '236.00']],
        ]);

        // The basis it will be taxed on when it posts — BillTemplate's rule.
        $this->read($id)->assertJsonPath('data.items.0.price_includes_tax', true);
    }

    #[Test]
    public function a_listing_does_not_resolve_the_catalogue_behind_every_draft(): void
    {
        $this->draft('purchase', [
            'party_id' => $this->party(PartyRole::Vendor)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $row = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?status=draft')
            ->assertOk()
            ->json('data.0');

        $this->assertNull($row['items'][0]['description']);
    }

    /* ---------------------------------------------------------------------
     | Rewriting it
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_continued_purchase_posts_at_what_the_edit_said_and_moves_the_stock_by_it(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        $id = $this->draft('purchase', [
            'party_id' => $vendor->id,
            'vendor_invoice_no' => 'V-1',
            'bill_discount' => '50.00',
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '100.00']],
        ]);

        // A whole restatement, as the form sends it: every term named, the
        // cleared ones as null.
        $this->rewrite($id, [
            'date' => now()->toDateString(),
            'notes' => 'Second delivery note',
            'party_id' => $vendor->id,
            'vendor_invoice_no' => 'V-2',
            'bill_discount' => null,
            'bill_discount_percent' => null,
            'settle_total' => null,
            'payments' => [],
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => '4',
                'unit_price' => '118.00',
                'discount_percent' => '10',
                'price_includes_tax' => true,
            ]],
        ])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('message', 'Draft updated.');

        $this->authorise($id)
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.vendor_invoice_no', 'V-2')
            ->assertJsonPath('data.notes', 'Second delivery note')
            // 4 × ₹118 inclusive, less 10%: ₹424.80 inclusive, ₹360.00 before tax.
            ->assertJsonPath('data.total', '424.80');

        $this->assertSame(
            ['quantity' => '4.000', 'value' => '360.00', 'average_cost' => '90.00'],
            $this->stockPositionOf($this->tenant, $this->bearing),
            'The stock arrives at the taxable value of the edited line, not the draft it started as.',
        );

        $this->assertBooksBalance($this->tenant);
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
    }

    #[Test]
    public function an_edit_that_switches_the_bill_discount_to_a_percentage_drops_the_rupees(): void
    {
        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'bill_discount' => '100.00',
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '1000.00']],
        ]);

        // Only the new unit is sent. Merged key by key, the old ₹100 would stay.
        $this->rewrite($id, ['bill_discount_percent' => '10'])->assertOk();

        $stored = $this->storedPayload($id);

        $this->assertNull($stored['bill_discount']);
        $this->assertSame('10', (string) $stored['bill_discount_percent']);
    }

    #[Test]
    public function an_edit_can_clear_a_short_settlement(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '5', '400.00');

        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'settle_total' => '1100.00',
            'payments' => [['mode' => 'cash', 'amount' => '1100.00']],
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '1000.00']],
        ]);

        $this->read($id)->assertJsonPath('data.bill_terms.settle_total', '1100.00');

        // Put on credit instead: no split and no settled figure.
        $this->rewrite($id, ['settle_total' => null, 'payments' => []])->assertOk();

        $this->assertNull($this->storedPayload($id)['settle_total']);

        $this->authorise($id)
            ->assertOk()
            // No discount was given after all — the full ₹1,180.
            ->assertJsonPath('data.total', '1180.00')
            ->assertJsonPath('data.paid_on_document', '0.00');
    }

    #[Test]
    public function an_edit_keeps_what_it_did_not_mention(): void
    {
        $id = $this->draft('purchase', [
            'party_id' => $this->party(PartyRole::Vendor)->id,
            'vendor_invoice_no' => 'KEEP-1',
            'bill_discount' => '20.00',
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '100.00']],
        ]);

        $this->rewrite($id, ['notes' => 'Only the note'])->assertOk();

        $this->read($id)
            ->assertJsonPath('data.notes', 'Only the note')
            ->assertJsonPath('data.vendor_invoice_no', 'KEEP-1')
            ->assertJsonPath('data.bill_terms.bill_discount', '20.00')
            ->assertJsonPath('data.items.0.quantity', '2');
    }

    #[Test]
    public function an_edit_stops_storing_the_old_ledger_lines_inside_the_request(): void
    {
        $id = $this->draft('purchase', [
            'party_id' => $this->party(PartyRole::Vendor)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '100.00']],
        ]);

        $this->rewrite($id, ['notes' => 'Edited'])->assertOk();

        $this->assertArrayNotHasKey('lines', $this->storedPayload($id));
    }

    #[Test]
    public function an_edit_refuses_both_units_of_one_discount(): void
    {
        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $this->rewrite($id, [
            'items' => [[
                'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '100.00',
                'discount' => '5.00', 'discount_percent' => '5',
            ]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->rewrite($id, ['bill_discount' => '5.00', 'bill_discount_percent' => '5'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function a_bills_terms_are_refused_on_a_draft_that_is_not_a_bill(): void
    {
        $receipt = $this->receiveFrom(
            $this->tenant,
            $this->party(PartyRole::Customer),
            [['cash', '100.00']],
            post: false,
        );

        $this->rewrite($receipt->id, ['bill_discount' => '10.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_BILL_TERMS_NOT_ACCEPTED');
    }

    #[Test]
    public function an_empty_split_is_still_refused_on_a_settlement_draft(): void
    {
        // The edit accepts `[]` now, because a bill on credit has none. A receipt
        // is meaningless without one, and its template is what says so.
        $receipt = $this->receiveFrom(
            $this->tenant,
            $this->party(PartyRole::Customer),
            [['cash', '100.00']],
            post: false,
        );

        $this->rewrite($receipt->id, ['payments' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYMENT_SPLIT_REQUIRED');
    }

    #[Test]
    public function a_vendors_number_is_refused_on_a_sale_draft(): void
    {
        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $this->rewrite($id, ['vendor_invoice_no' => 'V-9'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_BILL_TERMS_NOT_ACCEPTED');
    }

    #[Test]
    public function a_posted_bill_still_cannot_be_edited(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '5', '100.00');

        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '300.00']],
        ]);

        $this->authorise($id)->assertOk();

        $this->rewrite($id, ['notes' => 'After the fact'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TRANSACTION_IMMUTABLE');
    }

    #[Test]
    public function editing_a_draft_needs_the_update_grant(): void
    {
        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        // Raising a bill is WRITE; rewriting one is UPDATE, the same line the
        // route draws for posting a draft and for correcting a posted bill.
        [, $clerk] = $this->tenantWithUser([['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS']]);

        $this->withHeaders($this->authHeader($clerk))
            ->patchJson("/api/v1/transactions/{$id}", ['notes' => 'x'])
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Who did the work, and what posting says afterwards
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_edit_rewrites_who_did_the_work_on_a_sale_draft(): void
    {
        [$fitter, $ramesh, $sunil] = $this->actingForTenant($this->tenant, function () {
            $fitter = StaffDesignation::create(['name' => 'Fitter', 'track_on_sales' => true]);

            $hire = fn (string $name) => Employee::create([
                'name' => $name,
                'designation_id' => $fitter->id,
                'salary_basis' => 'monthly',
                'pay_rate' => '18000.00',
                'joined_on' => '2026-01-01',
            ]);

            return [$fitter, $hire('Ramesh'), $hire('Sunil')];
        });

        $this->receiveStock($this->tenant, $this->bearing, '5', '100.00');

        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '300.00']],
            'staff' => [['designation_id' => $fitter->id, 'employee_id' => $ramesh->id]],
        ]);

        $this->read($id)->assertJsonPath('data.staff.0.employee_id', $ramesh->id);

        $this->rewrite($id, ['staff' => [['designation_id' => $fitter->id, 'employee_id' => $sunil->id]]])
            ->assertOk()
            ->assertJsonPath('data.staff.0.employee_id', $sunil->id)
            ->assertJsonCount(1, 'data.staff');

        $this->authorise($id)->assertOk()->assertJsonPath('data.staff.0.employee', 'Sunil');

        // And an emptied box removes the name, on a draft as on a correction.
        $second = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '300.00']],
            'staff' => [['designation_id' => $fitter->id, 'employee_id' => $ramesh->id]],
        ]);

        $this->rewrite($second, ['staff' => [['designation_id' => $fitter->id, 'employee_id' => null]]])
            ->assertOk()
            ->assertJsonCount(0, 'data.staff');
    }

    #[Test]
    public function who_did_the_work_is_refused_on_a_purchase_draft_and_nothing_is_saved(): void
    {
        $id = $this->draft('purchase', [
            'party_id' => $this->party(PartyRole::Vendor)->id,
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $this->rewrite($id, [
            'notes' => 'Should not stick',
            'staff' => [['designation_id' => 1, 'employee_id' => 1]],
        ])->assertStatus(422);

        $this->read($id)->assertJsonPath('data.notes', null);
    }

    #[Test]
    public function posting_a_draft_returns_the_warnings_a_bill_posted_outright_would(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '5', '500.00');

        $id = $this->draft('sale', [
            'party_id' => $this->party(PartyRole::Customer)->id,
            // Below the ₹500 it cost.
            'items' => [['variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '200.00']],
        ]);

        $warnings = $this->authorise($id)->assertOk()->json('meta.warnings');

        $this->assertNotEmpty($warnings);
    }
}
