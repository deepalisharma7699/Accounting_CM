<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How things are counted — the Unit Master, and the table that replaces the
 * `UnitOfMeasure` enum.
 *
 * ## Why this stopped being an enum
 *
 * The enum knew seven units, and all seven came from a rewinding workshop: piece,
 * set, coil, kilogram, metre, litre, hour. That is the right list for motors and
 * the wrong list for everything else the product is now asked to hold — a
 * capacitor is measured in µF, a pump in LPM, a bearing in MM, a packet of chips
 * in GRAM, and none of those could be added without a developer editing a file.
 *
 * A unit is therefore a row, and the workshop owns it.
 *
 * ## Why the code stays a string on `items`, `transaction_lines` and
 * `workshop_job_parts`
 *
 * Those columns already hold `'piece'`, `'kg'`, `'hour'` and are copied onto
 * documents that have been issued. Turning them into foreign keys would mean
 * rewriting posted invoices to point at rows, and a bill line's unit is *document
 * detail* — the word that was true when the customer was handed the paper — not a
 * live reference. So the column stays exactly as it is and this table gives the
 * code its meaning. See `App\Support\Units\Unit` and `UnitCast`, which keep every
 * existing `->symbol()` and `->isFractional()` call site working unchanged.
 *
 * ## Why `decimals` rather than an `is_fractional` flag
 *
 * They were two facts in the enum and they are one fact: 2.5 kg is ordinary and
 * 2.5 bearings is a mistake *because* kilograms are recorded to three places and
 * pieces to none. One column cannot disagree with itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            // Per workshop rather than global: a motor shop and a garment shop
            // have no reason to see each other's units, and a shared list is one
            // nobody may prune.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // What the column on `items`, `transaction_lines` and
            // `workshop_job_parts` actually holds — 'piece', 'kg', 'box'. Lower
            // case and stable: renaming one would silently reinterpret every
            // quantity ever recorded against it, which is why the code cannot be
            // edited once the unit exists (only its label and symbol can).
            $table->string('code', 20);

            // 'Kilogram'. What a form's dropdown reads.
            $table->string('label', 60);

            // 'kg'. What a bill line and a stock report print beside a number.
            $table->string('symbol', 12);

            // count | weight | length | volume | time | electrical | other.
            // Grouping for the picker, and the seed of any future conversion
            // work: two units can only convert into one another within a kind.
            $table->string('kind', 20)->default('other');

            // The decimal places a quantity in this unit is recorded to, and
            // therefore whether a fraction of one is meaningful at all. Capped at
            // 3 by the check below, because `stock_movements.quantity` is
            // DECIMAL(15,3) and a unit claiming four places would be storing a
            // precision the ledger cannot hold.
            $table->unsignedTinyInteger('decimals')->default(0);

            // Seeded from the original enum, and referenced by quantities already
            // recorded. Archivable, never deletable — see UnitService. A workshop
            // that never weighs anything switches Kilogram off; it must not be
            // able to delete the row that explains what 12.500 meant.
            $table->boolean('is_system')->default(false);

            $table->boolean('is_active')->default(true);

            // The workshop's own ordering, so the units it uses hourly are not
            // below the ones it uses never.
            $table->unsignedSmallInteger('display_order')->default(0);

            $table->timestamps();

            // One code, one unit. Two rows called 'kg' would split a stock
            // balance the same way two items of one name would.
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'label']);

            // The picker, which is almost always active-only and in the
            // workshop's own order.
            $table->index(['tenant_id', 'is_active', 'display_order']);
        });

        if (DB::getDriverName() === 'mysql') {
            // The ledger stores DECIMAL(15,3). A unit promising more precision
            // than that would be promising something the database silently
            // rounds away.
            DB::statement(
                'ALTER TABLE units ADD CONSTRAINT units_decimals_within_ledger_scale
                 CHECK (decimals BETWEEN 0 AND 3)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
