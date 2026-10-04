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
        Schema::create('merchant_asset_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('merchant_id')->index();
            $table->bigInteger('currency_id')->unsigned()->index();

            // Override flags
            $table->boolean('is_enabled')->default(true);

            // Custom limits (null = use platform defaults)
            $table->decimal('custom_min_amount_usd', 18, 2)->nullable();
            $table->decimal('custom_max_amount_usd', 18, 2)->nullable();

            // Custom fees (null = use platform defaults)
            $table->decimal('custom_fee_percent', 5, 4)->nullable();

            // Custom confirmations (null = use platform defaults)
            $table->smallInteger('custom_confirmations')->unsigned()->nullable();

            // Auto-conversion settings
            $table->integer('auto_convert_to_currency_id')->unsigned()->nullable();
            $table->boolean('auto_convert_enabled')->default(false);

            // Statistics
            $table->bigInteger('total_invoices')->unsigned()->default(0);
            $table->decimal('total_volume_usd', 24, 2)->default(0.00);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->unique(['merchant_id', 'currency_id'], 'unique_merchant_asset');
            $table->index(['merchant_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_asset_settings');
    }
};
