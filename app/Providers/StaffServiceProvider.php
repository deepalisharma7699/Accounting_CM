<?php

namespace App\Providers;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\PayrollRepositoryInterface;
use App\Repositories\Contracts\StaffAttendanceRepositoryInterface;
use App\Repositories\Contracts\StaffDesignationRepositoryInterface;
use App\Repositories\Contracts\TransactionStaffRepositoryInterface;
use App\Repositories\Eloquent\EloquentEmployeeRepository;
use App\Repositories\Eloquent\EloquentPayrollRepository;
use App\Repositories\Eloquent\EloquentStaffAttendanceRepository;
use App\Repositories\Eloquent\EloquentStaffDesignationRepository;
use App\Repositories\Eloquent\EloquentTransactionStaffRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires M22 — the people who work for the workshop.
 *
 * Its own provider rather than a few lines added to
 * {@see AccountingServiceProvider}, and the reason is the same one
 * {@see WorkshopServiceProvider} gives: most of this module is not accounting.
 * Keeping a staff list, marking a day sheet and computing what a month adds up
 * to touch no account at all. What *is* accounting — the two posting templates —
 * is registered where every other template is, in the accounting provider, so
 * there is still exactly one file that says which types can be posted.
 *
 * That split is the boundary the module is built on, and it is easier to keep
 * than to restore.
 *
 * Registered in `bootstrap/providers.php`, which is presentational only: every
 * binding here is lazy, so nothing about the order is load-bearing.
 */
class StaffServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        EmployeeRepositoryInterface::class => EloquentEmployeeRepository::class,
        StaffDesignationRepositoryInterface::class => EloquentStaffDesignationRepository::class,
        StaffAttendanceRepositoryInterface::class => EloquentStaffAttendanceRepository::class,
        // Runs, payslips, and both halves of the advance position — see the
        // interface for why the advance queries live with payroll rather than
        // with employees.
        PayrollRepositoryInterface::class => EloquentPayrollRepository::class,
        /*
        | Who did the work an invoice was raised for.
        |
        | Injected by {@see \App\Services\Staff\WorkAttributionService}, which
        | the sale form writes through and the staff drawer reads through. Note
        | which provider it is bound in: the *reads* are a staff question, and
        | the row hangs off a transaction — so the boundary this module is built
        | on holds here too. Nothing about attribution touches an account, and
        | that is why none of it is in the accounting provider.
        |
        | See `docs/staff-module.md`.
        */
        TransactionStaffRepositoryInterface::class => EloquentTransactionStaffRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }
}
