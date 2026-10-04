<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('markets', function (Blueprint $table) {
            // Trend Configuration
            $table->enum('bot_trend_direction', ['uptrend', 'downtrend', 'sideways'])->default('sideways')->after('custom_liquidity_end_amount');
            $table->decimal('bot_trend_strength', 5, 2)->default(0.5)->after('bot_trend_direction'); // 0.0 to 1.0 (weak to strong)
            
            // Volatility Settings
            $table->decimal('bot_volatility', 5, 2)->default(0.02)->after('bot_trend_strength'); // Base volatility percentage (e.g., 0.02 = 2%)
            $table->decimal('bot_volatility_burst_chance', 5, 2)->default(0.05)->after('bot_volatility'); // Chance of volatility spike
            
            // Price Boundaries (safety limits)
            $table->string('bot_price_floor')->nullable()->after('bot_volatility_burst_chance'); // Absolute minimum price
            $table->string('bot_price_ceiling')->nullable()->after('bot_price_floor'); // Absolute maximum price
            
            // Order Book Settings
            $table->integer('bot_orderbook_depth')->default(20)->after('bot_price_ceiling'); // Number of orders per side
            $table->decimal('bot_spread_percentage', 5, 4)->default(0.001)->after('bot_orderbook_depth'); // Bid-ask spread (0.1%)
            
            // Trade Frequency
            $table->integer('bot_trade_frequency')->default(30)->after('bot_spread_percentage'); // Percentage chance of trade per cycle (0-100)
            $table->integer('bot_cycle_interval')->default(2)->after('bot_trade_frequency'); // Seconds between cycles
            
            // Current simulated price (tracked for continuity)
            $table->string('bot_current_price')->nullable()->after('bot_cycle_interval');
            
            // Momentum tracking (for realistic price movement)
            $table->decimal('bot_momentum', 8, 6)->default(0)->after('bot_current_price'); // Current momentum value
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn([
                'bot_trend_direction',
                'bot_trend_strength',
                'bot_volatility',
                'bot_volatility_burst_chance',
                'bot_price_floor',
                'bot_price_ceiling',
                'bot_orderbook_depth',
                'bot_spread_percentage',
                'bot_trade_frequency',
                'bot_cycle_interval',
                'bot_current_price',
                'bot_momentum',
            ]);
        });
    }
};
