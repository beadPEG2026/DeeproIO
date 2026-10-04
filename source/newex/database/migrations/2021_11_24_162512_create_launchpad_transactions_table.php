<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaunchpadTransactionsTable extends Migration
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

        Schema::create('launchpad_transactions', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {
            $table->id();
            $table->integer('launchpad_id')->index();
            $table->integer('user_id')->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            $table->boolean('is_credited')->default(0)->index();
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
        Schema::dropIfExists('launchpad_transactions');
    }
}
