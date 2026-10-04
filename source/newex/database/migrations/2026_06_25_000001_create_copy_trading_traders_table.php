<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copy_trading_traders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('display_name', 120)->nullable();
            $table->string('strategy_label', 120)->nullable();
            $table->unsignedInteger('history_orders_count')->default(0);
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->string('section_label', 40)->default('高盈亏');
            $table->unsignedInteger('display_followers_count')->default(0);
            $table->unsignedInteger('display_followers_limit')->default(0);
            $table->string('display_badges', 255)->nullable();
            $table->decimal('display_profit_amount', 20, 2)->default(0);
            $table->decimal('display_roi_percent', 10, 2)->default(0);
            $table->decimal('display_asset_scale', 20, 2)->default(0);
            $table->decimal('display_max_drawdown', 10, 2)->default(0);
            $table->unsignedInteger('display_lead_days')->default(0);
            $table->text('display_chart_points')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copy_trading_traders');
    }
};
