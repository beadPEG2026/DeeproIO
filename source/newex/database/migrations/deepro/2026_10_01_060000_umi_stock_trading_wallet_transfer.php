<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('umi_v2_live_settings', function (Blueprint $table): void {
            $table->boolean('stock_transfer_enabled')->default(false);
        });
        Schema::table('umi_v2_stock_share_moves', function (Blueprint $table): void {
            $table->unsignedBigInteger('exchange_user_id')->nullable();
            $table->unsignedBigInteger('exchange_currency_id')->nullable();
            $table->unsignedBigInteger('pool_wallet_id')->nullable();
            $table->unsignedBigInteger('exchange_wallet_id')->nullable();
            $table->decimal('pool_balance_before', 36, 18)->nullable();
            $table->decimal('pool_balance_after', 36, 18)->nullable();
            $table->decimal('exchange_balance_before', 36, 18)->nullable();
            $table->decimal('exchange_balance_after', 36, 18)->nullable();
            $table->index(['exchange_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('umi_v2_stock_share_moves')->where('kind', 'exchange_transfer')->exists()) {
            throw new RuntimeException('A Deepro stock transfer must survive code rollback.');
        }
        Schema::table('umi_v2_stock_share_moves', function (Blueprint $table): void {
            $table->dropIndex(['exchange_user_id', 'created_at']);
            $table->dropColumn(['exchange_user_id', 'exchange_currency_id', 'pool_wallet_id',
                'exchange_wallet_id', 'pool_balance_before', 'pool_balance_after',
                'exchange_balance_before', 'exchange_balance_after']);
        });
        Schema::table('umi_v2_live_settings', function (Blueprint $table): void {
            $table->dropColumn('stock_transfer_enabled');
        });
    }
};
