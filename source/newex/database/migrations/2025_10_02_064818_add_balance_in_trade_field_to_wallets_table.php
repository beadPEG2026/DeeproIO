<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::table('wallets', function (Blueprint $table)  use ($currencyLength, $currencyDecimals) {
            // Trade wallets
            $table->decimal('balance_in_trade', $currencyLength, $currencyDecimals)->unsigned()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('balance_in_trade');
        });
    }
};
