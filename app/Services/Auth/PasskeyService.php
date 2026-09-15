<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Exceptions\Auth\PasskeyCeremonyExpiredException;
use App\Exceptions\Auth\PasskeyRejectedException;
use App\Models\Passkey;
use App\Models\User;
use App\Repositories\Contracts\PasskeyRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Cose\Algorithms;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The WebAuthn ceremonies, and nothing else.
 *
 * A ceremony is always two requests. This server issues a challenge; the device
 * signs it along with the origin it is talking to; the signature comes back and
 * is checked. Both halves are here — everything about *sessions* (whether the
 * account is active, whether the workshop is, what tokens to mint) stays in
 * {@see AuthService}, which is the one place that decides what starting a
 * session means. This class proves who somebody is, and stops.
 *
 * Three things carry the security of the whole scheme, and each is easy to get
 * subtly wrong:
 *
 *   the challenge  — random, server-issued, and spent exactly once. Held in the
 *                    cache rather than the session because /api/v1 is
 *                    stateless, and handed to the client as an opaque `state`
 *                    it echoes back. Claiming it deletes it, so a captured
 *                    ceremony cannot be replayed even inside its own lifetime.
 *   the origin     — the browser writes the origin it is *actually* on into the
 *                    signed client data, and config('webauthn.origins') is what
 *                    that is checked against. This is the phishing resistance:
 *                    a convincing copy of this site on another domain produces
 *                    assertions that fail here. It is why the origin list is
 *                    exact rather than a pattern.
 *   the user check — every credential is enrolled and verified with user
 *                    verification *required*, so an assertion means the device
 *                    was unlocked by its owner, not merely that it was present.
 *
 * Attestation is deliberately not verified. It answers "what make of
 * authenticator is this", which matters to an enterprise enforcing a hardware
 * policy and not at all to a workshop whose staff use the phones they own.
 * Requiring it would refuse exactly the devices this is meant to be easy on.
 */
