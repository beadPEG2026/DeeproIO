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

        Schema::create('lending_users', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->integer('lending_id')->unsigned()->index();
            $table->integer('currency_id')->unsigned()->index()->nullable();
            $table->integer('collateral_id')->unsigned()->index()->nullable();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('remaining_amount', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->decimal('collateral_amount', $currencyLength, $currencyDecimals)->default(0)->index();
            $table->string('status')->default('active')->index();
            $table->boolean('is_notified')->default(false);
            $table->string('type')->default('flexible');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lending_users');
    }
};
