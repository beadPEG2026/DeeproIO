<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds minimum amount field specifically for Market Buy orders.
     * This allows admins to set a minimum quote currency amount for market buy orders per market.
     */
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->decimal('min_market_buy_amount', 36, 18)->default(0)->after('max_trade_value')
                ->comment('Minimum amount in quote currency for Market Buy orders');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('min_market_buy_amount');
        });
    }
};
