<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A passkey, in PHP.
 *
 * There is no way to drive a real authenticator from a test — the whole design
 * puts the private key somewhere no software can reach it — so the only way to
 * exercise the server's half of a ceremony is to be the other half. This holds
 * an ES256 key pair and produces exactly what a browser posts: an attestation
 * for enrolment, an assertion for signing in.
 *
 * It is deliberately capable of producing *wrong* answers too. The interesting
 * tests are not "a real device works", which any smoke test would catch; they
 * are the ones where the origin is a phishing domain, the challenge belongs to
 * a different ceremony, the counter has gone backwards, or the signature is
 * somebody else's. A helper that could only produce valid input would let all
 * four through unnoticed.
 */
class VirtualAuthenticator
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $key;

    private string $credentialId;

    private int $counter = 0;

    public function __construct(?string $credentialId = null)
    {
        $this->credentialId = $credentialId ?? random_bytes(32);

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'private_key_bits' => 2048,
            'config' => self::opensslConfig(),
        ]);

        if ($key === false) {
            $errors = [];

            while ($error = openssl_error_string()) {
                $errors[] = $error;
            }

            throw new RuntimeException('Could not generate a test key pair: '.implode('; ', $errors));
        }

        $this->key = $key;
    }

    /**
     * A config file for OpenSSL, written rather than found.
     *
     * `openssl_pkey_new()` refuses to do anything without one, and where it
     * lives differs per platform — a Windows PHP build commonly ships without
     * the path it looks in existing at all, which fails here as "no such file"
     * and looks like broken test code rather than a missing file. Writing a
     * minimal one keeps this suite self-contained: nothing about it depends on
     * how the machine running it happens to have OpenSSL laid out.
     */
    private static function opensslConfig(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'webauthn-test-openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, implode(PHP_EOL, [
                '[req]',
                // Never used to size an EC key, but openssl_pkey_new() reads
                // it before it looks at the curve and refuses a zero.
                'default_bits = 2048',
                'distinguished_name = dn',
                '',
                '[dn]',
                '',
            ]));
        }

        return $path;
    }

    public function credentialId(): string
    {
        return Base64UrlSafe::encodeUnpadded($this->credentialId);
    }

    /**
     * What `navigator.credentials.create()` resolves to, as the browser sends it.
     *
     * @return array<string, mixed>
     */
    public function attest(string $challenge, string $origin, string $rpId, bool $userVerified = true): array
    {
        $authData = $this->authenticatorData($rpId, $userVerified, attested: true);

        $attestationObject = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->clientData('webauthn.create', $challenge, $origin),
                'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestationObject),
            ],
        ];
    }

    /**
     * What `navigator.credentials.get()` resolves to.
     *
     * @return array<string, mixed>
     */
    public function assert(
        string $challenge,
        string $origin,
        string $rpId,
        string $userHandle,
        bool $userVerified = true,
        ?int $counter = null,
    ): array {
        $this->counter = $counter ?? $this->counter + 1;

        $authData = $this->authenticatorData($rpId, $userVerified, attested: false);
        $clientDataJSON = $this->clientData('webauthn.get', $challenge, $origin);

        // The signature covers the authenticator data and a hash of the client
        // data — which is what binds the assertion to this challenge and this
        // origin, and why changing either one has to break it.
        $signed = $authData.hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON), true);

        openssl_sign($signed, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientDataJSON,
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The bytes
     |-------------------------------------------------------------------- */

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return Base64UrlSafe::encodeUnpadded((string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ]));
    }

    /**
     * rpIdHash ‖ flags ‖ signCount [‖ attestedCredentialData].
     */
    private function authenticatorData(string $rpId, bool $userVerified, bool $attested): string
    {
        // UP (0x01) is always set — something was there. UV (0x04) says it was
        // unlocked by its owner. AT (0x40) says the attested credential data
        // below is present, which it only is at enrolment.
        $flags = 0x01 | ($userVerified ? 0x04 : 0x00) | ($attested ? 0x40 : 0x00);

        $data = hash('sha256', $rpId, true).chr($flags).pack('N', $this->counter);

        if (! $attested) {
            return $data;
        }

        return $data
            .str_repeat("\0", 16)                                   // AAGUID: none, for a "none" attestation
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();
    }

    /**
     * The public half, as COSE_Key: kty EC2, alg ES256, curve P-256, x and y.
     */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Could not read the test key.');
        }

        $map = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))     // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))    // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))    // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)));

        return (string) $map;
    }
}
