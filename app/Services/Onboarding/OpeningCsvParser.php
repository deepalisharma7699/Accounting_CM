<?php

namespace App\Services\Onboarding;

use App\Exceptions\Onboarding\OpeningBalanceException;

/**
 * Turning a spreadsheet into rows.
 *
 * ## Why CSV and not Excel
 *
 * Because reading `.xlsx` means a dependency that parses ZIP archives and XML
 * from an untrusted upload, and every spreadsheet in existence exports CSV. The
 * cost of the dependency is not the megabytes; it is that a workshop's go-live
 * file is the one piece of user-supplied data this product parses at all, and
 * the smallest possible parser is the right one to point at it. "Save as CSV" is
 * one menu item, and it is in the instructions.
 *
 * What this does accept is everything a real export actually contains: a UTF-8
 * byte-order mark, `\r\n` line endings, semicolons instead of commas where the
 * regional settings put them there, headers in any order and in any case, and
 * figures written "₹1,24,500.00" or "(2,000)". None of that is generosity for
 * its own sake — every one of them is a file somebody would otherwise have to
 * re-key by hand, and re-keying is where transcription errors come from.
 *
 * ## The column vocabulary
 *
 * | Column | Meaning |
 * | --- | --- |
 * | `kind` | stock, receivable, payable, balance |
 * | `name` | the item, or the party |
 * | `variant` | the specification: "6204", "22 SWG", "5 HP / 3 ph / 1440" |
 * | `type` | motor, part, bulk_material — only when the item is new |
 * | `quantity` | stock only |
 * | `unit_cost` | stock only, per base unit |
 * | `amount` | what it is worth; for stock, optional and overrides qty × cost |
 * | `account` | balance only: an account code or name |
 * | `side` | balance only: debit or credit, defaulting to the account's own |
 * | `gstin` | party rows, optional |
 * | `reference` | free text, kept as the ledger memo |
 *
 * Anything else in the header is ignored rather than refused. A workshop's own
 * spreadsheet has columns this product has no use for, and making them delete
 * their notes column before they can go live would be a poor trade.
 */
class OpeningCsvParser
{
    /**
     * Header spellings this parser recognises, mapped to its own vocabulary.
     *
     * Aliases exist for the words a workshop's previous package used — "party"
     * for a customer's name, "rate" for a unit cost, "qty" for a quantity — so
     * that an export can be uploaded rather than rewritten.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'kind' => 'kind',
        'type_of_row' => 'kind',
        'category' => 'kind',

        'name' => 'name',
        'item' => 'name',
        'item_name' => 'name',
        'party' => 'name',
        'party_name' => 'name',
        'particulars' => 'name',
        'description' => 'name',

        'variant' => 'variant',
        'specification' => 'variant',
        'spec' => 'variant',
        'size' => 'variant',

        'type' => 'type',
        'item_type' => 'type',

        'quantity' => 'quantity',
        'qty' => 'quantity',

        'unit_cost' => 'unit_cost',
        'cost' => 'unit_cost',
        'rate' => 'unit_cost',

        'amount' => 'amount',
        'value' => 'amount',
        'balance' => 'amount',
        'total' => 'amount',

        'account' => 'account',
        'account_code' => 'account',
        'ledger' => 'account',

        'side' => 'side',
        'dr_cr' => 'side',

        'gstin' => 'gstin',
        'gst_no' => 'gstin',

        'reference' => 'reference',
        'ref' => 'reference',
        'notes' => 'reference',
        'remarks' => 'reference',
    ];

    /** The delimiters a spreadsheet actually writes, most likely first. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Parse a whole file into rows, keeping each row's line number.
     *
     * The line number is of the *file*, header included, so "row 14" means row
     * 14 of the thing the user is looking at. Getting that wrong is a small
     * mistake that costs somebody twenty minutes.
     *
     * @return array<int, array{0: int, 1: OpeningRow}>
     *
     * @throws OpeningBalanceException
     */
    public function parse(string $csv): array
    {
        $lines = $this->lines($csv);

        if ($lines === []) {
            throw OpeningBalanceException::nothingToImport();
        }

        $delimiter = $this->delimiterFor($lines[0]);
        $header = $this->headerFrom(str_getcsv($lines[0], $delimiter, '"', '\\'));

        $rows = [];

        foreach ($lines as $index => $line) {
            // The header is line 1 and is not data. Skipped by index rather than
            // by shifting the array, so every row below keeps its true number.
            if ($index === 0) {
                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $row = OpeningRow::from($this->associate($header, $cells));

            // A blank line is a spacer, not a mistake. Spreadsheets are full of
            // them and reporting each one as an error would bury the real ones.
            if ($row->isBlank()) {
                continue;
            }

            $rows[] = [$index + 1, $row];
        }

        if ($rows === []) {
            throw OpeningBalanceException::nothingToImport();
        }

        return $rows;
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * @return array<int, string>
     */
    private function lines(string $csv): array
    {
        // The byte-order mark Excel writes on every UTF-8 export. Left in place
        // it becomes part of the first header's name, and every column silently
        // fails to match.
        $csv = preg_replace('/^\x{FEFF}/u', '', $csv) ?? $csv;

        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        // Trailing blank lines only. Interior ones are kept, because dropping
        // them would shift every line number after the gap.
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    /**
     * Whichever delimiter appears most often in the header.
     *
     * Sniffing rather than configuring, because the user does not know and
     * should not have to: a machine set to a European locale exports semicolons
     * and nothing in the spreadsheet says so.
     */
    private function delimiterFor(string $header): string
    {
        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($header, $delimiter);

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Map each column position to this parser's vocabulary.
     *
     * @param  array<int, string|null>  $cells
     * @return array<int, string>
     *
     * @throws OpeningBalanceException
     */
    private function headerFrom(array $cells): array
    {
        $header = [];

        foreach ($cells as $index => $cell) {
            $key = self::COLUMNS[$this->normaliseHeader((string) $cell)] ?? null;

            if ($key !== null) {
                $header[$index] = $key;
            }
        }

        // At least something recognisable. A file whose first row is data rather
        // than headers would otherwise be read as a header of gibberish and then
        // reported as "0 rows", which tells nobody anything.
        if ($header === []) {
            throw OpeningBalanceException::unknownColumns(
                implode(', ', array_values(array_unique(self::COLUMNS)))
            );
        }

        return $header;
    }

    private function normaliseHeader(string $cell): string
    {
        $cell = strtolower(trim($cell));
        $cell = preg_replace('/[^a-z0-9]+/', '_', $cell) ?? $cell;

        return trim($cell, '_');
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string|null>  $cells
     * @return array<string, string>
     */
    private function associate(array $header, array $cells): array
    {
        $row = [];

        foreach ($header as $index => $key) {
            $row[$key] = (string) ($cells[$index] ?? '');
        }

        return $row;
    }
}
