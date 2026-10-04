<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStakingUsers extends Migration
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

        Schema::create('staking_users', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->integer('staking_id')->unsigned()->index();
            $table->integer('currency_id')->unsigned()->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->integer('days')->nullable();
            $table->decimal('apy', $currencyLength, $currencyDecimals)->nullable();
            $table->decimal('reward', $currencyLength, $currencyDecimals)->default(0);
            $table->string('status')->default('active')->index();
            $table->timestamp('value_date')->nullable()->index();
            $table->timestamp('redemption_date')->nullable()->index();
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
        Schema::dropIfExists('staking_users');
    }
}
