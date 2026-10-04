<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFuturesContractTable extends Migration
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

        Schema::create('futures_contract', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->bigInteger('market_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->default(0);
            $table->decimal('price', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('quantity', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('leverage', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('balance', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('liquidation_price', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('released_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('pnl', $currencyLength, $currencyDecimals)->default(0);
            $table->boolean('is_long')->default(false)->index();
            $table->integer('base_currency_id')->unsigned()->nullable();
            $table->integer('quote_currency_id')->unsigned()->nullable();
            $table->string('status', 20)->nullable()->index();

            $table->timestamps();

            $table->foreign('market_id')->references('id')->on('markets');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('base_currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('quote_currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('futures_contract');
    }
}
