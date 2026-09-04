<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the catalogue needed once it stopped being about motors: the
 * category a product is filed under, and the handful of fields a shop selling
 * bearings, lamps, toys or shirts expects on a product record.
 *
 * ## `category_id` is nullable here and required by the service
 *
 * Nullable because this migration runs before the backfill that fills it in, and
 * a NOT NULL column added to a table with rows in it has to invent a value. The
 * backfill sets every existing row from its `type`, and `ItemService` refuses to
 * create a product without one — which is the constraint that actually matters,
 * because it is the one that can say *why*.
 *
 * ## Why `brand` is a column and not an attribute
 *
 * It was an optional attribute of the `part` type and nothing else, which meant a
 * motor could not record its make. Brand is not a property of *one kind* of
 * product — every shop in every trade asks "whose is it?" — so it belongs beside
 * the name rather than inside a category's template. A workshop that wants brand
 * as a dropdown per category can still add one; this is the field the search
 * indexes and the listing shows.
 *
 * ## Why `barcode` is on the variant and `brand` is on the item
 *
 * A barcode identifies the thing that crosses the counter, and that is the
 * variant: a shirt in Medium and the same shirt in Large carry different
 * barcodes, exactly as they carry different SKUs and different stock. The brand
 * is the same for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // restrictOnDelete: a product whose category vanished loses the
            // template that explains what its attributes mean. The service
            // refuses it first and says so; this catches everything else.
            $table->foreignId('category_id')->nullable()->after('code')
                ->constrained('item_categories')->restrictOnDelete();

            // Crompton, SKF, Havells. Nullable and legitimately so — an unbranded
            // bush is a real thing, and forcing a guess would put a wrong make on
            // the record.
            $table->string('brand', 120)->nullable()->after('category_id');

            // A relative key on the default disk, written by the upload path.
            // Just the key: the disk is configuration, and storing it per row
            // would mean a product pointing at a disk the application no longer
            // has.
            $table->string('image_path', 255)->nullable()->after('description');

            // The listing's category filter, and the "what is filed under this"
            // read the category master needs before it will allow an archive.
            $table->index(['tenant_id', 'category_id', 'is_active']);

            // Brand is searched from the same box as name and code.
            $table->index(['tenant_id', 'brand']);
        });

        Schema::table('item_variants', function (Blueprint $table) {
            // Scanned at the counter, so it has to resolve to exactly one thing.
            // Nullable — most workshops label nothing — and unique when present,
            // for the same reason a SKU is: a barcode that identifies two
            // products sells the wrong one.
            $table->string('barcode', 64)->nullable()->after('sku');

            // The hard floor, distinct from `reorder_level` above it and worth
            // stating: `reorder_level` is "order more when it reaches this" and
            // `min_stock` is "never let it fall below this". A shop orders at 20
            // and panics at 5, and collapsing them into one number loses the
            // difference between a purchase to plan and a purchase to make today.
            $table->decimal('min_stock', 15, 3)->nullable()->after('reorder_level');

            $table->unique(['tenant_id', 'barcode']);
        });

        if (DB::getDriverName() === 'mysql') {
            // A negative floor is not a floor. Folded into the existing
            // non-negative constraint's reasoning rather than added to it,
            // because MySQL cannot extend a CHECK in place.
            DB::statement(
                'ALTER TABLE item_variants ADD CONSTRAINT item_variants_min_stock_non_negative
                 CHECK (min_stock IS NULL OR min_stock >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_variants DROP CONSTRAINT item_variants_min_stock_non_negative');
        }

        Schema::table('item_variants', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'barcode']);
            $table->dropColumn(['barcode', 'min_stock']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'brand']);
            $table->dropIndex(['tenant_id', 'category_id', 'is_active']);
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['brand', 'image_path']);
        });
    }
};
