<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this product's price is quoted: before GST, or with it already in.
 *
 * A default for the bill form to prefill, and nothing more — the flag that
 * actually decides a document's arithmetic is the one on the line, because a
 * shop selling a part at its printed price still quotes the occasional job
 * before tax. See `transaction_lines.price_includes_tax`.
 *
 * It sits on `items` beside `gst_rate` and `hsn_sac` rather than on
 * `item_variants` beside `sell_price`, which is where the price itself lives.
 * The three are one statement about the product — what it is taxed at, under
 * which code, and on which of the two bases it is quoted — and splitting the
 * last one onto the variant would mean a family whose 5 HP is priced inclusive
 * and whose 7.5 HP is not, which is a distinction no workshop makes and one
 * more thing to keep in step on every variant screen.
 *
 * False by default, which is what every item and every posted line has always
 * meant. Nothing changes until a workshop says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('price_includes_tax')->default(false)->after('gst_rate');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('price_includes_tax');
        });
    }
};
