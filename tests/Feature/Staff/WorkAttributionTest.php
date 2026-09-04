<?php

namespace Tests\Feature\Staff;

use App\Enums\PartyRole;
use App\Models\Employee;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\StaffDesignation;
use App\Models\Tenant;
use App\Models\TransactionStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Who did the work an invoice was raised for — M22.
 *
 * Four things are being established here, and everything below is a variation on
 * one of them.
 *
 * **The trades are data.** There is no fitter column and no winder column. What a
 * sale asks about is the designations the workshop ticked, so a shop that starts
 * varnishing gets a third box without a deployment.
 *
 * **The counter can name a fitter without being able to read a wage.** Only OWNER
 * holds STAFF, deliberately — so the roster reaches the sale form under the
 * transactions grant, carrying names and nothing else.
 *
 * **A wrong name is correctable, and a posted invoice is still immutable.**
 * Nothing about attribution is a figure, so correcting it moves none — which is
 * just as well, because correcting a *sale* by reversing and reissuing it is
 * refused outright once the weighted average has moved.
 *
 * **Reversed work is not work.** The throughput figures ignore anything reversed,
 * which is what stops a correction counting the same motor twice.
 */
class WorkAttributionTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $bearing;

    private StaffDesignation $fitter;

    private StaffDesignation $winder;

    private Employee $ramesh;

    private Employee $sunil;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'STAFF'], ['WRITE', 'STAFF'], ['UPDATE', 'STAFF'], ['DELETE', 'STAFF'],
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['DELETE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'PARTIES'], ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $this->bearing = $this->variantFor($this->tenant, 'part');

        $this->actingForTenant($this->tenant, function () {
            $this->fitter = StaffDesignation::create(['name' => 'Fitter', 'track_on_sales' => true]);
            $this->winder = StaffDesignation::create(['name' => 'Winder', 'track_on_sales' => true]);

            $this->ramesh = $this->employee('Ramesh', $this->fitter);
            $this->sunil = $this->employee('Sunil', $this->winder);
        });
    }

    private function employee(string $name, ?StaffDesignation $designation = null): Employee
    {
        return Employee::create([
            'name' => $name,
            'designation_id' => $designation?->id,
            'salary_basis' => 'monthly',
            'pay_rate' => '18000.00',
            'joined_on' => '2026-01-01',
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
     * A posted invoice, optionally naming who did the work.
     *
     * @param  array<int, array{designation_id: int, employee_id: int|null}>  $staff
     * @return array<string, mixed>
     */
    private function postSale(array $staff = [], string $rate = '600.00'): array
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '1',
                    'unit_price' => $rate,
                ]],
                'staff' => $staff,
            ])
            ->assertCreated()
            ->json('data');
    }

    /* ---------------------------------------------------------------------
     | The trades are data
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_sale_asks_only_about_the_trades_the_workshop_ticked(): void
    {
        // A third trade exists and is not ticked. It must not reach the form —
        // a sale that asked who drove it is a form asking a question nobody
        // answers, which is how the answers stop being trusted.
        $this->actingForTenant($this->tenant, function () {
            StaffDesignation::create(['name' => 'Driver', 'track_on_sales' => false]);
        });

        $slots = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->assertOk()
            ->json('data.staff_slots');

        $this->assertSame(['Fitter', 'Winder'], array_column($slots, 'designation'));
    }

    #[Test]
    public function ticking_a_new_trade_puts_a_third_box_on_the_form(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/staff/designations', [
                'name' => 'Varnisher',
                'track_on_sales' => true,
            ])
            ->assertCreated();

        $slots = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->json('data.staff_slots');

        // No migration, no code change — the vocabulary is rows.
        $this->assertSame(['Fitter', 'Varnisher', 'Winder'], array_column($slots, 'designation'));
    }

    #[Test]
    public function an_archived_trade_stops_being_asked_about(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/staff/designations/{$this->winder->id}", ['is_active' => false])
            ->assertOk();

        $slots = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->json('data.staff_slots');

        $this->assertSame(['Fitter'], array_column($slots, 'designation'));
    }

    /* ---------------------------------------------------------------------
     | The roster carries names and nothing else
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_roster_never_carries_what_anybody_is_paid(): void
    {
        $slots = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->json('data.staff_slots');

        $this->assertSame(['id', 'name'], array_keys($slots[0]['employees'][0]));
    }

    #[Test]
    public function a_clerk_who_cannot_read_wages_can_still_name_a_fitter(): void
    {
        // The counter clerk this module is built for: they raise invoices and
        // hold no STAFF grant at all, because what people earn is not theirs to
        // read. They must still be able to say who did the job.
        [, $clerk] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['READ', 'PARTIES'],
        ]);

        $clerk->tenant_id = $this->tenant->id;
        $clerk->save();

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/staff')
            ->assertForbidden();

        $slots = $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/transactions/meta')
            ->assertOk()
            ->json('data.staff_slots');

        $this->assertSame(['Fitter', 'Winder'], array_column($slots, 'designation'));
    }

    #[Test]
    public function somebody_who_has_left_is_not_offered_on_a_new_sale(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/staff/{$this->sunil->id}", ['left_on' => now()->toDateString()])
            ->assertOk();

        $slots = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->json('data.staff_slots');

        $this->assertSame(['Ramesh'], array_column($slots[0]['employees'], 'name'));
    }

    /* ---------------------------------------------------------------------
     | Recording it on the sale
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_sale_records_who_fitted_it_and_who_wound_it(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
            ['designation_id' => $this->winder->id, 'employee_id' => $this->sunil->id],
        ]);

        $named = collect($sale['staff'])->pluck('employee', 'designation')->all();

        $this->assertSame(['Fitter' => 'Ramesh', 'Winder' => 'Sunil'], $named);
    }

    #[Test]
    public function a_slot_left_empty_records_nobody(): void
    {
        // The winder box was on screen and nothing was chosen. That is a
        // legitimate document, not a validation failure — plenty of invoices are
        // a fitting job with no winding in them.
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
            ['designation_id' => $this->winder->id, 'employee_id' => null],
        ]);

        $this->assertCount(1, $sale['staff']);
        $this->assertSame('Ramesh', $sale['staff'][0]['employee']);
    }

    #[Test]
    public function a_sale_with_nobody_named_still_posts(): void
    {
        $sale = $this->postSale();

        $this->assertSame([], $sale['staff']);
    }

    #[Test]
    public function a_purchase_cannot_be_credited_to_anybody(): void
    {
        $vendor = $this->party(PartyRole::Vendor);

        // Refused rather than accepted and ignored: goods arriving from a
        // supplier were not fitted by anybody in the building, and a silent
        // success would let the caller believe otherwise.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/purchase', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $vendor->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '5',
                    'unit_price' => '150.00',
                ]],
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTRIBUTION_NOT_A_SALE');
    }

    #[Test]
    public function a_refused_attribution_takes_the_whole_invoice_with_it(): void
    {
        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id,
                    'quantity' => '1',
                    'unit_price' => '600.00',
                ]],
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => 999999],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTRIBUTION_UNKNOWN_EMPLOYEE');

        // The point of the wrapper: an error beside a posted invoice, with
        // nothing saying which of the two happened, is the state nobody can act
        // on. Neither committed.
        $this->assertSame('10.000', $this->stockPositionOf($this->tenant, $this->bearing)['quantity']);
        $this->assertDatabaseCount('transaction_staff', 0);
    }

    #[Test]
    public function a_trade_the_workshop_does_not_ask_about_is_refused(): void
    {
        $driver = $this->actingForTenant(
            $this->tenant,
            fn () => StaffDesignation::create(['name' => 'Driver', 'track_on_sales' => false]),
        );

        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '600.00',
                ]],
                'staff' => [['designation_id' => $driver->id, 'employee_id' => $this->ramesh->id]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTRIBUTION_UNTRACKED_DESIGNATION');
    }

    #[Test]
    public function another_workshops_employee_cannot_be_credited(): void
    {
        [$other] = $this->tenantWithUser();

        $stranger = $this->actingForTenant($other, fn () => Employee::create([
            'name' => 'Someone Else',
            'salary_basis' => 'monthly',
            'pay_rate' => '1.00',
            'joined_on' => '2026-01-01',
        ]));

        $customer = $this->party(PartyRole::Customer);
        $this->receiveStock($this->tenant, $this->bearing, '10', '150.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '600.00',
                ]],
                'staff' => [[
                    'designation_id' => $this->fitter->id, 'employee_id' => $stranger->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTRIBUTION_UNKNOWN_EMPLOYEE');
    }

    /* ---------------------------------------------------------------------
     | Correcting a name on a posted invoice
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_wrong_fitter_can_be_swapped_for_the_right_one(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        $corrected = $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$sale['id']}/staff", [
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->sunil->id],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('Sunil', $corrected['staff'][0]['employee']);

        // The invoice itself has not moved. That is the whole licence for
        // allowing the edit at all.
        $this->assertSame($sale['total'], $corrected['total']);
        $this->assertSame('posted', $corrected['status']);
        $this->assertSame($sale['doc_no'], $corrected['doc_no']);
    }

    #[Test]
    public function a_name_can_be_taken_off_a_posted_invoice(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
            ['designation_id' => $this->winder->id, 'employee_id' => $this->sunil->id],
        ]);

        // The null is what does it. A PATCH that could only ever replace would
        // make a mis-picked name permanent, which is half a correction.
        $corrected = $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$sale['id']}/staff", [
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
                    ['designation_id' => $this->winder->id, 'employee_id' => null],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $corrected['staff']);
        $this->assertSame('Fitter', $corrected['staff'][0]['designation']);
    }

    #[Test]
    public function a_correction_is_on_the_audit_trail(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$sale['id']}/staff", [
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->sunil->id],
                ],
            ])
            ->assertOk();

        /*
        | The trail is the whole safeguard here. Everywhere else in this
        | application a posted document cannot be touched, so the posting is the
        | record; this row can be changed for as long as the invoice exists, and
        | without an entry "the report says Ramesh did forty jobs" would be
        | unanswerable the moment anybody doubted it.
        */
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'resource' => 'sale_attribution',
            'action' => 'updated',
        ]);
    }

    #[Test]
    public function re_saving_the_same_names_writes_nothing_to_the_trail(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        $before = \App\Models\AuditLog::where('resource', 'sale_attribution')->count();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$sale['id']}/staff", [
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
                ],
            ])
            ->assertOk();

        // Rows are matched and updated in place rather than cleared and
        // rewritten — so an unchanged trade is left completely alone, and the
        // trail stays a record of decisions rather than of saves.
        $this->assertSame($before, \App\Models\AuditLog::where('resource', 'sale_attribution')->count());
    }

    #[Test]
    public function a_trade_that_is_no_longer_asked_about_stays_correctable(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->winder->id, 'employee_id' => $this->sunil->id],
        ]);

        // The workshop stops recording winding. Last quarter's invoice still has
        // a winder on it, and a refusal about today's configuration must not
        // block a statement about the past.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/staff/designations/{$this->winder->id}", ['track_on_sales' => false])
            ->assertOk();

        $corrected = $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$sale['id']}/staff", [
                'staff' => [
                    ['designation_id' => $this->winder->id, 'employee_id' => $this->ramesh->id],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('Ramesh', $corrected['staff'][0]['employee']);
    }

    #[Test]
    public function correcting_a_name_needs_the_grant_to_change_a_transaction(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        [, $reader] = $this->tenantWithUser([['READ', 'TRANSACTIONS']]);

        $reader->tenant_id = $this->tenant->id;
        $reader->save();

        $this->withHeaders($this->authHeader($reader))
            ->patchJson("/api/v1/transactions/{$sale['id']}/staff", [
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->sunil->id],
                ],
            ])
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | The customer never sees it
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_customers_copy_of_the_invoice_names_nobody(): void
    {
        $sale = $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        $url = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale['id']}/share")
            ->assertOk()
            ->json('data.url');

        // The customer's page, fetched exactly as they would open it — unscoped,
        // tenancy established from the token.
        $page = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->getContent();

        /*
        | Whose hands were on the motor is the workshop's business. A customer
        | who learns that the apprentice wound it has been handed an argument
        | about the price — which is the same reasoning that keeps the cost of
        | every line off this document.
        |
        | Guaranteed structurally rather than by this assertion:
        | InvoiceDocumentService builds the customer's document from its own list
        | of fields, so there is no branch anywhere that could include this. The
        | test is here to catch somebody adding one.
        */
        $this->assertStringNotContainsString('Ramesh', $page);
    }

    /* ---------------------------------------------------------------------
     | How much work somebody is doing
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_work_report_counts_the_jobs_and_adds_up_the_invoices(): void
    {
        $this->postSale(
            [['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id]],
            '600.00',
        );

        $this->postSale(
            [['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id]],
            '400.00',
        );

        // Somebody else's motor. It must not reach Ramesh's figures.
        $this->postSale(
            [['designation_id' => $this->winder->id, 'employee_id' => $this->sunil->id]],
            '900.00',
        );

        $report = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/staff/{$this->ramesh->id}/work")
            ->assertOk()
            ->json();

        $this->assertSame(2, $report['meta']['summary']['job_count']);

        // The *invoice* value, gross — ₹1,000 of goods at 18%. Deliberately what
        // the documents came to rather than their taxable value: the figure an
        // owner is reading it against is the day book, and two numbers on two
        // screens that disagree by the GST is worse than one that is explained.
        $this->assertSame('1180.00', $report['meta']['summary']['invoice_value']);
        $this->assertCount(2, $report['data']);
        $this->assertSame(['Fitter'], $report['data'][0]['trades']);
    }

    #[Test]
    public function a_reversed_invoice_stops_counting_as_work_done(): void
    {
        $sale = $this->postSale(
            [['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id]],
            '600.00',
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$sale['id']}/reverse")
            ->assertCreated();

        $summary = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/staff/{$this->ramesh->id}/work")
            ->assertOk()
            ->json('meta.summary');

        // A repair that was billed and then cancelled is not work anybody did.
        // This is also what keeps a *correction* honest — a revision reverses the
        // original and posts a replacement, and without this the same motor
        // would be counted twice.
        $this->assertSame(0, $summary['job_count']);
        $this->assertSame('0.00', $summary['invoice_value']);
    }

    #[Test]
    public function the_period_narrows_what_is_counted(): void
    {
        $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        $summary = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/staff/{$this->ramesh->id}/work?from=2020-01-01&to=2020-12-31")
            ->assertOk()
            ->json('meta.summary');

        $this->assertSame(0, $summary['job_count']);
    }

    #[Test]
    public function reading_somebodys_throughput_needs_the_staff_grant(): void
    {
        [, $clerk] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
        ]);

        $clerk->tenant_id = $this->tenant->id;
        $clerk->save();

        // The counter can name a fitter and cannot read how they are doing.
        // "Which of my people is getting the work through" is a question about
        // staff, and its answer sits beside their wages on the same screen.
        $this->withHeaders($this->authHeader($clerk))
            ->getJson("/api/v1/staff/{$this->ramesh->id}/work")
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Nothing loses the name that explains it
     |-------------------------------------------------------------------- */

    #[Test]
    public function somebody_credited_with_work_cannot_be_deleted(): void
    {
        $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/staff/{$this->ramesh->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPLOYEE_IN_USE');

        // Refused with a sentence rather than by the foreign key with a 500.
        $this->assertDatabaseHas('employees', ['id' => $this->ramesh->id]);
    }

    #[Test]
    public function a_trade_that_is_on_an_invoice_cannot_be_deleted(): void
    {
        $this->postSale([
            ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
        ]);

        // Nobody holds the designation any more, so the employee guard would let
        // it go — and taking it would strip the record of who did the work off a
        // posted invoice that can never be edited again.
        $this->actingForTenant($this->tenant, function () {
            $this->ramesh->designation_id = null;
            $this->ramesh->save();
        });

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/staff/designations/{$this->fitter->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DESIGNATION_IN_USE')
            ->assertJsonPath('error.details.attribution_count', 1);
    }

    #[Test]
    public function discarding_a_draft_takes_its_attribution_with_it(): void
    {
        $customer = $this->party(PartyRole::Customer);

        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/sale', [
                'date' => now()->toDateString(),
                'post' => false,
                'party_id' => $customer->id,
                'items' => [[
                    'variant_id' => $this->bearing->id, 'quantity' => '1', 'unit_price' => '600.00',
                ]],
                'staff' => [
                    ['designation_id' => $this->fitter->id, 'employee_id' => $this->ramesh->id],
                ],
            ])
            ->assertCreated()
            ->json('data');

        // A draft keeps the names that were picked — reopening one that had
        // silently dropped them is how the figures go wrong quietly.
        $this->assertSame('Ramesh', $draft['staff'][0]['employee']);

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$draft['id']}")
            ->assertOk();

        $this->assertSame(0, TransactionStaff::withoutGlobalScopes()->count());
    }
}
