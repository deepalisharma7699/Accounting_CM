<?php

/*
|--------------------------------------------------------------------------
| Passkeys (WebAuthn)
|--------------------------------------------------------------------------
|
| A passkey is a key pair the staff member's own device holds, unlocked by
| their fingerprint, face or device PIN. The private half never leaves the
| device and never reaches this server, so there is nothing here for a
| database leak to replay — which is the whole reason it outranks a password.
|
| The two values that actually hold it shut are `rp.id` and `origins`. The
| browser signs the origin it is talking to into every assertion, and the
| checks below are what refuse an assertion produced on a look-alike domain.
| Get them wrong in the lax direction and the phishing resistance — the only
| property that makes this safer than a password — is gone.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Relying party
    |--------------------------------------------------------------------------
    |
    | `id` is the domain a credential is bound to: a bare host, never a scheme,
    | a port or a path. A passkey created under one is offered by the browser
    | only on that host or a subdomain of it, so it is also the thing that
    | decides how widely a credential is usable. Left null it is derived from
    | APP_URL, which is right in every environment that has APP_URL right.
    |
    | It must never be set to a public suffix ("com", "co.in") — browsers
    | refuse those outright — and moving it later invalidates every enrolled
    | passkey, because the binding is to the value that was used at creation.
    |
    */

    'rp' => [
        'name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'Laravel')),
        'id' => env('WEBAUTHN_RP_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed origins
    |--------------------------------------------------------------------------
    |
    | The full origins — scheme, host and port — a ceremony may be performed
    | from. Checked exactly, against the origin the browser itself wrote into
    | the signed client data, so a page served from anywhere else cannot mint
    | or use a credential here however convincing it looks.
    |
    | Derived from APP_URL when left empty. Set WEBAUTHN_ORIGINS as a
    | comma-separated list only when the application is genuinely served from
    | more than one origin.
    |
    */

    'origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBAUTHN_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | The ceremony
    |--------------------------------------------------------------------------
    |
    | A ceremony is two requests: this server issues a challenge, the device
    | signs it, and the signature comes back. `challenge_ttl` is how long that
    | window stays open — short, because a challenge is single-use and a stale
    | one is only ever a replay attempt or an abandoned prompt.
    |
    | `timeout` is the browser's own prompt timeout, in milliseconds, and is
    | deliberately shorter: the prompt should give up before the challenge
    | behind it does, so a slow user gets "try again" rather than an error
    | about something expiring.
    |
    */

    'challenge_ttl' => (int) env('WEBAUTHN_CHALLENGE_TTL', 300),
    'timeout' => (int) env('WEBAUTHN_TIMEOUT', 120_000),

    /*
    |--------------------------------------------------------------------------
    | How many, per person
    |--------------------------------------------------------------------------
    |
    | One per device, and a person has a phone, perhaps a second phone and the
    | machine at the counter. The cap is not a security control — it stops a
    | list nobody prunes from becoming a list nobody reads, which is where an
    | enrolment somebody does not recognise would go unnoticed.
    |
    */

    'max_per_user' => (int) env('WEBAUTHN_MAX_PER_USER', 10),

];
