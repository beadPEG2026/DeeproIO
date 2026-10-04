<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketsTable extends Migration
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

        Schema::create('markets', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {

            // Fields
            $table->bigIncrements('id');

            // General information
            $table->string('name', 255);
            $table->integer('base_currency_id')->unsigned()->index();
            $table->integer('quote_currency_id')->unsigned()->index();

            // Market Pair Rules
            $table->integer('base_precision')->default(0);
            $table->integer('quote_precision')->default(0);
            $table->decimal('min_trade_size', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_trade_size', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('min_trade_value', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_trade_value', $currencyLength, $currencyDecimals)->default(0);

            // Market Ticker Size
            $table->decimal('base_ticker_size', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('quote_ticker_size', $currencyLength, $currencyDecimals)->default(0);

            // Market Status
            $table->boolean('status')->default(true);
            $table->boolean('trade_status')->default(true);
            $table->boolean('buy_order_status')->default(true);
            $table->boolean('sell_order_status')->default(true);
            $table->boolean('cancel_order_status')->default(true);
            $table->boolean('has_options')->default(false);

            // Market Stats
            $table->decimal('bid', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('ask', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('last', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('high', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('low', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('volume', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('capitalization', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('change_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('change_percentage', 10, 2)->default(0);

            // Soft delete and timestamps
            $table->softDeletes();
            $table->timestamps();

            // Relations
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
        Schema::dropIfExists('markets');
    }
}
