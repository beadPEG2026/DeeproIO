<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('copy_trading_traders', function (Blueprint $table) {
            if (!Schema::hasColumn('copy_trading_traders', 'section_label')) {
                $table->string('section_label', 40)->default('高盈亏')->after('win_rate');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_followers_count')) {
                $table->unsignedInteger('display_followers_count')->default(0)->after('section_label');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_followers_limit')) {
                $table->unsignedInteger('display_followers_limit')->default(0)->after('display_followers_count');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_badges')) {
                $table->string('display_badges', 255)->nullable()->after('display_followers_limit');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_profit_amount')) {
                $table->decimal('display_profit_amount', 20, 2)->default(0)->after('display_badges');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_roi_percent')) {
                $table->decimal('display_roi_percent', 10, 2)->default(0)->after('display_profit_amount');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_asset_scale')) {
                $table->decimal('display_asset_scale', 20, 2)->default(0)->after('display_roi_percent');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_max_drawdown')) {
                $table->decimal('display_max_drawdown', 10, 2)->default(0)->after('display_asset_scale');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_lead_days')) {
                $table->unsignedInteger('display_lead_days')->default(0)->after('display_max_drawdown');
            }

            if (!Schema::hasColumn('copy_trading_traders', 'display_chart_points')) {
                $table->text('display_chart_points')->nullable()->after('display_lead_days');
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'display_chart_points',
            'display_lead_days',
            'display_max_drawdown',
            'display_asset_scale',
            'display_roi_percent',
            'display_profit_amount',
            'display_badges',
            'display_followers_limit',
            'display_followers_count',
            'section_label',
        ];

        Schema::table('copy_trading_traders', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('copy_trading_traders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
