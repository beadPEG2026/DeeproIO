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

        Schema::create('options', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->integer('market_id');
            $table->integer('currency_id');
            $table->integer('user_id');
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('price', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('market_price', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('pnl', $currencyLength, $currencyDecimals)->default(0);
            $table->string('type')->nullable();
            $table->string('period')->nullable();
            $table->string('status')->nullable();
            $table->integer('template_id')->nullable();
            $table->string('is_exception')->nullable();
            $table->uuid('uuid')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
