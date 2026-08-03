<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use DateTimeInterface;

/**
 * A transaction dated before the workshop's books opened.
 *
 * The period before go-live belongs to whatever the workshop kept previously —
 * a notebook, Tally, a spreadsheet. Its closing position arrives once, as
 * opening balances (M11); letting a stray back-dated entry in afterwards would
 * double-count it and make the two systems disagree forever.
 *
 * The rule itself lives on {@see Tenant::acceptsPostingOn()}; this is the point
 * where it is enforced.
 */
class BooksClosedException extends ApiException
{
    public static function before(Tenant $tenant, DateTimeInterface $date): self
    {
        $goLive = $tenant->books_start_date?->toDateString() ?? '';

        return new self(
            message: "This workshop's books open on {$goLive}. A transaction dated ".
                $date->format('Y-m-d').
                ' belongs to the previous system — enter it as an opening balance instead.',
            status: 422,
            errorCode: 'BOOKS_CLOSED',
            details: [
                'field' => 'date',
                'books_start_date' => $goLive,
                'date' => $date->format('Y-m-d'),
            ],
        );
    }
}
