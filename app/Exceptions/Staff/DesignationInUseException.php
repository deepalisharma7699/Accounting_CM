<?php

namespace App\Exceptions\Staff;

use App\Exceptions\ApiException;

/**
 * An attempt to delete a designation that employees still hold — M22.
 *
 * The Brand Master's rule, applied to people: removing the word would leave
 * every fitter filed under it with a blank where their trade used to be, and
 * nothing on any screen would explain when or why it went. Archiving takes it
 * out of every form while leaving the employees who hold it untouched, which is
 * what somebody retiring a designation actually means.
 */
class DesignationInUseException extends ApiException
{
    public static function hasEmployees(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '%d employee%s still %s "%s", so it cannot be deleted — they would be left with a blank where '.
                'their trade used to be. Archive it instead: it stops appearing on new records and everybody '.
                'who holds it keeps it.',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 'holds' : 'hold',
                $name,
            ),
            status: 409,
            errorCode: 'DESIGNATION_IN_USE',
            details: [
                'designation_id' => $id,
                'employee_count' => $count,
                'archive_instead' => true,
            ],
        );
    }

    /**
     * The other way a designation is in use — M22: it has been credited with
     * work on invoices that are already in the books.
     *
     * A separate constructor rather than a second count on the first, because
     * the two refusals mean different things and lead somewhere different. An
     * employee holding the trade loses a label; an invoice attributed to it
     * loses the record of who did the job, on a posted document that can no
     * longer be edited. The second is unrecoverable, so it says so.
     */
    public static function hasAttributions(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '%d invoice%s credited to "%s", so it cannot be deleted — the record of who did that work '.
                'would go with it, and those invoices are posted. Archive it instead: it stops appearing on '.
                'new sales and every invoice that carries it keeps it.',
                $count,
                $count === 1 ? ' is' : 's are',
                $name,
            ),
            status: 409,
            errorCode: 'DESIGNATION_IN_USE',
            details: [
                'designation_id' => $id,
                'attribution_count' => $count,
                'archive_instead' => true,
            ],
        );
    }
}
