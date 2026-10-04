<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWalletBalanceFieldsToCurreniesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::table('currencies', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {

            $table->decimal('wallet_balance_trc', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('wallet_balance_erc', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('wallet_balance_bep', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('wallet_balance_matic', $currencyLength, $currencyDecimals)->default(0);

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('wallet_balance_trc');
            $table->dropColumn('wallet_balance_erc');
            $table->dropColumn('wallet_balance_bep');
        });
    }
}
