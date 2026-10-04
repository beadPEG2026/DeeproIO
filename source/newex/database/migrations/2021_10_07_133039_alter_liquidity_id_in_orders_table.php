<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterLiquidityIdInOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('liquidity_id', 255)->after('price')->index()->nullable();
        });

        Schema::table('order_histories', function (Blueprint $table) {
            $table->string('liquidity_id', 255)->after('price')->index()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('liquidity_id');
        });

        Schema::table('order_histories', function (Blueprint $table) {
            $table->dropColumn('liquidity_id');
        });
    }
}
