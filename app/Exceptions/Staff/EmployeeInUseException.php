<?php

namespace App\Exceptions\Staff;

use App\Exceptions\ApiException;

/**
 * An attempt to remove somebody the records still point at — M22.
 *
 * The same rule, and the same reasoning, as {@see \App\Exceptions\Accounting\PartyInUseException}:
 * a payslip without a name is a number, and an attendance register with a gap
 * where a person used to be is a register nobody can reconcile. So somebody who
 * has been marked, paid or advanced anything is **archived** rather than deleted,
 * and their history stays readable.
 *
 * Delete is therefore only ever a way to undo a typo caught the same afternoon.
 * That is what it is for, and it is deliberately the only thing it is for.
 */
class EmployeeInUseException extends ApiException
{
    /**
     * @param  array{attendance: int, payslips: int, advances: int, attributions: int}  $counts
     */
    public static function hasRecords(int $id, string $name, array $counts): self
    {
        $parts = [];

        if ($counts['payslips'] > 0) {
            $parts[] = sprintf('%d payslip%s', $counts['payslips'], $counts['payslips'] === 1 ? '' : 's');
        }

        if ($counts['advances'] > 0) {
            $parts[] = sprintf('%d advance%s', $counts['advances'], $counts['advances'] === 1 ? '' : 's');
        }

        if (($counts['attributions'] ?? 0) > 0) {
            $parts[] = sprintf(
                '%d sale%s credited to them',
                $counts['attributions'],
                $counts['attributions'] === 1 ? '' : 's',
            );
        }

        if ($counts['attendance'] > 0) {
            $parts[] = sprintf(
                '%d attendance mark%s',
                $counts['attendance'],
                $counts['attendance'] === 1 ? '' : 's',
            );
        }

        return new self(
            message: sprintf(
                '%s has %s on record, so the entry cannot be deleted — those rows would lose the name that '.
                'explains them. Mark them as having left instead: they come off the day sheet and the next '.
                'payroll, and everything already posted stays intact.',
                $name,
                collect($parts)->join(', ', ' and '),
            ),
            status: 409,
            errorCode: 'EMPLOYEE_IN_USE',
            details: array_merge($counts, [
                'employee_id' => $id,
                // The alternative, named: an error that refuses without saying
                // what to do instead is a dead end.
                'archive_instead' => true,
            ]),
        );
    }
}
