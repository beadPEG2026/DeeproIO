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
            // Merchant acquiring feature flag
            $table->boolean('is_merchant')->default(false)->after('is_p2p');
            
            // Merchant-specific settings
            $table->decimal('merchant_fee_percent', 8, 4)->default(1.0000)->after('is_merchant');
            $table->decimal('merchant_min_amount_usd', 18, 2)->default(1.00)->after('merchant_fee_percent');
            $table->decimal('merchant_max_amount_usd', 18, 2)->default(100000.00)->after('merchant_min_amount_usd');
            $table->integer('merchant_confirmations')->default(3)->after('merchant_max_amount_usd');
            
            // Index for merchant queries
            $table->index('is_merchant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropIndex(['is_merchant']);
            $table->dropColumn([
                'is_merchant',
                'merchant_fee_percent',
                'merchant_min_amount_usd',
                'merchant_max_amount_usd',
                'merchant_confirmations',
            ]);
        });
    }
};
