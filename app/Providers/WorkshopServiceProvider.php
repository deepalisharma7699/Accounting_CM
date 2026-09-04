<?php

namespace App\Providers;

use App\Repositories\Contracts\WorkshopJobRepositoryInterface;
use App\Repositories\Eloquent\EloquentWorkshopJobRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires M19 — the motor on the bench.
 *
 * Its own provider rather than a few lines added to
 * {@see AccountingServiceProvider}, because a workshop job is not accounting. It
 * posts nothing, has no template and never touches the ledger; what it does is
 * hand a payload to the accounting module when somebody decides to bill it. The
 * separation is the same one the inventory provider makes for the catalogue, and
 * for the same reason: the boundary is easier to keep than to restore.
 *
 * Registered after the accounting and inventory providers in
 * `bootstrap/providers.php`, which is presentational only — every binding here
 * is lazy, so nothing about the order is load-bearing.
 */
class WorkshopServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        WorkshopJobRepositoryInterface::class => EloquentWorkshopJobRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }
}
