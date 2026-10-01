<?php

namespace Tests\Feature\Workshop;

use App\Enums\PartyRole;
use App\Models\JobKind;
use App\Models\JobKindAttribute;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Workshop\JobKindProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Kind Master — what the workshop takes in.
 *
 * The thing being established here is that the bench's list is **its own**. It
 * used to be `item_categories` filtered on `holds_stock`, which offered Part,
 * Bulk material, Bearing and Wire to somebody booking a motor in and offered no
 * cooler, fan or submersible at all — because a repair shop does not stock the
 * things it repairs. Everything below is a variation on that separation, or on
 * the one rule the master enforces: a definition something depends on may be
 * switched off, never removed.
 */
class JobKindMasterTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'WORKSHOP_JOBS'], ['WRITE', 'WORKSHOP_JOBS'],
            ['UPDATE', 'WORKSHOP_JOBS'], ['DELETE', 'WORKSHOP_JOBS'],
            ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['UPDATE', 'ITEMS'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | The list is the bench's own
     |-------------------------------------------------------------------- */

    /**
     * The bug this module was built for, asserted directly.
     */
    #[Test]
    public function the_intake_list_offers_what_comes_in_and_not_what_is_on_the_shelf(): void
    {
        $this->seedKinds();

        $labels = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/workshop-jobs/meta')
                ->assertOk()
                ->json('data.kinds')
        )->pluck('label');

        // What a customer wheels through a door.
        $this->assertContains('Cooler', $labels);
        $this->assertContains('Submersible pump', $labels);
        $this->assertContains('Ceiling fan', $labels);
        $this->assertContains('Motor', $labels);

        // What sits on a shelf. These are `item_categories` — seeded or
        // templated — and not one of them is a thing anybody brings in.
        $this->assertNotContains('Part', $labels);
        $this->assertNotContains('Bulk material', $labels);
        $this->assertNotContains('Bearing', $labels);
        $this->assertNotContains('Wire', $labels);
    }

    /**
     * Adding a category does not add a kind, and that is the separation.
     */
    #[Test]
    public function the_catalogue_and_the_bench_are_two_lists(): void
    {
        $this->seedKinds();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/item-categories', [
                'name' => 'Gearbox oil',
                'holds_stock' => true,
            ])->assertCreated();

        $labels = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/workshop-jobs/meta')->json('data.kinds')
        )->pluck('label');

        $this->assertNotContains('Gearbox oil', $labels);
    }

    /**
     * And the whole point of a master: a shop that starts repairing something
     * new says so, and the intake form asks the right questions — no column, no
     * migration, no deployment.
     */
    #[Test]
    public function a_workshop_adds_a_kind_and_the_intake_form_asks_its_questions(): void
    {
        $kind = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/job-kinds', [
                'name' => 'Sewing machine',
                'description' => 'Domestic and industrial heads.',
            ])->assertCreated()->json('data');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/job-kinds/{$kind['id']}/fields", [
                'label' => 'Head type',
                'data_type' => 'dropdown',
                'options' => ['domestic', 'industrial'],
            ])->assertCreated()
            // Derived from the label, because an admin typing "Head type"
            // should not be asked about JSON keys.
            ->assertJsonPath('data.key', 'head_type');

        $offered = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/workshop-jobs/meta')->json('data.kinds')
        )->firstWhere('label', 'Sewing machine');

        $this->assertSame(
            ['domestic', 'industrial'],
            $offered['attributes']['head_type']['values'],
        );

        // And it can be booked in under it, with the answer kept.
        $job = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $kind['id'],
                'specs' => ['head_type' => 'industrial'],
                'complaint' => 'Needle bar jammed',
            ])->assertCreated()->json('data');

        $this->assertSame('Sewing machine', $job['kind_label']);
        $this->assertEquals(['head_type' => 'industrial'], $job['specs']);
    }

    /* ---------------------------------------------------------------------
     | Switched off, never removed
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_kind_a_job_is_filed_under_cannot_be_deleted(): void
    {
        $kind = $this->kind('Cooler');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $kind->id,
                'complaint' => 'Pump not lifting water',
            ])->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/job-kinds/{$kind->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOB_KIND_IN_USE');

        // Archiving is the remedy the refusal names, and it works.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}", ['is_active' => false])
            ->assertOk();

        $labels = collect(
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson('/api/v1/workshop-jobs/meta')->json('data.kinds')
        )->pluck('label');

        $this->assertNotContains('Cooler', $labels);
    }

    /**
     * An archived kind still explains the jobs already filed under it —
     * otherwise a card stops being able to say what came through the door.
     */
    #[Test]
    public function an_archived_kind_still_explains_the_jobs_it_holds(): void
    {
        $kind = $this->kind('Cooler', [
            ['key' => 'capacity', 'label' => 'Tank capacity', 'data_type' => 'number', 'unit_code' => 'litre'],
        ]);

        $job = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $kind->id,
                'specs' => ['capacity' => '65'],
                'complaint' => 'Pump not lifting water',
            ])->assertCreated()->json('data');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}", ['is_active' => false])
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/workshop-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.kind_label', 'Cooler')
            ->assertJsonPath('data.specs_display.0.label', 'Tank capacity')
            ->assertJsonPath('data.specs_display.0.value', '65');
    }

    #[Test]
    public function a_field_a_job_has_answered_cannot_be_deleted(): void
    {
        $kind = $this->kind('Cooler', [
            ['key' => 'capacity', 'label' => 'Tank capacity', 'data_type' => 'number'],
        ]);

        $field = $this->firstField($kind);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $kind->id,
                'specs' => ['capacity' => '65'],
                'complaint' => 'Pump not lifting water',
            ])->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/job-kinds/{$kind->id}/fields/{$field->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOB_KIND_FIELD_ANSWERED');
    }

    /**
     * The quiet one: nothing rewrites a stored bag, so a job goes on reading
     * "Plastic" while the list no longer offers it, and the next person to open
     * that card loses the value by looking at it.
     */
    #[Test]
    public function a_choice_a_job_is_filed_under_cannot_be_dropped_from_the_list(): void
    {
        $kind = $this->kind('Cooler', [
            ['key' => 'body', 'label' => 'Body', 'data_type' => 'dropdown', 'options' => ['Plastic', 'Metal']],
        ]);

        $field = $this->firstField($kind);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $kind->id,
                'specs' => ['body' => 'Plastic'],
                'complaint' => 'Pump not lifting water',
            ])->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}/fields/{$field->id}", [
                'options' => ['Metal'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOB_KIND_FIELD_OPTION_IN_USE');

        // A choice nobody is filed under goes without complaint.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}/fields/{$field->id}", [
                'options' => ['Plastic'],
            ])->assertOk();
    }

    /**
     * Two kinds may both ask `hp`, and one's answers say nothing about whether
     * the other's field can be removed.
     */
    #[Test]
    public function one_kinds_answers_do_not_hold_another_kinds_field_open(): void
    {
        $motor = $this->kind('Motor', [
            ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal'],
        ]);

        $pump = $this->kind('Monoblock pump', [
            ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal'],
        ]);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $motor->id,
                'specs' => ['hp' => '7.5'],
                'complaint' => 'Winding burnt',
            ])->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/job-kinds/{$pump->id}/fields/{$this->firstField($pump)->id}")
            ->assertOk();
    }

    /* ---------------------------------------------------------------------
     | The key is write-once
     |-------------------------------------------------------------------- */

    /**
     * Renaming the key would not rename it inside the bags already written, it
     * would orphan every one of them. So the service ignores it on an edit.
     */
    #[Test]
    public function a_fields_key_cannot_be_renamed(): void
    {
        $kind = $this->kind('Cooler', [
            ['key' => 'capacity', 'label' => 'Tank capacity', 'data_type' => 'number'],
        ]);

        $field = $this->firstField($kind);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}/fields/{$field->id}", [
                'key' => 'tank_size',
                'label' => 'Tank size',
            ])
            ->assertOk()
            ->assertJsonPath('data.key', 'capacity')
            ->assertJsonPath('data.label', 'Tank size');
    }

    /**
     * Renaming is a display change and must not be a data migration: the jobs
     * already filed under it hold a **copied** `kind_label`, so they go on
     * saying what came through the door on the day it did.
     */
    #[Test]
    public function a_kind_can_be_renamed_and_redescribed(): void
    {
        $kind = $this->kind('Colar');

        $job = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/workshop-jobs', [
                'party_id' => $this->customer()->id,
                'job_kind_id' => $kind->id,
                'complaint' => 'Pump not lifting water',
            ])->assertCreated()->json('data');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}", [
                'name' => 'Cooler',
                'description' => 'Air coolers — the fan motor, the pump, the body.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cooler')
            ->assertJsonPath('data.description', 'Air coolers — the fan motor, the pump, the body.');

        // The job keeps the name it was booked in under. Correcting a spelling
        // does not rewrite history, and nothing here pretends it does.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/workshop-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.kind_label', 'Colar');
    }

    /**
     * A `PATCH` carrying only `is_active` must not be read as clearing the rest
     * — that is what the list's own archive switch sends.
     */
    #[Test]
    public function archiving_does_not_blank_the_rest_of_the_record(): void
    {
        $kind = $this->kind('Cooler');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}", ['description' => 'Air coolers.'])
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/job-kinds/{$kind->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cooler')
            ->assertJsonPath('data.description', 'Air coolers.')
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function a_kind_nothing_refers_to_is_deleted_outright(): void
    {
        $kind = $this->kind('Cooler', [
            ['key' => 'capacity', 'label' => 'Tank capacity', 'data_type' => 'number'],
        ]);

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/job-kinds/{$kind->id}")
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/job-kinds/{$kind->id}")
            ->assertNotFound();

        // Its questions go with it — `job_kind_attributes` cascades, and that is
        // safe only because no job referred to the kind.
        $this->assertDatabaseMissing('job_kind_attributes', ['job_kind_id' => $kind->id]);
    }

    #[Test]
    public function two_kinds_cannot_share_a_name(): void
    {
        $this->kind('Cooler');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/job-kinds', ['name' => 'Cooler'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOB_KIND_NAME_TAKEN');
    }

    /* ---------------------------------------------------------------------
     | Authority
     |-------------------------------------------------------------------- */

    /**
     * Booking a motor in and deciding what the workshop asks about every cooler
     * are different authorities — the same split the Category Master makes.
     */
    #[Test]
    public function reading_the_list_and_editing_it_are_different_grants(): void
    {
        [$tenant, $clerk] = $this->tenantWithUser([
            ['READ', 'WORKSHOP_JOBS'], ['WRITE', 'WORKSHOP_JOBS'], ['READ', 'PARTIES'],
        ]);

        $this->actingForTenant($tenant, fn () => JobKind::create(['name' => 'Cooler']));

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/job-kinds')
            ->assertOk();

        $this->withHeaders($this->authHeader($clerk))
            ->postJson('/api/v1/job-kinds', ['name' => 'Sewing machine'])
            ->assertForbidden();
    }

    /**
     * One workshop's kinds are its own. A second workshop sees none of them.
     */
    #[Test]
    public function kinds_do_not_leak_between_workshops(): void
    {
        $this->kind('Cooler');

        [, $other] = $this->tenantWithUser([['READ', 'WORKSHOP_JOBS']]);

        $this->withHeaders($this->authHeader($other))
            ->getJson('/api/v1/job-kinds')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function kind(string $name, array $fields = []): JobKind
    {
        return $this->actingForTenant($this->tenant, function () use ($name, $fields) {
            $kind = JobKind::create(['name' => $name, 'is_active' => true]);

            foreach ($fields as $order => $field) {
                $kind->fields()->create(array_merge([
                    'is_required' => false,
                    'is_active' => true,
                    'display_order' => $order,
                ], $field));
            }

            return $kind->refresh();
        });
    }

    /**
     * Reading a tenant-owned model outside a request needs the context set —
     * the global scope refuses rather than quietly returning everybody's rows.
     */
    private function firstField(JobKind $kind): JobKindAttribute
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => $kind->fields()->firstOrFail(),
        );
    }

    private function seedKinds(): void
    {
        app(JobKindProvisioner::class)->seedFor($this->tenant);
    }

    private function customer(): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'roles' => [PartyRole::Customer->value],
            'state_code' => '27',
        ]));
    }
}
