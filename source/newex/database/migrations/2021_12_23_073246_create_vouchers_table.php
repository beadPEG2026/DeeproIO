<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVouchersTable extends Migration
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

        Schema::create('vouchers', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->integer('currency_id')->unsigned()->index();

            $table->string('code', 20)->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);

            $table->boolean('is_redeemed')->default(false)->index();

            $table->timestamps();
            $table->timestamp('redeemed_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('currency_id')->references('id')->on('currencies');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vouchers');
    }
}
