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
        Schema::create('acquiring_supported_assets', function (Blueprint $table) {
            $table->id();
            $table->integer('currency_id')->unsigned()->index();
            $table->bigInteger('network_id')->unsigned()->index();
            
            // Asset identification
            $table->string('asset_code', 20)->unique(); // e.g., USDT_TRC20, BTC_NATIVE
            $table->string('display_name', 100); // e.g., "USDT (TRC-20)"
            $table->string('contract_address', 128)->nullable();
            
            // Status flags
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_deposit_enabled')->default(true);
            $table->boolean('is_visible')->default(true); // Show in widget
            $table->boolean('is_recommended')->default(false); // Highlight in widget
            
            // Blockchain configuration
            $table->smallInteger('required_confirmations')->unsigned()->default(12);
            $table->smallInteger('safe_confirmations')->unsigned()->default(20);
            $table->smallInteger('avg_block_time_seconds')->unsigned()->default(15);
            
            // Limits in USD
            $table->decimal('min_amount_usd', 18, 2)->default(1.00);
            $table->decimal('max_amount_usd', 18, 2)->default(100000.00);
            
            // Fee configuration
            $table->decimal('processing_fee_percent', 5, 4)->default(1.0000);
            $table->decimal('network_fee_usd', 18, 2)->default(0.00);
            
            // Rate source configuration
            $table->string('rate_source', 20)->default('binance'); // internal, binance, coingecko, fixed
            $table->string('rate_pair', 20)->nullable(); // e.g., BTCUSDT for Binance
            $table->decimal('rate_premium_percent', 5, 4)->default(0.0000);
            $table->decimal('fixed_rate_usd', 24, 12)->nullable(); // For stablecoins
            
            // Address generation
            $table->string('address_type', 20)->default('unique'); // unique, shared_memo, shared_tag
            $table->string('memo_field_name', 50)->nullable(); // e.g., "memo", "destination_tag"
            
            // Display settings
            $table->tinyInteger('display_decimals')->unsigned()->default(6);
            $table->tinyInteger('precision')->unsigned()->default(8); // Internal calculation precision
            $table->string('icon_url', 255)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->smallInteger('sort_order')->unsigned()->default(100);
            
            // SLA configuration
            $table->integer('detection_sla_seconds')->unsigned()->default(60);
            $table->integer('confirmation_sla_seconds')->unsigned()->default(900);
            
            // Metadata
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['currency_id', 'network_id'], 'unique_currency_network');
            $table->index(['is_enabled', 'is_visible'], 'idx_enabled_visible');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquiring_supported_assets');
    }
};
