<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which trades get asked for by name on a sale — M22, and the reason the sale
 * form has a Fitter box and a Winder box without either word being in the code.
 *
 * ## Why a flag here rather than two columns on `transactions`
 *
 * Because `fitter_id` and `winder_id` would be a hard-coded product type by
 * another name, and this table exists precisely because that list is different
 * in every workshop — see the create migration, which argues it at length. A
 * rewinding shop wants Fitter and Winder; the pump dealer across the road wants
 * Driver and Loader; the shop that starts varnishing next year wants Varnisher
 * and will not want a deployment to get it. Two named columns would mean a
 * migration, a request change, a form change and a report change every time,
 * and — long before that — somebody typing a third trade into the Notes field.
 *
 * With the flag, the sale form paints one picker per row that carries it. The
 * form knows how many boxes to draw and nothing at all about what they are for.
 *
 * ## Why not simply every designation
 *
 * Because a sale form with a Driver box, an Accountant box and a Watchman box
 * asks the counter clerk six questions to record two facts, and a form that
 * asks questions nobody answers is a form whose answers stop being trusted.
 * Only the trades that actually do the repair get asked about, and which those
 * are is the workshop's decision rather than this application's.
 *
 * ## Default `false`, deliberately
 *
 * An existing workshop's designations are all switched off until somebody ticks
 * two of them, so this migration changes no form on the day it runs. The
 * alternative — defaulting to true — would put every trade in the building onto
 * the sale form the moment it deployed, which is the failure above arrived at
 * by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_designations', function (Blueprint $table) {
            $table->boolean('track_on_sales')->default(false)->after('is_active');
        });

        /*
        | The one exception to the default, and it is for the workshop that is
        | already running: a shop with a Fitter and a Winder on its list means
        | those two, and making them tick a box to say so would be this
        | application asking a question it can already answer.
        |
        | Case-insensitive, and only these two: anything else — Helper, Driver —
        | stays off, because a helper who lends a hand is not the person the
        | repair is attributed to. A workshop that disagrees ticks the box.
        */
        DB::table('staff_designations')
            ->whereIn(DB::raw('LOWER(name)'), ['fitter', 'winder'])
            ->update(['track_on_sales' => true]);
    }

    public function down(): void
    {
        Schema::table('staff_designations', function (Blueprint $table) {
            $table->dropColumn('track_on_sales');
        });
    }
};
