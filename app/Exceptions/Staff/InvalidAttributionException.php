<?php

namespace App\Exceptions\Staff;

use App\Exceptions\ApiException;

/**
 * A sale credited to somebody it cannot be credited to — M22.
 *
 * Every case here is a 422: the request is well formed and says something that
 * is not true of this workshop. None of them is reachable from the sale form,
 * which draws its pickers from the same list the server checks against — they
 * guard the API, an importer, and the form running against a workshop whose
 * designations changed while a tab was open.
 */
class InvalidAttributionException extends ApiException
{
    /**
     * A purchase, an expense, a journal — anything that is not a sale.
     *
     * Refused rather than accepted and ignored. A payload that names a fitter on
     * a purchase has misunderstood something, and a silent success would let the
     * caller go on believing the attribution was recorded.
     */
    public static function notASale(string $type): self
    {
        return new self(
            message: sprintf(
                'Only a sale records who did the work — a %s cannot. Goods arriving from a supplier were '.
                'not fitted by anybody here.',
                $type,
            ),
            status: 422,
            errorCode: 'ATTRIBUTION_NOT_A_SALE',
            details: ['type' => $type],
        );
    }

    /**
     * A trade the workshop does not ask about on a sale.
     *
     * Either it was never ticked, or it has since been archived. Both mean the
     * same thing to the caller — this is not one of the boxes the invoice screen
     * offers — so both say it the same way.
     */
    public static function untrackedDesignation(int $designationId): self
    {
        return new self(
            message: 'That trade is not one this workshop records on a sale. Tick "ask for this on a sale" '.
                'against it in the Designation Master first.',
            status: 422,
            errorCode: 'ATTRIBUTION_UNTRACKED_DESIGNATION',
            details: ['designation_id' => $designationId, 'field' => 'staff'],
        );
    }

    /** Somebody who is not on this workshop's staff list at all. */
    public static function unknownEmployee(int $employeeId): self
    {
        return new self(
            message: 'That person is not on the staff list.',
            status: 422,
            errorCode: 'ATTRIBUTION_UNKNOWN_EMPLOYEE',
            details: ['employee_id' => $employeeId, 'field' => 'staff'],
        );
    }
}
