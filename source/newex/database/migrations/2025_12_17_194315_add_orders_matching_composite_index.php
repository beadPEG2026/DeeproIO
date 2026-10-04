<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds composite indexes optimized for order matching queries.
     * The primary query pattern is: market_id + type + side + price + quantity > 0 + created_at
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Composite index for buy limit order matching (sell orders lookup)
            // Query: type=limit, side=sell, market_id=X, price <= Y, quantity > 0, ORDER BY price ASC, created_at ASC
            $table->index(
                ['market_id', 'type', 'side', 'quantity', 'price', 'created_at'],
                'orders_matching_composite_idx'
            );

            // Index for stop limit order processing
            // Query: type=stop_limit, trigger_price, trigger_condition
            $table->index(
                ['type', 'trigger_price', 'trigger_condition', 'created_at'],
                'orders_stop_limit_trigger_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_matching_composite_idx');
            $table->dropIndex('orders_stop_limit_trigger_idx');
        });
    }
};
