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
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::table('currencies', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->decimal('withdraw_fee_sol', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('withdraw_fee_sol_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_sol_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_sol', $currencyLength, $currencyDecimals)->default(0)->after('fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('withdraw_fee_sol');
            $table->dropColumn('withdraw_fee_sol_fixed');
            $table->dropColumn('deposit_fee_sol_fixed');
            $table->dropColumn('deposit_fee_sol');
        });
    }
};
