<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a category asks about the things filed under it — HP, RPM and phase for a
 * motor; inner, outer and width for a bearing; wattage and lumens for a lamp.
 *
 * This table replaces `ItemType::attributeSchema()`, and it is the whole point of
 * the module: an admin adds a row here and the universal create form grows a
 * field, with no migration, no API, no component and no deployment.
 *
 * ## Where the *values* go — nowhere new
 *
 * They keep going into `item_variants.attributes`, the JSON bag that has held
 * them since M7. That is worth stating plainly, because it is what makes this
 * change cheap and safe: the storage was already right, and only the source of
 * the *rules* moves. Nothing already recorded is rewritten, and the four seeded
 * categories carry the same attribute keys the enum used, so every existing
 * variant validates unchanged.
 *
 * ## Why `key` is write-once
 *
 * `key` is the JSON key the values are stored under. Renaming `hp` to `rating`
 * would not rename it inside a thousand variant bags — it would orphan every one
 * of them and leave a required field looking unfilled. So the label is editable
 * and the key is not, exactly as an item's unit is fixed once quantities exist
 * against it. See ItemAttributeService.
 *
 * ## Inheritance
 *
 * A subcategory's fields are its parent's *plus* its own, resolved by walking up
 * `item_categories.parent_id`. So "Submersible Motor" under "Motor" asks for HP,
 * phase and RPM without restating them, and adds Head and Flow Rate. A child may
 * not reuse a parent's key — that would be two definitions of one JSON field, and
 * the resolver would have to pick one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_attributes', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // cascadeOnDelete, unlike most foreign keys here — and for the reason
            // `item_variants.item_id` cascades from `items`: an attribute
            // definition has no meaning apart from its category. "Flow Rate" is
            // uninterpretable without knowing it belongs to Water Pump. The
            // protection sits on the category, which cannot be deleted once
            // anything is filed under it.
            $table->foreignId('category_id')->constrained('item_categories')->cascadeOnDelete();

            // The JSON key inside `item_variants.attributes`. Write-once — see
            // the note above. Snake case, enforced by the service so a key is
            // always usable as a form field name and an object key.
            $table->string('key', 40);

            // What the form prints beside the box. Editable, unlike the key:
            // renaming "Rating" to "Horsepower" is a display change and should
            // not be a data migration.
            $table->string('label', 80);

            // text | number | decimal | dropdown | boolean | date.
            //
            // The brief also lists "measurement", which is not a seventh type: a
            // measurement is a number with a unit, and `unit_code` below is what
            // makes it one. Adding a type that differed from `number` only by
            // having a unit would be two ways to say one thing.
            $table->string('data_type', 20)->default('text');

            // The unit printed after the input — 'HP', 'RPM', 'mm', 'µF'. A code
            // into `units`, nullable because "Frame size" and "Material" have no
            // unit and inventing one would be noise on every form.
            $table->string('unit_code', 20)->nullable();

            // Whether a product of this category can exist without it. Demanded
            // sparingly by convention: a motor with no HP is not identifiable by
            // anybody afterwards, but refusing a bearing because nobody typed its
            // material pushes people into not recording the bearing at all.
            $table->boolean('is_required')->default(false);

            // Pre-filled on a new product. Stored as text like every value in the
            // bag — the data type governs how it is read, not how it is kept.
            $table->string('default_value', 120)->nullable();

            // The fixed set, for `dropdown`. A JSON array of strings, and null for
            // every other type. Ordered: it is what the select renders, and
            // alphabetising "Deep Groove, Needle, Tapered" would bury the common
            // one in the middle.
            $table->json('options')->nullable();

            // Bounds for `number` and `decimal`, and null where the range is open.
            // Decimal rather than float for the reason every number in this
            // product is: a bound compared against a value must not disagree with
            // itself at the eighth place.
            $table->decimal('min_value', 15, 3)->nullable();
            $table->decimal('max_value', 15, 3)->nullable();

            // One line under the input, for the field whose meaning is not
            // obvious from its label — "Measured at the shaft, not the frame".
            $table->string('help_text', 255)->nullable();

            // The order the fields appear in on the universal form. The workshop's
            // own, because a specification reads in the order a person reciting it
            // would say it: 5 HP, 3 phase, 1440 RPM.
            $table->unsignedSmallInteger('display_order')->default(0);

            // Switched off rather than deleted once values exist against it —
            // see ItemAttributeService. An inactive attribute stops appearing on
            // the form and keeps explaining the values already recorded under its
            // key.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One definition per key per category. Two rows called `hp` would be
            // two rules over one JSON field, and the resolver would have to guess.
            $table->unique(['category_id', 'key']);

            // The form build: every field of one category, in order. The single
            // read this table exists to serve.
            //
            // Named explicitly because the generated name would run past MySQL's
            // 64-character identifier limit.
            $table->index(
                ['tenant_id', 'category_id', 'is_active', 'display_order'],
                'item_attributes_form_build_index',
            );
        });

        if (DB::getDriverName() === 'mysql') {
            // A range whose floor is above its ceiling accepts nothing, and the
            // form would refuse every value a user typed without being able to
            // say why.
            DB::statement(
                'ALTER TABLE item_attributes ADD CONSTRAINT item_attributes_range_ordered
                 CHECK (min_value IS NULL OR max_value IS NULL OR min_value <= max_value)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_attributes');
    }
};
