<?php

namespace Tests\Unit;

use App\Enums\ItemType;
use App\Enums\OpeningRowKind;
use App\Exceptions\Onboarding\OpeningBalanceException;
use App\Services\Onboarding\NameMatcher;
use App\Services\Onboarding\OpeningCsvParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two pieces of M11 that touch no database at all: reading a spreadsheet,
 * and deciding whether two names are the same thing.
 *
 * Both are worth unit tests rather than only feature coverage, because both are
 * where a workshop's go-live goes quietly wrong — a file that parses to nothing,
 * or a matcher that attaches ₹80,000 of somebody else's debt to the wrong party.
 */
class OpeningCsvParserTest extends TestCase
{
    private function parser(): OpeningCsvParser
    {
        return new OpeningCsvParser;
    }

    private function matcher(): NameMatcher
    {
        return new NameMatcher;
    }

    /* ---------------------------------------------------------------------
     | Reading a file
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_reads_the_columns_this_product_publishes(): void
    {
        $rows = $this->parser()->parse(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost,amount,account,side,gstin,reference
        stock,Ball Bearing,6204,part,10,120.00,,,,,first count
        CSV);

        [$lineNo, $row] = $rows[0];

        $this->assertSame(2, $lineNo);
        $this->assertSame(OpeningRowKind::Stock, $row->kind);
        $this->assertSame('Ball Bearing', $row->name);
        $this->assertSame('6204', $row->variant);
        $this->assertSame(ItemType::Part, $row->itemType);
        $this->assertSame('10', $row->quantity);
        $this->assertSame('120.00', $row->unitCost);
        $this->assertSame('first count', $row->reference);
    }

    #[Test]
    public function it_survives_what_a_real_export_actually_contains(): void
    {
        // A byte-order mark, CRLF endings, semicolons because the machine is set
        // to a European locale, headers in the wrong case and a spelling from
        // whatever package the workshop used before. Every one of these is a file
        // somebody would otherwise re-key by hand — and re-keying is where
        // transcription errors come from.
        $csv = "\u{FEFF}Category;Party Name;Balance\r\nreceivable;Sharma Motors;15000.00\r\n";

        $rows = $this->parser()->parse($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('Sharma Motors', $rows[0][1]->name);
        $this->assertSame('15000.00', $rows[0][1]->amount);
    }

    #[Test]
    public function it_reads_indian_figures_and_bracketed_negatives(): void
    {
        $rows = $this->parser()->parse(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,"₹1,24,500.00"
        payable,Kohli Traders,"(2,000.00)"
        CSV);

        $this->assertSame('124500.00', $rows[0][1]->amount);
        // Kept as a minus rather than silently made positive, so the row's own
        // validation reports it.
        $this->assertSame('-2000.00', $rows[1][1]->amount);
    }

    #[Test]
    public function a_blank_spacer_row_is_skipped_without_shifting_the_numbers(): void
    {
        $rows = $this->parser()->parse(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00

        payable,Kohli Traders,32000.00
        CSV);

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0][0]);
        // Line 4 of the file, because line 3 was the blank one — quoting line 3
        // would send somebody to the wrong row of their spreadsheet.
        $this->assertSame(4, $rows[1][0]);
    }

    #[Test]
    public function a_column_this_product_has_no_use_for_is_ignored_rather_than_refused(): void
    {
        $rows = $this->parser()->parse(<<<'CSV'
        kind,name,amount,my own notes,checked by
        receivable,Sharma Motors,15000.00,rang them Tuesday,RS
        CSV);

        $this->assertSame('Sharma Motors', $rows[0][1]->name);
    }

    #[Test]
    public function a_file_with_no_recognisable_header_names_what_it_expected(): void
    {
        $this->expectException(OpeningBalanceException::class);

        $this->parser()->parse("alpha,beta,gamma\n1,2,3");
    }

    #[Test]
    public function an_empty_file_is_refused_rather_than_imported_as_nothing(): void
    {
        $this->expectException(OpeningBalanceException::class);

        $this->parser()->parse('   ');
    }

    /* ---------------------------------------------------------------------
     | Matching names
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_folds_away_what_does_not_identify_a_business(): void
    {
        $matcher = $this->matcher();

        $this->assertSame('sharma motors', $matcher->normalise('M/s Sharma Motors Pvt. Ltd.'));
        $this->assertSame('sharma motors', $matcher->normalise('SHARMA MOTORS'));
        $this->assertSame(100, $matcher->score(
            $matcher->normalise('M/s Sharma Motors Pvt. Ltd.'),
            $matcher->normalise('sharma motors'),
        ));
    }

    #[Test]
    public function digits_are_load_bearing_and_are_never_folded_away(): void
    {
        // 6204 and 6205 are two bearings. Any normalisation that lost the
        // difference would be worse than none.
        $matcher = $this->matcher();

        $this->assertLessThan(
            NameMatcher::THRESHOLD,
            $matcher->score($matcher->normalise('6204'), $matcher->normalise('6205')),
        );
    }

    #[Test]
    public function a_dropped_letter_matches_and_a_different_word_does_not(): void
    {
        $matcher = $this->matcher();

        $candidates = ['Sharma Motors', 'Verma Motors'];

        // A typo resolves.
        $this->assertSame(
            'Sharma Motors',
            $matcher->best('Sharma Motor', $candidates, fn (string $name) => $name)[0],
        );

        // A different business in the next street does not — there is no
        // "closest of a bad lot" here, deliberately.
        $this->assertNull($matcher->best('Bansal Traders', $candidates, fn (string $name) => $name));
    }

    #[Test]
    public function an_exact_match_wins_outright_whatever_the_iteration_order(): void
    {
        $matcher = $this->matcher();

        $this->assertSame(
            [' Sharma Motors ', 100],
            [
                $matcher->best('Sharma Motors', ['Sharma Motor', ' Sharma Motors ', 'Sharma Motorss'], fn ($n) => $n)[0],
                100,
            ],
        );
    }

    #[Test]
    public function an_empty_needle_matches_nothing(): void
    {
        $this->assertNull($this->matcher()->best('', ['Sharma Motors'], fn (string $name) => $name));
    }
}
