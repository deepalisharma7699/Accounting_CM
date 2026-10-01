<?php

namespace Tests\Unit;

use App\Services\Onboarding\OpeningRow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The row itself — shape, blankness and the import fingerprint.
 *
 * No database anywhere in here, which is the point: the fingerprint is what
 * decides whether a declaration is a duplicate of one already posted, and
 * getting that wrong refuses a figure that was never declared. It is worth
 * holding shut without a schema in the way.
 */
class OpeningRowTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | Identifying a variant outright
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_row_may_name_the_variant_it_is_about(): void
    {
        $row = OpeningRow::from([
            'kind' => 'stock',
            'name' => 'Ball Bearing',
            'variant_id' => '41',
            'quantity' => '10',
            'unit_cost' => '120.00',
        ]);

        $this->assertSame(41, $row->variantId);
    }

    #[Test]
    public function a_row_from_a_file_names_no_variant_id_at_all(): void
    {
        $row = OpeningRow::from([
            'kind' => 'stock',
            'name' => 'Ball Bearing',
            'variant' => '6204',
            'quantity' => '10',
        ]);

        $this->assertNull($row->variantId);
    }

    #[Test]
    public function an_empty_variant_id_is_absent_rather_than_zero(): void
    {
        // A form that posts every field sends '' for the one it did not fill,
        // and variant #0 does not exist — resolving it would be an error on a
        // row nobody meant to identify by id.
        $row = OpeningRow::from(['kind' => 'stock', 'name' => 'X', 'variant_id' => '']);

        $this->assertNull($row->variantId);
    }

    #[Test]
    public function a_row_carrying_only_a_variant_id_is_not_blank(): void
    {
        // isBlank() drops spacer rows out of a pasted file. A row that names a
        // variant is about something, and silently dropping it would answer a
        // declaration with "nothing to import".
        $this->assertFalse(OpeningRow::from(['kind' => 'stock', 'variant_id' => '41'])->isBlank());
        $this->assertTrue(OpeningRow::from(['kind' => 'stock'])->isBlank());
    }

    /* ---------------------------------------------------------------------
     | The fingerprint
     |-------------------------------------------------------------------- */

    #[Test]
    public function two_variants_of_one_product_do_not_share_a_fingerprint(): void
    {
        /*
         * The bug this exists for. Two variants of one product are the same
         * *name*, and a screen declaring one sends no `variant` text — so
         * "10 @ 120 of Ball Bearing" for the 6204 and for the 6204 ZZ hashed
         * identically, and the second was refused outright as a file already
         * imported. A correct figure the product would not accept.
         */
        $base = ['kind' => 'stock', 'name' => 'Ball Bearing', 'quantity' => '10', 'unit_cost' => '120.00'];

        $this->assertNotSame(
            OpeningRow::from($base + ['variant_id' => '41'])->fingerprintParts(),
            OpeningRow::from($base + ['variant_id' => '42'])->fingerprintParts(),
        );
    }

    #[Test]
    public function a_pasted_file_hashes_exactly_as_it_always_did(): void
    {
        /*
         * The other half of the same decision, and the reason the id is
         * *appended* rather than always present. Every fingerprint already
         * stored against an imported file was computed without it; an empty
         * segment added unconditionally would rehash all of them, and a
         * workshop re-pasting last month's file would stop being told it had
         * already been imported.
         */
        $row = OpeningRow::from([
            'kind' => 'stock',
            'name' => 'Ball Bearing',
            'variant' => '6204',
            'type' => 'part',
            'quantity' => '10',
            'unit_cost' => '120.00',
        ]);

        $this->assertSame([
            'stock', 'ball bearing', '6204', 'part', '10', '120.00', '', '', '', '',
        ], $row->fingerprintParts());
    }

    #[Test]
    public function the_same_variant_declared_twice_still_hashes_the_same(): void
    {
        // The duplicate guard has to go on working for the new path too: the
        // same declaration sent twice is one declaration.
        $row = ['kind' => 'stock', 'name' => 'Ball Bearing', 'variant_id' => '41', 'quantity' => '10'];

        $this->assertSame(
            OpeningRow::from($row)->fingerprintParts(),
            OpeningRow::from($row)->fingerprintParts(),
        );
    }
}
