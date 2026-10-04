<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePeerToPeerPaymentFields extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('peer_payment_fields', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title', 150);
            $table->integer('payment_method')->unsigned();
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->foreign('payment_method')->references('id')->on('peer_payment_methods')->onDelete('NO ACTION')->onUpdate('NO ACTION');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('peer_payment_fields');
    }
}
