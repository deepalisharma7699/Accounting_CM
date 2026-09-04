<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\IndexAttendanceRequest;
use App\Http\Requests\Staff\MarkAttendanceRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\Staff\AttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Who was in, on which day — M22.
 *
 * ## One endpoint, two zoom levels
 *
 * `?date=` is the day sheet: everybody on the payroll that day, with whatever
 * mark they already have. `?period=` is the month register: a row per person, a
 * column per day. One route rather than two because they are one question asked
 * at two zoom levels, and because a client moving between them should not have
 * to move endpoints.
 *
 * ## A null status is a value
 *
 * Both reads return **unmarked** days as unmarked rather than defaulting them to
 * present, and the write accepts null to clear a mark. That is not tidiness:
 * what an unmarked day is worth depends on how the person is paid, and the one
 * place that decision is made is {@see \App\Enums\SalaryBasis::unmarkedDayIsPaid()}.
 * Filling the gaps in here would be making it a second time, in the layer least
 * likely to be looked at when a payslip is queried.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * GET /api/v1/staff/attendance
     *
     * `?date=2026-09-03` for a day, `?period=2026-09` for the month. Neither
     * means today, which is what somebody opening the screen wants nine times
     * out of ten.
     */
    public function index(IndexAttendanceRequest $request): JsonResponse
    {
        return $request->wantsRegister()
            ? $this->register($request->period())
            : $this->daySheet($request->day());
    }

    /**
     * PUT /api/v1/staff/attendance
     *
     * The whole day's sheet at once. A PUT rather than a POST because that is
     * what it is: this day's marks are replaced by the ones sent, and sending
     * the same sheet twice leaves the same result — which is exactly the
     * property somebody tapping Save on a patchy connection needs.
     */
    public function store(MarkAttendanceRequest $request): JsonResponse
    {
        $result = $this->attendance->mark($request->day(), $request->rows());

        return ApiResponse::success(
            // The sheet as it now stands, so the screen repaints from the
            // server's answer rather than from what it hoped it had sent.
            $this->sheetPayload($this->attendance->daySheet($result['date'])),
            $this->messageFor($result),
            200,
            ['marked' => $result['marked'], 'cleared' => $result['cleared']],
        );
    }

    /* ---------------------------------------------------------------------
     | Shapes
     |-------------------------------------------------------------------- */

    private function daySheet(string $date): JsonResponse
    {
        $sheet = $this->attendance->daySheet($date);

        return ApiResponse::success(
            $this->sheetPayload($sheet),
            null,
            200,
            [
                'date' => $sheet['date'],
                // How the day breaks down, including how many nobody has touched
                // — the figure the person filling it in is actually checking.
                'counts' => $sheet['counts'],
            ],
        );
    }

    private function register(string $period): JsonResponse
    {
        $register = $this->attendance->register($period);

        return ApiResponse::success(
            array_map(fn (array $row) => [
                'employee' => (new EmployeeResource($row['employee']))->resolve(request()),
                // Sparse: a date appears only where there is a mark. The client
                // paints the gaps and the calculator prices them.
                'marks' => $row['marks'],
                'counts' => $row['counts'],
            ], $register['rows']),
            null,
            200,
            [
                'period' => substr($register['from'], 0, 7),
                'from' => $register['from'],
                'to' => $register['to'],
                'days' => $register['days'],
            ],
        );
    }

    /**
     * @param  array{date: string, rows: array<int, array<string, mixed>>, counts: array<string, int>}  $sheet
     * @return array<int, array<string, mixed>>
     */
    private function sheetPayload(array $sheet): array
    {
        return array_map(fn (array $row) => [
            'employee' => (new EmployeeResource($row['employee']))->resolve(request()),
            'status' => $row['status'],
            'notes' => $row['notes'],
        ], $sheet['rows']);
    }

    /**
     * @param  array{marked: int, cleared: int, date: string}  $result
     */
    private function messageFor(array $result): string
    {
        if ($result['marked'] === 0 && $result['cleared'] > 0) {
            return 'Marks cleared.';
        }

        return sprintf(
            'Attendance saved — %d marked%s.',
            $result['marked'],
            $result['cleared'] > 0 ? sprintf(', %d cleared', $result['cleared']) : '',
        );
    }
}
