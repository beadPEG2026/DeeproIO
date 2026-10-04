<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaunchpadsTable extends Migration
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

        Schema::create('launchpads', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {
            $table->id();
            $table->string('name', 150);
            $table->text('description');
            $table->integer('currency_id');
            $table->integer('network_id');
            $table->decimal('rate', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('min_buy', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_buy', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('soft_cap', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('hard_cap', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('raised_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->boolean('status');
            $table->boolean('purchasable')->default(false);
            $table->string('progress', 100)->default('pending')->index();
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
        Schema::dropIfExists('launchpads');
    }
}
