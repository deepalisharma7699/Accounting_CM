<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use DateTimeInterface;

/**
 * One mark, for one person, on one day — M22.
 *
 * ## Not `Auditable`, and that is a decision rather than an omission
 *
 * The trail exists for the changes that are hard to see afterwards: a rate
 * quietly raised, a designation renamed under every employee at once, a file
 * deleted. Attendance is none of those. It is high-volume, it is corrected all
 * the time as a matter of course, and it is *already visible* — the month
 * register shows every mark for every person side by side, which is a better
 * answer to "what does the sheet say" than a log of edits would be.
 *
 * Auditing it would put several hundred rows a month at the top of a workshop's
 * history, and a log whose first page is machine noise is a log people stop
 * opening. The consequential act downstream — posting a payroll run — snapshots
 * exactly what it counted onto its own lines, so the figures that were paid can
 * never be rewritten by a later correction here.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property \Illuminate\Support\Carbon $date
 * @property AttendanceStatus $status
 * @property string|null $notes
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'status', 'notes'])]
class StaffAttendance extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to): Builder
    {
        return $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
