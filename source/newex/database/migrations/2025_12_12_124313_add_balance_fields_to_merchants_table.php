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
        Schema::table('merchants', function (Blueprint $table) {
            // Balance tracking
            $table->decimal('available_balance_usd', 24, 2)->default(0)->after('total_fees_usd');
            $table->decimal('pending_balance_usd', 24, 2)->default(0)->after('available_balance_usd');
            $table->decimal('total_paid_out_usd', 24, 2)->default(0)->after('pending_balance_usd');
            
            // Payout settings
            $table->string('default_payout_address', 255)->nullable()->after('settlement_address');
            $table->string('default_payout_memo', 100)->nullable()->after('default_payout_address');
            $table->decimal('min_payout_amount_usd', 18, 2)->default(50.00)->after('default_payout_memo');
            $table->boolean('auto_payout_enabled')->default(false)->after('min_payout_amount_usd');
            $table->decimal('auto_payout_threshold_usd', 18, 2)->nullable()->after('auto_payout_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'available_balance_usd',
                'pending_balance_usd', 
                'total_paid_out_usd',
                'default_payout_address',
                'default_payout_memo',
                'min_payout_amount_usd',
                'auto_payout_enabled',
                'auto_payout_threshold_usd',
            ]);
        });
    }
};
