<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWithdrawalTable extends Migration
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

        Schema::create('withdrawals', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->bigIncrements('id');
            $table->string('withdrawal_id', 100);
            $table->string('txn', 500)->nullable()->index();
            $table->string('type', 20)->index();
            $table->integer('currency_id')->index();
            $table->string('source_id', 500)->nullable()->index();
            $table->integer('network_id')->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('fee', $currencyLength, $currencyDecimals)->default(0);
            $table->string('address', 500)->index();
            $table->string('payment_id', 500)->nullable()->index();
            $table->integer('user_id')->index()->nullable();
            $table->integer('confirms')->default(0);
            $table->string('status', 30)->index();
            $table->text('rejected_reason')->nullable();
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
        Schema::dropIfExists('withdrawals');
    }
}
