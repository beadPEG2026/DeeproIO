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

        Schema::create('peer_ads', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->bigInteger('user_id')->index();
            $table->integer('base_currency_id')->index();
            $table->integer('quote_currency_id')->index();
            $table->decimal('amount', $currencyLength, 8)->default(0);
            $table->decimal('remaining_amount', $currencyLength, 8)->default(0);
            $table->string('fee_rate')->default(0);
            $table->decimal('fee_reserved', $currencyLength, 8)->default(0);
            $table->decimal('fee_remaining', $currencyLength, 8)->default(0);
            $table->decimal('min_amount', $currencyLength, 3)->default(0);
            $table->decimal('max_amount', $currencyLength, 3)->default(0);
            $table->string('type')->index();
            $table->decimal('price', $currencyLength, 3)->default(0);
            $table->float('price_percentage')->default(0);
            $table->string('price_type')->index();
            $table->string('timeframe')->index();
            $table->text('remarks')->nullable();
            $table->text('auto_reply')->nullable();
            $table->text('status')->nullable();
            $table->text('hidden_reason')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('peer_ads_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('ad_id')->index();
            $table->bigInteger('payment_method_id')->index();
            $table->bigInteger('user_payment_method_id')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peer_ads');
        Schema::dropIfExists('peer_ads_payment_methods');
    }
};
