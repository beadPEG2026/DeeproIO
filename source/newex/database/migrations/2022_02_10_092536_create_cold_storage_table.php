<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateColdStorageTable extends Migration
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

        Schema::create('cold_storage', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->integer('currency_id')->index();
            $table->integer('network_id')->index();
            $table->text('address');
            $table->decimal('cold_min_balance_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('cold_transfer_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->integer('cold_storage_transaction_id')->nullable();
            $table->boolean('status')->default(true);
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
        Schema::dropIfExists('cold_storage');
    }
}
