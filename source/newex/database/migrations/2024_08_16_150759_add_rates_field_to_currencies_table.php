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
            $table->string('rate')->default(1);
            $table->double('p2p_min_order_amount')->nullable()->default(0);
            $table->double('p2p_max_order_amount')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('rate');
            $table->dropColumn('p2p_min_order_amount');
            $table->dropColumn('p2p_max_order_amount');
        });
    }
};
