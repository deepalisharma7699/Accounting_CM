<?php

namespace Tests\Feature\Accounting;

use App\Enums\DocumentSeries;
use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\DocumentSequence;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The number printed on the document — M16.
 *
 * What is worth watching here is not that numbering works. It is the three
 * properties that make a number series worth having at all: it is never reused,
 * it never skips, and a draft never takes one.
 */
class DocumentNumberingTest extends TestCase
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
            ['DELETE', 'TRANSACTIONS'], ['READ', 'LEDGER'], ['READ', 'PARTIES'],
        ]);
    }

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    /**
     * The financial year the workshop is in today, rendered the way an invoice
     * renders it — computed rather than hard-coded so this test does not start
     * failing every April.
     */
    private function financialYear(): string
    {
        [$start, $end] = $this->tenant->financialYearFor(now());

        return $start->format('y').'-'.$end->format('y');
    }

    /* ---------------------------------------------------------------------
     | Issuing
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_posted_sale_takes_the_first_number_in_the_invoice_series(): void
    {
        $motor = $this->variantFor($this->tenant, 'motor');
        $customer = $this->party(PartyRole::Customer);

        $this->receiveStock($this->tenant, $motor, '4', '8000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [['variant_id' => $motor->id, 'quantity' => '1', 'unit_price' => '10000.00']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.doc_no', 'INV/'.$this->financialYear().'/1001');
    }

    #[Test]
    public function each_series_counts_separately(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $vendor = $this->party(PartyRole::Vendor);

        // A receipt and a payment. Their numbers are both the first of their own
        // series rather than 1001 and 1002 of one shared counter — which is the
        // whole reason the series is part of the key, and what keeps the invoice
        // run consecutive when GST asks.
        $this->receiveFrom($this->tenant, $customer, [['cash', '500.00']]);
        $this->payVendor($this->tenant, $vendor, [['cash', '700.00']]);

        $numbers = $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->orderBy('id')->pluck('doc_no', 'type')->all()
        );

        $year = $this->financialYear();

        $this->assertSame("RCT/{$year}/1001", $numbers['receipt']);
        $this->assertSame("PAY/{$year}/1001", $numbers['payment']);
    }

    #[Test]
    public function numbers_run_consecutively_and_are_never_reused(): void
    {
        $customer = $this->party(PartyRole::Customer);

        for ($i = 0; $i < 5; $i++) {
            $this->receiveFrom($this->tenant, $customer, [['cash', '100.00']]);
        }

        $numbers = $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->orderBy('id')->pluck('doc_no')->all()
        );

        $year = $this->financialYear();

        $this->assertSame([
            "RCT/{$year}/1001",
            "RCT/{$year}/1002",
            "RCT/{$year}/1003",
            "RCT/{$year}/1004",
            "RCT/{$year}/1005",
        ], $numbers);

        // And the counter is left pointing at the next one, not at the last one
        // issued — the off-by-one that would hand 1005 out twice.
        $this->assertSame(1006, $this->actingForTenant(
            $this->tenant,
            fn () => (int) DocumentSequence::query()
                ->where('series', DocumentSeries::Receipt->value)
                ->value('next')
        ));
    }

    /**
     * The property the whole locking arrangement exists to guarantee, asserted
     * where nothing in the application can bypass it.
     *
     * A genuinely concurrent test would need two connections and would prove less
     * than this does: the lock narrows the *window*, and the unique index is what
     * makes a duplicate impossible even if the window were somehow missed.
     */
    #[Test]
    public function the_database_refuses_a_duplicate_number_within_a_workshop(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $first = $this->receiveFrom($this->tenant, $customer, [['cash', '100.00']]);
        $second = $this->receiveFrom($this->tenant, $customer, [['cash', '200.00']]);

        $this->expectException(UniqueConstraintViolationException::class);

        // Forced past the model's own immutability guard, because that guard is
        // not the thing under test — the index is.
        $this->actingForTenant($this->tenant, fn () => Transaction::query()
            ->where('id', $second->id)
            ->update(['doc_no' => $first->doc_no]));
    }

    #[Test]
    public function two_workshops_may_both_issue_the_same_number(): void
    {
        $mine = $this->party(PartyRole::Customer);
        $this->receiveFrom($this->tenant, $mine, [['cash', '100.00']]);

        [$other] = $this->tenantWithUser([['READ', 'TRANSACTIONS']]);
        $theirs = $this->actingForTenant($other, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
        ]));

        $received = $this->receiveFrom($other, $theirs, [['cash', '100.00']]);

        // Different businesses, so the same number on both is correct rather
        // than a collision — which is why the unique index is per tenant.
        $this->assertSame('RCT/'.$this->financialYear().'/1001', $received->doc_no);
    }

    /* ---------------------------------------------------------------------
     | Drafts, and gaps
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_has_no_number_until_it_is_posted(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '500.00']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.doc_no', null);

        // Nothing has been taken from the counter either — a series that
        // advanced for a draft would leave a gap the moment somebody discarded
        // it, and a gap is what an auditor asks about.
        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => DocumentSequence::count()));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/'.$draft->json('data.id').'/post')
            ->assertOk()
            ->assertJsonPath('data.doc_no', 'RCT/'.$this->financialYear().'/1001');
    }

    #[Test]
    public function a_discarded_draft_leaves_no_gap_in_the_series(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '500.00']],
            ])
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$draft}")
            ->assertOk();

        $posted = $this->receiveFrom($this->tenant, $customer, [['cash', '500.00']]);

        $this->assertSame('RCT/'.$this->financialYear().'/1001', $posted->doc_no);
    }

    /**
     * A number is taken inside the same database transaction that writes the
     * posting, so a posting that fails puts it back.
     */
    #[Test]
    public function a_refused_posting_consumes_no_number(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $this->receiveFrom($this->tenant, $customer, [['cash', '100.00']]);

        // Refused by the engine: an archived account cannot be posted to. The
        // refusal happens after the batch is composed and before it commits,
        // which is exactly the window a number could leak through.
        $this->actingForTenant($this->tenant, function () {
            $cash = $this->accountFor($this->tenant, SystemAccount::Cash);
            $cash->forceFill(['is_active' => false])->save();
        });

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '500.00']],
            ])
            ->assertStatus(422);

        $this->assertSame(1002, $this->actingForTenant(
            $this->tenant,
            fn () => (int) DocumentSequence::query()
                ->where('series', DocumentSeries::Receipt->value)
                ->value('next')
        ));
    }

    /* ---------------------------------------------------------------------
     | Reading it back
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_transaction_list_can_be_searched_by_document_number(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $this->receiveFrom($this->tenant, $customer, [['cash', '100.00']]);
        $wanted = $this->receiveFrom($this->tenant, $customer, [['cash', '200.00']]);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?search='.urlencode((string) $wanted->doc_no))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doc_no', $wanted->doc_no);
    }
}
