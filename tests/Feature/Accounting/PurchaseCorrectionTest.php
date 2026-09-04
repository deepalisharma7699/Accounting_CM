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
 * Correcting a purchase after it is in the books — the two acts the Purchase
 * module offers for a mistake, and the guard both of them now carry.
 *
 * The failure being tested against is a real one and it was silent: a purchase
 * of ten reversed after seven had left the shelf by another route posted a
 * position of minus seven, a negative Inventory valuation and a store-wide stock
 * value below zero, with nothing said at the moment somebody could still have
 * chosen the debit note instead.
 *
 * Both halves matter. The refusal has to fire, and it has to stay *out of the
 * way* of everything it was not aimed at — a sale's reversal, a stock take, a
 * workshop that has turned negative stock on.
 */
class PurchaseCorrectionTest extends TestCase
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
            ['READ', 'ITEMS'], ['READ', 'STOCK'],
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

    /** A posted purchase bill for the given quantity, through the real endpoint. */
    private function postPurchase(Party $vendor, string $quantity, string $rate = '150.00'): int
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => $rate,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function quantityOnHand(): string
    {
        return $this->stockPositionOf($this->tenant, $this->bearing)['quantity'];
    }

    /* ---------------------------------------------------------------------
     | F1 — reversing must not silently drive the shelf negative
     |-------------------------------------------------------------------- */

    #[Test]
    public function reversing_a_purchase_whose_stock_has_gone_is_refused(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        // The stock leaves by another route — a count correction here, a sale in
        // a build that has one. Either way the shelf now holds three.
        $this->issueStock($this->tenant, $this->bearing, '7');
        $this->assertSame('3.000', $this->quantityOnHand());

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/reverse")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVERSAL_WOULD_GO_NEGATIVE')
            ->assertJsonPath('error.details.shortfalls.0.available', '3')
            ->assertJsonPath('error.details.shortfalls.0.requested', '10')
            ->assertJsonPath('error.details.shortfalls.0.shortfall', '7');
    }

    #[Test]
    public function a_refused_reversal_writes_nothing(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');
        $this->issueStock($this->tenant, $this->bearing, '7');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/reverse")
            ->assertStatus(422);

        // The shelf is untouched, the bill is still posted rather than reversed,
        // and no reversing document took a number.
        $this->assertSame('3.000', $this->quantityOnHand());

        $this->actingForTenant($this->tenant, function () use ($bill) {
            $this->assertSame('posted', Transaction::query()->findOrFail($bill)->status->value);
            $this->assertSame(0, Transaction::query()->whereNotNull('reverses_id')->count());
        });

        $this->assertBooksBalance($this->tenant);
    }

    /**
     * The refusal has a way through, and it is the way M17 chose for the same
     * question on a bill: a rule nobody can get past is a rule people work
     * around by not recording the correction at all.
     */
    #[Test]
    public function an_explicit_acknowledgement_reverses_it_anyway(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');
        $this->issueStock($this->tenant, $this->bearing, '7');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/reverse", ['acknowledge_negative_stock' => true])
            ->assertCreated();

        $this->assertSame('-7.000', $this->quantityOnHand());
        $this->assertBooksBalance($this->tenant);
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
    }

    #[Test]
    public function a_reversal_that_leaves_stock_whole_is_untouched_by_the_guard(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/reverse")
            ->assertCreated();

        $this->assertSame('0.000', $this->quantityOnHand());
        $this->assertBooksBalance($this->tenant);
    }

    /**
     * A workshop that has said it bills ahead of its paperwork has already
     * answered this question. Asking it again per reversal would be the setting
     * failing to mean anything.
     */
    #[Test]
    public function the_negative_stock_setting_lifts_the_refusal(): void
    {
        $this->actingForTenant(
            $this->tenant,
            fn () => $this->tenant->forceFill(['allow_negative_stock' => true])->save()
        );

        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');
        $this->issueStock($this->tenant, $this->bearing, '7');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/reverse")
            ->assertCreated();

        $this->assertSame('-7.000', $this->quantityOnHand());
    }

    /**
     * The guard is aimed at purchases and must stay off everything else. A sale's
     * reversal puts stock *back*, and a stock adjustment is the only tool for
     * repairing a position that has already gone negative — gating either would
     * take away the instrument the repair depends on.
     */
    #[Test]
    public function reversing_a_sale_is_unaffected(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $sale = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '10',
                    'unit_price' => '600.00',
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('0.000', $this->quantityOnHand());

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale}/reverse")
            ->assertCreated();

        $this->assertSame('10.000', $this->quantityOnHand());
    }

    #[Test]
    public function reversing_a_stock_adjustment_is_unaffected(): void
    {
        $adjustment = $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $this->issueStock($this->tenant, $this->bearing, '7');

        // Reversing the arrival takes ten back off a shelf holding three. It is
        // allowed, because a stock take is the workshop asserting what is
        // physically there and it answers to nobody.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$adjustment->id}/reverse")
            ->assertCreated();

        $this->assertSame('-7.000', $this->quantityOnHand());
    }

    /**
     * The other half of the same question, and it needed no new code — a debit
     * note issues stock through the ordinary posting path, so M17's refusal
     * already applies to it. Asserted rather than assumed, because "Reverse and
     * Return are both guarded" is the claim, and only one of them was.
     */
    #[Test]
    public function sending_back_more_than_is_still_on_the_shelf_is_already_refused(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $this->issueStock($this->tenant, $this->bearing, '7');

        $line = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$bill}")
            ->assertOk()
            ->json('data.items.0');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/return", [
                'lines' => [['line_no' => $line['line_no'], 'quantity' => '10']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');

        $this->assertSame('3.000', $this->quantityOnHand());
    }

    /* ---------------------------------------------------------------------
     | F4 — a reversal is not a stock count
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_purchase_reversal_names_itself_on_the_stock_card(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/reverse")
            ->assertCreated();

        // Also post a genuine count correction, because the whole point is that
        // the two are told apart — both are stored as `adjust`.
        $this->adjustStock($this->tenant, [[$this->bearing, '4', '150.00']]);

        $movements = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/stock/variants/{$this->bearing->id}")
            ->assertOk()
            ->json('data.movements');

        $labels = array_column($movements, 'source_label');

        $this->assertContains('Purchase reversed', $labels, 'the reversal should name itself');

        // Exactly one row is labelled: the count correction stays an ordinary
        // adjustment, and the purchase receipt stays an ordinary arrival.
        $this->assertSame(1, count(array_filter($labels)));

        // And the document behind it is followable, which is the traceability a
        // purchase receipt already had and its reversal did not.
        $reversal = collect($movements)->firstWhere('source_label', 'Purchase reversed');

        $this->assertNotNull($reversal['transaction']['doc_no']);
        $this->assertSame($bill, $reversal['transaction']['reverses_id']);
    }

    /* ---------------------------------------------------------------------
     | F5 — correcting a posted bill
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $extra
     */
    private function revise(int $bill, Party $vendor, string $quantity, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/revise", array_merge([
                'date' => now()->toDateString(),
                // The corrected document is validated by the same form request a
                // new bill is, which asks for this explicitly. A correction is
                // always posted — the controller says so and overrides it — but
                // the field is still part of the shape.
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => '150.00',
                ]],
            ], $extra));
    }

    #[Test]
    public function correcting_a_bill_moves_stock_by_the_difference(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '20');

        $this->assertSame('20.000', $this->quantityOnHand());

        // The brief's own editing case: 20 was typed where 30 was delivered, and
        // what is expected is a delta of ten rather than a second bill.
        $this->revise($bill, $vendor, '30')->assertCreated();

        $this->assertSame('30.000', $this->quantityOnHand());
        $this->assertBooksBalance($this->tenant);
        $this->assertStockAgreesWithInventoryAccount($this->tenant);
    }

    #[Test]
    public function correcting_a_bill_leaves_both_documents_on_the_record(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '20');

        $revision = $this->revise($bill, $vendor, '30')->json('data.id');

        $this->actingForTenant($this->tenant, function () use ($bill, $revision) {
            // The original is reversed rather than edited or deleted, the
            // reversal points at it, and the replacement is a document of its own
            // with its own number.
            $this->assertSame('reversed', Transaction::query()->findOrFail($bill)->status->value);
            $this->assertSame(1, Transaction::query()->where('reverses_id', $bill)->count());

            $replacement = Transaction::query()->findOrFail($revision);

            $this->assertSame('posted', $replacement->status->value);
            $this->assertNull($replacement->reverses_id);
            $this->assertNotNull($replacement->doc_no);
        });
    }

    #[Test]
    public function a_correction_below_what_has_already_gone_out_is_refused_and_rolls_back(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $this->issueStock($this->tenant, $this->bearing, '7');

        // Correcting ten down to three leaves the shelf at minus four, because
        // seven have already gone. The intermediate state — the reversal alone —
        // is not what is checked; the result is.
        $this->revise($bill, $vendor, '3')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVERSAL_WOULD_GO_NEGATIVE');

        // Nothing happened at all: the original still stands, unreversed.
        $this->assertSame('3.000', $this->quantityOnHand());

        $this->actingForTenant($this->tenant, function () use ($bill) {
            $this->assertSame('posted', Transaction::query()->findOrFail($bill)->status->value);
            $this->assertSame(0, Transaction::query()->whereNotNull('reverses_id')->count());
        });

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_correction_that_raises_the_quantity_is_not_refused_over_the_middle_of_itself(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $this->issueStock($this->tenant, $this->bearing, '7');

        // The reversal on its own would take the shelf to minus seven, which the
        // plain Reverse refuses. As a correction it is fine: the replacement puts
        // twelve back in the same breath.
        $this->revise($bill, $vendor, '12')->assertCreated();

        $this->assertSame('5.000', $this->quantityOnHand());
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function correcting_a_bill_that_has_been_paid_is_refused(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        $bill = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '10',
                    'unit_price' => '150.00',
                ]],
                'payments' => [['mode' => 'cash', 'amount' => '500.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->revise($bill, $vendor, '12')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TRANSACTION_SETTLED');

        $this->assertSame('10.000', $this->quantityOnHand());
    }

    #[Test]
    public function correcting_a_bill_with_a_debit_note_against_it_is_refused(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $line = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$bill}")
            ->assertOk()
            ->json('data.items.0');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/return", [
                'lines' => [['line_no' => $line['line_no'], 'quantity' => '2']],
            ])
            ->assertCreated();

        // Reversing the bill now would leave the debit note pointing at a
        // cancelled document — the same orphan a payment would be.
        $this->revise($bill, $vendor, '12')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TRANSACTION_RETURNED_AGAINST');

        $this->assertSame('8.000', $this->quantityOnHand());
    }

    /**
     * Only a **bill** can be corrected — a purchase or an invoice. An invoice
     * became correctable in M20, on stricter terms; see {@see SaleCorrectionTest}.
     *
     * A note never can, on either side. It is already the correction to
     * something else, so correcting one is a decision about which correction
     * stands — which is a reversal and a fresh note, deliberately taken.
     */
    #[Test]
    public function a_debit_note_cannot_itself_be_corrected(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        $debitNote = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill}/return", [
                'lines' => [['line_no' => 1, 'quantity' => '2']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$debitNote}/revise", [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '1',
                    'unit_price' => '150.00',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_REVISABLE');
    }

    /**
     * Nor can anything that is not a bill at all. A journal has no document
     * lines to correct and a receipt is money that moved.
     */
    #[Test]
    public function a_settlement_cannot_be_corrected(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $this->postPurchase($vendor, '10');

        $payment = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/payment', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'payments' => [['mode' => 'cash', 'amount' => '500.00']],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$payment}/revise", [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '1',
                    'unit_price' => '150.00',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_REVISABLE');
    }

    #[Test]
    public function a_correction_is_validated_as_strictly_as_the_bill_it_replaces(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '10');

        // The server's own `gt:0`, which the form now says out loud rather than
        // filtering the line out and going quiet — see `lineProblem`.
        $fields = $this->revise($bill, $vendor, '-5')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->json('error.details.fields');

        // Read out of the envelope rather than through `assertJsonValidationErrors`,
        // because this API does not use Laravel's default `errors` key — and the
        // field name contains dots of its own, which a path assertion would read
        // as nesting.
        $this->assertSame(
            ['Each line needs a quantity greater than zero.'],
            $fields['items.0.quantity'] ?? null,
        );

        $this->assertSame('10.000', $this->quantityOnHand());
    }

    #[Test]
    public function tapping_correct_twice_corrects_once(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $bill = $this->postPurchase($vendor, '20');

        $ref = (string) \Illuminate\Support\Str::uuid();

        $first = $this->revise($bill, $vendor, '30', ['client_ref' => $ref])
            ->assertCreated()
            ->json('data.id');

        // The second tap answers with the document the first one wrote, rather
        // than finding the original already reversed and refusing a correction
        // that in fact worked.
        $this->revise($bill, $vendor, '30', ['client_ref' => $ref])
            ->assertOk()
            ->assertJsonPath('data.id', $first);

        $this->assertSame('30.000', $this->quantityOnHand());
    }

    /* ---------------------------------------------------------------------
     | What a list means by "lines"
     |
     | Two real questions with different answers. The Journal asks how many
     | entries a transaction made; a purchase list asks how many things were
     | bought. The list had been printing the first under a heading meaning the
     | second, so every single-item bill read one or two higher than the rows its
     | own detail view showed.
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_bill_reports_its_document_rows_apart_from_its_ledger_entries(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $id = $this->postPurchase($vendor, '4');

        $row = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=purchase')
            ->assertOk()
            ->json('data.0');

        $this->assertSame($id, $row['id']);

        // One thing bought.
        $this->assertSame(1, $row['item_line_count']);

        // Dr Inventory / Dr GST Input / Cr Payables — the ledger's count, kept
        // as it was because the Journal reads it and means exactly this.
        $this->assertSame(3, $row['line_count']);
    }

    #[Test]
    public function a_two_line_bill_reports_two_document_rows(): void
    {
        $vendor = $this->party(PartyRole::Vendor);
        $second = $this->variantFor($this->tenant, 'part');

        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [
                    ['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '100.00'],
                    ['variant_id' => $second->id, 'quantity' => '3', 'unit_price' => '50.00'],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $row = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=purchase')
            ->assertOk()
            ->json('data.0');

        $this->assertSame($id, $row['id']);
        $this->assertSame(2, $row['item_line_count']);
    }
}
