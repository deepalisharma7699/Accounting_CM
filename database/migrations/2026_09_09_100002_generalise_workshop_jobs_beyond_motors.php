<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bench takes in more than motors.
 *
 * ## What was wrong
 *
 * `workshop_jobs` recorded `hp` and `phase` as columns, and the intake form
 * asked for them under a heading that said "The motor". That is a product type
 * written into a schema and into a Blade template — the exact failure
 * CLAUDE.md's catalogue rule already records against `ItemType` and against a
 * typed brand — and it costs a real workshop something every week. A cooler, a
 * table fan, a mixer or a hand drill comes in for repair, and the only fields
 * the job card offered were two that mean nothing about any of them, under a
 * heading telling the counter it had the wrong screen.
 *
 * ## What replaces them
 *
 * The vocabulary the catalogue already owns. `item_categories` says what kinds
 * of thing exist and what to record about each, `item_attributes` is the
 * question set, and both are edited by an admin with no deployment. A job now
 * carries:
 *
 *   `category_id`   which kind of thing came in
 *   `kind_label`    that category's name, **copied**
 *   `specs`         the answers, keyed by attribute — `item_variants.attributes`
 *                   shape, for the same reason and drawn by the same renderer
 *
 * So a workshop that starts repairing coolers adds a Cooler category from the
 * Items card, and the bench asks what a cooler is described by. No column, no
 * migration, no deployment — which is the catalogue module's own acceptance
 * criterion, applied one module along.
 *
 * ## Why the label is copied, and the category alone is not enough
 *
 * The rule the columns beside it already follow, and the one this table's first
 * migration states: a job card is the record of a physical object on a day. The
 * casing said "Motor" when it arrived and must still say so next year, after
 * somebody has renamed the category or archived it. It also means a list row
 * renders with no join, and a job whose category was deleted still says what
 * came in rather than going blank.
 *
 * ## Why `hp` and `phase` are dropped rather than kept beside it
 *
 * Two places to record one fact is §4.4, and this would be the version of it
 * that hurts: a motor's rating would live in a column while a pump's lived in
 * the bag, and every reader would have to know which. The seeded Motor category
 * carries the keys `hp` and `phase` verbatim — they were `ItemType`'s and they
 * are still `item_attributes`' — so every existing value moves across unchanged
 * and lands under the key the schema already describes it by.
 *
 * The one conversion is `phase`. The column held what the old select offered,
 * `1-phase` / `3-phase`; the category's dropdown offers `1` / `3` with `ph`
 * printed beside it. Anything else a hand-written row held is carried across as
 * it stands rather than guessed at — a job's specs are never validated against
 * the schema (see JobService), so an unrecognised value still displays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table) {
            // Nullable, like `item_id` above it and for the same reason: a pump
            // is wheeled in at four in the afternoon by a driver who does not
            // know what it is, and a form that refused to book it in would be a
            // form that got a job card written on paper instead.
            $table->foreignId('category_id')->nullable()->after('item_id')
                ->constrained('item_categories')->nullOnDelete();

            $table->string('kind_label', 60)->nullable()->after('category_id');

            // What the category asked and what the counter answered. The same
            // shape as `item_variants.attributes` — a flat map of attribute key
            // to the value as a string — so one renderer draws both.
            $table->json('specs')->nullable()->after('serial_no');
        });

        $this->carryTheMotorsAcross();

        Schema::table('workshop_jobs', function (Blueprint $table) {
            $table->dropColumn(['hp', 'phase']);
        });
    }

    /**
     * Every job on every bench today is a motor, because until now the form
     * could not say otherwise. Each is stamped with its own workshop's Motor
     * category and its two fields moved into the bag.
     */
    private function carryTheMotorsAcross(): void
    {
        // One row per workshop, so a job is never stamped with another
        // workshop's category. `code` is the seeded value verbatim — see
        // CatalogueDefaults — and it survives a rename, which the name does not.
        $categories = DB::table('item_categories')
            ->where('code', 'motor')
            ->get(['id', 'tenant_id', 'name'])
            ->keyBy('tenant_id');

        DB::table('workshop_jobs')
            ->select(['id', 'tenant_id', 'hp', 'phase'])
            ->orderBy('id')
            ->chunk(500, function ($jobs) use ($categories) {
                foreach ($jobs as $job) {
                    $category = $categories[$job->tenant_id] ?? null;

                    $specs = array_filter([
                        'hp' => $this->cleaned($job->hp),
                        'phase' => $this->phase($job->phase),
                    ], fn (?string $value) => $value !== null);

                    DB::table('workshop_jobs')->where('id', $job->id)->update([
                        'category_id' => $category?->id,
                        // Named even where the category row is missing — a
                        // workshop provisioned before the catalogue masters
                        // existed still received a motor, and the card should
                        // say so.
                        'kind_label' => $category?->name ?? 'Motor',
                        'specs' => $specs === [] ? null : json_encode($specs),
                    ]);
                }
            });
    }

    /** `3-phase` is what the old select offered; `3` is what the category asks for. */
    private function phase(?string $value): ?string
    {
        $value = $this->cleaned($value);

        return match ($value) {
            '1-phase' => '1',
            '3-phase' => '3',
            default => $value,
        };
    }

    private function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function down(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table) {
            $table->string('hp', 20)->nullable()->after('item_id');
            $table->string('phase', 20)->nullable()->after('serial_no');
        });

        // Back the way they came, so a rollback loses nothing a motor recorded.
        // Anything a *cooler* recorded has no column to go back to and is lost
        // with `specs` — the honest cost of rolling a capability back, and the
        // reason `down()` is not a plan.
        DB::table('workshop_jobs')
            ->whereNotNull('specs')
            ->select(['id', 'specs'])
            ->orderBy('id')
            ->chunk(500, function ($jobs) {
                foreach ($jobs as $job) {
                    $specs = json_decode((string) $job->specs, true) ?: [];

                    DB::table('workshop_jobs')->where('id', $job->id)->update([
                        'hp' => $specs['hp'] ?? null,
                        'phase' => match ($specs['phase'] ?? null) {
                            '1' => '1-phase',
                            '3' => '3-phase',
                            default => $specs['phase'] ?? null,
                        },
                    ]);
                }
            });

        Schema::table('workshop_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['kind_label', 'specs']);
        });
    }
};
