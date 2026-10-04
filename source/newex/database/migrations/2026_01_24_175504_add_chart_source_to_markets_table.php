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
        Schema::table('markets', function (Blueprint $table) {
            // Chart source: binance, mexc, bybit, etc.
            $table->string('chart_source', 20)
                ->after('switch_chart')
                ->nullable()
                ->default('binance');
            
            // External symbol mapping (e.g., if internal is BTC-USDT but exchange uses BTCUSDT)
            $table->string('chart_symbol', 50)
                ->after('chart_source')
                ->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn(['chart_source', 'chart_symbol']);
        });
    }
};
