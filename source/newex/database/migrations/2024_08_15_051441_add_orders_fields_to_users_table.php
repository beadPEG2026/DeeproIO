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
        Schema::table('users', function (Blueprint $table) {
            $table->string('peer_username', 100)->nullable();
            $table->timestamp('peer_username_updated_at')->nullable();
            $table->float('orders_completion_rate')->default(0)->nullable();
            $table->integer('orders_completed')->default(0);
            $table->integer('orders_buy_completed')->default(0);
            $table->integer('orders_sell_completed')->default(0);

            $table->integer('orders_completed_thirty')->default(0);
            $table->integer('orders_buy_completed_thirty')->default(0);
            $table->integer('orders_sell_completed_thirty')->default(0);

            $table->string('orders_avg_paytime')->default(0);
            $table->string('orders_avg_releasetime')->default(0);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('peer_username');
            $table->dropColumn('peer_username_updated_at');
            $table->dropColumn('orders_completion_rate');
            $table->dropColumn('orders_completed');
            $table->dropColumn('orders_completed_thirty');
            $table->dropColumn('orders_buy_completed');
            $table->dropColumn('orders_sell_completed');
            $table->dropColumn('orders_avg_paytime');
            $table->dropColumn('orders_avg_releasetime');
        });
    }
};
