<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Passkey;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Signing in with a passkey.
 *
 * The happy path is one test. The rest are the ways a WebAuthn implementation
 * is wrong while appearing to work — and every one of them looks like a
 * successful sign-in to anybody testing by hand on their own laptop:
 *
 *   - the challenge is not actually checked, so a recorded assertion replays;
 *   - the challenge is checked but not spent, so it replays for five minutes;
 *   - the origin is not checked, so a phishing site's assertion is accepted;
 *   - the credential is not tied to its account, so one signs in as another;
 *   - user verification is not enforced, so possession alone gets in.
 *
 * These are the tests that make the scheme worth more than the password it
 * replaces, so they are the bulk of the file.
 */
class PasskeyTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private const ORIGIN = 'https://books.example.test';

    private const RP_ID = 'books.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', self::ORIGIN);
        config()->set('webauthn.rp.id', self::RP_ID);
        config()->set('webauthn.origins', [self::ORIGIN]);
    }

    /* ---------------------------------------------------------------------
     | Enrolling
     | ------------------------------------------------------------------ */

    public function test_a_signed_in_person_can_enrol_a_device_and_then_sign_in_with_it(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;

        $this->enrol($user, $device, 'Ramesh phone');

        $this->assertDatabaseHas('passkeys', [
            'user_id' => $user->id,
            'credential_id' => $device->credentialId(),
            'label' => 'Ramesh phone',
        ]);

        // …and now, with no session and no email address typed anywhere:
        $response = $this->signInWith($device, $user);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['access_token', 'user']]);

        // The refresh token stays in the HttpOnly cookie, never the body.
        $this->assertArrayNotHasKey('refresh_token', $response->json('data'));
        $response->assertCookie(config('jwt.cookie.name'));
    }

    public function test_enrolling_requires_a_session(): void
    {
        /*
        | The boundary the whole design rests on. Enrolment adds a way into an
        | account; if it were reachable unauthenticated, anybody who could load
        | the sign-in screen could register their own phone against it.
        */
        $this->postJson('/api/v1/auth/passkeys/options')->assertUnauthorized();

        $this->postJson('/api/v1/auth/passkeys', [
            'state' => 'anything',
            'credential' => [],
            'label' => 'Mine',
        ])->assertUnauthorized();
    }

    public function test_a_registration_ceremony_cannot_be_finished_by_a_different_account(): void
    {
        $victim = $this->activeUser();
        $attacker = $this->activeUser('attacker@demo.test');
        $device = new VirtualAuthenticator;

        // The victim starts an enrolment…
        $options = $this->postJson('/api/v1/auth/passkeys/options', [], $this->authHeader($victim))
            ->assertOk()
            ->json('data');

        // …and the attacker tries to finish it, enrolling their own device.
        $this->postJson('/api/v1/auth/passkeys', [
            'state' => $options['state'],
            'credential' => $device->attest(
                $options['options']['challenge'],
                self::ORIGIN,
                self::RP_ID,
            ),
            'label' => 'Not mine',
        ], $this->authHeader($attacker))->assertUnauthorized();

        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_one_device_cannot_be_enrolled_against_two_accounts(): void
    {
        $first = $this->activeUser();
        $second = $this->activeUser('second@demo.test');
        $device = new VirtualAuthenticator;

        $this->enrol($first, $device, 'Shared phone');

        // A credential id is unique across the table: sign-in looks an account
        // up *by* it, so a second claim would make that answer ambiguous.
        $this->enrolResponse($second, $device, 'Same phone')->assertStatus(409);

        $this->assertDatabaseCount('passkeys', 1);
    }

    /* ---------------------------------------------------------------------
     | The challenge
     | ------------------------------------------------------------------ */

    public function test_a_challenge_is_spent_by_the_first_use(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        $assertion = $device->assert($challenge, self::ORIGIN, self::RP_ID, $this->handle($user));

        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $assertion,
        ])->assertOk();

        /*
        | The same assertion again. This is the replay: somebody who captured
        | one response — a proxy, a shared machine's history, a log — must not
        | be able to post it a second time. The challenge is pulled from the
        | cache when it is claimed, so the state no longer names anything.
        */
        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $assertion,
        ])->assertStatus(400)->assertJsonPath('error.code', 'PASSKEY_CEREMONY_EXPIRED');
    }

    public function test_an_assertion_signed_over_another_challenge_is_refused(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        // Two ceremonies open at once; answer one with the other's challenge.
        $current = $this->loginChallenge();
        $stale = $this->loginChallenge();

        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $current['state'],
            'credential' => $device->assert($stale['challenge'], self::ORIGIN, self::RP_ID, $this->handle($user)),
        ])->assertUnauthorized()->assertJsonPath('error.code', 'PASSKEY_REJECTED');
    }

    public function test_a_registration_state_cannot_finish_a_sign_in(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        $registration = $this->postJson('/api/v1/auth/passkeys/options', [], $this->authHeader($user))
            ->assertOk()
            ->json('data');

        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $registration['state'],
            'credential' => $device->assert(
                $registration['options']['challenge'],
                self::ORIGIN,
                self::RP_ID,
                $this->handle($user),
            ),
        ])->assertStatus(400)->assertJsonPath('error.code', 'PASSKEY_CEREMONY_EXPIRED');
    }

    /* ---------------------------------------------------------------------
     | The origin — the phishing resistance
     | ------------------------------------------------------------------ */

    public function test_an_assertion_produced_on_another_origin_is_refused(): void
    {
        /*
        | The property that makes a passkey better than a password rather than
        | merely more convenient. A convincing copy of this site on a domain
        | somebody was sent by SMS can ask a device for an assertion — but the
        | browser writes the origin it is *actually* on into the signed client
        | data, and this refuses it. A password would simply have been typed in.
        */
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $device->assert(
                $challenge,
                'https://books-example.test.evil.example',
                self::RP_ID,
                $this->handle($user),
            ),
        ])->assertUnauthorized()->assertJsonPath('error.code', 'PASSKEY_REJECTED');
    }

    public function test_an_assertion_over_another_relying_party_is_refused(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        // Right origin in the client data, wrong rpIdHash in the signed
        // authenticator data — the second half of the same binding.
        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $device->assert($challenge, self::ORIGIN, 'evil.example', $this->handle($user)),
        ])->assertUnauthorized();
    }

    /* ---------------------------------------------------------------------
     | Whose credential it is
     | ------------------------------------------------------------------ */

    public function test_a_credential_cannot_sign_in_as_a_different_account(): void
    {
        $owner = $this->activeUser();
        $other = $this->activeUser('other@demo.test');

        $device = new VirtualAuthenticator;
        $this->enrol($owner, $device);

        // The other account enrols too, so the handle below is a real one
        // belonging to a real person — not an empty string, which would let
        // this pass for the wrong reason.
        $this->enrol($other, new VirtualAuthenticator);

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        // The device signs correctly, but claims the other account's handle.
        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $device->assert($challenge, self::ORIGIN, self::RP_ID, $this->handle($other)),
        ])->assertUnauthorized();
    }

    public function test_an_assertion_carrying_no_user_handle_is_refused(): void
    {
        /*
        | Every credential here is discoverable, so its device knows which
        | account it belongs to and says so. An assertion with that field
        | missing has not been matched to an account by anything, and accepting
        | it would mean the only thing tying a credential to a person is this
        | server's own lookup — which is exactly the check being skipped.
        */
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        $assertion = $device->assert($challenge, self::ORIGIN, self::RP_ID, $this->handle($user));
        $assertion['response']['userHandle'] = null;

        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $assertion,
        ])->assertUnauthorized();
    }

    public function test_an_unknown_credential_is_refused_without_saying_so(): void
    {
        $stranger = new VirtualAuthenticator;

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        $response = $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $stranger->assert($challenge, self::ORIGIN, self::RP_ID, 'nobody'),
        ]);

        /*
        | Identical to every other refusal. "No such credential" and "bad
        | signature" being distinguishable would tell somebody probing which of
        | the two to keep working on — the same reason the password path answers
        | one way for an unknown email and a wrong password.
        */
        $response->assertUnauthorized()->assertJsonPath('error.code', 'PASSKEY_REJECTED');
    }

    public function test_an_assertion_without_user_verification_is_refused(): void
    {
        /*
        | Every credential here is enrolled with user verification required, so
        | an assertion means the device was unlocked by its owner. Accepting one
        | without the UV flag would reduce a passkey to "this phone was
        | present", which is what a pickpocket has.
        */
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $device->assert(
                $challenge,
                self::ORIGIN,
                self::RP_ID,
                $this->handle($user),
                userVerified: false,
            ),
        ])->assertUnauthorized();
    }

    public function test_a_counter_that_goes_backwards_is_refused(): void
    {
        /*
        | Two things answering for one credential is a cloned authenticator.
        | Only meaningful for devices that keep a counter at all — a synced
        | passkey reports zero for ever, which is why the check is a comparison
        | and not a requirement that it move.
        */
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        $first = $this->loginChallenge();
        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $first['state'],
            'credential' => $device->assert(
                $first['challenge'],
                self::ORIGIN,
                self::RP_ID,
                $this->handle($user),
                counter: 40,
            ),
        ])->assertOk();

        $second = $this->loginChallenge();
        $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $second['state'],
            'credential' => $device->assert(
                $second['challenge'],
                self::ORIGIN,
                self::RP_ID,
                $this->handle($user),
                counter: 9,
            ),
        ])->assertUnauthorized();
    }

    /* ---------------------------------------------------------------------
     | Who may start a session at all
     | ------------------------------------------------------------------ */

    public function test_a_suspended_account_cannot_sign_in_with_a_passkey(): void
    {
        /*
        | The reason AuthService::startSession() exists. A second way in that
        | skipped these checks would leave a suspended employee — or a workshop
        | whose account was closed — still posting entries, with the passkey
        | they enrolled while they still worked there.
        */
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->signInWith($device, $user)->assertForbidden();
    }

    public function test_a_suspended_workshop_cannot_sign_in_with_a_passkey(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        $user->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->signInWith($device, $user)->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | The trusted session
     | ------------------------------------------------------------------ */

    public function test_a_passkey_session_is_trusted_and_a_password_session_is_not(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        RefreshToken::query()->delete();

        $this->signInWith($device, $user)->assertOk();

        $this->assertTrue(
            (bool) RefreshToken::query()->latest('id')->first()?->trusted,
            'A passkey sign-in should start a trusted session.'
        );

        RefreshToken::query()->delete();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Str0ng#Passw0rd!',
        ])->assertOk();

        $this->assertFalse(
            (bool) RefreshToken::query()->latest('id')->first()?->trusted,
            'A password sign-in must not buy the long session length.'
        );
    }

    public function test_a_trusted_session_stays_trusted_when_its_token_rotates(): void
    {
        /*
        | The bug this pins: rotation re-minting with the default lifetime.
        | Nothing fails at the time — the person is signed in, everything works
        | — and they are simply signed out a week later, which is indis-
        | tinguishable from the feature never having been built.
        */
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        $response = $this->signInWith($device, $user)->assertOk();

        $cookie = $response->getCookie(config('jwt.cookie.name'), false)->getValue();

        $this->withCredentials()
            ->withUnencryptedCookie(config('jwt.cookie.name'), $cookie)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        $latest = RefreshToken::query()->latest('id')->first();

        $this->assertTrue((bool) $latest?->trusted);
        $this->assertTrue(
            $latest->expires_at->greaterThan(now()->addSeconds(config('jwt.ttl.refresh'))),
            'A rotated trusted token kept only the ordinary lifetime.'
        );
    }

    /* ---------------------------------------------------------------------
     | Managing them
     | ------------------------------------------------------------------ */

    public function test_a_person_sees_only_their_own_passkeys(): void
    {
        $user = $this->activeUser();
        $other = $this->activeUser('other@demo.test');

        $this->enrol($user, new VirtualAuthenticator, 'Mine');
        $this->enrol($other, new VirtualAuthenticator, 'Theirs');

        $response = $this->getJson('/api/v1/auth/passkeys', $this->authHeader($user))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.label'));
    }

    public function test_the_stored_credential_is_never_returned(): void
    {
        $user = $this->activeUser();
        $this->enrol($user, new VirtualAuthenticator);

        $response = $this->getJson('/api/v1/auth/passkeys', $this->authHeader($user))->assertOk();

        $response->assertJsonMissingPath('data.0.credential');
        $response->assertJsonMissingPath('data.0.credential_id');
    }

    public function test_one_person_cannot_rename_or_remove_another_persons_passkey(): void
    {
        $owner = $this->activeUser();
        $attacker = $this->activeUser('attacker@demo.test');

        $this->enrol($owner, new VirtualAuthenticator, 'Owner phone');
        $passkey = Passkey::query()->firstOrFail();

        // Not found rather than refused: the scoping *is* the authorization,
        // so there is no branch to forget and nothing for an id to be probed
        // with.
        $this->patchJson("/api/v1/auth/passkeys/{$passkey->id}", ['label' => 'Taken'], $this->authHeader($attacker))
            ->assertNotFound();

        $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}", [], $this->authHeader($attacker))
            ->assertNotFound();

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id, 'label' => 'Owner phone']);
    }

    public function test_removing_a_passkey_stops_it_signing_in(): void
    {
        $user = $this->activeUser();
        $device = new VirtualAuthenticator;
        $this->enrol($user, $device);

        $passkey = Passkey::query()->firstOrFail();

        $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}", [], $this->authHeader($user))->assertOk();

        $this->signInWith($device, $user)->assertUnauthorized();
    }

    public function test_enrolling_and_removing_a_device_is_recorded_in_the_workshops_history(): void
    {
        $user = $this->activeUser();
        $this->enrol($user, new VirtualAuthenticator, 'Ramesh phone');

        $passkey = Passkey::query()->firstOrFail();

        /*
        | The two acts a trail exists for. Enrolling adds a way into an account
        | and removing takes one away, and neither leaves any other mark — the
        | user's own row is untouched — so without these rows a workshop could
        | not answer when a phone became able to open its books.
        |
        | The tenant is the point of the assertion. A passkey has no tenant_id
        | of its own, so the default would file both entries under no workshop
        | at all, where the owner who needs to read them cannot: they would be
        | written, and invisible, which is worse than absent.
        */
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $user->tenant_id,
            'resource' => AuditResource::Passkey->value,
            'resource_id' => $passkey->id,
            'action' => AuditAction::Created->value,
            'actor_id' => $user->id,
            'label' => 'Ramesh phone',
        ]);

        $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}", [], $this->authHeader($user))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $user->tenant_id,
            'resource' => AuditResource::Passkey->value,
            'resource_id' => $passkey->id,
            'action' => AuditAction::Deleted->value,
            'actor_id' => $user->id,
            'label' => 'Ramesh phone',
        ]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    private function activeUser(string $email = 'owner@demo.test'): User
    {
        return User::factory()
            ->withRole($this->roleWith([['READ', 'ITEMS']]))
            ->create([
                'email' => $email,
                'status' => UserStatus::Active,
                'password' => 'Str0ng#Passw0rd!',
                'tenant_id' => Tenant::factory()->create()->id,
            ]);
    }

    /** The opaque handle the person's devices know them by. */
    private function handle(User $user): string
    {
        return (string) $user->refresh()->passkey_handle;
    }

    /**
     * Run a full enrolment for a user, asserting it succeeded.
     */
    private function enrol(User $user, VirtualAuthenticator $device, string $label = 'Test device'): void
    {
        $this->enrolResponse($user, $device, $label)->assertCreated();
    }

    private function enrolResponse(User $user, VirtualAuthenticator $device, string $label = 'Test device'): TestResponse
    {
        $options = $this->postJson('/api/v1/auth/passkeys/options', [], $this->authHeader($user))
            ->assertOk()
            ->json('data');

        return $this->postJson('/api/v1/auth/passkeys', [
            'state' => $options['state'],
            'credential' => $device->attest(
                $options['options']['challenge'],
                self::ORIGIN,
                self::RP_ID,
            ),
            'label' => $label,
        ], $this->authHeader($user));
    }

    /**
     * @return array{state: string, challenge: string}
     */
    private function loginChallenge(): array
    {
        $data = $this->postJson('/api/v1/auth/passkeys/login/options')->assertOk()->json('data');

        return ['state' => $data['state'], 'challenge' => $data['options']['challenge']];
    }

    private function signInWith(VirtualAuthenticator $device, User $user): TestResponse
    {
        ['state' => $state, 'challenge' => $challenge] = $this->loginChallenge();

        return $this->postJson('/api/v1/auth/passkeys/login', [
            'state' => $state,
            'credential' => $device->assert($challenge, self::ORIGIN, self::RP_ID, $this->handle($user)),
        ]);
    }
}
