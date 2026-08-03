<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The specific thing that is bought and sold: this motor, at this rating, at this
 * speed.
 *
 * A variant exists because the trade does not deal in families. Nobody buys "a
 * three-phase induction motor" — they buy a 5 HP, 1440 RPM one, and that is what
 * has a price, a stock level and a cost. M8 counts stock per **variant**, not per
 * item, and M9 prices a bill line from one.
 *
 * **No quantity and no cost column here either.** `qty_on_hand` and `avg_cost` are
 * sums over `stock_movement` in M8 — the whole point of that table is that they
 * cannot be edited directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_variants', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // cascadeOnDelete, unlike every other foreign key in this schema.
            //
            // A variant is not an independent record: it has no meaning apart from
            // its item, and "5 HP / 1440" is not a thing anyone can interpret
            // without knowing it is a motor. Deleting the family therefore takes
            // its variants, and the protection sits where it belongs — on the item
            // itself, which cannot be deleted once anything references it.
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();

            // The workshop's stock-keeping code. Unique per workshop when present,
            // because a SKU that identifies two things is worse than no SKU.
            $table->string('sku', 60)->nullable();

            // A human label — "5 HP / 3ph / 1440 RPM". Derived from the attributes
            // when nobody supplies one, and stored rather than always computed so
            // a workshop can name a variant the way its fitters actually ask for
            // it. See ItemVariant::displayLabel().
            $table->string('label', 180)->nullable();

            // The attributes that make this variant what it is, validated against
            // the *item type's* schema — HP/phase/RPM for a motor, gauge for
            // copper wire. See ItemType::attributeSchema().
            //
            // JSON rather than columns because the fields differ per type and the
            // set grows: a column per attribute would mean a migration every time
            // the trade turns out to care about something new, and forty mostly
            // null columns on every row.
            $table->json('attributes')->nullable();

            // The list price, per base unit. Nullable and legitimately so: a motor
            // rewind is quoted per job, and a workshop that prices on the day
            // should not have to invent a number to record the variant.
            //
            // Not a stored margin: the *cost* comes from M8's weighted average at
            // the moment of sale, so a margin computed here would be stale the
            // next time stock arrived. M9 computes it per line.
            $table->decimal('sell_price', 15, 2)->nullable();

            // The markup the workshop wants over cost, as a percentage. Used to
            // *suggest* a price once M8 knows the average cost — which is why it
            // sits beside sell_price rather than replacing it. Neither is derived
            // from the other; one is what is charged and one is what was intended.
            $table->decimal('markup_percent', 6, 2)->nullable();

            // Reorder level, for M8's low-stock view. Held per variant because
            // that is the level a workshop actually thinks in: two 5 HP motors in
            // reserve, not "two motors".
            $table->decimal('reorder_level', 15, 3)->nullable();

            $table->boolean('is_draft')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);

            // The variant picker on a bill line, and the item detail view.
            $table->index(['tenant_id', 'item_id', 'is_active']);

            // The review queue, alongside draft items.
            $table->index(['tenant_id', 'is_draft']);
        });

        // Restating in the database what the service already refuses, exactly as
        // journal_entries and transaction_payments do. A negative price is not a
        // discount — it is a number nobody meant to type, and it would flow
        // straight into a bill total.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design).
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE item_variants ADD CONSTRAINT item_variants_non_negative
                 CHECK ((sell_price IS NULL OR sell_price >= 0)
                    AND (markup_percent IS NULL OR markup_percent >= 0)
                    AND (reorder_level IS NULL OR reorder_level >= 0))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_variants');
    }
};
