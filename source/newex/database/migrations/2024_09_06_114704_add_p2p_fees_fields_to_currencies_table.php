<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->string('p2p_maker_buy_fee')->default(0);
            $table->string('p2p_maker_sell_fee')->default(0);
            $table->string('p2p_taker_buy_fee')->default(0);
            $table->string('p2p_taker_sell_fee')->default(0);

            $table->string('p2p_maker_buy_min_fee')->default(0);
            $table->string('p2p_maker_sell_min_fee')->default(0);
            $table->string('p2p_taker_buy_min_fee')->default(0);
            $table->string('p2p_taker_sell_min_fee')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('p2p_maker_buy_fee');
            $table->dropColumn('p2p_maker_sell_fee');
            $table->dropColumn('p2p_taker_buy_fee');
            $table->dropColumn('p2p_taker_sell_fee');

            $table->dropColumn('p2p_maker_buy_min_fee');
            $table->dropColumn('p2p_maker_sell_min_fee');
            $table->dropColumn('p2p_taker_buy_min_fee');
            $table->dropColumn('p2p_taker_sell_min_fee');
        });
    }
};
