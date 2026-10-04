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
            $table->decimal('withdraw_fee_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('withdraw_fee_bep_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('withdraw_fee_erc_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('withdraw_fee_trc_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('withdraw_fee_matic_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');

            $table->decimal('deposit_fee_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_bep_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_erc_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_trc_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_matic_fixed', $currencyLength, $currencyDecimals)->default(0)->after('fee');

            $table->decimal('deposit_fee_bep', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_erc', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_trc', $currencyLength, $currencyDecimals)->default(0)->after('fee');
            $table->decimal('deposit_fee_matic', $currencyLength, $currencyDecimals)->default(0)->after('fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {

            $table->dropColumn('withdraw_fee_fixed');
            $table->dropColumn('withdraw_fee_bep_fixed');
            $table->dropColumn('withdraw_fee_erc_fixed');
            $table->dropColumn('withdraw_fee_trc_fixed');
            $table->dropColumn('withdraw_fee_matic_fixed');

            $table->dropColumn('deposit_fee_fixed');
            $table->dropColumn('deposit_fee_bep_fixed');
            $table->dropColumn('deposit_fee_erc_fixed');
            $table->dropColumn('deposit_fee_trc_fixed');
            $table->dropColumn('deposit_fee_matic_fixed');

            $table->dropColumn('deposit_fee_bep');
            $table->dropColumn('deposit_fee_erc');
            $table->dropColumn('deposit_fee_trc');
            $table->dropColumn('deposit_fee_matic');
        });
    }
};
