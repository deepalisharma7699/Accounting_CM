<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Which module cards sit at the top of a workshop's home screen.
 *
 * The grid has one card per module that exists (§1.3), and there are enough of
 * them now that the three a counter opens every day are below the fold. This is
 * the list that lifts them — stored on the workshop, set by whoever configures
 * the workshop, and read by everybody who signs in to it.
 *
 * Two properties carry most of the weight here, and neither is obvious:
 *
 * - it is a **replacement**, not a patch, so an empty array is a real answer.
 *   PUT rather than PATCH for exactly that reason.
 * - **reading it needs no grant.** It rides in /auth/me, because a clerk holds
 *   no READ:WORKSPACE and still has to see the home screen their owner arranged.
 */
class FavouriteModulesTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'WORKSPACE'], ['UPDATE', 'WORKSPACE'],
        ]);
    }

    /** Two keys that are certain to be enabled, whatever the registry holds. */
    private function twoModules(): array
    {
        return array_slice(array_keys(Modules::all()), 0, 2);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_owner_can_choose_the_cards_that_sit_at_the_top(): void
    {
        [$first, $second] = $this->twoModules();

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', [
                'favourite_modules' => [$second, $first],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.favourite_modules', [$second, $first]);

        // The order is the owner's, not the registry's: the row reads the way
        // they arranged it.
        $this->assertSame([$second, $first], $this->tenant->fresh()->favourite_modules);
    }

    /**
     * An empty list is "none starred", not "leave it alone".
     *
     * The whole reason this is a PUT. On a PATCH an absent key and an empty one
     * would have to mean different things, and there would be no way to say that
     * the last favourite has been removed.
     */
    #[Test]
    public function unstarring_the_last_card_clears_the_list(): void
    {
        $this->tenant->update(['favourite_modules' => $this->twoModules()]);

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', ['favourite_modules' => []])
            ->assertOk()
            ->assertJsonPath('data.settings.favourite_modules', []);

        $this->assertSame([], $this->tenant->fresh()->favourite_modules);
    }

    #[Test]
    public function it_replaces_the_whole_list_rather_than_adding_to_it(): void
    {
        [$first, $second] = $this->twoModules();

        $this->tenant->update(['favourite_modules' => [$first]]);

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', ['favourite_modules' => [$second]])
            ->assertOk()
            ->assertJsonPath('data.settings.favourite_modules', [$second]);
    }

    #[Test]
    public function it_de_duplicates_a_key_sent_twice(): void
    {
        [$first] = $this->twoModules();

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', [
                'favourite_modules' => [$first, $first],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.favourite_modules', [$first]);
    }

    /* ---------------------------------------------------------------------
     | What it refuses
     |-------------------------------------------------------------------- */

    /**
     * Checked against the registry, exactly as the fragment route checks the key
     * in its URL.
     *
     * Not tidiness: an unchecked key would sit in the column for ever matching no
     * card, and would spend one of the eight places on a favourite that can never
     * appear.
     */
    #[Test]
    public function it_refuses_a_key_that_is_not_a_module(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', [
                'favourite_modules' => ['sales', 'not-a-module'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['favourite_modules.1']]]]);

        $this->assertNull($this->tenant->fresh()->favourite_modules);
    }

    #[Test]
    public function it_refuses_a_module_that_is_switched_off(): void
    {
        $disabled = array_diff(array_keys(Modules::declared()), array_keys(Modules::all()));

        if ($disabled === []) {
            $this->markTestSkipped('Every module is enabled.');
        }

        // A card that does not exist cannot be starred — off is off, not merely
        // unlisted, which is the rule the fragment route already keeps.
        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', [
                'favourite_modules' => [reset($disabled)],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function it_refuses_more_than_the_cap(): void
    {
        $tooMany = array_slice(array_keys(Modules::all()), 0, 9);

        if (count($tooMany) < 9) {
            $this->markTestSkipped('Fewer enabled modules than the cap.');
        }

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', ['favourite_modules' => $tooMany])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['favourite_modules']]]]);
    }

    /**
     * A reader may look and may not rearrange.
     *
     * The stars are hidden for this caller, and that is presentation only: the
     * grant is checked here too (§6.2).
     */
    #[Test]
    public function a_reader_cannot_rearrange_the_home_screen(): void
    {
        [, $reader] = $this->tenantWithUser([['READ', 'WORKSPACE']]);

        $this->withHeaders($this->authHeader($reader))
            ->putJson('/api/v1/workspace/favourites', [
                'favourite_modules' => $this->twoModules(),
            ])
            ->assertForbidden();
    }

    /**
     * One URL, no id in it, so there is nothing to tamper with — the property
     * every /workspace route has.
     */
    #[Test]
    public function it_only_ever_writes_the_callers_own_workshop(): void
    {
        [$first] = $this->twoModules();

        $other = Tenant::factory()->create(['name' => 'Someone Else Motors']);
        $other->update(['favourite_modules' => []]);

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson('/api/v1/workspace/favourites', ['favourite_modules' => [$first]])
            ->assertOk();

        $this->assertSame([], $other->fresh()->favourite_modules);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * The dashboard reads the list out of the session it already fetches.
     *
     * That is the point of publishing it here rather than only on /workspace:
     * home paints after /auth/me and before anything else, so the favourites cost
     * no second request — and a clerk with no workspace grant still gets them.
     */
    #[Test]
    public function the_favourites_arrive_with_the_session(): void
    {
        [$first, $second] = $this->twoModules();

        $this->tenant->update(['favourite_modules' => [$first, $second]]);

        // In this workshop, not a new one: the point is a colleague of the owner
        // who holds no workspace grant at all.
        $clerk = User::factory()->forTenant($this->tenant)
            ->withRole($this->roleWith([['READ', 'TRANSACTIONS']], 'Counter Clerk'))
            ->create();

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.favourite_modules', [$first, $second]);
    }

    #[Test]
    public function a_workshop_that_has_never_chosen_reports_an_empty_list(): void
    {
        // Null in the column and `[]` on the wire. The distinction is kept in the
        // database and deliberately not published: every reader wants a list.
        $this->assertNull($this->tenant->fresh()->favourite_modules);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.settings.favourite_modules', []);
    }

    /**
     * A module switched off after it was starred stops being published.
     *
     * The column keeps the key, which is the half worth knowing: `enabled` is a
     * deployment decision that gets reversed, and a workshop should not have to
     * re-star a card because one was flipped off for a week.
     */
    #[Test]
    public function a_key_for_a_module_that_has_been_switched_off_is_not_published(): void
    {
        [$first, $second] = $this->twoModules();

        $this->tenant->update(['favourite_modules' => [$first, $second]]);

        config()->set("modules.{$first}.enabled", false);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.settings.favourite_modules', [$second]);

        $this->assertSame([$first, $second], $this->tenant->fresh()->favourite_modules);
    }
}
