<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReferralTransactionsTable extends Migration
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

        Schema::create('referral_transactions', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {

            // Fields
            $table->id();
            $table->boolean('is_credited')->default(false)->index();
            $table->bigInteger('transaction_id')->unsigned()->index();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->integer('currency_id')->unsigned()->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            // Timestamps
            $table->timestamps();

            // Foreign keys
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('referral_transactions');
    }
}
