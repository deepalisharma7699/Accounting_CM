<?php

namespace App\Services\Accounting\Posting;

use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Services\Accounting\ChartOfAccountService;

/**
 * The definition of what a kind of transaction *does* to the books.
 *
 * A template is where "a sale" becomes "debit the customer, credit Sales,
 * credit GST Output, debit COGS, credit Inventory". Keeping that in one named
 * class per transaction type is what stops the same double entry being
 * re-derived — slightly differently each time — in a controller, an importer
 * and an AI draft.
 *
 * Templates never resolve accounts by name or by id. They ask
 * {@see ChartOfAccountService::system()} for a
 * {@see SystemAccount}, so a workshop that renames or renumbers its
 * chart changes nothing here.
 *
 * A template's only job is to produce lines. It does not validate the balance,
 * check the books-open date or write anything — {@see PostingEngine} does all
 * three, for every template alike, so no template can accidentally skip one.
 */
interface PostingTemplate
{
    public function type(): TransactionType;

    /**
     * Turn a validated payload into the lines it implies.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidJournalException
     */
    public function build(array $input): array;
}
