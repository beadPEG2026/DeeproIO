<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletAddressesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wallet_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('address', 500)->index();
            $table->boolean('activated')->default(false)->nullable();
            $table->string('payment_id', 500)->nullable();
            $table->integer('network_id')->unsigned()->index();
            $table->integer('wallet_id')->unsigned()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallet_addresses');
    }
}
