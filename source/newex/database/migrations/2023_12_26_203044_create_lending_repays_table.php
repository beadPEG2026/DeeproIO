<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::create('lending_repays', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->integer('lending_id')->unsigned()->index()->nullable();
            $table->integer('lending_user_id')->unsigned()->index()->nullable();
            $table->integer('currency_id')->unsigned()->index()->nullable();
            $table->integer('collateral_id')->unsigned()->index()->nullable();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lending_repays');
    }
};
