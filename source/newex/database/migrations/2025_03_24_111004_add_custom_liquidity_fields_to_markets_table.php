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
        Schema::table('markets', function (Blueprint $table) {
            $table->boolean('custom_liquidity')->default(false);
            $table->string('custom_liquidity_start_price')->nullable();
            $table->string('custom_liquidity_end_price')->nullable();
            $table->string('custom_liquidity_start_amount')->nullable();
            $table->string('custom_liquidity_end_amount')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('custom_liquidity');
            $table->dropColumn('custom_liquidity_start_price');
            $table->dropColumn('custom_liquidity_end_price');
            $table->dropColumn('custom_liquidity_start_amount');
            $table->dropColumn('custom_liquidity_end_amount');
        });
    }
};
