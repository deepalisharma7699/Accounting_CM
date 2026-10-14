<?php

namespace App\Providers;

use App\Repositories\Contracts\PersonalExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\PersonalExpenseRepositoryInterface;
use App\Repositories\Eloquent\EloquentPersonalExpenseCategoryRepository;
use App\Repositories\Eloquent\EloquentPersonalExpenseRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the owner's personal diary.
 *
 * Its own provider rather than lines in the accounting one, because nothing
 * here is accounting: the diary posts nothing and no account reads it, and
 * keeping it out of that provider keeps that boundary visible.
 */
class PersonalServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        PersonalExpenseRepositoryInterface::class => EloquentPersonalExpenseRepository::class,
        PersonalExpenseCategoryRepositoryInterface::class => EloquentPersonalExpenseCategoryRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }
}
