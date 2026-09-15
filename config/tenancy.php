<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Sign-up
    |--------------------------------------------------------------------------
    |
    | When enabled, POST /api/v1/auth/register provisions a brand new tenant
    | and makes the registrant its owner — self-serve workshop onboarding.
    |
    | It is off by default: onboarding is sales-led, so registration returns
    | 403, /register answers 404, the sign-in modal offers no sign-up link, and
    | tenants are created only by a platform super-admin via POST /v1/tenants,
    | who also creates the owner account. Turning it back on is one env var,
    | and it re-opens all three at once — the endpoint, the page and the link
    | are deliberately one switch, because a visible form whose endpoint
    | refuses is worse than no form.
    |
    */

    'allow_public_signup' => (bool) env('TENANCY_ALLOW_PUBLIC_SIGNUP', false),

    /*
    |--------------------------------------------------------------------------
    | Owner Role
    |--------------------------------------------------------------------------
    |
    | The role granted to the first user of a newly provisioned tenant. Must
    | match a slug seeded by RoleSeeder.
    |
    */

    'owner_role' => env('TENANCY_OWNER_ROLE', 'OWNER'),

    /*
    |--------------------------------------------------------------------------
    | Default Workshop Settings
    |--------------------------------------------------------------------------
    |
    | Applied when a workshop is provisioned. Every one of these is editable
    | afterwards by the owner through PATCH /api/v1/workspace, except currency.
    |
    | state_code is the two-digit GST state code used when a workshop has not
    | supplied one. It determines intra- vs inter-state tax treatment once the
    | billing engine lands, so it is set at provisioning time rather than
    | inferred later. 27 = Maharashtra.
    |
    | financial_year_start_month drives every period-based report. India's
    | financial year runs April to March, hence 4.
    |
    | currency is display formatting only and is deliberately not editable:
    | GST, HSN/SAC and the whole tax engine are India-specific, so a workshop
    | set to anything else would format correctly and compute tax wrongly.
    |
    */

    'defaults' => [
        'state_code' => env('TENANCY_DEFAULT_STATE_CODE'),
        'financial_year_start_month' => (int) env('TENANCY_DEFAULT_FY_START_MONTH', 4),
        'timezone' => env('TENANCY_DEFAULT_TIMEZONE', 'Asia/Kolkata'),
        'currency' => 'INR',
    ],

];
