<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Category Master — "what information should I keep about this kind of
 * thing?" — and the table that replaces the `ItemType` enum.
 *
 * ## What a category is, and what it is not
 *
 * A category is a **template**. It says that a motor is described by its rating,
 * phase and speed; that a bearing is described by three diameters; that an LED
 * lamp is described by wattage, lumens and colour temperature. It holds no stock,
 * has no price and is never sold. {@see App\Models\Item} is the product and
 * {@see App\Models\ItemVariant} is the specific thing on the shelf.
 *
 * ## Why this stopped being an enum
 *
 * `ItemType` knew four kinds — motor, part, bulk material, service — and every
 * one of them came from a rewinding workshop. Adding "Water Pump", "LED Light" or
 * "Fashion" meant editing PHP and redeploying, which put the shape of the
 * business behind a developer. The four survive as seeded rows with the same
 * codes, so nothing already recorded changes meaning.
 *
 * ## What the category carries besides its attributes
 *
 * The three things the enum decided and nothing else could:
 *
 *   * **`holds_stock`** — whether a thing of this kind can be inventoried at all.
 *     False for labour, and that is not policy: an hour is produced at the moment
 *     it is sold, so an opening balance of forty hours would be inventing an
 *     asset. `items.is_stock` remains the workshop's choice *within* this
 *     capability, exactly as before.
 *   * **`uses_sac_code`** — whether its tax code is an HSN (goods) or a SAC
 *     (services). The same column on the item either way; not the same word on
 *     the invoice.
 *   * **the defaults** — unit, HSN/SAC and GST rate, copied onto a new item so
 *     the ordinary case needs no decision. Copied, never referenced: correcting a
 *     category's default rate next March must not restate what every existing
 *     item charges.
 *
 * ## Subcategories
 *
 * `parent_id` is self-referencing and one level is all the UI offers, but the
 * column does not enforce that — a workshop that files Bearings under Spare Parts
 * under Motor Components is not doing anything incoherent. Attributes are
 * inherited down the chain (see `item_attributes`), so a subcategory adds to what
 * its parent already asks for rather than restating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // restrictOnDelete rather than cascade, and deliberately: deleting
            // "Motor" must not silently take "Submersible" and every product
            // filed under it. The service refuses a parent with children and says
            // so; this is what catches anything that does not come through it.
            $table->foreignId('parent_id')->nullable()
                ->constrained('item_categories')->restrictOnDelete();

            $table->string('name', 120);

            // The workshop's own short code, and — for the four seeded rows — the
            // old `ItemType` value verbatim ('motor', 'part', 'bulk_material',
            // 'service'). That is what lets `items.type` and `items.category_id`
            // describe the same fact during the changeover instead of two.
            $table->string('code', 40)->nullable();

            $table->string('description', 500)->nullable();

            // Capability, not choice — see the note above. `items.is_stock` is
            // the workshop's choice within it.
            $table->boolean('holds_stock')->default(true);

            // HSN for goods, SAC for services. One flag because an item is one or
            // the other, never both.
            $table->boolean('uses_sac_code')->default(false);

            // Copied onto a new item of this category, never referenced by an
            // existing one. A code rather than a foreign key for the same reason
            // `items.base_uom` is one — see the units migration.
            $table->string('default_unit_code', 20)->nullable();
            $table->string('default_hsn_sac', 10)->nullable();

            // Nullable, unlike `items.gst_rate` which defaults to zero: null here
            // means "this category has no opinion, ask", and zero means "zero
            // rated". A category that defaulted to 0 by accident would put a
            // wrong rate on every product filed under it.
            $table->decimal('default_gst_rate', 5, 2)->nullable();

            // Seeded from the original enum and referenced by products that
            // already exist. Archivable, never deletable — see ItemCategoryService.
            $table->boolean('is_system')->default(false);

            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('display_order')->default(0);

            $table->timestamps();

            // One name, one category — the same rule as `items.name`, for the
            // same reason: two rows called "Bearing" would split one product
            // range in half and both halves would look plausible.
            //
            // Flat across the tenant rather than per parent, because MySQL treats
            // every NULL as distinct in a unique index: a (tenant, parent, name)
            // key would constrain subcategories and leave top-level ones
            // completely unprotected, which is exactly backwards.
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);

            // The picker and the card grid: active-only, in the workshop's order.
            $table->index(['tenant_id', 'is_active', 'display_order']);

            // "What sits under this one" — the tree read.
            $table->index(['tenant_id', 'parent_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            /*
            | No constraint against a category being its own parent, and not for
            | want of trying: MySQL refuses a CHECK that refers to an
            | auto-increment column (error 3818), so `parent_id <> id` cannot be
            | expressed here. Cycles of every length — including the one-step
            | kind — are therefore refused in ItemCategoryService, which has to
            | walk the chain anyway to resolve inherited attributes.
            */

            // A rate is a percentage — 18.00, not 0.18 — and a negative one would
            // flow straight into a bill total.
            DB::statement(
                'ALTER TABLE item_categories ADD CONSTRAINT item_categories_gst_rate_sane
                 CHECK (default_gst_rate IS NULL OR (default_gst_rate >= 0 AND default_gst_rate <= 100))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
