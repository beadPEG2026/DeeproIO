<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('copy_trading_traders', function (Blueprint $table) {
            if (!Schema::hasColumn('copy_trading_traders', 'history_orders_count')) {
                $table->unsignedInteger('history_orders_count')->default(0)->after('strategy_label');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'win_rate')) {
                $table->decimal('win_rate', 5, 2)->default(0)->after('history_orders_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('copy_trading_traders', function (Blueprint $table) {
            if (Schema::hasColumn('copy_trading_traders', 'win_rate')) {
                $table->dropColumn('win_rate');
            }

            if (Schema::hasColumn('copy_trading_traders', 'history_orders_count')) {
                $table->dropColumn('history_orders_count');
            }
        });
    }
};
