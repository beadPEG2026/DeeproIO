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

        Schema::table('futures_contract', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            // Entry fee: fee charged when opening the position (deducted from margin)
            $table->decimal('entry_fee', $currencyLength, $currencyDecimals)->default(0)->after('pnl');
            // Exit fee: fee charged when closing the position (deducted from payout)
            $table->decimal('exit_fee', $currencyLength, $currencyDecimals)->default(0)->after('entry_fee');
            // Fee rate applied at entry (for reference)
            $table->decimal('fee_rate', 8, 4)->default(0)->after('exit_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('futures_contract', function (Blueprint $table) {
            $table->dropColumn(['entry_fee', 'exit_fee', 'fee_rate']);
        });
    }
};
