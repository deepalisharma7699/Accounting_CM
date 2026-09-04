<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Brand Master — whose a thing is.
 *
 * ## Why this stopped being a free-text column
 *
 * `items.brand` was a string somebody typed, and a typed string is a master list
 * that nobody maintains: "Crompton", "crompton" and "Crompton Greaves" are three
 * brands to the database and one to the shop. The listing filtered on it, the
 * search indexed it, and both quietly disagreed with what the counter believed.
 * The same failure the Category Master was built to remove, one column across.
 *
 * ## Why it is a table of its own and not a category attribute
 *
 * It was an attribute of the `part` type once, and that is what stopped a motor
 * from recording its make. Every trade asks whose a thing is, so brand belongs
 * beside the name rather than inside one category's template — see the migration
 * that added the column. What changes here is only that the answer is now chosen
 * from a list the shop keeps, rather than spelled afresh each time.
 *
 * ## What it deliberately does not carry
 *
 * No default unit, no default HSN and no default GST rate. A category is a
 * *template* and copies those onto a new product; a brand is an *identity* and
 * has no opinion about how the thing it makes is taxed or counted. A brand that
 * carried defaults would be a second place a rate came from, and the two would
 * disagree the first time a shop stocked a Crompton pump and a Crompton motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_brands', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('name', 120);

            // The workshop's own short code, optional for the same reason an
            // item's is: a shop that has never used codes should not have to
            // invent one to record its first brand.
            $table->string('code', 40)->nullable();

            $table->string('description', 500)->nullable();

            // Archived rather than deleted once products carry it — the same rule
            // as a category, an account and a party, for the same reason: the
            // products left behind would lose the name that explains whose they
            // are. Archiving takes it off the create form and leaves it readable.
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('display_order')->default(0);

            $table->timestamps();

            // One name, one brand. Two rows called "Crompton" would split a
            // range in half and both halves would look right — the same rule
            // `items.name` and `item_categories.name` carry.
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);

            // The dropdown and the master list: active-only, in the shop's order.
            $table->index(['tenant_id', 'is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_brands');
    }
};
