<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue: what the workshop sells, fits, consumes and charges for.
 *
 * Catalogue only. **There is no quantity and no cost column here, and there never
 * will be.** Quantity on hand and weighted average cost are sums over M8's
 * `stock_movement`, for exactly the reason a party's outstanding is a sum over
 * `journal_entries`: a stored aggregate agrees with its movements right up until
 * one is written without the other, and nobody notices until a stock-take.
 *
 * An item is the *family* — "3-phase induction motor", "copper winding wire" —
 * and `item_variants` holds the specific thing that is actually bought and sold.
 * The split matters because the family carries what the tax authority and the
 * accountant care about (HSN code, GST rate, unit) while the variant carries what
 * the customer asks for (5 HP, 1440 RPM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('name', 180);

            // The workshop's own code for the family — "MOT-3PH", "CU-WIRE".
            // Optional, because a workshop that has never used codes should not
            // have to invent one to record its first item, and unique when
            // present so a code always identifies exactly one thing.
            $table->string('code', 40)->nullable();

            // motor | part | bulk_material | service. Decides whether stock is
            // possible, the default unit, and which attributes a variant needs —
            // see ItemType.
            $table->string('type', 20);

            // HSN for goods, SAC for services. One column because they are the
            // same field in the same position on a GST invoice, and an item is
            // one or the other, never both. Nullable: a workshop below the
            // registration threshold has no use for it, and forcing a guess would
            // put a wrong code on every bill.
            $table->string('hsn_sac', 10)->nullable();

            // The GST rate as a percentage — 18.00, not 0.18. Stored on the family
            // because the rate follows the HSN code, which is a property of what
            // the thing *is*, not of which variant of it was sold.
            //
            // DECIMAL, like every other number in this product. A float rate is
            // multiplied by an amount to compute tax, and that is the one place a
            // rounding error becomes a figure on a government return.
            $table->decimal('gst_rate', 5, 2)->default(0);

            // piece | kg | metre | … The unit every quantity of this item is
            // recorded in. Fixed once set, because changing it would silently
            // reinterpret every quantity ever recorded — see UnitOfMeasure.
            $table->string('base_uom', 20);

            // Whether this item is inventoried. A service can never be; the other
            // three types can, and a workshop may still turn it off for something
            // they buy to order and never hold — see ItemType::canHoldStock().
            $table->boolean('is_stock')->default(true);

            // Created by an importer or the capture agent from a name it did not
            // recognise, and not yet reviewed by a person.
            //
            // A flag rather than a separate table: a draft item is a real item
            // that somebody still has to look at, and it must be usable — M11
            // imports opening stock against items it has just invented, and
            // hiding those from the ledger would make the import unbalanced. The
            // flag drives a review queue, not a filter on the books.
            $table->boolean('is_draft')->default(false);

            $table->string('description', 500)->nullable();

            // Archived rather than deleted once anything references it, the same
            // rule as an account and a party: a bill line whose item vanished
            // loses the name that explains it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One name, one item. Two rows called "Copper Wire" split a stock
            // balance in half and both halves look plausible — the same failure
            // the unique party name prevents.
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);

            // The listing, which is almost always active-only and name-ordered.
            $table->index(['tenant_id', 'is_active', 'name']);

            // The type filter, and M8's "every stock item" sweep.
            $table->index(['tenant_id', 'type', 'is_stock']);

            // The review queue.
            $table->index(['tenant_id', 'is_draft']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
