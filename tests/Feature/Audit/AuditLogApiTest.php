<?php

namespace Tests\Feature\Audit;

use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Reading the trail over HTTP — M13.
 */
class AuditLogApiTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'AUDIT'], ['READ', 'PARTIES'], ['WRITE', 'PARTIES'], ['UPDATE', 'PARTIES'],
            ['READ', 'ACCOUNTS'], ['WRITE', 'ACCOUNTS'], ['UPDATE', 'ACCOUNTS'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_lists_the_trail_newest_first(): void
    {
        $id = $this->createParty('Bharat Motors');
        $this->editParty($id, ['phone' => '9812345678']);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/audit-logs')
            ->assertOk();

        $this->assertSame('updated', $response->json('data.0.action'));
        $this->assertSame('created', $response->json('data.1.action'));
        $this->assertSame('Bharat Motors', $response->json('data.0.label'));
        $this->assertSame($this->owner->name, $response->json('data.0.actor.name'));

        // A list, sorted by field name — the stored map's key order is MySQL's
        // and is arbitrary. See AuditLog::changedFields().
        $this->assertSame('phone', $response->json('data.0.changes.0.field'));
        $this->assertSame('9812345678', $response->json('data.0.changes.0.to'));
    }

    #[Test]
    public function one_record_has_its_own_history(): void
    {
        $first = $this->createParty('Bharat Motors');
        $second = $this->createParty('Alpha Traders');

        $this->editParty($first, ['phone' => '9812345678']);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/audit-logs?resource=party&resource_id={$first}")
            ->assertOk();

        // No second URL for this: one record's history is the list at a
        // different filter. A second endpoint would be a second thing to keep
        // in step, and the second one always drifts.
        $this->assertCount(2, $response->json('data'));
        $this->assertNotContains('Alpha Traders', array_column($response->json('data'), 'label'));
        $this->assertNotNull($second);
    }

    #[Test]
    public function it_filters_by_action_and_by_person(): void
    {
        $account = $this->createAccount('5910', 'Electricity');
        $this->archiveAccount($account);

        $byAction = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/audit-logs?action=archived')
            ->assertOk();

        $this->assertCount(1, $byAction->json('data'));
        $this->assertSame('5910 · Electricity', $byAction->json('data.0.label'));

        $byActor = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/audit-logs?actor_id='.$this->owner->id)
            ->assertOk();

        $this->assertNotEmpty($byActor->json('data'));
    }

    #[Test]
    public function an_unknown_filter_is_refused_rather_than_ignored(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/audit-logs?resource=journal_entry')
            ->assertStatus(422);

        // Silently ignoring it would show a complete history to somebody who
        // believes they are looking at a filtered one — and they would draw a
        // conclusion from the difference.
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/audit-logs?resource_id=4')
            ->assertStatus(422);

        // An id with no kind beside it would otherwise sweep every party, item
        // and account that happens to share it.
        $this->assertStringContainsString('which kind', $response->json('error.details.fields.resource.0'));
    }

    #[Test]
    public function meta_publishes_the_filters_and_the_people_on_the_trail(): void
    {
        $this->createParty('Bharat Motors');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/audit-logs/meta')
            ->assertOk();

        $this->assertContains('party', array_column($response->json('data.resources'), 'value'));
        $this->assertContains('archived', array_column($response->json('data.actions'), 'value'));

        // Read from the trail rather than from the user list: somebody who has
        // left still appears in the history, and a filter built from current
        // users could not select them.
        $this->assertContains($this->owner->name, array_column($response->json('data.actors'), 'name'));
        $this->assertGreaterThan(0, $response->json('meta.total'));
    }

    /* ---------------------------------------------------------------------
     | Authority and isolation
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_data_entry_user_cannot_read_the_trail(): void
    {
        $clerk = $this->actingForTenant($this->tenant, fn () => User::factory()
            ->forTenant($this->tenant)
            ->withRole($this->roleWith([['READ', 'PARTIES'], ['WRITE', 'PARTIES']], 'Clerk Role'))
            ->create());

        // The trail records what this user did; reading it is a different
        // authority, and DATA_ENTRY deliberately does not hold it.
        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }

    #[Test]
    public function the_trail_has_no_write_verbs_at_all(): void
    {
        $headers = $this->authHeader($this->owner);

        // There is no route that could put a claim on the trail or take one off
        // it — the shape of the module, asserted rather than assumed.
        //
        // A 405 on the collection (the URI exists, the verb does not) and a 404
        // on a single entry (no such URI at any verb). Both are refusals; the
        // second is the stronger one.
        $this->withHeaders($headers)->postJson('/api/v1/audit-logs', [])->assertStatus(405);
        $this->withHeaders($headers)->deleteJson('/api/v1/audit-logs/1')->assertNotFound();
        $this->withHeaders($headers)->patchJson('/api/v1/audit-logs/1', [])->assertNotFound();
    }

    #[Test]
    public function one_workshop_never_sees_anothers_trail(): void
    {
        $this->createParty('Mine Only');

        [$other, $stranger] = $this->tenantWithUser([['READ', 'AUDIT'], ['WRITE', 'PARTIES']], 'Stranger Role');

        $this->actingForTenant($other, fn () => Party::factory()->create(['name' => 'Theirs Only']));

        $labels = array_column(
            $this->withHeaders($this->authHeader($stranger))
                ->getJson('/api/v1/audit-logs')
                ->assertOk()
                ->json('data'),
            'label',
        );

        $this->assertContains('Theirs Only', $labels);
        $this->assertNotContains('Mine Only', $labels);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    private function createParty(string $name): int
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/parties', ['name' => $name, 'roles' => ['customer']])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function editParty(int $id, array $changes): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/parties/{$id}", $changes)
            ->assertOk();
    }

    private function createAccount(string $code, string $name): int
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/accounts', ['code' => $code, 'name' => $name, 'type' => 'expense'])
            ->assertCreated()
            ->json('data.id');
    }

    private function archiveAccount(int $id): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$id}", ['is_active' => false])
            ->assertOk();
    }
}
