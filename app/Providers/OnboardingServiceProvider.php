<?php

namespace App\Providers;

use App\Repositories\Contracts\OpeningImportRepositoryInterface;
use App\Repositories\Eloquent\EloquentOpeningImportRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires M11 — getting a running workshop's existing position into the books.
 *
 * Its own provider rather than a few more lines in
 * {@see AccountingServiceProvider}, because onboarding is not accounting: it
 * reaches across the chart, the catalogue, the parties and the ledger at once,
 * and it is the only part of the application that parses a file somebody
 * uploaded. Keeping that surface named and separate is worth a file.
 */
class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            OpeningImportRepositoryInterface::class,
            EloquentOpeningImportRepository::class,
        );
    }
}
