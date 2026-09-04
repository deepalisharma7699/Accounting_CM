<?php

namespace Tests\Feature\Workshop;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\WorkshopJobStatus;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkshopJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Workshop jobs — M19, and the brief's §16 to §18 and §34.
 *
 * Three things are being established here, and everything below is a variation
 * on one of them.
 *
 * **A job is not a document.** It exists before any money does, it has statuses
 * about a physical object, and nothing about it reaches the ledger until
 * somebody bills it.
 *
 * **Stock moves exactly once, at billing.** Adding a part to a job moves
 * nothing. This is the invariant the whole inventory module rests on, arrived at
 * from a new direction, so it is asserted at every step rather than once at the
 * end.
 *
 * **A cancelled job bills nothing** — scenario 10 — and a job cannot be billed
 * twice for the same part.
 */
class WorkshopJobTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $bearing;

    private ItemVariant $labour;

    private Party $customer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'WORKSHOP_JOBS'], ['WRITE', 'WORKSHOP_JOBS'],
            ['UPDATE', 'WORKSHOP_JOBS'], ['DELETE', 'WORKSHOP_JOBS'],
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part', sellPrice: '450.00');
        $this->labour = $this->serviceVariantFor($this->tenant, '1200.00');
        $this->customer = $this->party(PartyRole::Customer);
    }

    private function party(PartyRole ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => array_map(fn (PartyRole $role) => $role->value, $roles),
            'state_code' => '27',
        ]));
    }

    /* ---------------------------------------------------------------------
     | The API, as the counter reaches it
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bookIn(array $overrides = []): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', array_merge([
                'party_id' => $this->customer->id,
                'hp' => '7.5',
                'brand' => 'Crompton',
                'phase' => '3-phase',
                'complaint' => 'Winding burnt, not starting',
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    private function advance(int $jobId, WorkshopJobStatus $to): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->putJson("/api/v1/workshop-jobs/{$jobId}/status", ['status' => $to->value]);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function addPart(int $jobId, array $part): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$jobId}/parts", $part);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function bill(int $jobId, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$jobId}/bill", $body);
    }

    private function show(int $jobId): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/workshop-jobs/{$jobId}")
            ->assertOk()
            ->json('data');
    }

    /**
     * Stock bought in rather than adjusted in, so COGS starts at zero and the
     * assertions below say what they mean.
     */
    private function buyBearings(string $quantity, string $unitPrice): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $this->party(PartyRole::Vendor)->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]],
            ])
            ->assertCreated();
    }

    /* ---------------------------------------------------------------------
     | Booking in
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_motor_is_booked_in_with_a_number_and_nothing_else_happens(): void
    {
        $job = $this->bookIn();

        $this->assertSame(WorkshopJobStatus::Received->value, $job['status']);
        $this->assertStringStartsWith('JOB/', $job['job_no']);
        $this->assertSame('7.5 HP Crompton 3-phase', $job['motor']);

        // The whole point of D1 and D2 in one assertion: booking a motor in is
        // not an accounting event, so nothing at all has reached the books.
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after booking a job in');
    }

    #[Test]
    public function job_numbers_are_consecutive_within_a_workshop(): void
    {
        $first = $this->bookIn();
        $second = $this->bookIn();

        $this->assertNotSame($first['job_no'], $second['job_no']);

        // Same series, same year, next number — the counter behind an invoice
        // number, reused.
        $this->assertSame(
            (int) substr(strrchr($first['job_no'], '/'), 1) + 1,
            (int) substr(strrchr($second['job_no'], '/'), 1),
        );
    }

    #[Test]
    public function a_motor_cannot_be_booked_in_against_a_vendor(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $vendor->id,
                'complaint' => 'Winding burnt',
            ])
            // The refusal lands while the motor is still on the counter rather
            // than a fortnight later when somebody tries to bill it.
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_ROLE_MISMATCH');
    }

    #[Test]
    public function a_job_needs_a_complaint(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', ['party_id' => $this->customer->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath(
                'error.details.fields.complaint.0',
                'Say what the customer reported — it is what the job is for.',
            );
    }

    /* ---------------------------------------------------------------------
     | The pipeline
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_job_moves_along_the_pipeline(): void
    {
        $job = $this->bookIn();

        foreach ([
            WorkshopJobStatus::Inspection,
            WorkshopJobStatus::Estimate,
            WorkshopJobStatus::InProgress,
            WorkshopJobStatus::Ready,
            WorkshopJobStatus::Delivered,
        ] as $status) {
            $this->advance($job['id'], $status)
                ->assertOk()
                ->assertJsonPath('data.status', $status->value);
        }

        // Reaching `delivered` stamps when the motor actually left — one fact
        // wearing two hats, a filter and a date somebody quotes down the phone.
        $this->assertNotNull($this->show($job['id'])['delivered_at']);
    }

    #[Test]
    public function an_illegal_jump_is_refused(): void
    {
        $job = $this->bookIn();

        $this->advance($job['id'], WorkshopJobStatus::Delivered)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_TRANSITION_INVALID');

        $this->assertSame(WorkshopJobStatus::Received->value, $this->show($job['id'])['status']);
    }

    #[Test]
    public function a_delivered_job_is_finished(): void
    {
        $job = $this->bookIn();

        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->advance($job['id'], WorkshopJobStatus::Ready)->assertOk();
        $this->advance($job['id'], WorkshopJobStatus::Delivered)->assertOk();

        // Terminal. Whatever comes back next is a new job with its own
        // complaint, not this one reopened — which would silently rewrite how
        // long the first repair took.
        $this->advance($job['id'], WorkshopJobStatus::InProgress)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_TRANSITION_INVALID');
    }

    #[Test]
    public function a_ready_motor_can_go_back_to_the_bench(): void
    {
        $job = $this->bookIn();

        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->advance($job['id'], WorkshopJobStatus::Ready)->assertOk();

        // It failed the test run. Not an exception in a rewinding shop; a
        // Tuesday.
        $this->advance($job['id'], WorkshopJobStatus::InProgress)
            ->assertOk()
            ->assertJsonPath('data.status', WorkshopJobStatus::InProgress->value);
    }

    #[Test]
    public function moving_a_job_to_where_it_already_is_changes_nothing(): void
    {
        $job = $this->bookIn();

        // Two clerks tapping "Ready" is not a mistake anybody needs telling
        // about, and it is exactly what a slow connection produces.
        $this->advance($job['id'], WorkshopJobStatus::Received)
            ->assertOk()
            ->assertJsonPath('data.status', WorkshopJobStatus::Received->value);
    }

    /* ---------------------------------------------------------------------
     | Parts — and what they do not do
     |-------------------------------------------------------------------- */

    #[Test]
    public function adding_a_part_moves_no_stock(): void
    {
        $this->buyBearings('10', '300.00');

        $before = $this->stockPositionOf($this->tenant, $this->bearing);

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();

        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id,
            'quantity' => '2',
            'unit_price' => '450.00',
        ])->assertCreated();

        // Decision D2, asserted: a part on a job is a note about what will be
        // billed. The shelf has not moved and neither has the Inventory account.
        $this->assertSame($before, $this->stockPositionOf($this->tenant, $this->bearing));
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after adding a part to a job');
    }

    #[Test]
    public function a_stocked_family_without_a_specification_is_refused(): void
    {
        $job = $this->bookIn();

        $this->addPart($job['id'], [
            'item_id' => $this->bearing->item_id,
            'quantity' => '1',
        ])
            // The same refusal the bill engine makes, made a fortnight sooner —
            // while the job card is still being written rather than after the
            // motor has gone out.
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_LINE_NEEDS_VARIANT');
    }

    #[Test]
    public function half_a_bearing_is_refused(): void
    {
        $job = $this->bookIn();

        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id,
            'quantity' => '0.5',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_LINE_FRACTIONAL_UNIT');
    }

    #[Test]
    public function an_unbilled_part_can_be_taken_off_the_job(): void
    {
        $job = $this->bookIn();

        $added = $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id,
            'quantity' => '1',
        ])->assertCreated()->json('data');

        $partId = $added['parts'][0]['id'];

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/workshop-jobs/{$job['id']}/parts/{$partId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.parts');
    }

    /* ---------------------------------------------------------------------
     | The estimate — §18
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_estimate_posts_nothing_and_can_be_approved_then_copied_onto_the_job(): void
    {
        $job = $this->bookIn();

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson("/api/v1/workshop-jobs/{$job['id']}/estimate", [
                'lines' => [
                    ['variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1200.00'],
                    ['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.has_estimate', true)
            // 1,200 + 900, before tax — an estimate is a conversation at a
            // counter, not a document with a GST treatment.
            ->assertJsonPath('data.estimate_total', '2100.00')
            ->assertJsonPath('data.estimate_approved_at', null);

        // Decision D3: nothing has reached the books. An estimate that posted
        // journal entries would be claiming revenue nobody has agreed to.
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$job['id']}/estimate/approve")
            ->assertOk();

        $this->assertNotNull($this->show($job['id'])['estimate_approved_at']);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$job['id']}/estimate/apply")
            ->assertOk()
            ->assertJsonCount(2, 'data.parts');
    }

    #[Test]
    public function re_quoting_clears_the_approval(): void
    {
        $job = $this->bookIn();

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson("/api/v1/workshop-jobs/{$job['id']}/estimate", [
                'lines' => [['variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1200.00']],
            ])->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$job['id']}/estimate/approve")->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson("/api/v1/workshop-jobs/{$job['id']}/estimate", [
                'lines' => [['variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1800.00']],
            ])->assertOk()
            // A customer who agreed to ₹1,200 has not agreed to ₹1,800.
            ->assertJsonPath('data.estimate_approved_at', null);
    }

    /* ---------------------------------------------------------------------
     | Billing — where the stock finally moves
     |-------------------------------------------------------------------- */

    #[Test]
    public function billing_a_job_issues_its_parts_exactly_once(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();

        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        $this->addPart($job['id'], [
            'variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1200.00',
        ])->assertCreated();

        $bill = $this->bill($job['id'])->assertCreated()->json('data');

        // The invoice is an ordinary sale, written by the ordinary engine — it
        // has a number in the invoice series and it is posted.
        $this->assertStringStartsWith('INV/', $bill['doc_no']);
        $this->assertSame('sale', $bill['type']);

        // Two bearings off the shelf, and exactly two: the eight remaining are
        // what a job that reserved nothing leaves behind.
        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after billing a job');

        // The invoice records which motor it came off, and the parts record
        // which invoice took them.
        $job = $this->show($job['id']);

        $this->assertSame(1, $job['billed']['count']);
        $this->assertTrue(collect($job['parts'])->every(fn (array $part) => $part['is_billed']));

        $this->assertSame(
            (int) $job['id'],
            (int) Transaction::withoutGlobalScopes()->find($bill['id'])->workshop_job_id,
        );
    }

    #[Test]
    public function a_job_cannot_be_billed_twice_for_the_same_part(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        $this->bill($job['id'])->assertCreated();

        // Not by a flag somebody has to remember to set — the parts point at the
        // lines that consumed them, so the second invoice finds nothing to bill.
        $this->bill($job['id'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_NOTHING_TO_BILL');

        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
    }

    #[Test]
    public function a_second_repair_on_the_same_job_bills_only_what_is_new(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        $this->bill($job['id'])->assertCreated();

        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '450.00',
        ])->assertCreated();

        $second = $this->bill($job['id'])->assertCreated()->json('data');

        // One line, not three: a job billed in two halves must not put the first
        // half's bearings on the second invoice.
        $this->assertCount(1, $second['items']);
        $this->assertSame('7.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);

        $this->assertSame(2, $this->show($job['id'])['billed']['count']);
    }

    /**
     * The brief's scenario 10.
     */
    #[Test]
    public function a_cancelled_job_bills_nothing(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        $this->advance($job['id'], WorkshopJobStatus::Cancelled)->assertOk();

        $this->bill($job['id'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_NOT_BILLABLE');

        // Nothing left the shelf, and nothing reached the books — whatever was
        // optimistically listed while the estimate was being argued about.
        $this->assertSame('10.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after cancelling a job');
    }

    #[Test]
    public function a_job_that_has_had_no_work_done_cannot_be_billed(): void
    {
        $job = $this->bookIn();

        $this->addPart($job['id'], [
            'variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1200.00',
        ])->assertCreated();

        // Still `received`. An invoice against it would be charging for an
        // intention.
        $this->bill($job['id'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_NOT_BILLABLE');
    }

    #[Test]
    public function billing_a_job_refuses_stock_the_shelf_does_not_hold(): void
    {
        $this->buyBearings('1', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();

        // Written onto the job without complaint — a job reserves nothing, and
        // the fitter may well be about to buy them in.
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '4', 'unit_price' => '450.00',
        ])->assertCreated();

        // M17's refusal, reached through the job path exactly as through the
        // counter: the invoice is where the shelf gets a say.
        $this->bill($job['id'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');

        $this->assertSame('1.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertFalse($this->show($job['id'])['parts'][0]['is_billed']);
    }

    #[Test]
    public function a_repeated_bill_request_produces_one_invoice(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        $ref = '11111111-2222-4333-8444-555555555555';

        $first = $this->bill($job['id'], ['client_ref' => $ref])->assertCreated()->json('data');
        $second = $this->bill($job['id'], ['client_ref' => $ref])->json('data');

        // M17's duplicate protection, reached through this path too. The clerk
        // who tapped Save twice gets the bill, not an error and not a second one.
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
    }

    /* ---------------------------------------------------------------------
     | Deleting
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_billed_job_cannot_be_deleted(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '450.00',
        ])->assertCreated();
        $this->bill($job['id'])->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/workshop-jobs/{$job['id']}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOB_IN_USE');
    }

    #[Test]
    public function an_unbilled_job_can_be_deleted(): void
    {
        $job = $this->bookIn();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/workshop-jobs/{$job['id']}")
            ->assertOk();

        $this->assertNull(WorkshopJob::withoutGlobalScopes()->find($job['id']));
    }

    /* ---------------------------------------------------------------------
     | The §34 walkthrough, end to end
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_motor_repair_walkthrough(): void
    {
        $this->buyBearings('10', '300.00');

        // 1. A 7.5 HP motor arrives with a burnt winding.
        $job = $this->bookIn(['promised_date' => now()->addWeek()->toDateString()]);

        // 2. It is opened up.
        $this->advance($job['id'], WorkshopJobStatus::Inspection)->assertOk();

        // 3. It is quoted — and nothing is posted.
        $this->withHeaders($this->authHeader($this->owner))
            ->putJson("/api/v1/workshop-jobs/{$job['id']}/estimate", [
                'lines' => [
                    ['variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1200.00'],
                    ['variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00'],
                ],
            ])->assertOk();

        $this->advance($job['id'], WorkshopJobStatus::Estimate)->assertOk();
        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('type', 'sale')->count());

        // 4. The customer says yes, and the quotation becomes the shopping list.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$job['id']}/estimate/approve")->assertOk();

        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/workshop-jobs/{$job['id']}/estimate/apply")->assertOk();

        // Still nothing off the shelf: parts on a job move no stock.
        $this->assertSame('10.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);

        // 5. Finished and tested.
        $this->advance($job['id'], WorkshopJobStatus::Ready)->assertOk();

        // 6. Billed, with half collected at the counter.
        $bill = $this->bill($job['id'], [
            'payments' => [['mode' => 'cash', 'amount' => '1000.00']],
        ])->assertCreated()->json('data');

        // 7. Collected.
        $this->advance($job['id'], WorkshopJobStatus::Delivered)->assertOk();

        // What the workshop should be able to say afterwards, in one read: the
        // motor went out, it was billed once, part of it is still owed, and two
        // bearings left the shelf against it.
        $final = $this->show($job['id']);

        $this->assertSame(WorkshopJobStatus::Delivered->value, $final['status']);
        $this->assertSame(1, $final['billed']['count']);
        $this->assertSame('1000.00', $final['billed']['paid']);
        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);

        // The job's outstanding is the invoice's outstanding — the same
        // BillService arithmetic, reached from the other end, rather than a
        // second opinion about the same money.
        $invoice = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$bill['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame($invoice['due'], $final['billed']['due']);
        $this->assertSame($invoice['total'], $final['billed']['total']);

        // And the two invariants the whole application rests on still hold.
        $this->assertBooksBalance($this->tenant, 'after a full repair');
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a full repair');

        // Revenue reached the books once, through the ordinary sale path.
        $this->assertSame(
            1,
            Transaction::withoutGlobalScopes()->whereNotNull('workshop_job_id')->count(),
        );
        $this->assertNotSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Sales));
    }
}
