<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
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

        Schema::create('orders', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {

            // Fields
            $table->uuid('id')->primary();
            $table->bigInteger('market_id')->unsigned()->index();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->string('type', 10)->index();
            $table->string('side', 10)->index();
            $table->decimal('initial_quantity', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('quantity', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('initial_quote_quantity', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('quote_quantity', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('fee', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('fee_rate', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('price', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('filled_price', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('trigger_price', $currencyLength, $currencyDecimals)->nullable()->index();
            $table->string('trigger_condition', 10)->nullable()->index();
            $table->integer('base_currency_id')->unsigned()->index();
            $table->integer('quote_currency_id')->unsigned()->index();
            $table->boolean('locked')->default(false)->index();

            // Timestamps
            $table->timestamps();

            // Relations
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
        Schema::dropIfExists('orders');
    }
}
