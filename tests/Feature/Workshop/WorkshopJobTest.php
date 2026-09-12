<?php

namespace Tests\Feature\Workshop;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\WorkshopJobStatus;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
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

    private ItemCategory $motorKind;

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

        // Provisioned by the first variant above. The bench takes its vocabulary
        // from the catalogue rather than owning a second list of kinds, so this
        // is the same row the Items form draws a motor's fields from.
        $this->motorKind = $this->actingForTenant(
            $this->tenant,
            fn () => ItemCategory::where('code', 'motor')->firstOrFail(),
        );
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
                'category_id' => $this->motorKind->id,
                'specs' => ['hp' => '7.5', 'phase' => '3'],
                'brand' => 'Crompton',
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

    /**
     * A page of the bench, as a listing serialises it.
     *
     * Distinct from {@see show()} on purpose: a listing does not load the parts,
     * so anything derived from them here is derived from the repository's counts
     * instead — which is exactly what a badge on a row depends on.
     *
     * @return array<string, mixed>
     */
    private function index(array $query = []): array
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workshop-jobs?'.http_build_query($query))
            ->assertOk()
            ->json();
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

        // What it is, then whose it is. The specification is the category's own
        // first two fields with the category's own units — never a rating and a
        // phase this module knows the names of.
        $this->assertSame('Motor 7.5 HP, 3 ph · Crompton', $job['equipment']);
        $this->assertSame('Motor', $job['kind_label']);
        $this->assertEquals(['hp' => '7.5', 'phase' => '3'], $job['specs']);

        // The whole point of D1 and D2 in one assertion: booking a motor in is
        // not an accounting event, so nothing at all has reached the books.
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after booking a job in');
    }

    /* ---------------------------------------------------------------------
     | What comes in is not always a motor
     |
     | The bench used to record `hp` and `phase` as columns, under a heading that
     | said "The motor" — a product type in the schema and in a Blade template,
     | which is the failure the catalogue's vocabulary rule already records. Most
     | of what a motor workshop takes in is a motor; a good deal of it is a
     | cooler, a table fan or a pump, and now and then it is something nobody
     | expected. These hold that shut.
     |-------------------------------------------------------------------- */

    /**
     * A workshop that starts repairing coolers defines the kind and the bench
     * asks the right questions — no column, no migration, no deployment.
     */
    #[Test]
    public function anything_the_workshop_defines_a_kind_for_can_be_booked_in(): void
    {
        $cooler = $this->kind('Cooler', [
            ['key' => 'capacity', 'label' => 'Tank capacity', 'data_type' => 'number', 'unit_code' => 'litre'],
            ['key' => 'body', 'label' => 'Body', 'data_type' => 'dropdown', 'options' => ['Plastic', 'Metal']],
        ]);

        $job = $this->bookIn([
            'category_id' => $cooler->id,
            'specs' => ['capacity' => '65', 'body' => 'Plastic'],
            'brand' => 'Symphony',
            'complaint' => 'Pump not lifting water',
        ]);

        $this->assertSame('Cooler', $job['kind_label']);

        // `assertEquals`, not `assertSame`: MySQL's JSON type normalises an
        // object's key order, so a stored bag never comes back in the order it
        // was written. That is the whole reason `resolvedSpecs()` sorts by the
        // schema instead of trusting the bag — the assertion below is the one
        // that has to be in order.
        $this->assertEquals(['capacity' => '65', 'body' => 'Plastic'], $job['specs']);

        // Labelled and unitised from the category that asked, so a job card can
        // print it without knowing what a cooler is.
        $this->assertSame(
            [
                ['key' => 'capacity', 'label' => 'Tank capacity', 'value' => '65', 'suffix' => 'L'],
                ['key' => 'body', 'label' => 'Body', 'value' => 'Plastic', 'suffix' => null],
            ],
            $job['specs_display'],
        );

        $this->assertSame('Cooler 65 L, Plastic · Symphony', $job['equipment']);
    }

    /**
     * Nothing about the thing is compulsory — its kind included.
     *
     * A pump is wheeled in at four in the afternoon by a driver who does not
     * know what it is, and a form that refused to book it in would be a form
     * that got a job card written on paper instead.
     */
    #[Test]
    public function something_nobody_can_identify_is_still_booked_in(): void
    {
        $job = $this->bookIn([
            'category_id' => null,
            'specs' => null,
            'brand' => null,
            'complaint' => 'Sparking when switched on',
        ]);

        $this->assertNull($job['kind_label']);
        $this->assertSame([], (array) $job['specs']);
        $this->assertSame([], $job['specs_display']);

        // The job number, because there is nothing else to call it by — and not
        // an empty string, which would leave a blank cell on the bench.
        $this->assertSame($job['job_no'], $job['equipment']);
    }

    /**
     * A category demanding a rating demands it of a *product*. This is a
     * physical object that is already on the bench.
     */
    #[Test]
    public function a_kind_that_insists_on_a_field_does_not_insist_here(): void
    {
        // `hp`, `phase` and `rpm` are all `is_required` on the seeded Motor
        // category — an item variant cannot be saved without them.
        $this->assertNotEmpty($this->actingForTenant(
            $this->tenant,
            fn () => $this->motorKind->requiredAttributeKeys(),
        ));

        $job = $this->bookIn(['specs' => []]);

        $this->assertSame('Motor', $job['kind_label']);
        $this->assertSame([], (array) $job['specs']);
        $this->assertSame('Motor · Crompton', $job['equipment']);
    }

    /**
     * The bag is filtered to what the kind actually asks about.
     *
     * A key no schema explains cannot be labelled, printed or edited back into
     * the form, so it is dropped rather than stored as something nobody can read.
     */
    #[Test]
    public function a_specification_the_kind_never_asked_for_is_not_kept(): void
    {
        $job = $this->bookIn([
            'specs' => ['hp' => '5', 'colour' => 'Blue', 'lumens' => '900'],
        ]);

        $this->assertEquals(['hp' => '5'], $job['specs']);
    }

    /**
     * Correcting a motor to a cooler cannot leave a motor's answers behind:
     * `hp` is not a field a cooler has, and a bag its kind cannot read is one
     * nothing can print.
     */
    #[Test]
    public function changing_the_kind_takes_the_old_specification_with_it(): void
    {
        $cooler = $this->kind('Cooler', [
            ['key' => 'capacity', 'label' => 'Tank capacity', 'data_type' => 'number', 'unit_code' => 'litre'],
        ]);

        $job = $this->bookIn();

        $corrected = $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/workshop-jobs/{$job['id']}", [
                'category_id' => $cooler->id,
                'specs' => ['capacity' => '65'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('Cooler', $corrected['kind_label']);
        $this->assertEquals(['capacity' => '65'], $corrected['specs']);
    }

    /**
     * The intake form draws its fields from the server, and asks the route the
     * counter clerk actually holds a grant for.
     *
     * Fetching them from `GET /items/meta` would 403 the form for its main user:
     * booking a motor in needs WORKSHOP_JOBS and nothing says it needs ITEMS.
     */
    #[Test]
    public function the_bench_publishes_the_kinds_it_can_take_in(): void
    {
        $benchOnly = User::factory()
            ->forTenant($this->tenant)
            ->withRole($this->roleWith([['READ', 'WORKSHOP_JOBS']], 'Bench only'))
            ->create();

        $kinds = $this->withHeaders($this->authHeader($benchOnly))
            ->getJson('/api/v1/workshop-jobs/meta')
            ->assertOk()
            ->json('data.kinds');

        $labels = array_column($kinds, 'label');

        $this->assertContains('Motor', $labels);

        // Labour is produced at the moment it is sold — nobody wheels an hour
        // onto a bench — and `holds_stock` already says so, which is why there
        // is no second flag to keep in step with it.
        $this->assertNotContains('Service', $labels);

        $motor = collect($kinds)->firstWhere('label', 'Motor');

        $this->assertArrayHasKey('hp', (array) $motor['attributes']);
        $this->assertSame('Rating', ((array) $motor['attributes'])['hp']['label']);
    }

    /**
     * The kind is copied onto the row, so searching finds the coolers without a
     * join and a renamed category leaves old cards saying what came in.
     */
    #[Test]
    public function the_kind_is_searchable_and_survives_a_rename(): void
    {
        $cooler = $this->kind('Cooler');

        $this->bookIn(['category_id' => $cooler->id, 'specs' => null]);
        $this->bookIn();

        $found = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workshop-jobs?search=cooler')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $found);
        $this->assertSame('Cooler', $found[0]['kind_label']);

        $this->actingForTenant($this->tenant, fn () => $cooler->update(['name' => 'Air cooler']));

        $this->assertSame(
            'Cooler',
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson("/api/v1/workshop-jobs/{$found[0]['id']}")
                ->json('data.kind_label'),
        );
    }

    /**
     * A kind the workshop defines, with the fields it asks about.
     *
     * @param  array<int, array<string, mixed>>  $attributes
     */
    private function kind(string $name, array $attributes = []): ItemCategory
    {
        return $this->actingForTenant($this->tenant, function () use ($name, $attributes) {
            $category = ItemCategory::create([
                'name' => $name,
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'is_active' => true,
            ]);

            foreach ($attributes as $order => $attribute) {
                ItemAttribute::create(array_merge([
                    'category_id' => $category->id,
                    'is_required' => false,
                    'is_active' => true,
                    'display_order' => $order,
                ], $attribute));
            }

            return $category->refresh();
        });
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

    /* ---------------------------------------------------------------------
     | Whether the job has been invoiced
     |
     | A second signal beside the status and never folded into it: a job's status
     | is about the motor and this is about the money. Before it, a repair that
     | had been charged for looked on the list exactly like one that had not —
     | the status still read "In progress", because that is where the motor was,
     | and nothing anywhere said an invoice existed.
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_job_says_whether_it_has_been_invoiced(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();

        // Nothing on it yet, and nothing billed.
        $this->assertSame('unbilled', $this->show($job['id'])['billing_state']);

        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();
        $this->addPart($job['id'], [
            'variant_id' => $this->labour->id, 'quantity' => '1', 'unit_price' => '1200.00',
        ])->assertCreated();

        $this->assertSame('unbilled', $this->show($job['id'])['billing_state']);

        $this->bill($job['id'])->assertCreated();

        $billed = $this->show($job['id']);

        $this->assertSame('billed', $billed['billing_state']);
        $this->assertSame('Invoiced', $billed['billing_state_label']);
        // The tone travels with it, so a screen never maps a state to a colour.
        $this->assertSame('success', $billed['billing_state_tone']);

        // The status is untouched. The motor is still on the bench, and saying
        // otherwise because an invoice exists would be a lie about the workshop.
        $this->assertSame(WorkshopJobStatus::InProgress->value, $billed['status']);

        // Something more fitted afterwards puts it back to part billed: there is
        // work on the card the customer has not been charged for.
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '450.00',
        ])->assertCreated();

        $this->assertSame('part_billed', $this->show($job['id'])['billing_state']);
    }

    #[Test]
    public function the_listing_says_it_too_without_loading_every_part(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->billableJobWithTwoBearings();

        $row = collect($this->index()['data'])->firstWhere('id', $job);

        $this->assertSame('unbilled', $row['billing_state']);

        $this->bill($job)->assertCreated();

        $row = collect($this->index()['data'])->firstWhere('id', $job);

        $this->assertSame('billed', $row['billing_state']);
        // Derived from a count on the listing rather than from the parts, which a
        // page of twenty-five jobs does not load — see the repository.
        $this->assertSame('Invoiced', $row['billing_state_label']);
    }

    /**
     * Reversing the only invoice off a job puts it back to not billed.
     *
     * Which is the whole reason the state is derived rather than stored: nothing
     * in the Jobs module knows the invoice was reversed, and nothing has to.
     */
    public function test_reversing_the_invoice_takes_the_badge_away(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->billableJobWithTwoBearings();
        $bill = $this->bill($job)->assertCreated()->json('data');

        $this->assertSame('billed', $this->show($job)['billing_state']);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$bill['id']}/reverse")
            ->assertCreated();

        $reread = $this->show($job);

        $this->assertSame('unbilled', $reread['billing_state']);
        // The document itself stays on the card — the reversal is part of the
        // record of what happened, and the job lists both halves of the pair.
        $this->assertSame(1, $reread['billed']['count']);
        $this->assertSame(0, $reread['billed']['live']);
        $this->assertSame('0.00', $reread['billed']['total']);
    }

    /* ---------------------------------------------------------------------
     | What the counter changed on the way out
     |
     | `items` is an override, and sending it costs the pairing between a part
     | and the line it became — so `bill()` marks nothing when it is present.
     | That branch had no coverage at all until C4, and the Jobs card is the
     | first screen that can reach either half of it: it omits `items` while the
     | lines are the ones the job produced, and sends them when a rate was
     | argued down at the counter.
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_bill_whose_lines_were_replaced_posts_and_leaves_the_parts_on_the_card(): void
    {
        $this->buyBearings('10', '300.00');

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        // The customer argued the rate down while the motor was on the counter.
        $response = $this->bill($job['id'], [
            'items' => [[
                'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '400.00',
            ]],
        ])->assertCreated();

        $bill = $response->json('data');

        // The invoice is real, at the agreed rate, and stamped with the job.
        $this->assertSame('800.00', $response->json('meta.tax.taxable'));
        $this->assertSame(
            (int) $job['id'],
            (int) Transaction::withoutGlobalScopes()->find($bill['id'])->workshop_job_id,
        );

        // The stock moved, because the invoice posted.
        $this->assertSame('8.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);

        /*
        | And the parts stayed on the card, deliberately. Line three of the
        | operator's list is no longer part three of the job, so nothing is
        | paired — which is the safe way to be wrong: somebody sees the bearings
        | again rather than them vanishing off the job silently.
        */
        $reread = $this->show($job['id']);

        $this->assertSame(1, $reread['billed']['count']);
        $this->assertTrue(collect($reread['parts'])->every(fn (array $part) => ! $part['is_billed']));
    }

    #[Test]
    public function a_workshop_bill_takes_a_discount_on_the_whole_repair(): void
    {
        $this->buyBearings('20', '300.00');

        $plain = $this->billableJobWithTwoBearings();
        $discounted = $this->billableJobWithTwoBearings();

        $before = $this->bill($plain)->assertCreated()->json('meta.tax.taxable');
        $after = $this->bill($discounted, ['bill_discount' => '100.00'])
            ->assertCreated()
            ->json('meta.tax.taxable');

        // Apportioned across the lines *before* tax, so the tax falls with it.
        // Until C4 this endpoint named no such key and the figure was dropped on
        // the floor, which meant the two totals came back identical.
        $this->assertSame('900.00', $before);
        $this->assertSame('800.00', $after);
    }

    #[Test]
    public function a_line_quoted_with_the_tax_already_in_it_is_billed_that_way(): void
    {
        $this->buyBearings('10', '300.00');

        $this->actingForTenant($this->tenant, fn () => $this->bearing->item->update(['gst_rate' => '18.00']));

        $job = $this->bookIn();
        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '118.00',
        ])->assertCreated();

        $taxable = $this->bill($job['id'], [
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => '1',
                'unit_price' => '118.00',
                'price_includes_tax' => true,
            ]],
        ])->assertCreated()->json('meta.tax.taxable');

        // The tax is what is left over, never a second multiplication — a
        // customer handing over a hundred and eighteen for a hundred-and-
        // eighteen price is the whole point of the mode.
        $this->assertSame('100.00', $taxable);
    }

    #[Test]
    public function a_workshop_bill_records_who_did_the_work(): void
    {
        $this->buyBearings('10', '300.00');

        [$fitter, $ramesh] = $this->actingForTenant($this->tenant, function () {
            $designation = \App\Models\StaffDesignation::create([
                'name' => 'Fitter',
                'track_on_sales' => true,
            ]);

            return [$designation, \App\Models\Employee::create([
                'name' => 'Ramesh',
                'designation_id' => $designation->id,
                'salary_basis' => 'monthly',
                'pay_rate' => '18000.00',
                'joined_on' => '2026-01-01',
            ])];
        });

        $bill = $this->bill($this->billableJobWithTwoBearings(), [
            'staff' => [['designation_id' => $fitter->id, 'employee_id' => $ramesh->id]],
        ])->assertCreated()->json('data');

        /*
        | A rewind is the canonical case for attribution — "Ramesh fitted it,
        | Sunil wound it" is a sentence about a job. It reached this endpoint
        | from the shared bill document and was dropped, silently, because
        | nothing here named the key.
        */
        $this->assertSame('Ramesh', $bill['staff'][0]['employee']);
        $this->assertSame('Fitter', $bill['staff'][0]['designation']);
    }

    /** A job in progress with two bearings on it, ready to bill. */
    private function billableJobWithTwoBearings(): int
    {
        $job = $this->bookIn();

        $this->advance($job['id'], WorkshopJobStatus::InProgress)->assertOk();
        $this->addPart($job['id'], [
            'variant_id' => $this->bearing->id, 'quantity' => '2', 'unit_price' => '450.00',
        ])->assertCreated();

        return (int) $job['id'];
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
