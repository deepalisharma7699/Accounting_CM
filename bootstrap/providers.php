<?php

use App\Providers\AccountingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AsyncServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\AuthModuleServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\OnboardingServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    // Registered before the modules below: their repositories take the tenant
    // context as a constructor dependency.
    TenancyServiceProvider::class,
    // M13. Early, and before anything that writes: the recorder is a singleton
    // reached from model events, so it has to be resolvable the first time any
    // auditable model is saved — including inside a seeder.
    AuditServiceProvider::class,
    AuthModuleServiceProvider::class,
    AccountingServiceProvider::class,
    // The catalogue, and M8's stock on top of it. After accounting, because M8
    // writes stock movements through the posting engine.
    InventoryServiceProvider::class,
    // M11's go-live import. Last of the accounting modules, because it composes
    // all three above: it resolves against the catalogue and the parties, and
    // posts through the engine.
    OnboardingServiceProvider::class,
    // M14. Cross-cutting like the audit trail, and last because its jobs
    // re-establish the tenant context and attribute changes through the
    // recorder — both of which are registered above.
    AsyncServiceProvider::class,
];
