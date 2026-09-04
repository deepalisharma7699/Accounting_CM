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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A counter's day through the Sales module — §8.2.
 *
 * Every act here is already tested where it lives: `BillPostingTest` proves a
 * sale issues stock, `ReturnTest` proves a credit note puts it back at what it
 * left at, `SaleCorrectionTest` proves a correction moves the difference, and
 * `SettlementApiTest` proves a receipt discharges what is due. What none of
 * them does is put those acts in the order a counter performs them, against one
 * shelf, and ask whether the shelf and the Inventory account still agree at the
 * end.
 *
 * That is the question §8.2 asks — verify the actual stock impact, not just
 * that the request succeeded — and it is a different question from any of the
 * four. An error inside one act cancels itself within that act's own test and
 * shows up only against the next one: a correction that puts back a quantity it
 * valued at today's average, a credit note measured against an original that a
 * replacement has taken the place of, a receipt that touches the shelf at all.
 *
 * Only the endpoints `pages/sales.js` itself calls are used, in the order its
 * drawer offers them, so a passing run says the module's own path is sound
 * rather than that some path is.
 *
 * @see \App\Services\Accounting\PostingEngine
 * @see \Tests\Feature\Accounting\SaleCorrectionTest
 */
class SalesFlowTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $bearing;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['DELETE', 'TRANSACTIONS'], ['READ', 'LEDGER'], ['READ', 'PARTIES'],
            ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part');

        $this->customer = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => 'Ravi Rewinding Works',
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }

    /* ---------------------------------------------------------------------
     | Harness — the module's own calls, nothing else
     |-------------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function sell(string $quantity, string $rate = '600.00', array $payments = []): int
    {
        return (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => $rate,
                ]],
                'payments' => $payments,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * The drawer's Collect — pointed at the invoice it was opened from, which
     * is what `pages/sales.js` sends rather than letting the oldest open bill
     * take the money.
     */
    private function collect(int $billId, string $amount): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->customer->id,
                'payments' => [['mode' => 'cash', 'amount' => $amount]],
                'allocations' => [['bill_transaction_id' => $billId]],
            ])
            ->assertCreated();
    }

    /** The drawer's Sales return. */
    private function creditBack(int $billId, string $quantity, int $lineNo = 1): TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$billId}/return", [
                'lines' => [['line_no' => $lineNo, 'quantity' => $quantity]],
                'client_ref' => (string) Str::uuid(),
            ]);
    }

    /** The drawer's Correct. */
    private function correct(int $billId, string $quantity, string $rate = '600.00'): int
    {
        return (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$billId}/revise", [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => $rate,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function onHand(): string
    {
        return $this->stockPositionOf($this->tenant, $this->bearing)['quantity'];
    }

    private function due(int $billId): string
    {
        return (string) $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$billId}")
            ->assertOk()
            ->json('data.due');
    }

    /* ---------------------------------------------------------------------
     | The ordinary day
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_days_selling_collecting_and_crediting_leaves_the_shelf_where_the_books_say(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $this->assertSame('10.000', $this->onHand());

        // Two invoices over the counter, the first part paid in cash.
        $morning = $this->sell('4', '600.00', [['mode' => 'cash', 'amount' => '1000.00']]);

        $this->assertSame('6.000', $this->onHand(), 'Four bearings left the shelf.');

        $afternoon = $this->sell('3');

        $this->assertSame('3.000', $this->onHand(), 'Three more did.');

        // The drawer's Collect, opened from the *afternoon* invoice while the
        // morning is still part paid. The drawer names the invoice it was
        // opened from, so the money must land there and not on the older bill
        // the oldest-first default would have chosen.
        $morningOwed = $this->due($morning);
        $afternoonOwed = $this->due($afternoon);

        $this->assertNotSame('0.00', $morningOwed);
        $this->assertNotSame('0.00', $afternoonOwed);

        $this->collect($afternoon, $afternoonOwed);

        $this->assertSame('0.00', $this->due($afternoon), 'The invoice it was collected against is settled.');
        $this->assertSame($morningOwed, $this->due($morning), 'And the older one is untouched.');

        // A payment is the act most likely to be wired to the wrong service,
        // and the symptom would be stock moving when nobody sold anything.
        $this->assertSame('3.000', $this->onHand(), 'Collecting money moves no stock.');

        // One comes back off the afternoon invoice.
        $this->creditBack($afternoon, '1')->assertCreated();

        $this->assertSame('4.000', $this->onHand(), 'The returned bearing is back on the shelf.');

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a day of selling');
        $this->assertBooksBalance($this->tenant, 'after a day of selling');
    }

    /* ---------------------------------------------------------------------
     | The same day, with a correction in the middle of it
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_credit_note_against_a_corrected_invoice_measures_the_replacement(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        // Five went out; the slip said three.
        $original = $this->sell('5');

        $this->assertSame('5.000', $this->onHand());

        $replacement = $this->correct($original, '3');

        $this->assertSame('7.000', $this->onHand(), 'The two that were never sold are back.');

        // It is the *replacement* that has three left to credit. Measuring
        // against the original would allow five, and put stock back that the
        // correction has already returned.
        $this->creditBack($replacement, '3')->assertCreated();

        $this->assertSame('10.000', $this->onHand(), 'Everything is back on the shelf.');

        $this->creditBack($replacement, '1')->assertStatus(422);

        $this->assertSame('10.000', $this->onHand(), 'A refused return moves nothing.');

        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a correction and a credit note');
        $this->assertBooksBalance($this->tenant, 'after a correction and a credit note');
    }

    #[Test]
    public function the_cancelled_original_of_a_correction_cannot_be_credited_against(): void
    {
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $original = $this->sell('5');

        $this->correct($original, '3');

        // A drawer opened from a list fetched before the correction is looking
        // at a document that is no longer in the books. Taking goods back
        // against it would credit the customer for bearings the correction has
        // already put back.
        $this->creditBack($original, '1')->assertStatus(422);

        $this->assertSame('7.000', $this->onHand());

        $reversed = $this->actingForTenant($this->tenant, fn () => Transaction::findOrFail($original));

        $this->assertSame('reversed', $reversed->status->value);

        $this->assertStockAgreesWithInventoryAccount($this->tenant);
        $this->assertBooksBalance($this->tenant);
    }
}
