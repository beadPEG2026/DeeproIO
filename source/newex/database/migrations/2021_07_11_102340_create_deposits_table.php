<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDepositsTable extends Migration
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

        Schema::create('deposits', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->bigIncrements('id');
            $table->string('deposit_id', 100);
            $table->string('txn', 500)->nullable()->index();
            $table->string('type', 20)->index();
            $table->integer('currency_id')->index();
            $table->string('source_id', 500)->nullable()->index();
            $table->integer('network_id')->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('network_fee', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('system_fee', $currencyLength, $currencyDecimals)->default(0);
            $table->string('address', 500)->index();
            $table->string('payment_id', 500)->nullable()->index();
            $table->integer('user_id')->index()->nullable();
            $table->integer('confirms')->default(0);
            $table->string('status', 20)->index();
            $table->json('initial_raw')->nullable();
            $table->json('raw')->nullable();
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
        Schema::dropIfExists('deposits');
    }
}
