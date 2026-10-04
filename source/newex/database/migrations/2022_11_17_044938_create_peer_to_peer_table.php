<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePeerToPeerTable extends Migration
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

        Schema::create('peer_trades', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {

            $table->uuid('id')->primary();
            $table->bigInteger('user_id')->unsigned()->default(0);
            $table->integer('currency_id')->unsigned()->nullable();
            $table->decimal('price', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('min_amount', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('max_amount', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('available_amount', $currencyLength, $currencyDecimals)->default(0)->index();

            $table->string('status', 20)->nullable()->index();

            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users');
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
        Schema::dropIfExists('peer_trades');
    }
}

