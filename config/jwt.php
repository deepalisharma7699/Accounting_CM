<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signing Key
    |--------------------------------------------------------------------------
    |
    | Secret used to sign and verify access tokens. Falls back to the
    | application key so the module still boots on a fresh clone, but a
    | dedicated JWT_SECRET must be set in every non-local environment.
    |
    */

    'secret' => env('JWT_SECRET', env('APP_KEY')),

    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Registered Claims
    |--------------------------------------------------------------------------
    */

    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),

    'audience' => env('JWT_AUDIENCE', env('APP_URL', 'http://localhost')),

    /*
    | Clock skew tolerated when validating `exp` / `nbf` / `iat`, in seconds.
    */
    'leeway' => (int) env('JWT_LEEWAY', 5),

    /*
    |--------------------------------------------------------------------------
    | Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | Access tokens are short lived and never persisted; refresh tokens are
    | long lived, persisted (hashed) and rotated on every use.
    |
    */

    'ttl' => [
        'access' => (int) env('JWT_ACCESS_TTL', 15 * 60),          // 15 minutes
        'refresh' => (int) env('JWT_REFRESH_TTL', 7 * 24 * 60 * 60), // 7 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh Token Cookie
    |--------------------------------------------------------------------------
    |
    | The refresh token is only ever transported in an HTTP-only cookie so it
    | is unreachable from JavaScript. `path` is scoped to the auth endpoints
    | that actually need it, which keeps it off every other API request.
    |
    | SameSite=strict is the default. Use `none` (with secure = true) only when
    | the SPA lives on a different registrable domain than the API.
    |
    */

    'cookie' => [
        'name' => env('JWT_REFRESH_COOKIE', 'refresh_token'),
        'path' => env('JWT_REFRESH_COOKIE_PATH', '/api/v1/auth'),
        'domain' => env('JWT_REFRESH_COOKIE_DOMAIN'),
        'secure' => env('JWT_REFRESH_COOKIE_SECURE', env('APP_ENV') !== 'local'),
        'http_only' => true,
        'same_site' => env('JWT_REFRESH_COOKIE_SAME_SITE', 'strict'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh Token Rotation
    |--------------------------------------------------------------------------
    |
    | When a refresh token is redeemed it is revoked and replaced. If a token
    | that was already redeemed is presented again it means the cookie leaked,
    | so the whole token family is revoked (reuse detection).
    |
    */

    'rotation' => [
        'enabled' => env('JWT_REFRESH_ROTATION', true),
        'revoke_family_on_reuse' => env('JWT_REFRESH_REVOKE_FAMILY_ON_REUSE', true),

        /*
        | Grace period, in seconds, during which a token that was *just* rotated
        | may be presented again without being treated as a leak.
        |
        | Without this, any refresh whose response never reaches the browser —
        | tab closed mid-flight, dropped connection, background tab frozen —
        | leaves the client holding a token the server has already spent. The
        | next attempt then looks exactly like a replay and destroys the whole
        | session family, logging the user out for a transient network fault.
        |
        | The window only ever forgives the immediate predecessor of the current
        | token (revoked_reason = "rotated"). A token revoked by logout, a
        | password change or an actual reuse is still rejected outright, and
        | replaying anything older than the window still burns the family.
        */
        'grace_seconds' => (int) env('JWT_REFRESH_GRACE', 10),
    ],

];
