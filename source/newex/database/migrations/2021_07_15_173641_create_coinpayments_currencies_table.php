<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCoinpaymentsCurrenciesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('coinpayments_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('symbol', 50);
            $table->string('fee', 50)->nullable();
            $table->integer('confirmations')->nullable();
            $table->string('status', 50)->nullable();
            $table->text('blockchain_url')->nullable();
            $table->boolean('has_payment_id')->default(false);
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
        Schema::dropIfExists('coinpayments_currencies');
    }
}
