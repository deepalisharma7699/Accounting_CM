<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\TransactionType;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\PostingEngine;

/**
 * The manual journal: the one template whose lines the user writes directly.
 *
 * Every other template derives its lines from a business document — a bill, a
 * payment, an opening balance — and the accounts are decided by the template
 * rather than by the person entering it. A journal entry is the escape hatch:
 * it can express any correction, which is exactly why it is restricted to users
 * trusted with the books and why it is built first. Everything else is checked
 * against what this one proves.
 *
 * The work here is deliberately thin — the safety is in
 * {@see PostingEngine}, which validates the result of
 * every template identically.
 */
class ManualJournalTemplate implements PostingTemplate
{
    public function type(): TransactionType
    {
        return TransactionType::Journal;
    }

    /**
     * @param  array{lines: array<int, array<string, mixed>>}  $input
     * @return array<int, PostingLine>
     */
    public function build(array $input): array
    {
        $lines = [];

        foreach (array_values($input['lines'] ?? []) as $index => $line) {
            // 1-based, so the error a user sees names the row they are looking
            // at rather than the array index behind it.
            $lines[] = PostingLine::fromInput($line, $index + 1);
        }

        return $lines;
    }
}
