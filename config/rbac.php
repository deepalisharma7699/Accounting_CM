<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wildcard Token
    |--------------------------------------------------------------------------
    |
    | Used for both `action` and `resource`. A permission of `*` / `*` grants
    | everything; `MANAGE` / `USERS` style grants are expressed by seeding the
    | concrete actions instead, keeping the check itself trivial to audit.
    |
    */

    'wildcard' => '*',

    /*
    |--------------------------------------------------------------------------
    | System Roles
    |--------------------------------------------------------------------------
    |
    | System roles are seeded by the application and may not be renamed,
    | re-scoped or deleted through the API.
    |
    */

    'system_roles' => [
        'admin' => 'ADMIN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Cache
    |--------------------------------------------------------------------------
    |
    | Effective permissions are resolved on every guarded request, so they are
    | cached per role. The cache is flushed whenever a role's permissions are
    | mutated. Set ttl to 0 to disable caching entirely.
    |
    */

    'cache' => [
        'enabled' => env('RBAC_CACHE_ENABLED', true),
        'ttl' => (int) env('RBAC_CACHE_TTL', 3600),
        'prefix' => 'rbac:role_permissions:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Throttling / Account Locking
    |--------------------------------------------------------------------------
    |
    | Two independent layers: IP+email rate limiting (middleware) and per
    | account locking (persisted counter) so a distributed attack against a
    | single account still trips the lock.
    |
    */

    'login' => [
        'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('AUTH_LOGIN_LOCK_MINUTES', 15),
        'rate_limit' => [
            'attempts' => (int) env('AUTH_LOGIN_RATE_LIMIT', 10),
            'decay_minutes' => (int) env('AUTH_LOGIN_RATE_DECAY', 1),
        ],
    ],

];
