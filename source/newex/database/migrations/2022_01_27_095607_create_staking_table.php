<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStakingTable extends Migration
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

        Schema::create('staking', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->integer('currency_id')->unsigned()->index();
            $table->string('allowed_days', 255)->nullable();
            $table->string('rewards_percentage', 255)->nullable();
            $table->decimal('min_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->string('status')->nullable();

            $table->timestamps();

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
        Schema::dropIfExists('staking');
    }
}
