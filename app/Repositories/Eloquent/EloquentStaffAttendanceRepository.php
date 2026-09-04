<?php

namespace App\Repositories\Eloquent;

use App\Enums\AttendanceStatus;
use App\Models\StaffAttendance;
use App\Repositories\Contracts\StaffAttendanceRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EloquentStaffAttendanceRepository implements StaffAttendanceRepositoryInterface
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function forDate(DateTimeInterface|string $date): Collection
    {
        return StaffAttendance::query()
            ->whereDate('date', CarbonImmutable::parse($date)->toDateString())
            ->get()
            ->keyBy('employee_id');
    }

    public function forPeriod(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?array $employeeIds = null,
    ): Collection {
        return StaffAttendance::query()
            ->between(
                CarbonImmutable::parse($from)->toDateString(),
                CarbonImmutable::parse($to)->toDateString(),
            )
            ->when(
                $employeeIds !== null,
                fn ($query) => $query->whereIn('employee_id', array_map('intval', $employeeIds))
            )
            ->orderBy('employee_id')
            ->orderBy('date')
            ->get();
    }

    public function statusCountsFor(
        array $employeeIds,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        $counts = array_fill_keys($ids, []);

        if ($ids === []) {
            return $counts;
        }

        $rows = StaffAttendance::query()
            ->whereIn('employee_id', $ids)
            ->between(
                CarbonImmutable::parse($from)->toDateString(),
                CarbonImmutable::parse($to)->toDateString(),
            )
            ->groupBy('employee_id', 'status')
            ->selectRaw('employee_id, status, COUNT(*) as days')
            ->get();

        foreach ($rows as $row) {
            // `status` is cast to the enum on the model; the aggregate columns
            // are not, so each is read for what it actually is.
            $status = $row->status instanceof AttendanceStatus
                ? $row->status->value
                : (string) $row->status;

            $counts[(int) $row->employee_id][$status] = (int) $row->days;
        }

        return $counts;
    }

    /**
     * One statement for the whole day sheet.
     *
     * `upsert()` goes round the model, which means round `BelongsToTenant` —
     * there is no `creating` event to stamp `tenant_id` and no global scope on
     * an INSERT. So the tenant is written explicitly here, and the caller is
     * responsible for having established that every `employee_id` belongs to it
     * (see {@see \App\Services\Staff\AttendanceService::mark()}, which resolves
     * them through the tenant-scoped employee repository first).
     *
     * That is a deliberate trade and worth the note: the alternative is one
     * `updateOrCreate` per person per save, and the day sheet is saved several
     * times a day by somebody standing at a bench.
     */
    public function upsertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $tenantId = $this->context->requireTenant(StaffAttendance::class);
        $now = CarbonImmutable::now();

        $values = array_map(fn (array $row) => [
            'tenant_id' => $tenantId,
            'employee_id' => (int) $row['employee_id'],
            'date' => CarbonImmutable::parse($row['date'])->toDateString(),
            'status' => (string) $row['status'],
            'notes' => $row['notes'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($rows));

        StaffAttendance::query()->upsert(
            $values,
            // The unique index, exactly — anything else silently inserts
            // duplicates on databases that do not complain.
            ['tenant_id', 'employee_id', 'date'],
            // `created_at` is deliberately not in the update list: a corrected
            // mark keeps the moment it was first written.
            ['status', 'notes', 'updated_at'],
        );

        return count($values);
    }

    public function clear(array $employeeIds, DateTimeInterface|string $date): int
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($ids === []) {
            return 0;
        }

        return StaffAttendance::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('date', CarbonImmutable::parse($date)->toDateString())
            ->delete();
    }
}
