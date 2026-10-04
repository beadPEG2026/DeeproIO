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

        Schema::create('lending', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->integer('currency_id')->unsigned()->index();
            $table->decimal('min_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_amount', $currencyLength, $currencyDecimals)->default(0);

            $table->boolean('is_flexible')->default(1)->nullable();;
            $table->boolean('is_weekly')->default(0)->nullable();;
            $table->boolean('is_monthly')->default(0)->nullable();;

            $table->string('annual_rate_flexible')->default(0)->nullable();
            $table->string('annual_rate_weekly')->default(0)->nullable();
            $table->string('annual_rate_monthly')->default(0)->nullable();

            $table->string('status')->nullable();

            $table->timestamps();

            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lending');
    }
};
