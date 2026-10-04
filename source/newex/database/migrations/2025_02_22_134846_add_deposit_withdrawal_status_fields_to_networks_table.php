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
        Schema::table('networks', function (Blueprint $table) {
            $table->boolean('deposit_status')->default(true);
            $table->boolean('withdraw_status')->default(true);
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->text('disabled_withdrawal_networks')->nullable();
            $table->text('disabled_deposit_networks')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('deposit_status');
            $table->dropColumn('withdraw_status');
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('disabled_withdrawal_networks');
            $table->dropColumn('disabled_deposit_networks');
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('network_deposit_status');
            $table->dropColumn('network_withdraw_status');
        });
    }
};
