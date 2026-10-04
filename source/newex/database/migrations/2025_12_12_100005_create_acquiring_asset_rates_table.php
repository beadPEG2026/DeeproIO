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
        Schema::create('acquiring_asset_rates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('currency_id')->unsigned()->index();

            // Rate data
            $table->decimal('rate_usd', 24, 12); // Main rate
            $table->decimal('bid_price', 24, 12)->nullable();
            $table->decimal('ask_price', 24, 12)->nullable();
            $table->decimal('spread_percent', 8, 4)->nullable();

            // Source information
            $table->string('rate_source', 20); // binance, coingecko, internal, fixed
            $table->string('source_pair', 20)->nullable(); // Original trading pair

            // Validity
            $table->timestamp('fetched_at');
            $table->timestamp('valid_until');

            // Quality metrics
            $table->integer('source_latency_ms')->unsigned()->nullable();
            $table->boolean('is_stale')->default(false);

            $table->timestamps();

            // Indexes for efficient rate lookup
            $table->index(['currency_id', 'valid_until'], 'idx_asset_valid');
            $table->index(['fetched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquiring_asset_rates');
    }
};
