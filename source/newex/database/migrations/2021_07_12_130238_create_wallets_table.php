<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTable extends Migration
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

        Schema::create('wallets', function (Blueprint $table)  use ($currencyLength, $currencyDecimals) {

            // fields
            $table->bigIncrements('id');
            $table->integer('currency_id')->unsigned()->index();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->decimal('balance_in_wallet', $currencyLength, $currencyDecimals)->unsigned()->default(0);
            $table->decimal('balance_in_order', $currencyLength, $currencyDecimals)->unsigned()->default(0);
            $table->decimal('balance_in_withdraw', $currencyLength, $currencyDecimals)->unsigned()->default(0);

            // timestamp
            $table->timestamps();

            // relations
            $table->foreign('user_id')->references('id')->on('users')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallets');
    }
}
