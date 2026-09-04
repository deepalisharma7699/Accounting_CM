<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point `items` at the Brand Master, and take the typed string away.
 *
 * ## Why the column goes rather than staying beside the key
 *
 * A name kept next to the foreign key is a copy, and a copy is a thing that can
 * disagree: rename "Crompton Greaves" in the master and every product created
 * before the rename goes on printing the old spelling from its own column, with
 * nothing to say which of the two the shop meant. §4.4 — one source of truth —
 * and the brand's is the master row.
 *
 * ## The backfill, and what it deliberately does not do
 *
 * Every distinct non-empty `items.brand`, per workshop, becomes a brand row and
 * the products pointing at it are relinked. Matching is exact after trimming:
 * "Crompton" and "crompton" merge (MySQL's collation is case-insensitive, so the
 * unique key treats them as one name), but "Crompton" and "Crompton Greaves" stay
 * two. Guessing that those are one shop would be guessing about somebody's
 * catalogue, and the master screen can merge them in a minute now that there is
 * a list to see them in.
 *
 * `down()` writes the names back before dropping the key, so a rollback loses the
 * master list and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // restrictOnDelete: a product whose brand vanished loses the name
            // that says whose it is. The service refuses it first and says why;
            // this catches everything that does not come through the service.
            $table->foreignId('brand_id')->nullable()->after('category_id')
                ->constrained('item_brands')->restrictOnDelete();

            $table->index(['tenant_id', 'brand_id']);
        });

        $this->backfillBrands();

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'brand']);
            $table->dropColumn('brand');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('brand', 120)->nullable()->after('category_id');
            $table->index(['tenant_id', 'brand']);
        });

        DB::table('items')
            ->join('item_brands', 'items.brand_id', '=', 'item_brands.id')
            ->update(['items.brand' => DB::raw('item_brands.name')]);

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'brand_id']);
            $table->dropConstrainedForeignId('brand_id');
        });
    }

    /**
     * Turn what people typed into rows, one workshop at a time.
     *
     * Unscoped `DB` queries throughout: a migration runs outside any tenant
     * context, and the global scope on the models would filter every row away.
     */
    private function backfillBrands(): void
    {
        $typed = DB::table('items')
            ->select('tenant_id', 'brand')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->get();

        $now = now();

        foreach ($typed as $row) {
            $name = trim((string) $row->brand);

            if ($name === '') {
                continue;
            }

            $brandId = DB::table('item_brands')
                ->where('tenant_id', $row->tenant_id)
                ->where('name', $name)
                ->value('id');

            if ($brandId === null) {
                $brandId = DB::table('item_brands')->insertGetId([
                    'tenant_id' => $row->tenant_id,
                    'name' => $name,
                    'code' => null,
                    'description' => null,
                    'is_active' => true,
                    'display_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Matched on the raw stored value rather than the trimmed one, so a
            // row saved with a stray space is relinked too.
            DB::table('items')
                ->where('tenant_id', $row->tenant_id)
                ->where('brand', $row->brand)
                ->update(['brand_id' => $brandId]);
        }
    }
};
