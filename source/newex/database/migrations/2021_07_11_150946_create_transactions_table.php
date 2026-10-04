<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
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

        Schema::create('transactions', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {

            // Fields
            $table->bigIncrements('id');

            $table->uuid('process_id')->index();
            $table->uuid('order_id')->index()->nullable();

            $table->integer('user_id')->nullable()->index();
            $table->boolean('is_maker')->index()->default(false);
            $table->integer('market_id')->index();
            $table->string('order_type', 10)->index();
            $table->string('order_side', 10)->index();
            $table->decimal('price', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('fee', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('referral_fee', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('base_currency', $currencyLength, $currencyDecimals);
            $table->decimal('quote_currency', $currencyLength, $currencyDecimals);

            // Timestamps
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
