<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStripePaymentIntentsTable extends Migration
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

        Schema::create('stripe_payment_intents', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {
            $table->id();
            $table->string('intent_id', 255)->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            $table->string('amount_raw', 255)->nullable();
            $table->string('currency_raw', 255)->nullable();
            $table->string('status', 20)->nullable()->index();
            $table->integer('currency_id')->unsigned()->index();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
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
        Schema::dropIfExists('stripe_payment_intents');
    }
}