class PasskeyService
{
    /** Cache key prefix for an in-flight ceremony. */
    private const CEREMONY = 'passkey:ceremony:';

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly PasskeyRepositoryInterface $passkeys,
        private readonly UserRepositoryInterface $users,
    ) {}

    /* ---------------------------------------------------------------------
     | Enrolling a device
     |-------------------------------------------------------------------- */

    /**
     * Begin enrolment: what the browser should ask the device to create.
     *
     * Only ever called inside an authenticated session. Enrolment adds a way
     * into an account, so it is something an already-signed-in person does to
     * their own account — never a step in signing in, which would let anybody
     * who reached the login form register their own device against somebody
     * else's login.
     *
     * @return array{state: string, options: array<string, mixed>}
     */
    public function registrationOptions(User $user): array
    {
        $max = (int) config('webauthn.max_per_user', 10);

        if ($this->passkeys->countForUser($user) >= $max) {
            throw new ApiException(
                message: "You can have at most {$max} passkeys. Remove one you no longer use first.",
                status: 422,
                errorCode: 'PASSKEY_LIMIT_REACHED',
            );
        }

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(
                (string) config('webauthn.rp.name'),
                $this->relyingPartyId(),
            ),
            user: PublicKeyCredentialUserEntity::create(
                $user->email,
                $this->handleFor($user),
                $user->name,
            ),
            challenge: random_bytes(32),

            // ES256 first because it is what almost every authenticator uses;
            // RS256 because Windows Hello's TPM path may not offer ES256.
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],

            /*
            | Both requirements are what make this worth building.
            |
            | A resident (discoverable) key is stored on the device alongside
            | the account it belongs to, which is what lets somebody sign in
            | without first typing who they are — the whole of the UX win. User
            | verification means the device unlocks with a fingerprint, face or
            | PIN, so an assertion proves a person and not a possession.
            */
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),

            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,

            // Devices already enrolled, so the browser says "you already have a
            // passkey for this site" instead of silently creating a duplicate
            // that the account picker then shows twice.
            excludeCredentials: $this->passkeys->forUser($user)
                ->map(fn (Passkey $passkey): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $this->decode($passkey->credential_id),
                ))
                ->all(),

            timeout: (int) config('webauthn.timeout'),
        );

        return [
            'state' => $this->stash($options, ['user_id' => (int) $user->getKey()]),
            'options' => $this->toArray($options),
        ];
    }

    /**
     * Finish enrolment: verify what the device produced, and store the key.
     *
     * @param  array<string, mixed>  $credential  The browser's PublicKeyCredential, as JSON.
     */
    public function register(User $user, string $state, array $credential, string $label, ?Request $request = null): Passkey
    {
        ['options' => $options, 'context' => $context] = $this->claim($state, PublicKeyCredentialCreationOptions::class);

        // The ceremony was issued to one account. Finishing it as another would
        // be enrolling your own device against somebody else's login.
        if (($context['user_id'] ?? null) !== (int) $user->getKey()) {
            throw new PasskeyRejectedException;
        }

        $response = $this->responseFrom($credential, AuthenticatorAttestationResponse::class);

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremony()->creationCeremony())
                ->check($response, $options, $this->host($request));
        } catch (Throwable $e) {
            $this->reject('registration_failed', $e, $request, ['user_id' => $user->getKey()]);
        }

        $credentialId = $this->encode($record->publicKeyCredentialId);

        // Unique across the table, so this is "somebody already has it" rather
        // than "you already have it" — and the answer is the same either way.
        if ($this->passkeys->findByCredentialId($credentialId) !== null) {
            throw new ApiException(
                message: 'That device is already enrolled.',
                status: 409,
                errorCode: 'PASSKEY_ALREADY_ENROLLED',
            );
        }

        $passkey = $this->passkeys->create([
            'user_id' => $user->getKey(),
            'credential_id' => $credentialId,
            'credential' => $this->serializer()->serialize($record, 'json'),
            'label' => $label,
            'aaguid' => $record->aaguid->__toString(),
            'sign_count' => $record->counter,
            'backed_up' => (bool) $record->backupStatus,
        ]);

        Log::info('auth.passkey_enrolled', [
            'user_id' => $user->getKey(),
            'passkey_id' => $passkey->id,
            'ip' => $request?->ip(),
        ]);

        return $passkey;
    }

    /* ---------------------------------------------------------------------
     | Signing in
     |-------------------------------------------------------------------- */

    /**
     * Begin a sign-in: a challenge, and no account named.
     *
     * `allowCredentials` is deliberately left empty. Naming the credentials
     * that would be accepted requires knowing who is signing in first — which
     * puts the email field back — and it publishes, to anybody who asks, which
     * credentials belong to a given address. An empty list lets the browser
     * offer whatever discoverable passkey it holds for this site, which is both
     * the private answer and the one that takes a single tap.
     *
     * @return array{state: string, options: array<string, mixed>}
     */
    public function authenticationOptions(): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->relyingPartyId(),
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: (int) config('webauthn.timeout'),
        );

        return [
            'state' => $this->stash($options),
            'options' => $this->toArray($options),
        ];
    }

    /**
     * Finish a sign-in: verify the assertion, and say whose it is.
     *
     * Returns the user only. Whether that user may actually start a session —
     * active account, active workshop — is AuthService's question, asked in one
     * place for every way in.
     *
     * @param  array<string, mixed>  $credential
     * @return array{user: User, passkey: Passkey}
     */
    public function authenticate(string $state, array $credential, ?Request $request = null): array
    {
        ['options' => $options] = $this->claim($state, PublicKeyCredentialRequestOptions::class);

        $response = $this->responseFrom($credential, AuthenticatorAssertionResponse::class);

        $credentialId = (string) ($credential['rawId'] ?? $credential['id'] ?? '');
        $passkey = $credentialId === '' ? null : $this->passkeys->findByCredentialId($credentialId);

        if ($passkey === null) {
            $this->reject('unknown_credential', null, $request, ['credential_id' => $credentialId]);
        }

        // Cross-tenant on purpose: a sign-in happens before any workshop is
        // established, exactly as the password path does.
        $user = $this->users->findAuthenticatable((int) $passkey->user_id);

        if ($user === null) {
            $this->reject('orphaned_credential', null, $request, ['passkey_id' => $passkey->id]);
        }

        $stored = $this->recordFor($passkey);

        try {
            /*
            | The last argument is null on purpose, and it is not the lazy
            | choice — it is the strict one.
            |
            | It means "no account was identified before this ceremony began",
            | which is the literal truth here: the challenge named nobody. The
            | library answers that by *requiring* the device's own assertion to
            | carry a user handle and comparing it against the stored record.
            | Passing a handle instead would let this method choose what the
            | answer is checked against — and the only handle it has to hand is
            | the one on the record, which would be comparing the record with
            | itself and calling it a check.
            */
            $verified = AuthenticatorAssertionResponseValidator::create($this->ceremony()->requestCeremony())
                ->check($stored, $response, $options, $this->host($request), null);
        } catch (Throwable $e) {
            $this->reject('assertion_failed', $e, $request, ['passkey_id' => $passkey->id]);
        }

        $this->passkeys->recordUse($passkey, $verified->counter, $request?->ip());

        Log::info('auth.passkey_verified', [
            'user_id' => $user->getKey(),
            'passkey_id' => $passkey->id,
            'ip' => $request?->ip(),
        ]);

        return ['user' => $user, 'passkey' => $passkey];
    }

    /* ---------------------------------------------------------------------
     | Managing them
     |-------------------------------------------------------------------- */

    /**
     * The devices this person has enrolled.
     *
     * @return Collection<int, Passkey>
     */
    public function listFor(User $user): Collection
    {
        return $this->passkeys->forUser($user);
    }

    public function rename(User $user, int $id, string $label): Passkey
    {
        return $this->passkeys->rename($this->owned($user, $id), $label);
    }

    public function remove(User $user, int $id, ?Request $request = null): void
    {
        $passkey = $this->owned($user, $id);

        Log::info('auth.passkey_removed', [
            'user_id' => $user->getKey(),
            'passkey_id' => $passkey->id,
            'ip' => $request?->ip(),
        ]);

        $this->passkeys->delete($passkey);
    }

    /**
     * One of this person's own passkeys, or nothing.
     *
     * The scoping is the authorization: a row belonging to somebody else is not
     * found rather than found and refused, so there is no branch to forget and
     * no difference in the answer for an id to be probed with.
     */
    private function owned(User $user, int $id): Passkey
    {
        $passkey = $this->passkeys->findForUser($user, $id);

        if ($passkey === null) {
            throw new ApiException(
                message: 'Passkey not found.',
                status: 404,
                errorCode: 'PASSKEY_NOT_FOUND',
            );
        }

        return $passkey;
    }

    /* ---------------------------------------------------------------------
     | The challenge
     |-------------------------------------------------------------------- */

    /**
     * Hold a ceremony's options server-side, and return the opaque handle.
     *
     * The options — the challenge above all — must be the server's own when
     * they are checked, never whatever came back alongside the answer. Sending
     * them to the client and trusting them on return would let anybody choose
     * the challenge their own recorded assertion already satisfies.
     *
     * @param  array<string, mixed>  $context
     */
    private function stash(object $options, array $context = []): string
    {
        $state = Str::random(48);

        Cache::put(
            self::CEREMONY.hash('sha256', $state),
            [
                'options' => $this->serializer()->serialize($options, 'json'),
                'class' => $options::class,
                'context' => $context,
            ],
            (int) config('webauthn.challenge_ttl', 300),
        );

        return $state;
    }

    /**
     * Take a ceremony back out, once.
     *
     * `Cache::pull` rather than get-then-forget: the read and the delete are
     * one operation, so two requests racing the same state cannot both be
     * served — which is the difference between a single-use challenge and one
     * that is single-use most of the time.
     *
     * @template T of object
     *
     * @param  class-string<T>  $expected
     * @return array{options: T, context: array<string, mixed>}
     */
    private function claim(string $state, string $expected): array
    {
        $stored = $state === '' ? null : Cache::pull(self::CEREMONY.hash('sha256', $state));

        // A registration state must not finish an authentication, or the other
        // way about: the two are checked by different ceremonies under
        // different rules, and what has to match is (challenge, what it was for).
        if (! is_array($stored) || ($stored['class'] ?? null) !== $expected) {
            throw new PasskeyCeremonyExpiredException;
        }

        /** @var T $options */
        $options = $this->serializer()->deserialize($stored['options'], $expected, 'json');

        return ['options' => $options, 'context' => (array) ($stored['context'] ?? [])];
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * The domain credentials are bound to.
     *
     * A bare host: no scheme, no port, no path. Derived from APP_URL when it is
     * not configured, which is right wherever APP_URL is right — and a
     * misconfiguration here is loud rather than silent, because every ceremony
     * fails rather than a few succeeding weakly.
     */
    private function relyingPartyId(): string
    {
        $configured = config('webauthn.rp.id');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new ApiException(
                message: 'Passkeys are not configured on this server.',
                status: 500,
                errorCode: 'PASSKEY_NOT_CONFIGURED',
            );
        }

        return $host;
    }

    /**
     * Exactly which origins may run a ceremony.
     *
     * @return array<int, string>
     */
    private function allowedOrigins(): array
    {
        $configured = array_values(array_filter((array) config('webauthn.origins', [])));

        if ($configured !== []) {
            return $configured;
        }

        return [rtrim((string) config('app.url'), '/')];
    }

    /**
     * The person's opaque id, minted on first use.
     *
     * Written once and never changed: it is the name their devices know this
     * account by, so altering it would orphan every passkey already enrolled.
     */
    private function handleFor(User $user): string
    {
        if (! is_string($user->passkey_handle) || $user->passkey_handle === '') {
            $user->forceFill(['passkey_handle' => (string) Str::uuid()])->save();
        }

        return (string) $user->passkey_handle;
    }

    /**
     * Rebuild the stored verifier for the library to check against.
     *
     * The counter is re-applied from its own column rather than left at
     * whatever was serialized into the blob at enrolment — which is zero, for
     * ever. Without this line the clone check compares every assertion against
     * zero and therefore passes every one of them, and nothing anywhere looks
     * wrong: sign-in works, the column even moves. The one thing it stops
     * detecting is the thing it exists for.
     */
    private function recordFor(Passkey $passkey): CredentialRecord
    {
        $record = $this->serializer()->deserialize($passkey->credential, CredentialRecord::class, 'json');

        $record->counter = (int) $passkey->sign_count;

        return $record;
    }

    /**
     * Turn the browser's JSON into the response object, insisting on the shape
     * this ceremony expects.
     *
     * @param  array<string, mixed>  $credential
     * @param  class-string  $expected
     */
    private function responseFrom(array $credential, string $expected): AuthenticatorAttestationResponse|AuthenticatorAssertionResponse
    {
        try {
            $publicKeyCredential = $this->serializer()->denormalize($credential, PublicKeyCredential::class);
        } catch (Throwable) {
            // Malformed input, not a failed check: nothing was verified, so
            // there is nothing here to be careful about revealing.
            throw new ApiException(
                message: 'That response could not be read.',
                status: 422,
                errorCode: 'PASSKEY_MALFORMED',
            );
        }

        if (! $publicKeyCredential->response instanceof $expected) {
            throw new PasskeyRejectedException;
        }

        return $publicKeyCredential->response;
    }

    /**
     * Refuse, saying nothing useful to the caller and everything to the log.
     *
     * One outcome for every cause — unknown credential, bad signature, wrong
     * origin, a counter that went backwards — for the reason the password path
     * gives only one: a response that distinguishes them tells whoever is
     * probing which one to keep working on.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws PasskeyRejectedException
     */
    private function reject(string $reason, ?Throwable $e, ?Request $request, array $context = []): never
    {
        Log::warning('auth.passkey_rejected', [
            'reason' => $reason,
            'ip' => $request?->ip(),
            'detail' => $e?->getMessage(),
            ...$context,
        ]);

        throw new PasskeyRejectedException;
    }

    /**
     * The ceremony rules, built per call from config.
     *
     * Not memoised deliberately: building it is cheap, and a cached copy would
     * hold the origin list from whenever it was first built — which is
     * precisely the value a test, or a deployment reading changed config, needs
     * to be able to change underneath it.
     */
    private function ceremony(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins($this->allowedOrigins());

        return $factory;
    }

    private function host(?Request $request): string
    {
        return $request?->getHost() ?? (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(object $options): array
    {
        return (array) json_decode(
            $this->serializer()->serialize($options, 'json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport]),
        ))->create();
    }

    private function encode(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }

    /**
     * Tolerant on the way in: browsers and libraries disagree about padding,
     * and a credential id that has round-tripped through one of each must still
     * match the row it was stored as.
     */
    private function decode(string $encoded): string
    {
        return Base64UrlSafe::decodeNoPadding(rtrim($encoded, '='));
    }
}
